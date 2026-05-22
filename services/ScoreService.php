<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  services/ScoreService.php
//  !! THE ONLY PLACE scoring logic may live !!
//
//  FIXES APPLIED:
//    1. buffer_zone NULL/unknown now gives 50.0 penalty (was 0.0 — too generous)
//    2. shade NULL now explicitly handled (was falling to 'No' = 100 via default — OK,
//       but now explicit for clarity)
//    3. light_after_sunset NULL explicitly handled
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

/**
 * Calculate the full score breakdown for one segment audit.
 */
function calculateSegmentScore(int $segmentAuditId, PDO $pdo): array
{
    // ── 1. Fetch the main audit row ───────────────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT buffer_zone, light_after_sunset, shade, surface_material
         FROM   segment_audits
         WHERE  id = ?
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

    // ── 4. Safety penalty ─────────────────────────────────────

    // FIX: buffer_zone NULL/unknown now gets 50 (moderate), not 0 (best)
    $bufferPenalty = match($audit['buffer_zone'] ?? null) {
        'Segregated', 'Buffer Zone' => 0.0,
        'NA'                        => 0.0,   // no cycle track — not applicable
        'None'                      => 100.0, // track exists but no buffer — dangerous
        default                     => 50.0,  // NULL / unknown — assume moderate risk
    };

    // FIX: light_after_sunset explicit null handling
    $lightPenalty = match($audit['light_after_sunset'] ?? null) {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,  // 'No' or NULL
    };

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

    $safetyPenalty = ($bufferPenalty + $lightPenalty + $rampSafetyPenalty + $partialPenalty) / 4.0;

    // ── 5. Continuity penalty ─────────────────────────────────
    $rampContPenalty = match (true) {
        $noRampCount === 0 => 0.0,
        $noRampCount >= 5  => 100.0,
        $noRampCount >= 3  => 50.0,
        default            => 25.0,
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

    $continuityPenalty = ($rampContPenalty + $signPenalty + $totalObsPenalty) / 3.0;

    // ── 6. Comfort penalty ────────────────────────────────────
    $surfacePenalty = ($audit['surface_material'] === 'Interlock Blocks') ? 100.0 : 0.0;

    $slowedPenalty = match (true) {
        $slowed < 5   => 0.0,
        $slowed <= 10 => 50.0,
        $slowed <= 20 => 75.0,
        default       => 100.0,
    };

    // FIX: shade explicit null handling
    $shadePenalty = match($audit['shade'] ?? null) {
        'Yes'     => 0.0,
        'Partial' => 50.0,
        default   => 100.0,  // 'No' or NULL
    };

    $comfortPenalty = ($surfacePenalty + $slowedPenalty + $shadePenalty) / 3.0;

    // ── 7. Final score ────────────────────────────────────────
    $avgPenalty = ($safetyPenalty + $continuityPenalty + $comfortPenalty) / 3.0;
    $finalScore = round(100.0 - $avgPenalty, 1);

    // ── 8. Rating label ───────────────────────────────────────
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
        'final'  => $finalScore,
        'rating' => $rating,
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
