<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityAudit.php
//  Pure rules for City Audits (no DB, no session) so they are unit
//  tested: input validation and the segment plan for a road.
//  The plan uses the same rule as the surveyor's Define Road screen:
//  count = ceil(length / segment length), the last segment is the
//  remainder.
// ═══════════════════════════════════════════════════════════════

const CITY_AUDIT_MIN_ROAD_LENGTH    = 50.0;
const CITY_AUDIT_MAX_ROAD_LENGTH    = 100000.0;
const CITY_AUDIT_MIN_SEGMENT_LENGTH = 10.0;
const CITY_AUDIT_MAX_SEGMENTS       = 500;
const CITY_AUDIT_MAX_ASSIGN_BATCH   = 500;

function cityAuditCleanText(mixed $v): string
{
    $s = strip_tags((string)$v);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/**
 * @param array<string,mixed> $in  name, state, audit_year, programme_info
 * @return array{errors: array<string,string>, clean: array<string,mixed>}
 */
function cityAuditValidate(array $in): array
{
    $errors = [];

    $name = cityAuditCleanText($in['name'] ?? '');
    if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
        $errors['name'] = 'Audit name must be 3 to 150 characters.';
    }

    $state = cityAuditCleanText($in['state'] ?? '');
    if (mb_strlen($state) < 2 || mb_strlen($state) > 100
        || !preg_match("/^\\p{L}[\\p{L} .'-]*$/u", $state)) {
        $errors['state'] = 'Enter a valid state name.';
    }

    $year = filter_var($in['audit_year'] ?? null, FILTER_VALIDATE_INT);
    if ($year === false || $year === null || $year < 2000 || $year > 2100) {
        $errors['audit_year'] = 'Enter a year between 2000 and 2100.';
    }

    $info = trim(strip_tags((string)($in['programme_info'] ?? '')));
    if (mb_strlen($info) > 2000) {
        $errors['programme_info'] = 'Programme info is limited to 2000 characters.';
    }

    return [
        'errors' => $errors,
        'clean'  => [
            'name'           => $name,
            'state'          => $state,
            'audit_year'     => (int)$year,
            'programme_info' => $info === '' ? null : $info,
        ],
    ];
}

/**
 * @param array<string,mixed> $in  road_group_id, total_length, segment_length
 * @return array{errors: array<string,string>, clean: array<string,mixed>}
 */
function cityAuditValidateRoad(array $in): array
{
    $errors = [];

    $groupId = filter_var($in['road_group_id'] ?? null, FILTER_VALIDATE_INT);
    if ($groupId === false || $groupId === null || $groupId <= 0) {
        $errors['road_group_id'] = 'Select a road.';
    }

    $total = filter_var($in['total_length'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($total === false || $total === null
        || $total < CITY_AUDIT_MIN_ROAD_LENGTH || $total > CITY_AUDIT_MAX_ROAD_LENGTH) {
        $errors['total_length'] = 'Road length must be between 50 and 100000 metres.';
    }

    $seg = filter_var($in['segment_length'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($seg === false || $seg === null || $seg < CITY_AUDIT_MIN_SEGMENT_LENGTH) {
        $errors['segment_length'] = 'Segment length must be at least 10 metres.';
    }

    if (!$errors && (int)ceil(round($total / $seg, 9)) > CITY_AUDIT_MAX_SEGMENTS) {
        $errors['segment_length'] = 'That would create more than ' . CITY_AUDIT_MAX_SEGMENTS
            . ' segments. Use a longer segment length.';
    }

    return [
        'errors' => $errors,
        'clean'  => [
            'road_group_id'  => (int)$groupId,
            'total_length'   => round((float)$total, 2),
            'segment_length' => round((float)$seg, 2),
        ],
    ];
}

/** "500" -> "500m", "250.5" -> "250.5m" (same labels the surveyor screen stores). */
function cityAuditDistanceLabel(float $metres): string
{
    return rtrim(rtrim(number_format($metres, 2, '.', ''), '0'), '.') . 'm';
}

/**
 * Split a road into segments of a standard length; the last one is the remainder.
 *
 * @return list<array{segment_number:int,start_distance:float,end_distance:float,length:float,start_label:string,end_label:string}>
 */
function cityAuditSegmentPlan(float $totalLength, float $segmentLength): array
{
    if ($totalLength < CITY_AUDIT_MIN_ROAD_LENGTH) {
        throw new InvalidArgumentException('Road length must be at least 50 metres.');
    }
    if ($segmentLength < CITY_AUDIT_MIN_SEGMENT_LENGTH) {
        throw new InvalidArgumentException('Segment length must be at least 10 metres.');
    }
    $count = (int)ceil(round($totalLength / $segmentLength, 9));
    if ($count > CITY_AUDIT_MAX_SEGMENTS) {
        throw new InvalidArgumentException('Too many segments.');
    }

    $plan = [];
    for ($i = 0; $i < $count; $i++) {
        $start = round($i * $segmentLength, 2);
        $end   = round(min(($i + 1) * $segmentLength, $totalLength), 2);
        $plan[] = [
            'segment_number' => $i + 1,
            'start_distance' => $start,
            'end_distance'   => $end,
            'length'         => round($end - $start, 2),
            'start_label'    => cityAuditDistanceLabel($start),
            'end_label'      => cityAuditDistanceLabel($end),
        ];
    }
    return $plan;
}

/**
 * Validate an assignment request. Two shapes:
 *   - segments: { segment_ids: [1,2,3], surveyor_id }
 *   - whole road: { road_id, surveyor_id }
 * surveyor_id of null / "" / 0 means "unassign".
 *
 * @param array<string,mixed> $in
 * @return array{errors: array<string,string>, clean: array{mode:string,segment_ids:list<int>,road_id:?int,surveyor_id:?int}}
 */
function cityAuditValidateAssignment(array $in): array
{
    $errors = [];

    $rawSurveyor = $in['surveyor_id'] ?? null;
    $surveyorId  = null;
    if (!($rawSurveyor === null || $rawSurveyor === '' || $rawSurveyor === 0 || $rawSurveyor === '0')) {
        $sid = filter_var($rawSurveyor, FILTER_VALIDATE_INT);
        if ($sid === false || $sid === null || $sid <= 0) {
            $errors['surveyor_id'] = 'Select a surveyor.';
        } else {
            $surveyorId = (int)$sid;
        }
    }

    $hasRoad = array_key_exists('road_id', $in) && $in['road_id'] !== null && $in['road_id'] !== '';
    $hasSegs = array_key_exists('segment_ids', $in) && $in['segment_ids'] !== null;
    $roadId  = null;
    $segIds  = [];
    $mode    = 'segments';

    if ($hasRoad && $hasSegs) {
        $errors['road_id'] = 'Send either a road or a list of segments, not both.';
    } elseif ($hasRoad) {
        $mode = 'road';
        $rid  = filter_var($in['road_id'], FILTER_VALIDATE_INT);
        if ($rid === false || $rid === null || $rid <= 0) {
            $errors['road_id'] = 'Invalid road.';
        } else {
            $roadId = (int)$rid;
        }
    } else {
        if (!is_array($in['segment_ids'] ?? null) || !$in['segment_ids']) {
            $errors['segment_ids'] = 'Select at least one segment.';
        } elseif (count($in['segment_ids']) > CITY_AUDIT_MAX_ASSIGN_BATCH) {
            $errors['segment_ids'] = 'Too many segments in one request.';
        } else {
            foreach ($in['segment_ids'] as $raw) {
                $id = filter_var($raw, FILTER_VALIDATE_INT);
                if ($id === false || $id === null || $id <= 0) {
                    $errors['segment_ids'] = 'Invalid segment.';
                    $segIds = [];
                    break;
                }
                $segIds[] = (int)$id;
            }
            $segIds = array_values(array_unique($segIds));
        }
    }

    return [
        'errors' => $errors,
        'clean'  => ['mode' => $mode, 'segment_ids' => $segIds, 'road_id' => $roadId, 'surveyor_id' => $surveyorId],
    ];
}
