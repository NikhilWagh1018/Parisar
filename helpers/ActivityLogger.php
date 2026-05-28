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
        // Only trust X-Forwarded-For if the direct connection is from a
        // known Railway/private proxy range — prevents spoofing (Issue 18).
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trustedCidrs = [
            '100.64.0.0/10', '10.0.0.0/8',
            '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
        ];
        $remoteIp = inet_pton($remoteAddr);
        $trusted  = false;
        if ($remoteIp !== false) {
            foreach ($trustedCidrs as $cidr) {
                [$subnet, $bits] = explode('/', $cidr);
                $subnetIp = inet_pton($subnet);
                $mask     = str_repeat("\xff", (int)($bits / 8))
                          . (($bits % 8) ? chr(0xff << (8 - ($bits % 8))) : '')
                          . str_repeat("\x00", strlen($subnetIp) - (int)ceil($bits / 8));
                if (($remoteIp & $mask) === ($subnetIp & $mask)) {
                    $trusted = true;
                    break;
                }
            }
        }
        if ($trusted) {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($fwd !== '') {
                $ip = filter_var(trim(explode(',', $fwd)[0]), FILTER_VALIDATE_IP);
                if ($ip !== false) return $ip;
            }
        }
        return $remoteAddr;
    }
}
