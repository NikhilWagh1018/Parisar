<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/auth_guard.php
//  require_once this at the TOP of EVERY protected page or API.
//  • Browser requests  → redirect to login page
//  • JSON/XHR requests → 401 JSON response
//
//  Also exposes:
//    $CURRENT_USER_ID   (int)
//    $CURRENT_USER_NAME (string)
//    $CURRENT_USER_ROLE (string)
//    $CURRENT_USER_PIC  (string|null)
//    $CURRENT_USER_AUTH (string)
//    $_SESSION['csrf_token'] — generated on first use
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db.php';

startSecureSession();
enforceSessionTimeout();

// ── Content-Security-Policy ────────────────────────────────────
// Allow Google's CDN for profile pictures on all protected pages.
$nonce = base64_encode(random_bytes(16));
$_SESSION['csp_nonce'] = $nonce;
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$nonce}'; " .
    "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data: https://lh3.googleusercontent.com https://*.googleusercontent.com; " .
    "connect-src 'self';"
);

if (!isset($_SESSION['user_id'])) {
    $wantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']        ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']    ?? '', 'application/json')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    } else {
        // Compute redirect depth from the calling script's location
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}auth/login.php");
    }
    exit;
}

// ── Ensure a CSRF token exists for this session ────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Convenience variables available in every protected file ───
$CURRENT_USER_ID   = (int)$_SESSION['user_id'];
$CURRENT_USER_NAME = (string)($_SESSION['user_name']       ?? '');
$CURRENT_USER_ROLE = (string)($_SESSION['user_role']       ?? 'surveyor');
$CURRENT_USER_AUTH = (string)($_SESSION['auth_provider']   ?? 'local');

// Belt-and-suspenders: if the session profile_picture is missing, always
// re-fetch it from the DB (covers local users whose Google pic was linked,
// session loss after session_regenerate_id, etc.) and restore for next request.
$CURRENT_USER_PIC = $_SESSION['profile_picture'] ?? null;
if ($CURRENT_USER_PIC === null) {
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$CURRENT_USER_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['profile_picture'])) {
        $CURRENT_USER_PIC            = $row['profile_picture'];
        $_SESSION['profile_picture'] = $CURRENT_USER_PIC; // restore for next request
    }
}
