<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/rate_limit.php
//  IP + action-based rate limiting (generalized from login-only).
//
//  Rules:
//    · 5 failed attempts within 15 minutes for a given action → 15-minute lockout
//    · Lockout doubles on each subsequent block (15 → 30 → 60 min), capped at 24h
//    · Successful attempt clears the record for that IP + action
//    · Old rows pruned on every check (no cron needed)
//
//  Each protected endpoint calls these with its own $action string, e.g.
//  'login', 'register', 'password_reset', 'survey_submit'. Attempts are
//  tracked independently per (ip_address, action) pair, so hammering
//  /register doesn't lock you out of /login and vice versa.
// ═══════════════════════════════════════════════════════════════

const RL_MAX_ATTEMPTS  = 5;
const RL_WINDOW_SEC    = 15 * 60;   // 15-minute sliding window
const RL_BASE_LOCK_SEC = 15 * 60;   // first lockout = 15 min

/**
 * Call at the top of the POST handler, before doing the sensitive work
 * (auth check, account creation, sending a reset email, etc.).
 *
 * Returns an array:
 *   ['allowed' => true]
 *   ['allowed' => false, 'retry_after' => int seconds, 'message' => string]
 *
 * @param PDO    $pdo
 * @param string $ip     Pass in getClientIp(), not raw $_SERVER['REMOTE_ADDR']
 * @param string $action A short identifier for the endpoint, e.g. 'login',
 *                        'register', 'password_reset'. Keep it stable —
 *                        changing it resets that endpoint's counters.
 */
function checkRateLimit(PDO $pdo, string $ip, string $action): array
{
    _pruneOldAttempts($pdo);

    $row = _getRecord($pdo, $ip, $action);

    // ── Currently locked out? ────────────────────────────────────
    if ($row && $row['locked_until'] !== null) {
        $remaining = strtotime($row['locked_until']) - time();
        if ($remaining > 0) {
            $mins = (int)ceil($remaining / 60);
            return [
                'allowed'     => false,
                'retry_after' => $remaining,
                'message'     => "Too many attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.',
            ];
        }
        // Lockout expired — clear it so we start a fresh window
        _clearRecord($pdo, $ip, $action);
    }

    return ['allowed' => true];
}

/**
 * Call after a FAILED attempt for the given action.
 * Increments the counter; locks the IP+action if the threshold is crossed.
 */
function recordFailedAttempt(PDO $pdo, string $ip, string $action): void
{
    $row = _getRecord($pdo, $ip, $action);

    if ($row === null) {
        // First failure for this IP + action
        $pdo->prepare(
            'INSERT INTO rate_limit_attempts (ip_address, action, attempts, last_attempt_at)
             VALUES (?, ?, 1, NOW())'
        )->execute([$ip, $action]);
        return;
    }

    $newAttempts = (int)$row['attempts'] + 1;

    if ($newAttempts >= RL_MAX_ATTEMPTS) {
        // Calculate lockout duration — doubles each time they've been locked
        $lockouts    = (int)($row['lockout_count'] ?? 0) + 1;
        $lockSec     = RL_BASE_LOCK_SEC * (int)pow(2, $lockouts - 1);
        $lockSec     = min($lockSec, 60 * 60 * 24); // cap at 24 hours
        $lockedUntil = date('Y-m-d H:i:s', time() + $lockSec);

        $pdo->prepare(
            'UPDATE rate_limit_attempts
             SET attempts = ?, last_attempt_at = NOW(),
                 locked_until = ?, lockout_count = ?
             WHERE ip_address = ? AND action = ?'
        )->execute([$newAttempts, $lockedUntil, $lockouts, $ip, $action]);
    } else {
        $pdo->prepare(
            'UPDATE rate_limit_attempts
             SET attempts = ?, last_attempt_at = NOW()
             WHERE ip_address = ? AND action = ?'
        )->execute([$newAttempts, $ip, $action]);
    }
}

/**
 * Call after a SUCCESSFUL attempt (e.g. successful login, completed
 * registration). Wipes the record so a legitimate user starts fresh.
 */
function clearRateLimitAttempts(PDO $pdo, string $ip, string $action): void
{
    _clearRecord($pdo, $ip, $action);
}

/**
 * Returns the number of remaining attempts before lockout for this
 * IP + action. Use this to show a warning on a form ("2 attempts remaining").
 */
function remainingAttempts(PDO $pdo, string $ip, string $action): int
{
    $row = _getRecord($pdo, $ip, $action);
    if ($row === null) {
        return RL_MAX_ATTEMPTS;
    }
    return max(0, RL_MAX_ATTEMPTS - (int)$row['attempts']);
}

// ── Private helpers ──────────────────────────────────────────────

function _getRecord(PDO $pdo, string $ip, string $action): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM rate_limit_attempts WHERE ip_address = ? AND action = ? LIMIT 1'
    );
    $stmt->execute([$ip, $action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function _clearRecord(PDO $pdo, string $ip, string $action): void
{
    $pdo->prepare('DELETE FROM rate_limit_attempts WHERE ip_address = ? AND action = ?')
        ->execute([$ip, $action]);
}

function _pruneOldAttempts(PDO $pdo): void
{
    // Remove rows with no active lockout whose last attempt is outside the window
    $pdo->prepare(
        'DELETE FROM rate_limit_attempts
         WHERE locked_until IS NULL
           AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
    )->execute([RL_WINDOW_SEC]);

    // Also remove rows where the lockout has fully expired
    $pdo->prepare(
        'DELETE FROM rate_limit_attempts
         WHERE locked_until IS NOT NULL
           AND locked_until < NOW()'
    )->execute([]);
}

/**
 * Resolves the real client IP.
 *
 * X-Forwarded-For is only trusted when the direct connection (REMOTE_ADDR)
 * comes from a known Railway/private proxy range — prevents attackers from
 * spoofing the header to bypass IP-based rate limiting (Issue 18).
 *
 * Railway proxy ranges: 100.64.0.0/10 (CGNAT) + RFC-1918 private ranges.
 * On local XAMPP, REMOTE_ADDR is 127.0.0.1 — also trusted for dev.
 *
 * Cloudflare note: the site is served through Cloudflare in front of
 * Railway. REMOTE_ADDR at the app layer is a Cloudflare edge IP, which
 * varies request-to-request even for the same visitor — it must never be
 * used directly for rate limiting. When the connection comes from a known
 * Cloudflare range, we prefer the CF-Connecting-IP header, which Cloudflare
 * sets to the real visitor IP and which the visitor cannot spoof (Cloudflare
 * overwrites any client-supplied value for this header at its edge).
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Trusted proxy subnets: Cloudflare edge ranges + Railway CGNAT +
    // private ranges + localhost
    $trustedProxyCidrs = [
        // Cloudflare IPv4 ranges (see https://www.cloudflare.com/ips-v4/)
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '100.64.0.0/10',  // Railway CGNAT range
        '10.0.0.0/8',     // RFC-1918
        '172.16.0.0/12',  // RFC-1918
        '192.168.0.0/16', // RFC-1918
        '127.0.0.0/8',    // localhost / XAMPP dev
    ];

    $isTrustedProxy = false;
    $remoteIp = inet_pton($remoteAddr);
    if ($remoteIp !== false) {
        foreach ($trustedProxyCidrs as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $subnetIp  = inet_pton($subnet);
            $mask      = str_repeat("\xff", (int)($bits / 8))
                       . (($bits % 8) ? chr(0xff << (8 - ($bits % 8))) : '')
                       . str_repeat("\x00", strlen($subnetIp) - (int)ceil($bits / 8));
            if (($remoteIp & $mask) === ($subnetIp & $mask)) {
                $isTrustedProxy = true;
                break;
            }
        }
    }

    if ($isTrustedProxy) {
        // Prefer CF-Connecting-IP: Cloudflare sets this to the real visitor
        // IP and strips/overwrites any value a client tries to send for it,
        // so it can't be spoofed the way X-Forwarded-For sometimes can.
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cfIp !== '') {
            $ip = filter_var($cfIp, FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For is a comma-separated list — leftmost is the client
            $ips = array_map('trim', explode(',', $forwarded));
            $ip  = filter_var($ips[0], FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
    }

    return $remoteAddr;
}
