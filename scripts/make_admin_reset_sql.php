<?php
declare(strict_types=1);

// ---------------------------------------------------------------
//  scripts/make_admin_reset_sql.php
//  Emergency password reset for an Admin who is locked out (and has no
//  second Admin to press "Reset password" for them).
//
//  Prints ONE SQL statement. Paste it into Railway > MySQL > Database >
//  Data > Query and run it. The person then signs in with the password you
//  gave here and is asked to choose their own.
//
//  Usage (from the project folder; use single quotes in PowerShell):
//    php scripts/make_admin_reset_sql.php <admin email> '<temporary password>'
//
//  Needs migration 022 (users.must_change_password) to have been run.
//  Command line only.
// ---------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../helpers/PasswordReset.php';

$email    = trim((string)($argv[1] ?? ''));
$password = (string)($argv[2] ?? '');

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/make_admin_reset_sql.php <admin email> '<temporary password>'\n");
    exit(1);
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "That does not look like an email address.\n");
    exit(1);
}
$rule = passwordRuleError($password, $password);
if ($rule !== null) {
    fwrite(STDERR, $rule . "\n");
    exit(1);
}

$hash  = password_hash($password, PASSWORD_DEFAULT);
$mail  = str_replace("'", "''", $email);

fwrite(STDERR, "Run this in the Railway query box (one statement). It changes only an account with this email whose role is admin:\n\n");
echo "UPDATE users SET password = '" . $hash . "', must_change_password = 1 WHERE email = '" . $mail . "' AND role = 'admin';\n";
