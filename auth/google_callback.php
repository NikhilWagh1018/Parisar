<?php
declare(strict_types=1);

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  auth/google_callback.php
//  Handles the OAuth 2.0 redirect back from Google.
//  Flow:
//    1. Verify CSRF state token
//    2. Exchange authorization code for access token
//    3. Fetch user info from Google
//    4. Upsert user in parisar_db â†’ users
//    5. Create authenticated session â†’ redirect to dashboard
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_config.php';

// â”€â”€ 1. CSRF / state verification â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ 2. Authorization code â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$code = $_GET['code'] ?? '';
if ($code === '') {
    header('Location: login.php?error=no_code');
    exit;
}

// â”€â”€ 3. Exchange code for access token â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ 4. Fetch user info â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
$name           = htmlspecialchars(strip_tags((string)($userInfo['name'] ?? '')), ENT_QUOTES, 'UTF-8');
$profilePicture = $userInfo['picture'] ?? null;

// â”€â”€ 5. Upsert user â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Look up by google_id first, then fall back to email (link existing account)
$stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
$stmt->execute([$googleId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user === false) {
    // Try matching by email â€” existing local account â†’ link Google ID
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
        // Brand-new Google user â€” auto-register as surveyor
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
    // Known Google user â€” only overwrite picture if user hasn't set a custom avatar
    $hasCustomAvatar = str_starts_with((string)($user['profile_picture'] ?? ''), 'data:');
    $picToUse = $hasCustomAvatar ? $user['profile_picture'] : $profilePicture;

    $pdo->prepare(
        'UPDATE users SET profile_picture = ?, last_login = NOW() WHERE id = ?'
    )->execute([$picToUse, $user['id']]);

    $user['profile_picture'] = $picToUse;
}

// â”€â”€ 6. Create authenticated session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
