<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/profile.php
//  GET  → return current user's profile data
//  POST → update profile fields (name, phone, org, gender, age,
//         address) OR change password OR update email
//  All write ops are CSRF-protected.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

// ── Helper ─────────────────────────────────────────────────────
function json_out(bool $ok, array $data = [], string $error = ''): never
{
    if ($ok) {
        echo json_encode(['success' => true] + $data);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
    }
    exit;
}

// ── GET — return profile ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT public_id, name, email, phone, organisation, gender, age,
                address, role, auth_provider, profile_picture, email_verified,
                last_login, created_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$CURRENT_USER_ID]);
    $user = $stmt->fetch();

    if (!$user) {
        json_out(false, error: 'User not found.');
    }

    // Mask email partially for display
    json_out(true, ['user' => $user]);
}

// ── POST — update profile ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, error: 'Method not allowed.');
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? '');

// CSRF check
$csrf = $body['csrf_token'] ?? '';
if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    json_out(false, error: 'Invalid CSRF token.');
}

// ── Action: update_profile ─────────────────────────────────────
if ($action === 'update_profile') {
    $name   = trim($body['name']         ?? '');
    $phone  = trim($body['phone']        ?? '');
    $org    = trim($body['organisation'] ?? '');
    $gender = $body['gender']            ?? null;
    $age    = isset($body['age']) && $body['age'] !== '' ? (int)$body['age'] : null;
    $addr   = trim($body['address']      ?? '');

    if ($name === '') {
        json_out(false, error: 'Name is required.');
    }

    $allowedGenders = ['Male', 'Female', 'Other', null, ''];
    if (!in_array($gender, $allowedGenders, true)) {
        json_out(false, error: 'Invalid gender value.');
    }

    if ($age !== null && ($age < 10 || $age > 120)) {
        json_out(false, error: 'Age must be between 10 and 120.');
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET name = ?, phone = ?, organisation = ?, gender = ?,
             age = ?, address = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $phone ?: null,
        $org   ?: null,
        $gender ?: null,
        $age,
        $addr  ?: null,
        $CURRENT_USER_ID,
    ]);

    // Refresh session name
    $_SESSION['user_name'] = $name;

    json_out(true, ['message' => 'Profile updated successfully.']);
}

// ── Action: update_email ───────────────────────────────────────
if ($action === 'update_email') {
    // Google users cannot change email
    if ($CURRENT_USER_AUTH === 'google') {
        json_out(false, error: 'Google-linked accounts cannot change their email here.');
    }

    $newEmail = trim(filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $password = $body['password'] ?? '';

    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        json_out(false, error: 'Please enter a valid email address.');
    }

    if ($password === '') {
        json_out(false, error: 'Please confirm your current password.');
    }

    // Verify current password
    $stmt = $pdo->prepare('SELECT password, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$CURRENT_USER_ID]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string)$row['password'])) {
        json_out(false, error: 'Incorrect current password.');
    }

    if (strtolower($row['email']) === strtolower($newEmail)) {
        json_out(false, error: 'That is already your current email.');
    }

    // Check uniqueness
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $chk->execute([$newEmail, $CURRENT_USER_ID]);
    if ($chk->fetch()) {
        json_out(false, error: 'That email is already in use by another account.');
    }

    $pdo->prepare('UPDATE users SET email = ?, email_verified = 0 WHERE id = ?')
        ->execute([$newEmail, $CURRENT_USER_ID]);

    $_SESSION['user_email'] = $newEmail;
    json_out(true, ['message' => 'Email updated. Please verify your new address.']);
}

// ── Action: change_password ────────────────────────────────────
if ($action === 'change_password') {
    if ($CURRENT_USER_AUTH === 'google') {
        json_out(false, error: 'Google-linked accounts cannot set a password here.');
    }

    $current = $body['current_password'] ?? '';
    $new     = $body['new_password']     ?? '';
    $confirm = $body['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        json_out(false, error: 'All password fields are required.');
    }

    if (strlen($new) < 8) {
        json_out(false, error: 'New password must be at least 8 characters.');
    }

    if ($new !== $confirm) {
        json_out(false, error: 'New passwords do not match.');
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$CURRENT_USER_ID]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, (string)$row['password'])) {
        json_out(false, error: 'Current password is incorrect.');
    }

    if (password_verify($new, (string)$row['password'])) {
        json_out(false, error: 'New password must be different from your current password.');
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([$hash, $CURRENT_USER_ID]);

    json_out(true, ['message' => 'Password changed successfully.']);
}

// ── Action: upload_avatar (base64) ────────────────────────────
if ($action === 'upload_avatar') {
    $dataUrl  = $body['image'] ?? '';
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!preg_match('/^data:([a-zA-Z\/]+);base64,/', $dataUrl, $m)) {
        json_out(false, error: 'Invalid image format.');
    }

    $mime = $m[1];
    if (!in_array($mime, $allowed, true)) {
        json_out(false, error: 'Only JPEG, PNG, WEBP, and GIF images are allowed.');
    }

    $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bytes  = base64_decode($base64, true);

    if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
        json_out(false, error: 'Image too large. Max size is 2 MB.');
    }

    $ext     = explode('/', $mime)[1] === 'jpeg' ? 'jpg' : explode('/', $mime)[1];
    $dir     = ROOT_PATH . '/assets/avatars/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'avatar_' . $CURRENT_USER_ID . '_' . time() . '.' . $ext;
    file_put_contents($dir . $filename, $bytes);

    $url = BASE_URL . '/assets/avatars/' . $filename;
    $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?')
        ->execute([$url, $CURRENT_USER_ID]);

    $_SESSION['profile_picture'] = $url;
    json_out(true, ['picture_url' => $url, 'message' => 'Profile picture updated.']);
}

json_out(false, error: 'Unknown action.');
