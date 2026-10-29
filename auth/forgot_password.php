<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/forgot_password.php — "Forgot password?"
//  Asks for an email address and, if an active account has it, emails a
//  one-time link to auth/reset_password.php.
//
//  The reply is always the same, whether or not the address is known, so
//  the page cannot be used to find out who has an account. Requests are
//  rate limited per IP, and one account gets at most one email every
//  2 minutes.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../helpers/PasswordReset.php';
require_once __DIR__ . '/../helpers/Mailer.php';

header('Cache-Control: no-store');

$error    = '';
$sent     = false;
$clientIp = getClientIp();
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');

    $rl = checkRateLimit($pdo, $clientIp, 'password_reset');
    if (!$rl['allowed']) {
        $error = $rl['message'];
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } else {
        // Every valid request counts toward the limit, known address or not.
        recordFailedAttempt($pdo, $clientIp, 'password_reset');

        try {
            $user = passwordResetFindUser($pdo, $email);
            if ($user !== null && !passwordResetRecentlyRequested($pdo, $user['id'])) {
                if (!mailerConfigured()) {
                    error_log('Password reset asked for user ' . $user['id']
                        . ' but email is not set up (MAIL_PROVIDER, MAIL_API_KEY, MAIL_FROM).');
                } else {
                    $token = passwordResetCreate($pdo, $user['id'], $clientIp);
                    $mail  = passwordResetEmail($user['name'], passwordResetLink(BASE_URL, $token), APP_NAME);
                    if (!mailerSend($user['email'], $user['name'], $mail['subject'], $mail['html'], $mail['text'])) {
                        error_log('Password reset email could not be sent to user ' . $user['id'] . '.');
                    }
                }
            }
        } catch (Throwable $e) {
            // e.g. the password_resets table has not been created yet
            error_log('forgot_password error: ' . $e->getMessage());
        }

        $sent = true;
    }
}

$pageTitle = 'Forgot password';
$cardTitle = 'Forgot password?';
require __DIR__ . '/partials/shell_top.php';
?>

      <?php if ($sent): ?>
      <div class="alert alert--ok" role="status">
        If an account exists for that email, we have sent a link to reset the password.
        It works once and expires in <?= PASSWORD_RESET_TTL_MINUTES ?> minutes.
      </div>
      <p class="card-text">Nothing in your inbox after a few minutes? Check the spam folder, or ask your City Leader or an Admin for help.</p>
      <div class="card-links"><a href="login.php">Back to sign in</a></div>

      <?php else: ?>
      <p class="card-text">Enter the email address you signed up with and we will send you a link to choose a new password.</p>

      <?php if ($error !== ''): ?>
      <div class="alert" id="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" id="forgot-form" class="card-form">
        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /> <path d="M3 7l9 6l9 -6" /></svg>
            <label class="sr-only" for="email">Email address</label>
            <input type="email" id="email" name="email" required maxlength="150"
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="you@example.com" autocomplete="email" inputmode="email" autofocus
                   <?= $error !== '' ? 'aria-describedby="auth-error"' : '' ?>>
          </div>
        </div>

        <button type="submit" class="btn-submit"><span class="btn-label">Send reset link</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>

      <div class="card-links"><a href="login.php">Back to sign in</a></div>
      <?php endif; ?>

<?php require __DIR__ . '/partials/shell_bottom.php'; ?>
