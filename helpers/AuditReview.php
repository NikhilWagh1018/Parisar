<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/AuditReview.php
//  Rules for the City Leader's review loop and for closing an audit.
//  Pure functions (no DB) so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

/** Audit statuses in which the City Leader can still approve / send back segments. */
const AUDIT_REVIEW_OPEN_STATUSES = ['active', 'in_review'];

/** Longest note a City Leader can attach when sending a segment back. */
const AUDIT_REVIEW_NOTE_MAX = 500;

/**
 * Clean the note that goes with "send back for re-audit". A note is required
 * so the surveyor knows what to fix.
 *
 * @return array{note:string, error:?string}
 */
function auditReviewCleanNote(mixed $raw): array
{
    $note = trim(is_scalar($raw) ? (string)$raw : '');
    if ($note === '') {
        return ['note' => '', 'error' => 'Tell the surveyor what needs to be fixed.'];
    }
    if (mb_strlen($note) > AUDIT_REVIEW_NOTE_MAX) {
        return ['note' => $note, 'error' => 'Keep the note under ' . AUDIT_REVIEW_NOTE_MAX . ' characters.'];
    }
    return ['note' => $note, 'error' => null];
}

/**
 * Counts per assignment status for the audit's segments.
 * $statuses holds one entry per segment: the assignment status, or null when
 * the segment has no assignment.
 *
 * @param list<?string> $statuses
 * @return array{total:int,unassigned:int,assigned:int,submitted:int,needs_revisit:int,approved:int}
 */
function auditReviewCounts(array $statuses): array
{
    $out = ['total' => 0, 'unassigned' => 0, 'assigned' => 0, 'submitted' => 0, 'needs_revisit' => 0, 'approved' => 0];
    foreach ($statuses as $s) {
        $out['total']++;
        if ($s === null) {
            $out['unassigned']++;
        } elseif (isset($out[$s])) {
            $out[$s]++;
        }
    }
    return $out;
}

/** The audit can be closed only when it has segments and every one is approved. */
function auditReviewCanClose(string $auditStatus, array $counts): bool
{
    return in_array($auditStatus, AUDIT_REVIEW_OPEN_STATUSES, true)
        && $counts['total'] > 0
        && $counts['approved'] === $counts['total'];
}

/** Why the audit cannot be closed yet (shown next to the disabled button), or null. */
function auditReviewCloseBlockReason(string $auditStatus, array $counts): ?string
{
    if (!in_array($auditStatus, AUDIT_REVIEW_OPEN_STATUSES, true)) {
        return 'Only an active audit can be closed.';
    }
    if ($counts['total'] === 0) {
        return 'This audit has no segments.';
    }
    $left = $counts['total'] - $counts['approved'];
    if ($left > 0) {
        return $left . ($left === 1 ? ' segment is' : ' segments are') . ' not approved yet.';
    }
    return null;
}
