<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/admin_guard.php
//  require_once this at the TOP of any admin-only page or API,
//  INSTEAD of auth_guard.php (it requires auth_guard.php itself,
//  so login/session checks still happen first).
//  • Not logged in     → handled by auth_guard.php (redirect / 401)
//  • Logged in, not admin → redirect to dashboard / 403 JSON
//  • Admits BOTH national_admin and city_admin. Pages/APIs that are
//    national-only (no city-scoped equivalent — e.g. managing the
//    `cities` list itself) must additionally check
//    $CURRENT_USER_ROLE === 'national_admin' themselves after this
//    guard passes. Endpoints that support both roles are expected to
//    filter their own results/actions by $CURRENT_USER_CITY_ID when
//    $CURRENT_USER_ROLE === 'city_admin'.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/permissions.php';

if (!isAnyAdmin($CURRENT_USER_ROLE)) {
    $wantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']         ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']     ?? '', 'application/json')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admins only.']);
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}pages/dashboard.php");
    }
    exit;
}
