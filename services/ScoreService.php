<?php
declare(strict_types=1);

/**
 * services/ScoreService.php (UPDATED)
 * Refactored to use ScoreHelpers class
 * Main scoring orchestration logic
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/ScoreHelpers.php';

// ── Category weights (must match PDF) ─────────────────────────
const WEIGHT_SAFETY      = 1.0;
const WEIGHT_COMFORT     = 1.25;
const WEIGHT_CONTINUITY  = 1.5;
const WEIGHT_TOTAL       = 3.75; // 1.0 + 1.25 + 1.5

// ── Backward compatibility: Function wrappers ─────────────────

function penaltyBufferZone(?string $bufferZone): float
{
    return ScoreHelpers::bufferZone($bufferZone);
}

function penaltyLight(?string $light): float
{
    return ScoreHelpers::lightAfterDark($light);
}

function penaltyTrafficCalming(int $absentCount): float
{
    return ScoreHelpers::trafficCalming($absentCount);
}

function penaltyPartialObstructions(float $count): float
{
    return ScoreHelpers::partialObstructions($count);
}

function penaltyMissingRamps(int $missingRampCount): float
{
    return ScoreHelpers::missingRamps($missingRampCount);
}

function penaltyMissingSignage(int $absentSignCount): float
{
    return ScoreHelpers::missingSignage($absentSignCount);
}

function penaltyTotalObstructions(float $count): float
{
    return ScoreHelpers::totalObstructions($count);
}

function penaltySurface(?string $material): float
{
    return ScoreHelpers::surface($material);
}

function penaltyCyclistSlowed(float $count, string $roadName = ''): float
{
    return ScoreHelpers::cyclistSlowed($count, $roadName);
}

function penaltyShade(?string $shade): float
{
    return ScoreHelpers::shade($shade);
}

function applyMissingLength(float $rawScore, float $missingLen, float $presentLen, float $totalLen): float
{
    return ScoreHelpers::applyMissingLength($rawScore, $missingLen, $presentLen, $totalLen);
}

function scoreToCondition(float $score): string
{
    return ScoreHelpers::scoreToCondition($score);
}

function penaltyToCondition(float $score): string
{
    return ScoreHelpers::scoreToCondition($score);
}

function conditionColour(string $condition): string
{
    return ScoreHelpers::conditionColor($condition);
}

function ratingColour(string $rating): string
{
    return ScoreHelpers::conditionColor($rating);
}

/**
 * Calculate the full score breakdown for one segment audit.
 * Now uses ScoreHelpers for helper functions
 */
function calculateSegmentScore(int $segmentAuditId, PDO $pdo): array
{
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length AS segment_length,
                r.name   AS road_name
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         JOIN   roads    r ON r.id = s.road_id
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

    $totalLen = max(1.0, (float)($audit['segment_length'] ?? 500));

    $missingLen = 0.0;
    if (($audit['cycle_track_missing'] ?? '') === 'Yes') {
        $missingLen = max(0.0, (float)($audit['missing_length'] ?? 0));
    }
    $missingLen = min($missingLen, $totalLen);
    $presentLen = $totalLen - $missingLen;
    $allMissing = ($presentLen <= 0.0);

    // SAFETY CATEGORY
    if ($allMissing) {
        $safetyRaw = 100.0;
    } else {
        $safetyRaw = (
            ScoreHelpers::bufferZone($audit['buffer_zone'] ?? null) +
            ScoreHelpers::lightAfterDark($audit['light_after_sunset'] ?? null) +
            ScoreHelpers::trafficCalming($absentCalmingCount) +
            ScoreHelpers::partialObstructions($partialObs)
        ) / 4.0;
    }
    $safetyScore = ScoreHelpers::applyMissingLength($safetyRaw, $missingLen, $presentLen, $totalLen);

    // CONTINUITY CATEGORY
    if ($allMissing) {
        $continuityRaw = 100.0;
    } else {
        $continuityRaw = (
            ScoreHelpers::missingRamps($missingRampCount) +
            ScoreHelpers::missingSignage($missingSignCount) +
            ScoreHelpers::totalObstructions($totalObs)
        ) / 3.0;
    }
    $continuityScore = ScoreHelpers::applyMissingLength($continuityRaw, $missingLen, $presentLen, $totalLen);

    // COMFORT CATEGORY
    if ($allMissing) {
        $comfortRaw = 100.0;
    } else {
        $roadName = $audit['road_name'] ?? '';
        $comfortRaw = (
            ScoreHelpers::surface($audit['surface_material'] ?? null) +
            ScoreHelpers::cyclistSlowed($cyclistSlowed, $roadName) +
            ScoreHelpers::shade($audit['shade'] ?? null)
        ) / 3.0;
    }
    $comfortScore = ScoreHelpers::applyMissingLength($comfortRaw, $missingLen, $presentLen, $totalLen);

    // WEIGHTED FINAL SCORE
    $finalScore = (
        $safetyScore     * WEIGHT_SAFETY     +
        $comfortScore    * WEIGHT_COMFORT     +
        $continuityScore * WEIGHT_CONTINUITY
    ) / WEIGHT_TOTAL;

    $finalScore = round(max(0.0, min(100.0, $finalScore)), 2);
    $condition = ScoreHelpers::scoreToCondition($finalScore);

    return [
        'safety_score'       => round($safetyScore,     2),
        'continuity_score'   => round($continuityScore, 2),
        'comfort_score'      => round($comfortScore,    2),
        'final'              => $finalScore,
        'condition'          => $condition,
        'rating'             => $condition,
    ];
}

/**
 * Calculate length-weighted road score
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

    $condition = ScoreHelpers::scoreToCondition($roadScore);

    return [
        'score'            => $roadScore,
        'condition'        => $condition,
        'rating'           => $condition,
        'safety_score'     => $roadSafety,
        'continuity_score' => $roadContinuity,
        'comfort_score'    => $roadComfort,
        'segment_count'    => count($rows),
    ];
}

/**
 * Detailed breakdown with parameter-level scores
 */
function calculateSegmentScoreDetailed(int $segmentAuditId, PDO $pdo): array
{
    $base = calculateSegmentScore($segmentAuditId, $pdo);

    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone, sa.light_after_sunset, sa.shade,
                sa.surface_material, sa.cycle_track_missing, sa.missing_length,
                s.length AS segment_length, r.name AS road_name
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         JOIN   roads r ON r.id = s.road_id
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
            'buffer_zone'      => ScoreHelpers::bufferZone($audit['buffer_zone'] ?? null),
            'light_after_dark' => ScoreHelpers::lightAfterDark($audit['light_after_sunset'] ?? null),
            'traffic_calming'  => ScoreHelpers::trafficCalming($absentCalmingCount),
            'partial_obs'      => ScoreHelpers::partialObstructions((float)($obs['partial'] ?? 0)),
        ],
        'continuity' => [
            'missing_ramps'   => ScoreHelpers::missingRamps($missingRampCount),
            'missing_signage' => ScoreHelpers::missingSignage($missingSignCount),
            'total_obs'       => ScoreHelpers::totalObstructions((float)($obs['total'] ?? 0)),
        ],
        'comfort' => [
            'surface'        => ScoreHelpers::surface($audit['surface_material'] ?? null),
            'cyclist_slowed' => ScoreHelpers::cyclistSlowed((float)($obs['slowed'] ?? 0), $audit['road_name'] ?? ''),
            'shade'          => ScoreHelpers::shade($audit['shade'] ?? null),
        ],
    ];

    return array_merge($base, ['parameters' => $params]);
}
