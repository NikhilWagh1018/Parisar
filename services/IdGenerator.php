<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  services/IdGenerator.php
//  Formats a numeric surrogate key into a human-readable public ID.
//
//  Usage:
//    generatePublicId('SURV', 1)   → 'SURV-0001'
//    generatePublicId('ROAD', 42)  → 'ROAD-0042'
//    generatePublicId('SA',   999) → 'SA-0999'
//
//  Note: The database AFTER INSERT triggers call the same logic
//  automatically, so PHP code only needs this function when it
//  wants to DISPLAY or PREDICT an ID before querying the DB.
// ═══════════════════════════════════════════════════════════════

/**
 * Formats a surrogate integer as a zero-padded public ID string.
 *
 * @param string $prefix  e.g. 'SURV', 'ROAD', 'AUD', 'SEG', 'SA', 'OBS', 'INT'
 * @param int    $n       The AUTO_INCREMENT id value (must be >= 1)
 * @param int    $pad     Minimum digit width — default 4 (matches trigger LPAD)
 *
 * @return string  e.g. 'SURV-0001'
 *
 * @throws \InvalidArgumentException if $n < 1 or $prefix is empty
 */
function generatePublicId(string $prefix, int $n, int $pad = 4): string
{
    if ($prefix === '') {
        throw new \InvalidArgumentException('generatePublicId: prefix must not be empty.');
    }
    if ($n < 1) {
        throw new \InvalidArgumentException(
            "generatePublicId: n must be >= 1, got {$n}."
        );
    }

    return strtoupper($prefix) . '-' . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}