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

// ── Scoring thresholds (SCORE = 100 − penalty, higher is better) ──
//  Condition bands match the PDF (penalty 0–100 scale):
//    penalty  0–20  → score 80–100 → Good
//    penalty 20–40  → score 60–80  → OK
//    penalty 40–60  → score 40–60  → Poor
//    penalty 60–80  → score 20–40  → Bad
//    penalty 80–100 → score  0–20  → Very Bad
//
//  These constants are the SCORE (not penalty) lower-bound for each band:
define('SCORE_GOOD',      80);   // score ≥ 80
define('SCORE_OK',        60);   // score ≥ 60
define('SCORE_POOR',      40);   // score ≥ 40
define('SCORE_BAD',       20);   // score ≥ 20
// score < 20 → Very Bad

// ── Legacy alias (keeps old code that referenced SCORE_MODERATE working) ──
define('SCORE_MODERATE', 60);

// ── Pagination ────────────────────────────────────────────────
define('PER_PAGE', 20);

// ── File paths ────────────────────────────────────────────────
define('ROOT_PATH',     dirname(__DIR__));
define('SERVICES_PATH', ROOT_PATH . '/services');
define('REPORTS_PATH',  ROOT_PATH . '/reports');
