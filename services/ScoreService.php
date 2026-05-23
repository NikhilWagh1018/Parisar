<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  services/ScoreService.php
//  !! THE ONLY PLACE scoring logic may live !!
//
//  Implements the EXACT Parisar scoring methodology per:
//    - Cycle Track Audit Report 2025 (PDF)
//    - Cycle_track_scores.xlsx (ground-truth calculations)
//
//  ──────────────────────────────────────────────────────────────
//  SCORING CONVENTION
//    Penalties: 0 = ideal / best, 100 = worst possible
//
//  ──────────────────────────────────────────────────────────────
//  THREE CATEGORIES & THEIR PARAMETERS
//
//  SAFETY (weight 1.0) — 4 parameters, averaged:
//    1. Buffer Zone / Segregation
//       Segregated or Buffer Zone → 0
//       None (no buffer/segregation) → 100
//       Unknown/NULL → 50
//    2. Light After Dark (light_after_sunset)
//       Yes → 0
//       Partial → 50
//       No / NULL → 100
//    3. Traffic Calming at intersections (absent count)
//       All present (0 absent) → 0
//       Absent at 1 intersection → 50
//       Absent at 2 intersections → 75
//       Absent at 3 or more → 100
//    4. Partial Obstructions (count)
//       < 5  → 0
//       5–10 → 50
//       > 10 → 100
//
//  CONTINUITY (weight 1.5) — 3 parameters, averaged:
//    5. Missing Ramps (count of missing ramp intersections)
//       0 missing → 0
//       ≥ 1 missing → 25
//       ≥ 3 missing → 50
//       ≥ 5 missing → 100
//    6. Missing Signage and Markings (absent count at intersections)
//       0 absent → 0
//       1 absent → 50
//       2 absent → 75
//       3 or more absent → 100
//    7. Total Obstructions (count)
//       < 5  → 0
//       5–10 → 50
//       > 10 → 100
//
//  COMFORT (weight 1.25) — 3 parameters, averaged:
//    8. Track Surface (surface_material)
//       Concrete or Asphalt → 0
//       Interlock Blocks → 100
//    9. Cyclist Slowed Down (count)
//       < 5   → 0
//       5–10  → 50
//       10–20 → 75
//       > 20  → 100
//   10. Shade
//       Yes → 0
//       Partial → 50
//       No / NULL → 100
//
//  ──────────────────────────────────────────────────────────────
//  MISSING-LENGTH ADJUSTMENT (per PDF):
//    For each category:
//      segCatPenalty = (100 × missingLen + catPenaltyRaw × presentLen) / totalLen
//    If entire segment is missing → all categories = 100 penalty
//
//  ──────────────────────────────────────────────────────────────
//  WEIGHTED TOTAL SEGMENT SCORE (per PDF):
//    weightedPenalty = Σ(segCatPenalty × weight) / Σ(weights)
//                    = (S×1 + Co×1.25 + Cn×1.5) / 3.75
//    segmentScore  = 100 − weightedPenalty   (0=worst, 100=best)
//
//  ──────────────────────────────────────────────────────────────
//  ROAD SCORE:
//    roadScore = Σ(segmentScore × segmentLength) / Σ(segmentLength)
//    (length-weighted average of segment scores)
//
//  ──────────────────────────────────────────────────────────────
//  CONDITION BANDS (score = 100 − penalty, higher is better):
//    BUT the PDF chart uses penalty directly (0=good, 100=bad)
//    and grades by penalty range:
//      0–20   → Good      (score 80–100)
//      20–40  → OK        (score 60–80)
//      40–60  → Poor      (score 40–60)
//      60–80  → Bad       (score 20–40)
//      80–100 → Very Bad  (score 0–20)
//
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

// ── Category weights (must match PDF) ─────────────────────────
const WEIGHT_SAFETY      = 1.0;
const WEIGHT_COMFORT     = 1.25;
const WEIGHT_CONTINUITY  = 1.5;
const WEIGHT_TOTAL       = 3.75; // 1.0 + 1.25 + 1.5

// ──────────────────────────────────────────────────────────────
//  PARAMETER PENALTY HELPERS
// ──────────────────────────────────────────────────────────────

/**
 * P1. Buffer Zone / Segregation penalty
 * Segregated or Buffer Zone = 0 (safe separation exists)
 * None = 100 (no separation)
 * Unknown/NULL = 50 (assume partial risk)
 */
function penaltyBufferZone(?string $bufferZone): float
{
    return match ($bufferZone ?? '') {
        'Segregated', 'Buffer Zone' => 0.0,
        'None'                      => 100.0,
        default                     => 50.0,   // NULL, empty, unknown
    };
}

/**
 * P2. Light After Dark penalty
 * Yes=0, Partial=50, No/NULL=100
 */
function penaltyLight(?string $light): float
{
    return match ($light ?? '') {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,  // 'No' or NULL
    };
}

/**
 * P3. Traffic Calming Devices at intersections (Safety)
 * Counts intersections where traffic_calming is 'Absent'
 * 0 absent = 0, 1 absent = 50, 2 absent = 75, ≥3 absent = 100
 */
function penaltyTrafficCalming(int $absentCount): float
{
    return match (true) {
        $absentCount === 0 => 0.0,
        $absentCount === 1 => 50.0,
        $absentCount === 2 => 75.0,
        default            => 100.0,
    };
}

/**
 * P4. Partial Obstructions (Safety)
 * <5=0, 5–10=50, >10=100
 */
function penaltyPartialObstructions(float $count): float
{
    return match (true) {
        $count < 5   => 0.0,
        $count <= 10 => 50.0,
        default      => 100.0,
    };
}

/**
 * P5. Missing Ramps (Continuity)
 * Counts intersections where off_ramp OR on_ramp is 'No Ramp'
 * 0 missing=0, ≥1=25, ≥3=50, ≥5=100
 */
function penaltyMissingRamps(int $missingRampCount): float
{
    return match (true) {
        $missingRampCount === 0 => 0.0,
        $missingRampCount < 3   => 25.0,
        $missingRampCount < 5   => 50.0,
        default                 => 100.0,
    };
}

/**
 * P6. Missing Signage and Markings (Continuity)
 * Counts intersections where markings='Absent' OR signage='Absent'
 * 0 absent=0, 1=50, 2=75, ≥3=100
 */
function penaltyMissingSignage(int $absentSignCount): float
{
    return match (true) {
        $absentSignCount === 0 => 0.0,
        $absentSignCount === 1 => 50.0,
        $absentSignCount === 2 => 75.0,
        default                => 100.0,
    };
}

/**
 * P7. Total Obstructions (Continuity)
 * <5=0, 5–10=50, >10=100
 */
function penaltyTotalObstructions(float $count): float
{
    return match (true) {
        $count < 5   => 0.0,
        $count <= 10 => 50.0,
        default      => 100.0,
    };
}

/**
 * P8. Track Surface (Comfort)
 * Concrete=0, Asphalt=0, Interlock Blocks=100
 */
function penaltySurface(?string $material): float
{
    return match ($material ?? '') {
        'Interlock Blocks', 'Interblocks' => 100.0,
        default                            => 0.0,   // Concrete, Asphalt, unknown → 0
    };
}

/**
 * P9. Cyclist Slowed Down (Comfort)
 * <5=0, 5–10=50, 10–20=75, >20=100
 */
function penaltyCyclistSlowed(float $count): float
{
    return match (true) {
        $count < 5   => 0.0,
        $count <= 10 => 50.0,
        $count <= 20 => 75.0,
        default      => 100.0,
    };
}

/**
 * P10. Shade (Comfort)
 * Yes=0, Partial=50, No/NULL=100
 */
function penaltyShade(?string $shade): float
{
    return match ($shade ?? '') {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,  // 'No' or NULL
    };
}

// ──────────────────────────────────────────────────────────────
//  MISSING-LENGTH ADJUSTMENT (PDF formula)
// ──────────────────────────────────────────────────────────────

/**
 * Apply missing-track adjustment to a raw category penalty.
 * PDF formula: segCatPenalty = (100×missingLen + rawPenalty×presentLen) / totalLen
 */
function applyMissingLength(float $rawPenalty, float $missingLen, float $presentLen, float $totalLen): float
{
    if ($totalLen <= 0.0) {
        return 100.0;
    }
    if ($presentLen <= 0.0) {
        return 100.0; // entire segment missing
    }
    return (100.0 * $missingLen + $rawPenalty * $presentLen) / $totalLen;
}

// ──────────────────────────────────────────────────────────────
//  CONDITION BAND (based on penalty, which = 100 − score)
// ──────────────────────────────────────────────────────────────

/**
 * Returns the condition label based on the PENALTY value (0–100).
 * PDF grading: 0–20=Good, 20–40=OK, 40–60=Poor, 60–80=Bad, 80–100=Very Bad
 */
function penaltyToCondition(float $penalty): string
{
    return match (true) {
        $penalty <= 20 => 'Good',
        $penalty <= 40 => 'OK',
        $penalty <= 60 => 'Poor',
        $penalty <= 80 => 'Bad',
        default        => 'Very Bad',
    };
}

/**
 * Returns condition label based on SCORE value (0–100, higher=better).
 * score = 100 − penalty, so:
 *   score ≥ 80 → Good
 *   score ≥ 60 → OK
 *   score ≥ 40 → Poor
 *   score ≥ 20 → Bad
 *   score < 20 → Very Bad
 */
function scoreToCondition(float $score): string
{
    return penaltyToCondition(100.0 - $score);
}

/**
 * Returns hex colour for a condition label.
 */
function conditionColour(string $condition): string
{
    return match ($condition) {
        'Good'     => '#27ae60',
        'OK'       => '#f1c40f',
        'Poor'     => '#e67e22',
        'Bad'      => '#e74c3c',
        'Very Bad' => '#8e1010',
        default    => '#95a5a6',
    };
}

/**
 * Backward-compatible alias used in older pages.
 */
function ratingColour(string $rating): string
{
    return conditionColour($rating);
}

// ──────────────────────────────────────────────────────────────
//  MAIN: SEGMENT SCORE CALCULATION
// ──────────────────────────────────────────────────────────────

/**
 * Calculate the full score breakdown for one segment audit.
 *
 * Returns an array with:
 *   safety_penalty     : float  (0–100, lower is better)
 *   continuity_penalty : float
 *   comfort_penalty    : float
 *   safety_score       : float  (100 − penalty, higher is better)
 *   continuity_score   : float
 *   comfort_score      : float
 *   final              : float  (0–100, higher is better)
 *   condition          : string (Good / OK / Poor / Bad / Very Bad)
 *   rating             : string (alias for condition, for backward compat)
 *
 * Scoring matches the Excel sheet exactly:
 *   - Safety   = avg(bufferZone, lightAfterDark, trafficCalming, partialObstructions)
 *   - Continuity = avg(missingRamps, missingSignage, totalObstructions)
 *   - Comfort  = avg(surface, cyclistSlowedDown, shade)
 *   All categories adjusted for missing length before weighting.
 *   final = 100 − [(S×1 + Co×1.25 + Cn×1.5) / 3.75]
 */
function calculateSegmentScore(int $segmentAuditId, PDO $pdo): array
{
    // ── 1. Fetch audit row ────────────────────────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length AS segment_length
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         WHERE  sa.id = ?
         LIMIT  1'
    );
    $stmtAudit->execute([$segmentAuditId]);
    $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

    if ($audit === false) {
        throw new \InvalidArgumentException(
            "ScoreService: segment_audit id={$segmentAuditId} not found."
        );
    }

    // ── 2. Obstruction aggregates ─────────────────────────────
    $stmtObs = $pdo->prepare(
        'SELECT COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id = ?'
    );
    $stmtObs->execute([$segmentAuditId]);
    $obs = $stmtObs->fetch(PDO::FETCH_ASSOC);

    $partialObs  = (float)($obs['partial'] ?? 0);
    $totalObs    = (float)($obs['total']   ?? 0);
    $cyclistSlowed = (float)($obs['slowed'] ?? 0);

    // ── 3. Intersection parameters ────────────────────────────
    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id = ?'
    );
    $stmtInt->execute([$segmentAuditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    // Count intersections with missing ramps (off OR on ramp missing)
    $missingRampCount = 0;
    // Count intersections with missing signage/markings
    $missingSignCount = 0;
    // Count intersections with absent traffic calming (Safety)
    $absentCalmingCount = 0;

    foreach ($intersections as $i) {
        // Missing ramp: either off_ramp or on_ramp is 'No Ramp' / 'Uncomfortable' / absent
        $offRamp = strtolower(trim($i['off_ramp'] ?? ''));
        $onRamp  = strtolower(trim($i['on_ramp']  ?? ''));
        if ($offRamp === 'no ramp' || $onRamp === 'no ramp') {
            $missingRampCount++;
        }

        // Missing signage: markings or signage is 'Absent'
        $markings = strtolower(trim($i['markings'] ?? ''));
        $signage  = strtolower(trim($i['signage']  ?? ''));
        if ($markings === 'absent' || $signage === 'absent') {
            $missingSignCount++;
        }

        // Absent traffic calming (Safety parameter)
        $calming = strtolower(trim($i['traffic_calming'] ?? ''));
        if ($calming === 'absent') {
            $absentCalmingCount++;
        }
    }

    // ── 4. Missing length fractions ───────────────────────────
    $totalLen = max(1.0, (float)($audit['segment_length'] ?? 500));

    // missing_length is stored as a number (metres), cycle_track_missing as 'Yes'/'No'
    $missingLen = 0.0;
    if (($audit['cycle_track_missing'] ?? '') === 'Yes') {
        $missingLen = max(0.0, (float)($audit['missing_length'] ?? 0));
    }
    // Guard: missing cannot exceed total
    $missingLen = min($missingLen, $totalLen);
    $presentLen = $totalLen - $missingLen;

    $allMissing = ($presentLen <= 0.0);

    // ── 5. SAFETY CATEGORY ────────────────────────────────────
    //  Parameters: bufferZone, lightAfterDark, trafficCalming, partialObstructions
    if ($allMissing) {
        $safetyPenaltyRaw = 100.0;
    } else {
        $p_buffer   = penaltyBufferZone($audit['buffer_zone'] ?? null);
        $p_light    = penaltyLight($audit['light_after_sunset'] ?? null);
        $p_calming  = penaltyTrafficCalming($absentCalmingCount);
        $p_partial  = penaltyPartialObstructions($partialObs);

        // Safety = average of its 4 parameters
        $safetyPenaltyRaw = ($p_buffer + $p_light + $p_calming + $p_partial) / 4.0;
    }

    // Apply missing-length adjustment
    $safetyPenalty = applyMissingLength($safetyPenaltyRaw, $missingLen, $presentLen, $totalLen);

    // ── 6. CONTINUITY CATEGORY ────────────────────────────────
    //  Parameters: missingRamps, missingSignage, totalObstructions
    if ($allMissing) {
        $continuityPenaltyRaw = 100.0;
    } else {
        $p_ramps   = penaltyMissingRamps($missingRampCount);
        $p_signage = penaltyMissingSignage($missingSignCount);
        $p_total   = penaltyTotalObstructions($totalObs);

        // Continuity = average of its 3 parameters
        $continuityPenaltyRaw = ($p_ramps + $p_signage + $p_total) / 3.0;
    }

    // Apply missing-length adjustment
    $continuityPenalty = applyMissingLength($continuityPenaltyRaw, $missingLen, $presentLen, $totalLen);

    // ── 7. COMFORT CATEGORY ───────────────────────────────────
    //  Parameters: surface, cyclistSlowedDown, shade
    if ($allMissing) {
        $comfortPenaltyRaw = 100.0;
    } else {
        $p_surface = penaltySurface($audit['surface_material'] ?? null);
        $p_slowed  = penaltyCyclistSlowed($cyclistSlowed);
        $p_shade   = penaltyShade($audit['shade'] ?? null);

        // Comfort = average of its 3 parameters
        $comfortPenaltyRaw = ($p_surface + $p_slowed + $p_shade) / 3.0;
    }

    // Apply missing-length adjustment
    $comfortPenalty = applyMissingLength($comfortPenaltyRaw, $missingLen, $presentLen, $totalLen);

    // ── 8. WEIGHTED FINAL PENALTY ─────────────────────────────
    //  weightedPenalty = (Safety×1 + Comfort×1.25 + Continuity×1.5) / 3.75
    $weightedPenalty =
        ($safetyPenalty     * WEIGHT_SAFETY     +
         $comfortPenalty    * WEIGHT_COMFORT     +
         $continuityPenalty * WEIGHT_CONTINUITY)
        / WEIGHT_TOTAL;

    // ── 9. Final score (higher = better, 0–100) ───────────────
    $finalScore = round(100.0 - $weightedPenalty, 2);
    $finalScore = max(0.0, min(100.0, $finalScore));

    // ── 10. Condition labels ───────────────────────────────────
    $condition = scoreToCondition($finalScore);

    return [
        // Raw penalty values (0=best, 100=worst) — match Excel columns
        'safety_penalty'     => round($safetyPenalty,     2),
        'continuity_penalty' => round($continuityPenalty, 2),
        'comfort_penalty'    => round($comfortPenalty,    2),

        // Score values (0=worst, 100=best) — convenience inverses
        'safety_score'       => round(100.0 - $safetyPenalty,     2),
        'continuity_score'   => round(100.0 - $continuityPenalty, 2),
        'comfort_score'      => round(100.0 - $comfortPenalty,    2),

        // Final weighted score
        'final'              => $finalScore,

        // Condition band per PDF
        'condition'          => $condition,

        // Backward-compatible alias
        'rating'             => $condition,
    ];
}

// ──────────────────────────────────────────────────────────────
//  ROAD SCORE CALCULATION
// ──────────────────────────────────────────────────────────────

/**
 * Calculate length-weighted road score across all completed segments.
 *
 * Road score = Σ(segmentScore × segmentLength) / Σ(segmentLength)
 *
 * Returns null if no segment audits exist yet.
 * Returns array with:
 *   score            : float  (0–100)
 *   condition        : string
 *   rating           : string (alias)
 *   safety_score     : float  (length-weighted avg)
 *   continuity_score : float
 *   comfort_score    : float
 *   segment_count    : int
 */
function calculateRoadScore(int $roadId, PDO $pdo): ?array
{
    // Get latest audit for each segment of this road
    $stmt = $pdo->prepare(
        'SELECT s.id      AS segment_id,
                s.length,
                sa.id     AS audit_id
         FROM   segments s
         JOIN   segment_audits sa
                ON  sa.segment_id = s.id
                AND sa.id = (
                      SELECT MAX(sa2.id)
                      FROM   segment_audits sa2
                      WHERE  sa2.segment_id = s.id
                    )
         WHERE  s.road_id = ?
         ORDER  BY s.segment_number ASC'
    );
    $stmt->execute([$roadId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $totalLength        = 0.0;
    $weightedFinal      = 0.0;
    $weightedSafety     = 0.0;
    $weightedContinuity = 0.0;
    $weightedComfort    = 0.0;

    foreach ($rows as $row) {
        $seg  = calculateSegmentScore((int)$row['audit_id'], $pdo);
        $len  = max(0.0, (float)$row['length']);

        $totalLength        += $len;
        $weightedFinal      += $seg['final']              * $len;
        $weightedSafety     += $seg['safety_score']       * $len;
        $weightedContinuity += $seg['continuity_score']   * $len;
        $weightedComfort    += $seg['comfort_score']       * $len;
    }

    if ($totalLength <= 0.0) {
        return null;
    }

    $roadScore        = round($weightedFinal      / $totalLength, 2);
    $roadSafety       = round($weightedSafety     / $totalLength, 2);
    $roadContinuity   = round($weightedContinuity / $totalLength, 2);
    $roadComfort      = round($weightedComfort    / $totalLength, 2);

    $condition = scoreToCondition($roadScore);

    return [
        'score'            => $roadScore,
        'condition'        => $condition,
        'rating'           => $condition,              // backward compat
        'safety_score'     => $roadSafety,
        'continuity_score' => $roadContinuity,
        'comfort_score'    => $roadComfort,
        'segment_count'    => count($rows),
    ];
}

/**
 * Calculate score breakdown for a specific segment audit.
 * Convenience wrapper that also includes parameter-level detail.
 */
function calculateSegmentScoreDetailed(int $segmentAuditId, PDO $pdo): array
{
    // Get base scores
    $base = calculateSegmentScore($segmentAuditId, $pdo);

    // ── Fetch raw data for parameter breakdown ────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone, sa.light_after_sunset, sa.shade,
                sa.surface_material, sa.cycle_track_missing, sa.missing_length,
                s.length AS segment_length
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         WHERE  sa.id = ? LIMIT 1'
    );
    $stmtAudit->execute([$segmentAuditId]);
    $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

    $stmtObs = $pdo->prepare(
        'SELECT COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions WHERE audit_id = ?'
    );
    $stmtObs->execute([$segmentAuditId]);
    $obs = $stmtObs->fetch(PDO::FETCH_ASSOC);

    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections WHERE audit_id = ?'
    );
    $stmtInt->execute([$segmentAuditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    $missingRampCount   = 0;
    $missingSignCount   = 0;
    $absentCalmingCount = 0;

    foreach ($intersections as $i) {
        $offRamp = strtolower(trim($i['off_ramp'] ?? ''));
        $onRamp  = strtolower(trim($i['on_ramp']  ?? ''));
        if ($offRamp === 'no ramp' || $onRamp === 'no ramp') {
            $missingRampCount++;
        }
        $markings = strtolower(trim($i['markings'] ?? ''));
        $signage  = strtolower(trim($i['signage']  ?? ''));
        if ($markings === 'absent' || $signage === 'absent') {
            $missingSignCount++;
        }
        $calming = strtolower(trim($i['traffic_calming'] ?? ''));
        if ($calming === 'absent') {
            $absentCalmingCount++;
        }
    }

    $partialObs    = (float)($obs['partial'] ?? 0);
    $totalObs      = (float)($obs['total']   ?? 0);
    $cyclistSlowed = (float)($obs['slowed']  ?? 0);

    // Parameter penalties for display
    $params = [
        'safety' => [
            'buffer_zone'      => penaltyBufferZone($audit['buffer_zone'] ?? null),
            'light_after_dark' => penaltyLight($audit['light_after_sunset'] ?? null),
            'traffic_calming'  => penaltyTrafficCalming($absentCalmingCount),
            'partial_obs'      => penaltyPartialObstructions($partialObs),
        ],
        'continuity' => [
            'missing_ramps'    => penaltyMissingRamps($missingRampCount),
            'missing_signage'  => penaltyMissingSignage($missingSignCount),
            'total_obs'        => penaltyTotalObstructions($totalObs),
        ],
        'comfort' => [
            'surface'          => penaltySurface($audit['surface_material'] ?? null),
            'cyclist_slowed'   => penaltyCyclistSlowed($cyclistSlowed),
            'shade'            => penaltyShade($audit['shade'] ?? null),
        ],
    ];

    return array_merge($base, ['parameters' => $params]);
}
