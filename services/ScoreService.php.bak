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
//  SCORING CONVENTION  ← matches the Excel & PDF exactly
//    0   = ideal / best possible
//    100 = worst possible
//
//  The PDF report prints scores where 0=Good and 100=Very Bad.
//  Baner Road = 13, PMC Road = 55, etc.
//
//  ──────────────────────────────────────────────────────────────
//  THREE CATEGORIES & THEIR PARAMETERS
//
//  SAFETY (weight 1.0) — 4 parameters, averaged:
//    1. Buffer Zone / Segregation
//       Segregated or Buffer Zone → 0
//       None (no buffer/segregation) → 100
//       Unknown/NULL → 100
//    2. Light After Dark
//       Yes → 0 | Partial → 50 | No/NULL → 100
//    3. Traffic Calming at intersections (absent count)
//       0 absent → 0 | 1 absent → 50 | 2 absent → 75 | ≥3 → 100
//    4. Partial Obstructions (count)
//       <5 → 0 | 5–10 → 50 | >10 → 100
//
//  CONTINUITY (weight 1.5) — 3 parameters, averaged:
//    5. Missing Ramps
//       0 missing → 0 | ≥1 → 25 | ≥3 → 50 | ≥5 → 100
//    6. Missing Signage and Markings
//       0 absent → 0 | 1 → 50 | 2 → 75 | ≥3 → 100
//    7. Total Obstructions
//       <5 → 0 | 5–10 → 50 | >10 → 100
//
//  COMFORT (weight 1.25) — 3 parameters, averaged:
//    8. Track Surface
//       Concrete/Asphalt → 0 | Interlock Blocks → 100
//    9. Cyclist Slowed Down
//       <5 → 0 | 5–10 → 50 | 10–20 → 75 | >20 → 100
//   10. Shade
//       Yes → 0 | Partial → 50 | No/NULL → 100
//
//  ──────────────────────────────────────────────────────────────
//  MISSING-LENGTH ADJUSTMENT (per PDF):
//    segCatScore = (100×missingLen + catScoreRaw×presentLen) / totalLen
//    If entire segment missing → all categories = 100
//
//  ──────────────────────────────────────────────────────────────
//  WEIGHTED TOTAL SEGMENT SCORE:
//    segmentScore = Σ(catScore × weight) / Σ(weights)
//                 = (S×1 + Comfort×1.25 + Continuity×1.5) / 3.75
//    Where 0 = perfect, 100 = terrible (matches PDF & Excel)
//
//  ──────────────────────────────────────────────────────────────
//  ROAD SCORE:
//    roadScore = Σ(segmentScore × segmentLength) / Σ(segmentLength)
//
//  ──────────────────────────────────────────────────────────────
//  CONDITION BANDS (0=best, 100=worst — same as PDF table):
//    0–20   → Good
//    20–40  → OK
//    40–60  → Poor
//    60–80  → Bad
//    80–100 → Very Bad
//
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

// ── Category weights (must match PDF) ─────────────────────────
const WEIGHT_SAFETY      = 1.0;
const WEIGHT_COMFORT     = 1.25;
const WEIGHT_CONTINUITY  = 1.5;
const WEIGHT_TOTAL       = 3.75; // 1.0 + 1.25 + 1.5

// ──────────────────────────────────────────────────────────────
//  PARAMETER SCORE HELPERS  (0=best, 100=worst)
// ──────────────────────────────────────────────────────────────

/**
 * P1. Buffer Zone / Segregation
 * Segregated or Buffer Zone = 0, None/unknown = 100
 */
function penaltyBufferZone(?string $bufferZone): float
{
    return match ($bufferZone ?? '') {
        'Segregated', 'Buffer Zone' => 0.0,
        default                     => 100.0,
    };
}

/**
 * P2. Light After Dark
 * Yes=0, Partial=50, No/NULL=100
 */
function penaltyLight(?string $light): float
{
    return match ($light ?? '') {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,
    };
}

/**
 * P3. Traffic Calming at intersections
 * 0 absent=0, 1=50, 2=75, ≥3=100
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
 * P4. Partial Obstructions
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
 * P5. Missing Ramps
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
 * P6. Missing Signage and Markings
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
 * P7. Total Obstructions
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
 * P8. Track Surface
 * Concrete/Asphalt=0, Interlock Blocks=100
 */
function penaltySurface(?string $material): float
{
    return match ($material ?? '') {
        'Interlock Blocks', 'Interblocks' => 100.0,
        default                            => 0.0,
    };
}

/**
 * P9. Cyclist Slowed Down
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
 * P10. Shade
 * Yes=0, Partial=50, No/NULL=100
 */
function penaltyShade(?string $shade): float
{
    return match ($shade ?? '') {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,
    };
}

// ──────────────────────────────────────────────────────────────
//  MISSING-LENGTH ADJUSTMENT (PDF formula)
// ──────────────────────────────────────────────────────────────

/**
 * Apply missing-track adjustment to a raw category score.
 * PDF: segCatScore = (100×missingLen + rawScore×presentLen) / totalLen
 */
function applyMissingLength(float $rawScore, float $missingLen, float $presentLen, float $totalLen): float
{
    if ($totalLen <= 0.0) return 100.0;
    if ($presentLen <= 0.0) return 100.0;
    return (100.0 * $missingLen + $rawScore * $presentLen) / $totalLen;
}

// ──────────────────────────────────────────────────────────────
//  CONDITION BAND  (score: 0=Good → 100=Very Bad)
// ──────────────────────────────────────────────────────────────

/**
 * Returns the condition label for a score (0=best, 100=worst).
 * Matches the PDF table exactly.
 */
function scoreToCondition(float $score): string
{
    return match (true) {
        $score <= 20 => 'Good',
        $score <= 40 => 'OK',
        $score <= 60 => 'Poor',
        $score <= 80 => 'Bad',
        default      => 'Very Bad',
    };
}

// Alias kept for backward compatibility
function penaltyToCondition(float $score): string
{
    return scoreToCondition($score);
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

/** Backward-compatible alias */
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
 * SCORING: 0 = perfect / best, 100 = worst  (matches Excel & PDF)
 *
 * Returns:
 *   safety_score     : float  (0–100, lower is better)
 *   continuity_score : float
 *   comfort_score    : float
 *   final            : float  (0–100, lower is better)
 *   condition        : string (Good / OK / Poor / Bad / Very Bad)
 *   rating           : string (alias for condition)
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

    $partialObs    = (float)($obs['partial'] ?? 0);
    $totalObs      = (float)($obs['total']   ?? 0);
    $cyclistSlowed = (float)($obs['slowed']  ?? 0);

    // ── 3. Intersection parameters ────────────────────────────
    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id = ?'
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

    // ── 4. Missing length fractions ───────────────────────────
    $totalLen = max(1.0, (float)($audit['segment_length'] ?? 500));

    $missingLen = 0.0;
    if (($audit['cycle_track_missing'] ?? '') === 'Yes') {
        $missingLen = max(0.0, (float)($audit['missing_length'] ?? 0));
    }
    $missingLen = min($missingLen, $totalLen);
    $presentLen = $totalLen - $missingLen;
    $allMissing = ($presentLen <= 0.0);

    // ── 5. SAFETY CATEGORY ────────────────────────────────────
    if ($allMissing) {
        $safetyRaw = 100.0;
    } else {
        $safetyRaw = (
            penaltyBufferZone($audit['buffer_zone'] ?? null) +
            penaltyLight($audit['light_after_sunset'] ?? null) +
            penaltyTrafficCalming($absentCalmingCount) +
            penaltyPartialObstructions($partialObs)
        ) / 4.0;
    }
    $safetyScore = applyMissingLength($safetyRaw, $missingLen, $presentLen, $totalLen);

    // ── 6. CONTINUITY CATEGORY ────────────────────────────────
    if ($allMissing) {
        $continuityRaw = 100.0;
    } else {
        $continuityRaw = (
            penaltyMissingRamps($missingRampCount) +
            penaltyMissingSignage($missingSignCount) +
            penaltyTotalObstructions($totalObs)
        ) / 3.0;
    }
    $continuityScore = applyMissingLength($continuityRaw, $missingLen, $presentLen, $totalLen);

    // ── 7. COMFORT CATEGORY ───────────────────────────────────
    if ($allMissing) {
        $comfortRaw = 100.0;
    } else {
        $comfortRaw = (
            penaltySurface($audit['surface_material'] ?? null) +
            penaltyCyclistSlowed($cyclistSlowed) +
            penaltyShade($audit['shade'] ?? null)
        ) / 3.0;
    }
    $comfortScore = applyMissingLength($comfortRaw, $missingLen, $presentLen, $totalLen);

    // ── 8. WEIGHTED FINAL SCORE ───────────────────────────────
    //  (Safety×1 + Comfort×1.25 + Continuity×1.5) / 3.75
    //  Result: 0 = best, 100 = worst  ← matches PDF & Excel
    $finalScore = (
        $safetyScore     * WEIGHT_SAFETY     +
        $comfortScore    * WEIGHT_COMFORT     +
        $continuityScore * WEIGHT_CONTINUITY
    ) / WEIGHT_TOTAL;

    $finalScore = round(max(0.0, min(100.0, $finalScore)), 2);

    $condition = scoreToCondition($finalScore);

    return [
        // Category scores (0=best, 100=worst) — match Excel columns directly
        'safety_score'       => round($safetyScore,     2),
        'continuity_score'   => round($continuityScore, 2),
        'comfort_score'      => round($comfortScore,    2),

        // Final weighted score (0=best, 100=worst)
        'final'              => $finalScore,

        // Condition band per PDF
        'condition'          => $condition,
        'rating'             => $condition,  // backward-compatible alias
    ];
}

// ──────────────────────────────────────────────────────────────
//  ROAD SCORE CALCULATION
// ──────────────────────────────────────────────────────────────

/**
 * Calculate length-weighted road score across all completed segments.
 *
 * Road score = Σ(segmentScore × segmentLength) / Σ(segmentLength)
 * Score: 0 = best, 100 = worst (matches Excel Sheet7 values)
 *
 * Returns null if no segment audits exist yet.
 */
function calculateRoadScore(int $roadId, PDO $pdo): ?array
{
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
        $seg = calculateSegmentScore((int)$row['audit_id'], $pdo);
        $len = max(0.0, (float)$row['length']);

        $totalLength        += $len;
        $weightedFinal      += $seg['final']            * $len;
        $weightedSafety     += $seg['safety_score']     * $len;
        $weightedContinuity += $seg['continuity_score'] * $len;
        $weightedComfort    += $seg['comfort_score']    * $len;
    }

    if ($totalLength <= 0.0) {
        return null;
    }

    $roadScore      = round($weightedFinal      / $totalLength, 2);
    $roadSafety     = round($weightedSafety     / $totalLength, 2);
    $roadContinuity = round($weightedContinuity / $totalLength, 2);
    $roadComfort    = round($weightedComfort    / $totalLength, 2);

    $condition = scoreToCondition($roadScore);

    return [
        'score'            => $roadScore,       // 0=best, 100=worst
        'condition'        => $condition,
        'rating'           => $condition,
        'safety_score'     => $roadSafety,
        'continuity_score' => $roadContinuity,
        'comfort_score'    => $roadComfort,
        'segment_count'    => count($rows),
    ];
}

/**
 * Detailed breakdown including parameter-level scores.
 */
function calculateSegmentScoreDetailed(int $segmentAuditId, PDO $pdo): array
{
    $base = calculateSegmentScore($segmentAuditId, $pdo);

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
        if ($offRamp === 'no ramp' || $onRamp === 'no ramp') $missingRampCount++;

        $markings = strtolower(trim($i['markings'] ?? ''));
        $signage  = strtolower(trim($i['signage']  ?? ''));
        if ($markings === 'absent' || $signage === 'absent') $missingSignCount++;

        $calming = strtolower(trim($i['traffic_calming'] ?? ''));
        if ($calming === 'absent') $absentCalmingCount++;
    }

    $params = [
        'safety' => [
            'buffer_zone'      => penaltyBufferZone($audit['buffer_zone'] ?? null),
            'light_after_dark' => penaltyLight($audit['light_after_sunset'] ?? null),
            'traffic_calming'  => penaltyTrafficCalming($absentCalmingCount),
            'partial_obs'      => penaltyPartialObstructions((float)($obs['partial'] ?? 0)),
        ],
        'continuity' => [
            'missing_ramps'   => penaltyMissingRamps($missingRampCount),
            'missing_signage' => penaltyMissingSignage($missingSignCount),
            'total_obs'       => penaltyTotalObstructions((float)($obs['total'] ?? 0)),
        ],
        'comfort' => [
            'surface'        => penaltySurface($audit['surface_material'] ?? null),
            'cyclist_slowed' => penaltyCyclistSlowed((float)($obs['slowed'] ?? 0)),
            'shade'          => penaltyShade($audit['shade'] ?? null),
        ],
    ];

    return array_merge($base, ['parameters' => $params]);
}
