<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityDashboard.php
//  Pure rules behind the City Leader dashboard, the segment review
//  page and the audit report page (no DB, no session), so they are
//  unit-tested.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../services/ScoreHelpers.php';

/** Condition labels, best to worst (same words as ScoreHelpers::scoreToCondition). */
const CITY_CONDITIONS = ['Good', 'OK', 'Poor', 'Bad', 'Very Bad'];

/** "1 segment" / "3 segments". */
function cityPlural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** "in_review" -> "In review". */
function cityStatusLabel(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}

/** Whole-number percentage (0-100) of $done out of $total. */
function cityDashProgress(int $done, int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    $ratio = $done / $total;
    return (int)round(max(0.0, min(1.0, $ratio)) * 100);
}

/** CSS class suffix for a condition label: "Very Bad" -> "very-bad", empty -> "none". */
function cityConditionClass(?string $condition): string
{
    $c = strtolower(trim((string)$condition));
    return $c === '' ? 'none' : str_replace(' ', '-', $c);
}

/**
 * Did the surveyor record anything at all? A submitted segment whose key
 * answers are all empty should not be approved without a second look.
 *
 * @param array<string,mixed> $data  a segment_audits row (or part of one)
 */
function cityAnswersRecorded(array $data): bool
{
    foreach (['cycle_track_missing', 'cyclist_use', 'surface_material', 'segment_width',
              'shade', 'buffer_zone', 'light_after_sunset', 'comments'] as $key) {
        $v = $data[$key] ?? null;
        if ($v !== null && trim((string)$v) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * What the City Leader should do next in one audit.
 * $c holds counts: total, unassigned, assigned, submitted, needs_revisit, approved.
 * Each item has a level: "action" (needs you), "info" (waiting on others) or "done".
 *
 * @param array<string,int> $c
 * @return list<array{level:string,text:string}>
 */
function cityAuditAttention(string $status, array $c, ?string $adminNote = null, bool $forAdmin = false): array
{
    $total      = (int)($c['total'] ?? 0);
    $unassigned = (int)($c['unassigned'] ?? 0);
    $submitted  = (int)($c['submitted'] ?? 0);
    $sentBack   = (int)($c['needs_revisit'] ?? 0);
    $approved   = (int)($c['approved'] ?? 0);
    $items      = [];

    if ($status === 'draft') {
        if ($total === 0) {
            $items[] = ['level' => 'action', 'text' => 'Add a road to start this audit.'];
        } elseif ($unassigned > 0) {
            $items[] = ['level' => 'action', 'text' => cityPlural($unassigned, 'segment still needs', 'segments still need') . ' a surveyor.'];
        } else {
            $items[] = ['level' => 'action', 'text' => 'Every segment has a surveyor. Activate the audit.'];
        }
        return $items;
    }

    if ($status === 'active' || $status === 'in_review') {
        if ($adminNote !== null && $adminNote !== '' && !$forAdmin) {
            $items[] = ['level' => 'action', 'text' => 'The Admin sent this audit back: ' . $adminNote];
        }
        if ($submitted > 0) {
            $items[] = ['level' => 'action', 'text' => cityPlural($submitted, 'segment is', 'segments are') . ' waiting for your review.'];
        }
        if ($sentBack > 0) {
            $items[] = ['level' => 'info', 'text' => cityPlural($sentBack, 'segment was', 'segments were') . ' sent back and is with the surveyor.'];
        }
        if ($total > 0 && $approved === $total) {
            $items[] = ['level' => 'action', 'text' => 'Every segment is approved. Close the audit to produce the report.'];
        } elseif ($submitted === 0 && $sentBack === 0) {
            $left = max(0, $total - $approved);
            $items[] = ['level' => 'info', 'text' => cityPlural($left, 'segment is', 'segments are') . ' still being audited.'];
        }
        return $items;
    }

    if ($status === 'finalised') {
        return [['level' => 'action', 'text' => 'The audit is closed. Send the report to the Admin.']];
    }
    if ($status === 'awaiting_approval') {
        return $forAdmin
            ? [['level' => 'action', 'text' => 'Waiting for your approval. Open the report to approve or return it.']]
            : [['level' => 'info', 'text' => 'Sent to the Admin. Waiting for approval.']];
    }
    if ($status === 'published') {
        return [['level' => 'done', 'text' => 'Published. The report is final.']];
    }
    return [];
}

/**
 * Headline numbers across all of a city's audits.
 *
 * @param list<array<string,mixed>> $audits  rows from CityDashboardRepository::auditSummaries()
 * @return array{audits:int,open:int,segments:int,approved:int,submitted:int,with_surveyors:int}
 */
function cityDashTotals(array $audits): array
{
    $t = ['audits' => 0, 'open' => 0, 'segments' => 0, 'approved' => 0, 'submitted' => 0, 'with_surveyors' => 0];
    foreach ($audits as $a) {
        $t['audits']++;
        if (!in_array((string)($a['status'] ?? ''), ['published', 'voided'], true)) {
            $t['open']++;
        }
        $t['segments']       += (int)($a['segment_count'] ?? 0);
        $t['approved']       += (int)($a['approved_count'] ?? 0);
        $t['submitted']      += (int)($a['submitted_count'] ?? 0);
        $t['with_surveyors'] += (int)($a['assigned_count'] ?? 0) + (int)($a['needs_revisit_count'] ?? 0);
    }
    return $t;
}

/**
 * Length-weighted scores over a set of scored segments (one road or the whole audit).
 * Rows without a "final" score are ignored. Returns null when nothing is scored.
 *
 * @param list<array<string,mixed>> $rows  each: length, final, safety_score, continuity_score, comfort_score
 * @return array{score:float,safety:float,continuity:float,comfort:float,condition:string,scored:int}|null
 */
function cityReportAggregate(array $rows): ?array
{
    $len = 0.0; $f = 0.0; $s = 0.0; $c = 0.0; $m = 0.0; $n = 0;
    foreach ($rows as $r) {
        if (!isset($r['final'])) {
            continue;
        }
        $l = max(0.0, (float)($r['length'] ?? 0));
        $len += $l;
        $f   += (float)$r['final'] * $l;
        $s   += (float)($r['safety_score'] ?? 0) * $l;
        $c   += (float)($r['continuity_score'] ?? 0) * $l;
        $m   += (float)($r['comfort_score'] ?? 0) * $l;
        $n++;
    }
    if ($n === 0 || $len <= 0.0) {
        return null;
    }
    $score = round($f / $len, 2);
    return [
        'score'      => $score,
        'safety'     => round($s / $len, 2),
        'continuity' => round($c / $len, 2),
        'comfort'    => round($m / $len, 2),
        'condition'  => ScoreHelpers::scoreToCondition($score),
        'scored'     => $n,
    ];
}

/**
 * How many scored segments fall in each condition (all five keys always present).
 *
 * @param list<array<string,mixed>> $rows  each may have "condition"
 * @return array<string,int>
 */
function cityConditionCounts(array $rows): array
{
    $out = array_fill_keys(CITY_CONDITIONS, 0);
    foreach ($rows as $r) {
        $c = (string)($r['condition'] ?? '');
        if (isset($out[$c])) {
            $out[$c]++;
        }
    }
    return $out;
}

/** Database times are UTC; show them in India time. "2026-10-04 13:35:05" -> "4 Oct 2026, 7:05 PM". */
function cityLocalTime(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return '—';
    }
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $d->format('j M Y, g:i A');
    } catch (Exception $e) {
        return $utc;
    }
}
