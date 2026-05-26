<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/BaseController.php
//  All API endpoints extend this class.
//  Provides: CSRF check, auth check, JSON helpers, body parsing.
// ═══════════════════════════════════════════════════════════════

abstract class BaseController
{
    protected PDO $pdo;
    protected int $userId;
    protected string $userRole;

    public function __construct(PDO $pdo, int $userId, string $userRole = 'surveyor')
    {
        $this->pdo      = $pdo;
        $this->userId   = $userId;
        $this->userRole = $userRole;

        header('Content-Type: application/json');
    }

    // ── CSRF ──────────────────────────────────────────────────

    protected function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $this->abort(403, 'Invalid CSRF token.');
        }
    }

    // ── Method guard ──────────────────────────────────────────

    protected function requireMethod(string $method): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
            $this->abort(405, 'Method not allowed.');
        }
    }

    // ── JSON body ─────────────────────────────────────────────

    /**
     * Parse and return the JSON request body as an array.
     * Aborts with 400 if body is missing or invalid JSON.
     *
     * @return array<string,mixed>
     */
    protected function jsonBody(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (!is_array($data)) {
            $this->abort(400, 'Invalid or missing JSON body.');
        }

        return $data;
    }

    // ── Responses ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     */
    protected function ok(array $data = []): never
    {
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function fail(int $status, string $error, array $data = []): never
    {
        http_response_code($status);
        echo json_encode(array_merge(['success' => false, 'error' => $error], $data));
        exit;
    }

    protected function abort(int $status, string $message): never
    {
        $this->fail($status, $message);
    }

    // ── Role / permission helpers ─────────────────────────────

    protected function requireRole(string ...$roles): void
    {
        if (!in_array($this->userRole, $roles, true)) {
            $this->abort(403, 'Insufficient permissions.');
        }
    }
}
