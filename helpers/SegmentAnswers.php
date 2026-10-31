<?php
declare(strict_types=1);

/**
 * helpers/SegmentAnswers.php
 * Pure rules: which answers a surveyor MUST give before a segment can be
 * submitted. These are the answers the scoring engine reads
 * (services/ScoreService.php). The engine treats a blank as a real answer
 * (blank buffer zone = worst, blank shade = best), so a blank form would get
 * an invented score. No database access here, so it is easy to unit-test.
 */

/** The obstruction types the form offers, per category. */
function segmentObstructionCategories(): array
{
    return [
        'fixed'   => [
            'Trees', 'Poles', 'CCTV', 'TrafficSignal', 'SignBoard',
            'TelephonePanel', 'ElectricalPanel', 'BusStand',
            'BuiltEncroachment', 'Bollards', 'PropertyEntrance', 'UtilityChambers',
        ],
        'movable' => [
            'Hawkers', 'GarbageBins', 'ConstructionMaterial',
            'TrafficBarricade', 'PeopleSitting', 'Hoardings',
        ],
        'parked'  => [
            'ReligiousLandmark', 'RestaurantEatery', 'AutoGarage',
            'CommercialRetailShops', 'OnStreetVending', 'PublicSpace',
        ],
    ];
}

/** Radio questions that feed the score: field name => [label, allowed values]. */
function segmentRequiredRadios(): array
{
    return [
        'buffer_zone'         => ['Presence of Buffer Zone',        ['Segregated', 'Buffer Zone', 'None', 'NA']],
        'light_after_sunset'  => ['Light (after sunset)',           ['Yes', 'No', 'Partial']],
        'surface_material'    => ['Cycle track surface material',   ['Interlock Blocks', 'Concrete', 'Asphalt']],
        'shade'               => ['Shade',                          ['Yes', 'No', 'Partial']],
        'cycle_track_missing' => ['Cycle Track Missing',            ['Yes', 'No']],
    ];
}

/** Intersection questions that feed the score: field => [label, allowed values]. */
function segmentRequiredIntersectionFields(): array
{
    return [
        'off_ramp'        => ['Ramp off track',          ['Comfortable', 'Uncomfortable', 'No Ramp']],
        'on_ramp'         => ['Ramp back to track',      ['Comfortable', 'Uncomfortable', 'No Ramp']],
        'markings'        => ['Markings',                ['Present', 'Absent']],
        'signage'         => ['Signage',                 ['Present', 'Absent']],
        'traffic_calming' => ['Traffic Calming Device',  ['Present', 'Absent']],
    ];
}

/** Sum of every partial / total / slowed count the surveyor entered. */
function segmentObstructionCount(array $post): int
{
    $sum = 0;
    foreach (segmentObstructionCategories() as $category => $types) {
        foreach ($types as $type) {
            foreach (['slowed', 'partial', 'total'] as $kind) {
                $sum += max(0, (int)($post["{$category}_{$type}_{$kind}"] ?? 0));
            }
        }
    }
    return $sum;
}

/**
 * First problem with the answers, as a plain sentence, or null when the
 * segment has everything the score needs.
 *
 * @param array<string,mixed> $post          the submitted form fields
 * @param float               $segmentLength length of the segment in metres
 * @param array<int,mixed>    $intersections decoded intersections list
 */
function segmentAnswersError(array $post, float $segmentLength, array $intersections): ?string
{
    // 1. Radio questions the score reads
    foreach (segmentRequiredRadios() as $field => [$label, $allowed]) {
        $v = trim((string)($post[$field] ?? ''));
        if ($v === '' || !in_array($v, $allowed, true)) {
            return "Please answer: {$label}.";
        }
    }

    // 2. Missing length, only when the track is missing
    if (($post['cycle_track_missing'] ?? '') === 'Yes') {
        $raw = trim((string)($post['missing_length'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 'Please enter the missing cycle track length (in metres).';
        }
        $len = (float)$raw;
        if ($len <= 0) {
            return 'The missing cycle track length must be more than 0 m.';
        }
        if ($segmentLength > 0 && $len > $segmentLength) {
            return 'The missing cycle track length cannot be more than the segment length ('
                 . rtrim(rtrim(number_format($segmentLength, 1, '.', ''), '0'), '.') . ' m).';
        }
    }

    // 3. Obstructions: either some are recorded, or "none" is confirmed
    $noObstructions = (string)($post['no_obstructions'] ?? '') === '1';
    $obsCount       = segmentObstructionCount($post);
    if ($noObstructions && $obsCount > 0) {
        return 'You ticked "No obstructions" but also entered obstruction counts. Untick one of them.';
    }
    if (!$noObstructions && $obsCount === 0) {
        return 'Add the obstructions you found (with a count), or tick "No obstructions on this segment".';
    }

    // 4. Intersections: either complete rows, or "none" is confirmed
    $noIntersections = (string)($post['no_intersections'] ?? '') === '1';
    if ($noIntersections && !empty($intersections)) {
        return 'You ticked "No intersections" but also added an intersection. Untick it or remove the intersection.';
    }
    if (!$noIntersections && empty($intersections)) {
        return 'Add the intersections in this segment, or tick "No intersections in this segment".';
    }
    foreach (array_values($intersections) as $idx => $row) {
        $n = $idx + 1;
        foreach (segmentRequiredIntersectionFields() as $field => [$label, $allowed]) {
            $v = is_array($row) ? trim((string)($row[$field] ?? '')) : '';
            if ($v === '' || !in_array($v, $allowed, true)) {
                return "Intersection {$n}: please answer \"{$label}\".";
            }
        }
    }

    return null;
}
