<?php
declare(strict_types=1);

// ===============================================================
//  helpers/RoadCatalogue.php
//  Rules for a city's road list (the `road_groups` rows a City
//  Leader keeps and an audit picks from). Pure functions, no DB.
// ===============================================================

/**
 * Why a road cannot be deleted, or null when it can.
 * A road that an audit uses holds that audit's segments and surveys, so
 * it must first be removed from the audit; deleting it here would wipe
 * that work.
 */
function roadDeleteBlockedReason(int $entryCount): ?string
{
    if ($entryCount > 0) {
        return 'This road is used in an audit. Remove it from the audit first.';
    }
    return null;
}
