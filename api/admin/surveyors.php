<?php
declare(strict_types=1);
header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/surveyors.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});
require_once __DIR__ . '/../../config/admin_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.organisation,
            u.profile_picture,
            u.is_active,
            u.last_login,
            u.created_at,
            (SELECT COUNT(*) FROM roads r WHERE r.creator_id = u.id) AS roads_created,
            (SELECT COUNT(*) FROM segment_audits sa WHERE sa.surveyor_id = u.id) AS segments_audited,
            (SELECT MAX(sa2.created_at) FROM segment_audits sa2 WHERE sa2.surveyor_id = u.id) AS last_audit_at
         FROM users u
        WHERE u.role = 'surveyor'
        ORDER BY u.is_active DESC, u.name ASC"
    );
    $surveyors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($surveyors as &$s) {
        $s['id']               = (int)$s['id'];
        $s['roads_created']    = (int)$s['roads_created'];
        $s['segments_audited'] = (int)$s['segments_audited'];
        $s['is_active']        = (bool)$s['is_active'];
        $s['has_profile_picture'] = $s['profile_picture'] !== null && $s['profile_picture'] !== '';
        unset($s['profile_picture']);
    }
    unset($s);
    echo json_encode(['success' => true, 'surveyors' => $surveyors]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $active   = $body['is_active'] ?? null;

    if ($targetId === false || $targetId === null || !is_bool($active)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $check->execute([$targetId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['role'] !== 'surveyor') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Surveyor not found.']);
        exit;
    }

    $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $update->execute([$active ? 1 : 0, $targetId]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);