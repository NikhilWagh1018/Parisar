<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/SurveyorWork.php
//  What a surveyor sees for an assigned segment, and when they may
//  submit it. Pure functions (no DB) so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

/** Audit statuses in which surveyors can work on their segments. */
const SURVEYOR_WORKABLE_AUDIT_STATUSES = ['active', 'in_review'];

/** Assignment statuses a surveyor may (re)submit from. */
const SURVEYOR_SUBMITTABLE_STATUSES = ['assigned', 'needs_revisit'];

/**
 * The state a surveyor sees for one assignment.
 * "In progress" is shown when the work is still to do but the surveyor
 * already has an active session on the road.
 *
 * @return array{key:string,label:string}
 */
function surveyorWorkState(string $assignmentStatus, bool $hasActiveSession): array
{
    return match ($assignmentStatus) {
        'submitted'     => ['key' => 'submitted',     'label' => 'Submitted'],
        'needs_revisit' => ['key' => 'needs_revisit', 'label' => 'Needs revisit'],
        'approved'      => ['key' => 'approved',      'label' => 'Approved'],
        default         => $hasActiveSession
            ? ['key' => 'in_progress', 'label' => 'In progress']
            : ['key' => 'todo',        'label' => 'To do'],
    };
}

/**
 * Counts per state for a list of rows that carry state_key.
 *
 * @param list<array{state_key:string}> $rows
 * @return array{todo:int,in_progress:int,needs_revisit:int,submitted:int,approved:int}
 */
function surveyorWorkSummary(array $rows): array
{
    $out = ['todo' => 0, 'in_progress' => 0, 'needs_revisit' => 0, 'submitted' => 0, 'approved' => 0];
    foreach ($rows as $r) {
        if (isset($out[$r['state_key']])) {
            $out[$r['state_key']]++;
        }
    }
    return $out;
}

/** May the surveyor open the audit form for this assignment status? */
function surveyorCanAudit(string $assignmentStatus): bool
{
    return in_array($assignmentStatus, SURVEYOR_SUBMITTABLE_STATUSES, true);
}

/**
 * Why this surveyor may not submit this segment, or null if they may.
 * Roads that do not belong to a city audit (audit_id null) keep the
 * old behaviour and are never blocked here.
 */
function surveyorSubmitBlockReason(
    ?int $auditId,
    ?string $auditStatus,
    ?int $assignedTo,
    ?string $assignmentStatus,
    int $userId
): ?string {
    if ($auditId === null) {
        return null;
    }
    if (!in_array((string)$auditStatus, SURVEYOR_WORKABLE_AUDIT_STATUSES, true)) {
        return 'This audit is not open for surveying.';
    }
    if ($assignedTo === null || $assignedTo !== $userId) {
        return 'This segment is not assigned to you.';
    }
    if (!in_array((string)$assignmentStatus, SURVEYOR_SUBMITTABLE_STATUSES, true)) {
        return 'This segment has already been submitted.';
    }
    return null;
}

/**
 * Dashboard totals for a surveyor's assigned city-audit segments.
 * Rows come from SurveyorWorkRepository::forSurveyor().
 *
 * @param list<array<string,mixed>> $rows
 * @return array{roads:int,segments:int,completed:int,in_progress:int}
 */
function surveyorAssignedTotals(array $rows): array
{
    $roads = [];
    $out   = ['roads' => 0, 'segments' => 0, 'completed' => 0, 'in_progress' => 0];
    foreach ($rows as $r) {
        $roads[(int)($r['road_id'] ?? 0)] = true;
        $out['segments']++;
        if (in_array((string)($r['assignment_status'] ?? ''), ['submitted', 'approved'], true)) {
            $out['completed']++;
        }
        if (($r['state_key'] ?? '') === 'in_progress') {
            $out['in_progress']++;
        }
    }
    $out['roads'] = count($roads);
    return $out;
}
