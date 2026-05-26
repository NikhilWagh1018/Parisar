<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/logout.php
//  Destroys the session cleanly for both local and Google users.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

// Clear all session variables
$_SESSION = [];

// Expire the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: ../auth/login.php');
exit;