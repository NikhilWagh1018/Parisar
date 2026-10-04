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

/** The lifecycle shown as a tracker: label for each stage, in order. */
const CITY_AUDIT_STAGES = ['Setup', 'Auditing', 'Review', 'Closed', 'With Admin', 'Published'];

/**
 * Which tracker stage an audit status belongs to (index into CITY_AUDIT_STAGES),
 * or -1 for a status that is not on the path (e.g. voided).
 */
function cityAuditStage(string $status): int
{
    return match ($status) {
        'draft'             => 0,
        'active'            => 1,
        'in_review'         => 2,
        'finalised'         => 3,
        'awaiting_approval' => 4,
        'published'         => 5,
        default             => -1,
    };
}

/**
 * Segment progress as bar parts, in the order approved, submitted, sent back,
 * with surveyors, unassigned. Each part has key, label, count and a whole-number
 * width (percent); widths always add up to exactly 100 when there are segments
 * (the rounding remainder goes to the largest part). Empty list when total is 0.
 *
 * @param array<string,int> $c  total, approved, submitted, needs_revisit, assigned, unassigned
 * @return list<array{key:string,label:string,count:int,pct:int}>
 */
function citySegmentMix(array $c): array
{
    $total = (int)($c['total'] ?? 0);
    if ($total <= 0) {
        return [];
    }
    $parts = [
        ['key' => 'approved',  'label' => 'Approved',        'count' => (int)($c['approved'] ?? 0)],
        ['key' => 'review',    'label' => 'To review',       'count' => (int)($c['submitted'] ?? 0)],
        ['key' => 'back',      'label' => 'Sent back',       'count' => (int)($c['needs_revisit'] ?? 0)],
        ['key' => 'surveyor',  'label' => 'With surveyors',  'count' => (int)($c['assigned'] ?? 0)],
        ['key' => 'open',      'label' => 'Unassigned',      'count' => (int)($c['unassigned'] ?? 0)],
    ];
    $sum = 0;
    foreach ($parts as $i => $p) {
        $parts[$i]['count'] = max(0, $p['count']);
        $parts[$i]['pct']   = (int)floor($parts[$i]['count'] / $total * 100);
        $sum += $parts[$i]['pct'];
    }
    $rest = 100 - $sum;
    if ($rest > 0) {
        $big = 0;
        foreach ($parts as $i => $p) {
            if ($p['count'] > $parts[$big]['count']) {
                $big = $i;
            }
        }
        $parts[$big]['pct'] += $rest;
    }
    return $parts;
}

/**
 * Number of audits in each tracker stage, keyed by stage label (all keys present).
 *
 * @param list<array<string,mixed>> $audits
 * @return array<string,int>
 */
function cityPipeline(array $audits): array
{
    $out = array_fill_keys(CITY_AUDIT_STAGES, 0);
    foreach ($audits as $a) {
        $i = cityAuditStage((string)($a['status'] ?? ''));
        if ($i >= 0) {
            $out[CITY_AUDIT_STAGES[$i]]++;
        }
    }
    return $out;
}

/** "Good evening" style greeting for the hour (0-23) in India time. */
function cityGreeting(int $hour): string
{
    if ($hour < 12) {
        return 'Good morning';
    }
    return $hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * The one button that best moves an audit forward, for the "Next step" banner on the
 * audit page. Returns null when there is nothing for this viewer to click.
 * $c holds counts: total, unassigned, submitted, needs_revisit, approved.
 *
 * @param array<string,int> $c
 * @return array{label:string,href:string}|null
 */
function cityAuditNextAction(string $status, array $c, bool $forAdmin, int $auditId): ?array
{
    $total      = (int)($c['total'] ?? 0);
    $unassigned = (int)($c['unassigned'] ?? 0);
    $submitted  = (int)($c['submitted'] ?? 0);
    $approved   = (int)($c['approved'] ?? 0);
    $report     = 'city_audit_report.php?id=' . $auditId;

    if ($forAdmin) {
        return $status === 'awaiting_approval' ? ['label' => 'Open report', 'href' => $report] : null;
    }
    if ($status === 'draft') {
        if ($total === 0) {
            return ['label' => 'Add a road', 'href' => '#caAddRoad'];
        }
        return $unassigned > 0
            ? ['label' => 'Assign surveyors', 'href' => '#caRoads']
            : ['label' => 'Activate audit', 'href' => '#caAssign'];
    }
    if ($status === 'active' || $status === 'in_review') {
        if ($submitted > 0) {
            return ['label' => 'Review submissions', 'href' => '#caReview'];
        }
        if ($total > 0 && $approved === $total) {
            return ['label' => 'Close audit', 'href' => '#caReview'];
        }
        return null;
    }
    if ($status === 'finalised') {
        return ['label' => 'Send to Admin', 'href' => '#caReview'];
    }
    if ($status === 'awaiting_approval' || $status === 'published') {
        return ['label' => 'Open report', 'href' => $report];
    }
    return null;
}
