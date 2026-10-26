<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Notifications.php
//  The wording of notifications. Pure functions (no DB, no session),
//  so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

const NOTIFY_ASSIGNED = 'segments_assigned';

/** "1 segment" / "3 segments". */
function notifyPlural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/**
 * What a surveyor is told when a City Leader assigns them work.
 *
 * @param string      $auditName   the audit's name
 * @param string      $auditStatus draft | active | in_review | ... (city_audits.status)
 * @param int         $segments    how many segments were assigned
 * @param string|null $roadName    set when a whole road was assigned
 * @return array{title:string, body:string}
 */
function assignmentNotificationText(string $auditName, string $auditStatus, int $segments, ?string $roadName): array
{
    $what = $roadName !== null && $roadName !== ''
        ? notifyPlural($segments, 'segment', 'segments') . ' of ' . $roadName
        : notifyPlural($segments, 'segment', 'segments');

    $body = 'Your City Leader assigned you ' . $what . ' in the audit "' . $auditName . '".';
    $body .= $auditStatus === 'draft'
        ? ' You can start once the audit is activated.'
        : ' You can start from your dashboard.';

    return ['title' => 'New assignment', 'body' => $body];
}
