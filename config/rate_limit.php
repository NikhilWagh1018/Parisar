<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/rate_limit.php
//  IP-based login rate limiting.
//
//  Rules:
//    · 5 failed attempts within 15 minutes  → 15-minute lockout
//    · Lockout doubles on each subsequent block (15 → 30 → 60 min)
//    · Successful login clears the record for that IP
//    · Old rows pruned on every check (no cron needed)
// ═══════════════════════════════════════════════════════════════

const RL_MAX_ATTEMPTS  = 5;
const RL_WINDOW_SEC    = 15 * 60;   // 15-minute sliding window
const RL_BASE_LOCK_SEC = 15 * 60;   // first lockout = 15 min

/**
 * Call at the top of the POST handler, before touching the DB for auth.
 * Returns an array:
 *   ['allowed' => true]
 *   ['allowed' => false, 'retry_after' => int seconds, 'message' => string]
 *
 * @param PDO    $pdo
 * @param string $ip   Pass in $_SERVER['REMOTE_ADDR'] (or forwarded IP)
 */
function checkLoginRateLimit(PDO $pdo, string $ip): array
{
    _pruneOldAttempts($pdo);

    $row = _getRecord($pdo, $ip);

    // ── Currently locked out? ──────────────────────────────────
    if ($row && $row['locked_until'] !== null) {
        $remaining = strtotime($row['locked_until']) - time();
        if ($remaining > 0) {
            $mins = (int)ceil($remaining / 60);
            return [
                'allowed'     => false,
                'retry_after' => $remaining,
                'message'     => "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.',
            ];
        }
        // Lockout expired — clear it so we start a fresh window
        _clearRecord($pdo, $ip);
    }

    return ['allowed' => true];
}

/**
 * Call after a FAILED login attempt.
 * Increments the counter; locks the IP if the threshold is crossed.
 */
function recordFailedAttempt(PDO $pdo, string $ip): void
{
    $row = _getRecord($pdo, $ip);

    if ($row === null) {
        // First failure for this IP
        $pdo->prepare(
            'INSERT INTO login_attempts (ip_address, attempts, last_attempt_at)
             VALUES (?, 1, NOW())'
        )->execute([$ip]);
        return;
    }

    $newAttempts = (int)$row['attempts'] + 1;

    if ($newAttempts >= RL_MAX_ATTEMPTS) {
        // Calculate lockout duration — doubles each time they've been locked
        $lockouts     = (int)($row['lockout_count'] ?? 0) + 1;
        $lockSec      = RL_BASE_LOCK_SEC * (int)pow(2, $lockouts - 1);
        $lockSec      = min($lockSec, 60 * 60 * 24); // cap at 24 hours
        $lockedUntil  = date('Y-m-d H:i:s', time() + $lockSec);

        $pdo->prepare(
            'UPDATE login_attempts
             SET attempts = ?, last_attempt_at = NOW(),
                 locked_until = ?, lockout_count = ?
             WHERE ip_address = ?'
        )->execute([$newAttempts, $lockedUntil, $lockouts, $ip]);
    } else {
        $pdo->prepare(
            'UPDATE login_attempts
             SET attempts = ?, last_attempt_at = NOW()
             WHERE ip_address = ?'
        )->execute([$newAttempts, $ip]);
    }
}

/**
 * Call after a SUCCESSFUL login.
 * Wipes the record so a legitimate user starts fresh next time.
 */
function clearLoginAttempts(PDO $pdo, string $ip): void
{
    _clearRecord($pdo, $ip);
}

/**
 * Returns the number of remaining attempts before lockout.
 * Use this to show a warning on the login form ("2 attempts remaining").
 */
function remainingAttempts(PDO $pdo, string $ip): int
{
    $row = _getRecord($pdo, $ip);
    if ($row === null) {
        return RL_MAX_ATTEMPTS;
    }
    return max(0, RL_MAX_ATTEMPTS - (int)$row['attempts']);
}

// ── Private helpers ───────────────────────────────────────────

function _getRecord(PDO $pdo, string $ip): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM login_attempts WHERE ip_address = ? LIMIT 1'
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function _clearRecord(PDO $pdo, string $ip): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?')
        ->execute([$ip]);
}

function _pruneOldAttempts(PDO $pdo): void
{
    // Remove rows with no active lockout whose last attempt is outside the window
    $pdo->prepare(
        'DELETE FROM login_attempts
         WHERE locked_until IS NULL
           AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
    )->execute([RL_WINDOW_SEC]);

    // Also remove rows where the lockout has fully expired
    $pdo->prepare(
        'DELETE FROM login_attempts
         WHERE locked_until IS NOT NULL
           AND locked_until < NOW()'
    )->execute([]);
}

/**
 * Resolves the real client IP, respecting Railway's X-Forwarded-For proxy header.
 * Falls back to REMOTE_ADDR for local dev.
 */
function getClientIp(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        // X-Forwarded-For can be a comma-separated list — leftmost is the client
        $ips = array_map('trim', explode(',', $forwarded));
        $ip  = filter_var($ips[0], FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
