<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/ActivityLogger.php
//  Writes rows to the activity_log table.
//  Fails silently — never breaks the main request.
// ═══════════════════════════════════════════════════════════════

class ActivityLogger
{
    // ── Action constants ──────────────────────────────────────
    public const SEGMENT_SUBMITTED = 'segment_submitted';
    public const SEGMENT_EDITED    = 'segment_edited';
    public const SESSION_STARTED   = 'session_started';
    public const SESSION_CLOSED    = 'session_closed';
    public const USER_LOGIN        = 'user_login';
    public const USER_LOGOUT       = 'user_logout';
    public const USER_REGISTERED   = 'user_registered';
    public const ROAD_CREATED      = 'road_created';
    public const ROAD_DELETED      = 'road_deleted';

    /**
     * Log an activity.
     *
     * @param PDO                  $pdo
     * @param string               $action   One of the class constants above
     * @param int|null             $userId   Authenticated user (null = anonymous)
     * @param array<string,mixed>  $meta     Any extra data to store as JSON
     */
    public static function log(
        PDO    $pdo,
        string $action,
        ?int   $userId = null,
        array  $meta   = []
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO activity_log (user_id, action, meta, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $userId,
                $action,
                empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::clientIp(),
            ]);
        } catch (Throwable $e) {
            // Never crash the main request — just log to file
            if (function_exists('appLog')) {
                appLog('warning', 'ActivityLogger::log failed: ' . $e->getMessage(), [
                    'action' => $action,
                    'userId' => $userId,
                ]);
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────

    private static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val !== '') {
                // X-Forwarded-For can be a comma-separated list; take the first
                return trim(explode(',', $val)[0]);
            }
        }
        return '0.0.0.0';
    }
}
