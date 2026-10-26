<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/SurveyLabels.php
//  Readable wording for the answers a surveyor ticks on the audit
//  form. The database stores short keys ("overheadCables"); the
//  screens a City Leader reads must show the words from the form.
//  Keep the labels here in step with pages/form.php.
// ═══════════════════════════════════════════════════════════════

const SURVEY_ANSWER_LABELS = [
    'surface_issues' => [
        'gravel'   => 'Gravel / Sand / Debris / Dirt',
        'loose'    => 'Loose Interlock Blocks',
        'broken'   => 'Broken Surface',
        'water'    => 'Water Stagnation / Surface Undulation',
        'roots'    => 'Tree Roots',
        'manholes' => 'Manholes',
        'cables'   => 'Exposed Underground Cables',
    ],
    'overhead_issues' => [
        'overheadCables' => 'Cables',
        'branches'       => 'Branches',
    ],
    'footpath_rating' => [
        'minWidth'         => 'Min 1.8 m wide',
        'continuous'       => 'Continuous and levelled',
        'obstructionFree'  => 'Obstruction free',
        'disabledFriendly' => 'Disabled friendly (access ramps with tactile tiles)',
        'comfort'          => 'Comfort (tree shade with seating spaces)',
    ],
];

/**
 * "GarbageBins" -> "Garbage Bins", "TrafficSignal" -> "Traffic Signal", "CCTV" -> "CCTV",
 * "fixed" -> "Fixed". Used for keys that have no hand-written label.
 */
function surveyHumanize(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace('_', ' ', $key)) ?? $key;
    return ucfirst($spaced);
}

/** The label for one stored key of a multi-choice field; unknown keys are humanised, never dropped. */
function surveyAnswerLabel(string $field, string $key): string
{
    return SURVEY_ANSWER_LABELS[$field][$key] ?? surveyHumanize($key);
}

/**
 * Labels for a stored multi-choice answer (a JSON list like '["gravel","water"]', or an array).
 * @return list<string>  empty when nothing was ticked
 */
function surveyAnswerLabels(string $field, mixed $stored): array
{
    $list = $stored;
    if (is_string($stored)) {
        $decoded = json_decode($stored, true);
        $list = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $key) {
        if (is_scalar($key) && trim((string)$key) !== '') {
            $out[] = surveyAnswerLabel($field, (string)$key);
        }
    }
    return $out;
}
