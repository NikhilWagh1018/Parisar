<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/constants.php
//  All application-wide constants in one place.
//  Uses environment variables for production (Railway).
//  Falls back to localhost defaults for local XAMPP dev.
// ═══════════════════════════════════════════════════════════════

// ── Database ──────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'parisar_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ── Application ───────────────────────────────────────────────
define('BASE_URL',  getenv('BASE_URL') ?: 'http://localhost/Parisar');
define('APP_NAME',  'CycleAudit');
define('APP_ORG',   'Parisar');

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 60 * 60 * 8);   // 8 hours in seconds
define('SESSION_NAME',     'ca_session');

// ── Scoring thresholds (SCORE: 0=best, 100=worst — matches PDF & Excel) ──
//  Condition bands per the Cycle Track Audit Report 2025:
//    0–20   → Good
//    20–40  → OK
//    40–60  → Poor
//    60–80  → Bad
//    80–100 → Very Bad
//
//  These constants are the score UPPER-BOUND for each band:
define('SCORE_GOOD',      20);   // score ≤ 20 → Good
define('SCORE_OK',        40);   // score ≤ 40 → OK
define('SCORE_POOR',      60);   // score ≤ 60 → Poor
define('SCORE_BAD',       80);   // score ≤ 80 → Bad
// score > 80 → Very Bad

// ── Legacy alias ──────────────────────────────────────────────
define('SCORE_MODERATE', 40);

// ── Pagination ────────────────────────────────────────────────
define('PER_PAGE', 20);

// ── File paths ────────────────────────────────────────────────
define('ROOT_PATH',     dirname(__DIR__));
define('SERVICES_PATH', ROOT_PATH . '/services');
define('REPORTS_PATH',  ROOT_PATH . '/reports');
