<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  services/ScoreService.php
//  !! THE ONLY PLACE scoring logic may live !!
//
//  Implements the canonical Parisar scoring methodology:
//
//  SCORING CONVENTION
//    Penalties:  0 = ideal, 100 = worst possible
//    Category penalty = average of its parameter penalties
//    Missing-track adjustment (per PDF):
//      segCatPenalty = [100×missingLen + catPenalty×presentLen] / totalLen
//    Weighted final penalty (per PDF):
//      Safety×1, Comfort×1.25, Continuity×1.5  →  total weight = 3.75
//    Final score (0–100, higher = better):
//      finalScore = 100 − weightedPenalty
//    Road score = length-weighted average of segment finalScores
//
//  RATING THRESHOLDS (constants.php)
//    SCORE_GOOD     ≥ 80  →  "Good"
//    SCORE_MODERATE ≥ 50  →  "Moderate"
//    < 50           →  "Poor"
//
//  FIXES vs previous version
//    1. Category weights (Safety 1 / Comfort 1.25 / Continuity 1.5) applied.
//    2. Missing-track length penalty applied to all three categories before
//       weighting, matching the PDF formula exactly.
//    3. buffer_zone NULL/unknown → 50 penalty (was 0 — too generous).
//    4. shade / light_after_sunset NULL → explicit handling.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

// Category weights (must sum to WEIGHT_TOTAL)
const WEIGHT_SAFETY      = 1.0;
const WEIGHT_COMFORT     = 1.25;
const WEIGHT_CONTINUITY  = 1.5;
const WEIGHT_TOTAL       = 3.75; // 1.0 + 1.25 + 1.5

/**
 * Calculate the full score breakdown for one segment audit.
 *
 * @param  int $segmentAuditId  PK of segment_audits row
 * @param  PDO $pdo
 * @return array{
 *   safety_penalty:     float,
 *   continuity_penalty: float,
 *   comfort_penalty:    float,
 *   safety_score:       float,
 *   continuity_score:   float,
 *   comfort_score:      float,
 *   final:              float,
 *   rating:             string,
 * }
 */
function calculateSegmentScore(int $segmentAuditId, PDO $pdo): array
{
    // ── 1. Fetch the main audit row ───────────────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone, sa.light_after_sunset, sa.shade, sa.surface_material,
                sa.cycle_track_missing, sa.missing_length,
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

    // ── 2. Aggregate obstruction counts ──────────────────────
    $stmtObs = $pdo->prepare(
        'SELECT COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id = ?'
    );
    $stmtObs->execute([$segmentAuditId]);
    $obs = $stmtObs->fetch(PDO::FETCH_ASSOC);

    $partial = (float)$obs['partial'];
    $totalO  = (float)$obs['total'];
    $slowed  = (float)$obs['slowed'];

    // ── 3. Fetch intersections ────────────────────────────────
    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage
         FROM   intersections
         WHERE  audit_id = ?'
    );
    $stmtInt->execute([$segmentAuditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    $noRampCount = 0;
    $noSignCount = 0;
    foreach ($intersections as $i) {
        if (($i['off_ramp'] ?? '') === 'No Ramp' || ($i['on_ramp'] ?? '') === 'No Ramp') {
            $noRampCount++;
        }
        if (($i['markings'] ?? '') === 'Absent' || ($i['signage'] ?? '') === 'Absent') {
            $noSignCount++;
        }
    }

    // ── 4. Missing-track fractions ────────────────────────────
    //  PDF formula: segCatPenalty = [100×missingLen + catPenalty×presentLen] / totalLen
    //  We store the proportions now and apply them after computing raw penalties.
    $totalLen   = max(1.0, (float)($audit['segment_length'] ?? 500));
    $missingLen = ($audit['cycle_track_missing'] === 'Yes')
        ? max(0.0, (float)($audit['missing_length'] ?? 0))
        : 0.0;
    $presentLen = max(0.0, $totalLen - $missingLen);

    // If the entire segment is missing, every category gets 100 penalty.
    $allMissing = ($presentLen <= 0.0);

    // ── 5. Safety penalty ─────────────────────────────────────
    if ($allMissing) {
        $safetyPenaltyRaw = 100.0;
    } else {
        $bufferPenalty = match($audit['buffer_zone'] ?? null) {
            'Segregated', 'Buffer Zone' => 0.0,
            'NA'                        => 0.0,   // no track — not applicable
            'None'                      => 100.0, // track exists but unprotected
            default                     => 50.0,  // NULL / unknown — moderate risk
        };

        $lightPenalty = match($audit['light_after_sunset'] ?? null) {
            'Yes'     => 0.0,
            'Partial' => 50.0,
            default   => 100.0,  // 'No' or NULL
        };

        // Traffic calming / ramp presence at intersections (safety perspective)
        $rampSafetyPenalty = match (true) {
            $noRampCount === 0 => 0.0,
            $noRampCount === 1 => 50.0,
            $noRampCount <= 3  => 75.0,
            default            => 100.0,
        };

        $partialPenalty = match (true) {
            $partial < 5   => 0.0,
            $partial <= 10 => 50.0,
            default        => 100.0,
        };

        $safetyPenaltyRaw = ($bufferPenalty + $lightPenalty + $rampSafetyPenalty + $partialPenalty) / 4.0;
    }

    // Apply missing-track adjustment (PDF formula)
    $safetyPenalty = $allMissing
        ? 100.0
        : (100.0 * $missingLen + $safetyPenaltyRaw * $presentLen) / $totalLen;

    // ── 6. Continuity penalty ─────────────────────────────────
    if ($allMissing) {
        $continuityPenaltyRaw = 100.0;
    } else {
        // Ramp presence (continuity perspective — different thresholds from safety)
        $rampContPenalty = match (true) {
            $noRampCount === 0 => 0.0,
            $noRampCount >= 5  => 100.0,
            $noRampCount >= 3  => 50.0,
            default            => 25.0,  // 1 or 2 missing
        };

        $signPenalty = match (true) {
            $noSignCount === 0 => 0.0,
            $noSignCount === 1 => 50.0,
            $noSignCount <= 3  => 75.0,
            default            => 100.0,
        };

        $totalObsPenalty = match (true) {
            $totalO < 5   => 0.0,
            $totalO <= 10 => 50.0,
            default       => 100.0,
        };

        $continuityPenaltyRaw = ($rampContPenalty + $signPenalty + $totalObsPenalty) / 3.0;
    }

    // Apply missing-track adjustment
    $continuityPenalty = $allMissing
        ? 100.0
        : (100.0 * $missingLen + $continuityPenaltyRaw * $presentLen) / $totalLen;

    // ── 7. Comfort penalty ────────────────────────────────────
    if ($allMissing) {
        $comfortPenaltyRaw = 100.0;
    } else {
        $surfacePenalty = ($audit['surface_material'] === 'Interlock Blocks') ? 100.0 : 0.0;

        $slowedPenalty = match (true) {
            $slowed < 5   => 0.0,
            $slowed <= 10 => 50.0,
            $slowed <= 20 => 75.0,
            default       => 100.0,
        };

        $shadePenalty = match($audit['shade'] ?? null) {
            'Yes'     => 0.0,
            'Partial' => 50.0,
            default   => 100.0,  // 'No' or NULL
        };

        $comfortPenaltyRaw = ($surfacePenalty + $slowedPenalty + $shadePenalty) / 3.0;
    }

    // Apply missing-track adjustment
    $comfortPenalty = $allMissing
        ? 100.0
        : (100.0 * $missingLen + $comfortPenaltyRaw * $presentLen) / $totalLen;

    // ── 8. Weighted final penalty (PDF weights) ───────────────
    $weightedPenalty =
        ($safetyPenalty     * WEIGHT_SAFETY     +
         $comfortPenalty    * WEIGHT_COMFORT     +
         $continuityPenalty * WEIGHT_CONTINUITY)
        / WEIGHT_TOTAL;

    $finalScore = round(100.0 - $weightedPenalty, 1);

    // ── 9. Rating ─────────────────────────────────────────────
    $rating = match (true) {
        $finalScore >= SCORE_GOOD     => 'Good',
        $finalScore >= SCORE_MODERATE => 'Moderate',
        default                       => 'Poor',
    };

    return [
        'safety_penalty'     => round($safetyPenalty,     1),
        'continuity_penalty' => round($continuityPenalty, 1),
        'comfort_penalty'    => round($comfortPenalty,    1),
        'safety_score'       => round(100.0 - $safetyPenalty,     1),
        'continuity_score'   => round(100.0 - $continuityPenalty, 1),
        'comfort_score'      => round(100.0 - $comfortPenalty,    1),
        'final'              => $finalScore,
        'rating'             => $rating,
    ];
}

/**
 * Calculate weighted road score across all completed segments.
 * Returns null if no segments have been audited yet.
 */
function calculateRoadScore(int $roadId, PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id   AS segment_id,
                s.length,
                sa.id  AS audit_id
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

    $totalWeighted = 0.0;
    $totalLength   = 0.0;

    foreach ($rows as $row) {
        $segScore       = calculateSegmentScore((int)$row['audit_id'], $pdo);
        $len            = (float)$row['length'];
        $totalWeighted += $segScore['final'] * $len;
        $totalLength   += $len;
    }

    if ($totalLength === 0.0) {
        return null;
    }

    $roadScore = round($totalWeighted / $totalLength, 1);
    $rating    = match (true) {
        $roadScore >= SCORE_GOOD     => 'Good',
        $roadScore >= SCORE_MODERATE => 'Moderate',
        default                      => 'Poor',
    };

    return ['score' => $roadScore, 'rating' => $rating];
}

/**
 * Returns the hex colour associated with a rating string.
 */
function ratingColour(string $rating): string
{
    return match ($rating) {
        'Good'     => '#27ae60',
        'Moderate' => '#f39c12',
        default    => '#e74c3c',
    };
}
