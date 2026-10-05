<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/AdminDashboard.php
//  Pure rules behind the Admin dashboard (no DB, no session), so
//  they are unit-tested: what needs the Admin's attention, how the
//  audit list is ordered, and how a status is worded.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/CityDashboard.php';

/** An audit with no segment activity for this many days is flagged as stalled. */
const ADMIN_STALLED_DAYS = 14;

/** Audit statuses that count as "in progress" (after setup, before the Admin decides). */
const ADMIN_IN_PROGRESS_STATUSES = ['active', 'in_review', 'finalised'];

/** Short status words the Admin sees (the stored values stay unchanged). */
function adminAuditStatusLabel(string $status): string
{
    return match ($status) {
        'draft'             => 'Setup',
        'active'            => 'Auditing',
        'in_review'         => 'In review',
        'finalised'         => 'Closed by city',
        'awaiting_approval' => 'Awaiting approval',
        'published'         => 'Published',
        'voided'            => 'Voided',
        default             => cityStatusLabel($status),
    };
}

/** CSS class suffix for the status chip. */
function adminAuditStatusClass(string $status): string
{
    return str_replace('_', '-', $status);
}

/** Sort rank: what the Admin must act on first comes first. */
function adminAuditRank(string $status): int
{
    $order = ['awaiting_approval', 'in_review', 'active', 'finalised', 'draft', 'published', 'voided'];
    $i = array_search($status, $order, true);
    return $i === false ? count($order) : (int)$i;
}

/**
 * Audits in the order the Admin should look at them: awaiting approval first,
 * then by status rank, newest activity first within a rank.
 *
 * @param list<array<string,mixed>> $audits
 * @return list<array<string,mixed>>
 */
function adminSortAudits(array $audits): array
{
    usort($audits, static function (array $a, array $b): int {
        $r = adminAuditRank((string)$a['status']) <=> adminAuditRank((string)$b['status']);
        if ($r !== 0) {
            return $r;
        }
        $t = strcmp((string)($b['last_activity'] ?? ''), (string)($a['last_activity'] ?? ''));
        return $t !== 0 ? $t : ((int)$b['id'] <=> (int)$a['id']);
    });
    return $audits;
}

/** Where the Admin opens an audit: setup audits show the setup page, the rest the report. */
function adminAuditLink(array $audit): string
{
    $id = (int)$audit['id'];
    return (string)$audit['status'] === 'draft' ? 'city_audit.php?id=' . $id : 'city_audit_report.php?id=' . $id;
}

/** Whole days between two timestamps (never negative); null when either is missing. */
function adminIdleDays(?string $last, DateTimeImmutable $now): ?int
{
    if ($last === null || trim($last) === '') {
        return null;
    }
    try {
        $then = new DateTimeImmutable($last, new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
    $secs = $now->getTimestamp() - $then->getTimestamp();
    return $secs <= 0 ? 0 : intdiv($secs, 86400);
}

/**
 * Audits being worked on that have gone quiet. Each gets 'idle_days'.
 *
 * @param list<array<string,mixed>> $audits  rows with status and last_activity
 * @return list<array<string,mixed>> longest idle first
 */
function adminStalledAudits(array $audits, DateTimeImmutable $now, int $days = ADMIN_STALLED_DAYS): array
{
    $out = [];
    foreach ($audits as $a) {
        if (!in_array((string)$a['status'], ['active', 'in_review'], true)) {
            continue;
        }
        $idle = adminIdleDays($a['last_activity'] ?? null, $now);
        if ($idle !== null && $idle >= $days) {
            $a['idle_days'] = $idle;
            $out[] = $a;
        }
    }
    usort($out, static fn(array $x, array $y): int => $y['idle_days'] <=> $x['idle_days']);
    return $out;
}

/** Cities that have no City Leader yet. @return list<array<string,mixed>> */
function adminCitiesWithoutLeader(array $cities): array
{
    return array_values(array_filter($cities, static fn(array $c): bool => (int)$c['leaders'] === 0));
}

/**
 * The "Needs your attention" list, most urgent first.
 * Each item: tone (urgent|warn|info), title, detail, href, cta.
 *
 * @param list<array<string,mixed>> $awaiting   audits waiting for approval
 * @param list<array<string,mixed>> $stalled    from adminStalledAudits()
 * @param int                       $roadsToVerify
 * @param list<array<string,mixed>> $noLeader   from adminCitiesWithoutLeader()
 * @return list<array{tone:string,title:string,detail:string,href:string,cta:string}>
 */
function adminAttentionItems(array $awaiting, array $stalled, int $roadsToVerify, array $noLeader): array
{
    $items = [];
    foreach ($awaiting as $a) {
        $items[] = [
            'tone'   => 'urgent',
            'title'  => (string)$a['name'] . ' (' . (int)$a['audit_year'] . ')',
            'detail' => (string)$a['city_name'] . ' sent this audit for your approval: '
                        . cityPlural((int)$a['road_count'], 'road', 'roads') . ', '
                        . cityPlural((int)$a['segment_count'], 'segment', 'segments') . '.',
            'href'   => 'city_audit_report.php?id=' . (int)$a['id'],
            'cta'    => 'Review',
        ];
    }
    foreach ($stalled as $a) {
        $items[] = [
            'tone'   => 'warn',
            'title'  => (string)$a['name'] . ' (' . (int)$a['audit_year'] . ')',
            'detail' => (string)$a['city_name'] . ': no segment activity for '
                        . cityPlural((int)$a['idle_days'], 'day', 'days') . '.',
            'href'   => adminAuditLink($a),
            'cta'    => 'Open',
        ];
    }
    if ($roadsToVerify > 0) {
        $items[] = [
            'tone'   => 'info',
            'title'  => cityPlural($roadsToVerify, 'road', 'roads') . ' to verify',
            'detail' => 'New roads are waiting to be verified in the Roads page.',
            'href'   => 'admin.php',
            'cta'    => 'Verify',
        ];
    }
    foreach ($noLeader as $c) {
        $items[] = [
            'tone'   => 'warn',
            'title'  => (string)$c['name'] . ' has no City Leader',
            'detail' => 'Nobody can start audits there until a City Leader is assigned.',
            'href'   => 'admin_surveyors.php',
            'cta'    => 'Assign',
        ];
    }
    return $items;
}
