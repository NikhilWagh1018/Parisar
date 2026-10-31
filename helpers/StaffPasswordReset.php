<?php
declare(strict_types=1);

// ---------------------------------------------------------------
//  helpers/StaffPasswordReset.php
//  Staff-driven password reset: an Admin or City Leader presses
//  "Reset password" on the Users page, gets a temporary password to hand
//  over, and the person must choose their own at the next sign-in.
//
//  Who may reset whom:
//    - Nobody resets their own password this way (use the profile page).
//    - Admin: any other active account.
//    - City Leader: active surveyors of their own city only.
//    - Surveyor: nobody.
//
//  The rules are pure functions (no DB) so they are unit-tested.
//  Needs migration 022 (users.must_change_password).
// ---------------------------------------------------------------

require_once __DIR__ . '/RoleRules.php';

/** Words for temporary passwords: short, easy to read out loud, nothing confusable. */
const STAFF_TEMP_PASSWORD_WORDS = [
    'Amber', 'Anchor', 'Apple', 'Aspen', 'Badger', 'Banyan', 'Basil', 'Beacon',
    'Birch', 'Breeze', 'Bronze', 'Cedar', 'Cherry', 'Clover', 'Cobalt', 'Coral',
    'Cricket', 'Daisy', 'Delta', 'Ember', 'Falcon', 'Fennel', 'Forest', 'Garnet',
    'Ginger', 'Harbor', 'Hazel', 'Indigo', 'Jasmine', 'Juniper', 'Lantern', 'Lemon',
    'Lotus', 'Maple', 'Meadow', 'Mango', 'Marble', 'Mint', 'Monsoon', 'Nectar',
    'Olive', 'Orchid', 'Otter', 'Pebble', 'Pepper', 'Pine', 'Plum', 'Poppy',
    'Quartz', 'Raven', 'River', 'Saffron', 'Sage', 'Silver', 'Sparrow', 'Sunrise',
    'Tiger', 'Tulip', 'Velvet', 'Walnut', 'Willow', 'Yellow', 'Zephyr', 'Teak',
];

/** Pages a person with a temporary password may still open. */
const STAFF_MUST_CHANGE_EXEMPT_PAGES = ['/auth/force_password.php', '/auth/logout.php'];

/**
 * A readable temporary password such as "Coral-Tiger-Maple-4821":
 * three words and four digits. It always passes passwordRuleError().
 */
function tempPasswordGenerate(): string
{
    $words = STAFF_TEMP_PASSWORD_WORDS;
    $max   = count($words) - 1;
    return $words[random_int(0, $max)] . '-'
         . $words[random_int(0, $max)] . '-'
         . $words[random_int(0, $max)] . '-'
         . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * May this actor reset the target's password? Returns an error message, or
 * null when allowed.
 */
function staffPasswordResetError(
    string $actorRole,
    int $actorId,
    ?int $actorCityId,
    int $targetId,
    string $targetRole,
    ?int $targetCityId,
    bool $targetActive
): ?string {
    if ($actorId === $targetId) {
        return 'Use your profile page to change your own password.';
    }
    if (!canManageUser($actorRole, $targetRole)) {
        return $actorRole === 'city_leader'
            ? 'City Leaders can only reset passwords for surveyors.'
            : 'You do not have permission to reset passwords.';
    }
    if ($actorRole !== 'admin') {
        if ($actorCityId === null || $targetCityId === null || $actorCityId !== $targetCityId) {
            return 'You can only reset passwords for users in your own city.';
        }
    }
    if (!$targetActive) {
        return 'This account is deactivated. Reactivate it first.';
    }
    return null;
}

/** Closes any emailed reset links still open for the account. */
function staffPasswordResetCancelLinks(PDO $pdo, int $userId, ?int $now = null): void
{
    $now ??= time();
    try {
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([date('Y-m-d H:i:s', $now), $userId]);
    } catch (Throwable $e) {
        // The password_resets table is optional here (email reset may not be set up).
        error_log('staffPasswordResetCancelLinks: ' . $e->getMessage());
    }
}

/**
 * Sets the temporary password and marks the account "must choose a new
 * password". Open emailed links are closed so they cannot be used to undo it.
 */
function staffPasswordResetApply(PDO $pdo, int $targetId, string $tempPassword, ?int $now = null): void
{
    $pdo->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
        ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $targetId]);
    staffPasswordResetCancelLinks($pdo, $targetId, $now);
}

/**
 * True when the account has to choose a new password before doing anything
 * else. Returns false (instead of failing) when the column is missing, so the
 * site keeps working until migration 022 has been run.
 */
function userMustChangePassword(PDO $pdo, int $userId): bool
{
    try {
        $st = $pdo->prepare('SELECT must_change_password FROM users WHERE id = ? LIMIT 1');
        if ($st === false || !$st->execute([$userId])) {
            return false;
        }
        $v = $st->fetchColumn();
        return $v !== false && (int)$v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** The person picks their own password: saves it and clears the flag. */
function staffPasswordSetOwn(PDO $pdo, int $userId, string $newPassword): bool
{
    $st = $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
    $st->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    return $st->rowCount() === 1;
}

/** Pages that stay open while a new password is still owed (change page, sign out). */
function mustChangePasswordExempt(string $scriptName): bool
{
    $path = str_replace('\\', '/', $scriptName);
    foreach (STAFF_MUST_CHANGE_EXEMPT_PAGES as $page) {
        if ($path === $page || str_ends_with($path, $page)) {
            return true;
        }
    }
    return false;
}
