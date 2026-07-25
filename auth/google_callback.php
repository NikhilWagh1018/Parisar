<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/google_callback.php
//  Handles the OAuth 2.0 redirect back from Google.
//  Flow:
//    1. Verify CSRF state token
//    2. Exchange authorization code for access token
//    3. Fetch user info from Google
//    4. Upsert user in parisar_db → users
//    5. Create authenticated session → redirect to dashboard
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_config.php';

// ── 1. CSRF / state verification ──────────────────────────────
$state = $_GET['state'] ?? '';
if (
    empty($_SESSION['oauth_state'])
    || !hash_equals($_SESSION['oauth_state'], $state)
) {
    session_regenerate_id(true);
    header('Location: login.php?error=state_mismatch');
    exit;
}
unset($_SESSION['oauth_state']);

// ── 2. Authorization code ──────────────────────────────────────
$code = $_GET['code'] ?? '';
if ($code === '') {
    header('Location: login.php?error=no_code');
    exit;
}

// ── 3. Exchange code for access token ─────────────────────────
$tokenResponse = oauthPost(GOOGLE_TOKEN_URL, [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if ($tokenResponse === null || !isset($tokenResponse['access_token'])) {
    error_log('Google OAuth token error: ' . json_encode($tokenResponse));
    header('Location: login.php?error=token_failed');
    exit;
}

// ── 4. Fetch user info ─────────────────────────────────────────
$userInfo = oauthGet(GOOGLE_USERINFO_URL, $tokenResponse['access_token']);

if ($userInfo === null || !isset($userInfo['email'])) {
    header('Location: login.php?error=userinfo_failed');
    exit;
}

if (!($userInfo['email_verified'] ?? false)) {
    header('Location: login.php?error=email_not_verified');
    exit;
}

$googleId       = (string)$userInfo['sub'];
$email          = (string)filter_var($userInfo['email'], FILTER_SANITIZE_EMAIL);
$name           = sanitizeOAuthName((string)($userInfo['name'] ?? ''), $email);
$profilePicture = $userInfo['picture'] ?? null;

// ── 5. Upsert user ─────────────────────────────────────────────
// Look up by google_id first, then fall back to email (link existing account)
$stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
$stmt->execute([$googleId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user === false) {
    // Try matching by email — existing local account → link Google ID
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user !== false) {
        // Link the Google identity to the existing local account.
        // Only use Google's picture if the user hasn't set a custom avatar.
        $hasCustomAvatar = str_starts_with((string)($user['profile_picture'] ?? ''), 'data:');
        $picToUse = $hasCustomAvatar ? $user['profile_picture'] : $profilePicture;

        $pdo->prepare(
            'UPDATE users
             SET google_id = ?, profile_picture = ?, auth_provider = \'google\',
                 email_verified = 1, last_login = NOW()
             WHERE id = ?'
        )->execute([$googleId, $picToUse, $user['id']]);

        $user['google_id']       = $googleId;
        $user['profile_picture'] = $picToUse;
    } else {
        // Brand-new Google user — auto-register as surveyor
        $pdo->prepare(
            'INSERT INTO users
               (name, email, google_id, profile_picture, auth_provider,
                email_verified, role, last_login)
             VALUES (?, ?, ?, ?, \'google\', 1, \'surveyor\', NOW())'
        )->execute([$name, $email, $googleId, $profilePicture]);

        $newId = (int)$pdo->lastInsertId();

        // FIX: Generate public_id in PHP (no trigger)
        $newPublicId = 'SURV-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE users SET public_id = ? WHERE id = ?')
            ->execute([$newPublicId, $newId]);

        $stmt  = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$newId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} else {
    // Known Google user — only overwrite picture if user hasn't set a custom avatar
    $hasCustomAvatar = str_starts_with((string)($user['profile_picture'] ?? ''), 'data:');
    $picToUse = $hasCustomAvatar ? $user['profile_picture'] : $profilePicture;

    $pdo->prepare(
        'UPDATE users SET profile_picture = ?, last_login = NOW() WHERE id = ?'
    )->execute([$picToUse, $user['id']]);

    $user['profile_picture'] = $picToUse;
}

// ── 6. Create authenticated session ───────────────────────────
if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
    header('Location: login.php?error=account_disabled');
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id']         = $user['id'];
$_SESSION['user_name']       = $user['name'];
$_SESSION['user_email']      = $user['email'];
$_SESSION['user_role']       = $user['role'] ?? 'surveyor';
$_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
$_SESSION['auth_provider']   = 'google';

// Generate CSRF token for this session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Location: ../pages/dashboard.php');
exit;

// ── Helpers ───────────────────────────────────────────────────

/**
 * Sanitizes a display name pulled from an external identity provider
 * (Google) so it follows the same character rules enforced on manual
 * signups in auth/register.php — letters, spaces, hyphens, apostrophes,
 * and periods only. Manual signup validates and rejects bad input; OAuth
 * signup has no form to reject on, so instead we clean the name and fall
 * back to the email's local-part if nothing usable remains, rather than
 * silently storing whatever punctuation/garbage the provider returns.
 *
 * @param string $rawName  Raw 'name' field from the provider's userinfo response.
 * @param string $email    The account email, used for the fallback.
 * @return string
 */
function sanitizeOAuthName(string $rawName, string $email): string
{
    $rawName = strip_tags($rawName);

    // Drop anything outside the allowed character set, collapse repeated
    // whitespace, then trim stray leading/trailing punctuation (this is
    // what catches cases like a Google profile name of ",Mithilesh").
    $clean = preg_replace('/[^A-Za-z\s\'\-\.]/', '', $rawName) ?? '';
    $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
    $clean = trim($clean, " \t\n\r\0\x0B'-.");

    if ($clean === '' || mb_strlen($clean) < 2) {
        // Fall back to the email's local-part, e.g. "jane.doe" -> "Jane Doe".
        $local = explode('@', $email)[0] ?? '';
        $local = preg_replace('/[^A-Za-z]+/', ' ', $local) ?? '';
        $local = trim($local);
        $clean = $local !== '' ? ucwords(strtolower($local)) : 'User';
    }

    return mb_substr($clean, 0, 80);
}

/**
 * POST request via cURL, returns decoded JSON array or null on failure.
 *
 * @param string               $url
 * @param array<string,string> $data
 * @return array<string,mixed>|null
 */
function oauthPost(string $url, array $data): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return is_string($raw) ? json_decode($raw, true) : null;
}

/**
 * GET request via cURL with Bearer token, returns decoded JSON array or null.
 *
 * @param string $url
 * @param string $token
 * @return array<string,mixed>|null
 */
function oauthGet(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return is_string($raw) ? json_decode($raw, true) : null;
}
