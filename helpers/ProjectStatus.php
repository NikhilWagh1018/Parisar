<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/ProjectStatus.php
//  A project (city audit) the Admin deleted is kept as status 'voided'.
//  This is the one wording shown wherever work on it is refused.
// ═══════════════════════════════════════════════════════════════

/** Shown wherever work is refused because the Admin deleted the project. */
const PROJECT_DELETED_MESSAGE = 'This project was deleted by the Admin. Work on it has stopped.';

/** Is this audit row a deleted project? */
function projectIsDeleted(?array $audit): bool
{
    return $audit !== null && (string)($audit['status'] ?? '') === 'voided';
}
