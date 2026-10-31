<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Notifications.php
//  The wording of notifications. Pure functions (no DB, no session),
//  so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

const NOTIFY_ASSIGNED = 'segments_assigned';
const NOTIFY_PROJECT_DELETED = 'project_deleted';
const NOTIFY_PROJECT_UPDATED = 'project_updated';
const NOTIFY_LEADER_CHANGED  = 'city_leader_changed';
const NOTIFY_PROJECT_ASSIGNED = 'project_assigned';

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

/** What people on a project are told when the Admin deletes it. */
function projectDeletedNotificationText(string $auditName): array
{
    return [
        'title' => 'Project deleted',
        'body'  => 'The Admin deleted the project "' . $auditName . '". Work on it has stopped and it no longer appears on your dashboard.',
    ];
}

/**
 * What the City Leader is told when the Admin edits a project.
 *
 * @param list<string> $changed  human labels, e.g. ['name', 'audit date', 'programme info']
 * @return array{title:string, body:string}
 */
function projectUpdatedNotificationText(string $auditName, array $changed): array
{
    $what = count($changed) > 1
        ? implode(', ', array_slice($changed, 0, -1)) . ' and ' . end($changed)
        : (string)($changed[0] ?? 'details');
    return [
        'title' => 'Project updated',
        'body'  => 'The Admin updated the ' . $what . ' of the project "' . $auditName . '".',
    ];
}

/** What a person is told when the Admin changes who leads a city. */
function cityLeaderChangedNotificationText(string $cityName, bool $nowLeader): array
{
    return $nowLeader
        ? ['title' => 'You are now City Leader', 'body' => 'The Admin made you the City Leader of ' . $cityName . '. Open your City Dashboard to manage its projects.']
        : ['title' => 'City Leader changed', 'body' => 'The Admin made someone else the City Leader of ' . $cityName . '. You are now a Surveyor.'];
}

/** What a City Leader is told when the Admin assigns a project to them. */
function projectAssignedNotificationText(string $auditName): array
{
    return [
        'title' => 'New project assigned',
        'body'  => 'The Admin assigned the project "' . $auditName . '" to you. Open it to add roads and assign surveyors.',
    ];
}

/** What a City Leader is told when the Admin hands their project to another City Leader. */
function projectUnassignedNotificationText(string $auditName): array
{
    return [
        'title' => 'Project reassigned',
        'body'  => 'The Admin assigned the project "' . $auditName . '" to another City Leader. It no longer appears on your dashboard.',
    ];
}
