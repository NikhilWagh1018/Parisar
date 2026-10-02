<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityApi.php
//  Shared preamble for the City Leader write APIs (api/city/*.php).
//  Call it AFTER config/admin_guard.php, passing the guard's variables.
//  Exits with a JSON error unless the request is a valid POST from a
//  City Leader that has a city. Returns the decoded JSON body.
// ═══════════════════════════════════════════════════════════════

function cityApiFail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message] + $extra);
    exit;
}

/** @return array<string,mixed> */
function cityApiContext(string $role, ?int $cityId): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cityApiFail(405, 'Method not allowed.');
    }
    if ($role !== 'city_admin') {
        cityApiFail(403, 'Only a City Leader can change audits.');
    }
    if ($cityId === null || $cityId <= 0) {
        cityApiFail(403, 'No city is assigned to your account.');
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        cityApiFail(403, 'Invalid CSRF token.');
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        cityApiFail(400, 'Invalid request.');
    }
    return $body;
}
