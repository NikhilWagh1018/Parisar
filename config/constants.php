<?php
declare(strict_types=1);

// ── Load phpdotenv for local dev (vendor/ only exists locally) ──
$_vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($_vendorAutoload)) {
    require_once $_vendorAutoload;
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
}
unset($_vendorAutoload);

// ── Global error handler ──────────────────────────────────────
require_once __DIR__ . '/error_handler.php';
registerErrorHandlers();

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

// ── Secure session bootstrap ───────────────────────────────────
/**
 * Call this instead of bare session_start() in every auth file
 * and auth_guard.php. Enforces cookie security in PHP itself,
 * giving defence-in-depth on top of the Dockerfile php.ini flags.
 * Safe to call multiple times — exits early if already active.
 */
function startSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Railway terminates TLS at the proxy and forwards the scheme
    // via X-Forwarded-Proto. Check both to detect HTTPS correctly.
    $isHttps = (
        ($_SERVER['HTTPS']                    ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',        // current domain only
        'secure'   => $isHttps,  // HTTPS-only cookie on Railway
        'httponly' => true,      // JS cannot read session cookie
        'samesite' => 'Strict',  // blocks cross-site cookie sending
    ]);

    session_start();
}

/**
 * Call after startSecureSession() on every protected page/API.
 * Destroys the session and sends the user back to login if they
 * have been inactive longer than SESSION_LIFETIME seconds.
 */
function enforceSessionTimeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return; // not logged in — auth_guard.php handles the redirect
    }

    $lastActivity = (int)($_SESSION['_last_activity'] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();

        $isApi = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
              || str_contains($_SERVER['REQUEST_URI']  ?? '', '/api/');

        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        } else {
            header('Location: /auth/login.php?expired=1');
        }
        exit;
    }

    $_SESSION['_last_activity'] = time();
}