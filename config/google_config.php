<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/google_config.php
//  Google OAuth 2.0 configuration.
//  Uses environment variables for production (Railway).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/constants.php';

// ── Credentials ────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

// ── Redirect URI ───────────────────────────────────────────────
define('GOOGLE_REDIRECT_URI',  getenv('GOOGLE_REDIRECT_URI')  ?: BASE_URL . '/auth/google_callback.php');

// ── Google OAuth endpoints (do not change) ─────────────────────
define('GOOGLE_AUTH_URL',     'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',    'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v3/userinfo');

// ── Scopes ─────────────────────────────────────────────────────
define('GOOGLE_SCOPES', 'openid email profile');

/**
 * Builds and returns the Google OAuth authorization URL.
 */
function getGoogleAuthUrl(): string
{
    startSecureSession();

    $state = bin2hex(random_bytes(24));
    $_SESSION['oauth_state'] = $state;

    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => GOOGLE_SCOPES,
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
}