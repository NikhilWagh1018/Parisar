<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/login.php — CycleAudit Login
//  Supports: local email/password + Google OAuth
//  Schema: parisar_db → users table
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

// Already logged in → dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../config/rate_limit.php';

$error     = '';
$clientIp  = getClientIp();

// ── Handle POST (local login) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {

        // ── Rate limit check ───────────────────────────────────
        $rl = checkRateLimit($pdo, $clientIp, 'login');
        if (!$rl['allowed']) {
            $error = $rl['message'];
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['auth_provider'] === 'google' && empty($user['password'])) {
                // Google accounts don't count as a brute-force attempt
                $error = 'This account uses Google Sign-In. Please click "Continue with Google" below.';
            } elseif ($user && password_verify($password, (string)$user['password'])) {
                if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                    $error = 'This account has been deactivated. Please contact an administrator.';
                } else {
                // ── Successful local login ─────────────────────
                clearRateLimitAttempts($pdo, $clientIp, 'login');

                session_regenerate_id(true);
                $_SESSION['user_id']         = $user['id'];
                $_SESSION['user_name']       = $user['name'];
                $_SESSION['user_email']      = $user['email'];
                $_SESSION['user_role']       = $user['role'];
                $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                $_SESSION['auth_provider']   = $user['auth_provider']   ?? 'local';

                // Generate a CSRF token for this session
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                // Update last_login timestamp
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                    ->execute([$user['id']]);

                header('Location: ../pages/dashboard.php');
                exit;
                }
            } else {
                // Wrong password or unknown email — record the failure
                recordFailedAttempt($pdo, $clientIp, 'login');
                $remaining = remainingAttempts($pdo, $clientIp, 'login');

                $error = 'Invalid email or password. Please try again.';
                if ($remaining > 0 && $remaining <= 2) {
                    $error .= " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining before lockout)";
                }
            }
        }
    }
}

// ── Map OAuth error codes → readable messages ──────────────────
$oauthErrors = [
    'state_mismatch'     => 'Security check failed. Please try signing in again.',
    'no_code'            => 'Google sign-in was cancelled. Please try again.',
    'token_failed'       => 'Could not connect to Google. Please try again.',
    'userinfo_failed'    => 'Could not retrieve account info from Google. Please try again.',
    'email_not_verified' => 'Your Google email address is not verified. Please verify it with Google first.',
    'account_disabled'   => 'This account has been deactivated. Please contact an administrator.',
];
$oauthKey = $_GET['error'] ?? '';
if ($oauthKey !== '' && isset($oauthErrors[$oauthKey])) {
    $error = $oauthErrors[$oauthKey];
}

$googleUrl = getGoogleAuthUrl();

// Values used by the template
$prefillEmail  = (string)($_POST['email'] ?? '');
$focusPassword = ($error !== '' && $prefillEmail !== '');
$focusEmail    = ($error !== '' && $prefillEmail === '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#f6efe0">
  <title>Sign in — CycleAudit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/login.css?v=1">
</head>
<body class="login">

<div class="stage">

  <a class="brand" href="../index.html" aria-label="CycleAudit home">
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.5 2 6 5 6 8.5c0 4.5 6 11.5 6 11.5s6-7 6-11.5C18 5 15.5 2 12 2zm0 9.5a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>
    </span>
    <span class="brand-name">CycleAudit</span>
  </a>

  <main class="card-wrap">

    <!-- Decorative wheel: sits behind the card, hub aligned to the card centre -->
    <svg class="wheel" viewBox="-280 -280 560 560" aria-hidden="true" focusable="false">
      <circle class="wheel-tread" r="266"/>
      <circle class="wheel-rim" r="246"/>
      <circle class="wheel-rim-line" r="232"/>
      <g class="wheel-spokes"><line x1="26.0" y1="0.0" x2="230.3" y2="27.8"/><line x1="25.6" y1="4.5" x2="222.0" y2="67.3"/><line x1="24.4" y1="8.9" x2="206.9" y2="104.9"/><line x1="22.5" y1="13.0" x2="185.6" y2="139.2"/><line x1="19.9" y1="16.7" x2="158.6" y2="169.3"/><line x1="16.7" y1="19.9" x2="126.8" y2="194.3"/><line x1="13.0" y1="22.5" x2="91.1" y2="213.4"/><line x1="8.9" y1="24.4" x2="52.7" y2="225.9"/><line x1="4.5" y1="25.6" x2="12.6" y2="231.7"/><line x1="0.0" y1="26.0" x2="-27.8" y2="230.3"/><line x1="-4.5" y1="25.6" x2="-67.3" y2="222.0"/><line x1="-8.9" y1="24.4" x2="-104.9" y2="206.9"/><line x1="-13.0" y1="22.5" x2="-139.2" y2="185.6"/><line x1="-16.7" y1="19.9" x2="-169.3" y2="158.6"/><line x1="-19.9" y1="16.7" x2="-194.3" y2="126.8"/><line x1="-22.5" y1="13.0" x2="-213.4" y2="91.1"/><line x1="-24.4" y1="8.9" x2="-225.9" y2="52.7"/><line x1="-25.6" y1="4.5" x2="-231.7" y2="12.6"/><line x1="-26.0" y1="0.0" x2="-230.3" y2="-27.8"/><line x1="-25.6" y1="-4.5" x2="-222.0" y2="-67.3"/><line x1="-24.4" y1="-8.9" x2="-206.9" y2="-104.9"/><line x1="-22.5" y1="-13.0" x2="-185.6" y2="-139.2"/><line x1="-19.9" y1="-16.7" x2="-158.6" y2="-169.3"/><line x1="-16.7" y1="-19.9" x2="-126.8" y2="-194.3"/><line x1="-13.0" y1="-22.5" x2="-91.1" y2="-213.4"/><line x1="-8.9" y1="-24.4" x2="-52.7" y2="-225.9"/><line x1="-4.5" y1="-25.6" x2="-12.6" y2="-231.7"/><line x1="-0.0" y1="-26.0" x2="27.8" y2="-230.3"/><line x1="4.5" y1="-25.6" x2="67.3" y2="-222.0"/><line x1="8.9" y1="-24.4" x2="104.9" y2="-206.9"/><line x1="13.0" y1="-22.5" x2="139.2" y2="-185.6"/><line x1="16.7" y1="-19.9" x2="169.3" y2="-158.6"/><line x1="19.9" y1="-16.7" x2="194.3" y2="-126.8"/><line x1="22.5" y1="-13.0" x2="213.4" y2="-91.1"/><line x1="24.4" y1="-8.9" x2="225.9" y2="-52.7"/><line x1="25.6" y1="-4.5" x2="231.7" y2="-12.6"/></g>
      <circle class="wheel-hub" r="26"/>
      <circle class="wheel-hub-core" r="11"/>
    </svg>

    <section class="card<?= $error !== '' ? ' card--error' : '' ?>" aria-labelledby="login-title">
      <h1 class="card-title" id="login-title">Sign in</h1>
      <p class="card-sub">Don't have an account? <a href="register.php">Register here</a></p>

      <?php if ($error !== ''): ?>
      <div class="alert" id="login-error" role="alert">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php endif; ?>

      <!-- Google Sign-In -->
      <a href="<?= htmlspecialchars($googleUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-google">
        <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Continue with Google
      </a>

      <div class="divider-row"><span>or sign in with email</span></div>

      <form method="POST" novalidate id="login-form">
        <div class="field">
          <label for="email">Email address</label>
          <div class="control">
            <svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="you@example.com" autocomplete="email" inputmode="email"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusEmail ? 'autofocus' : '' ?>>
          </div>
          <p class="field-msg" id="email-msg" hidden></p>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="control">
            <svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="password" name="password"
                   placeholder="Enter your password" autocomplete="current-password"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusPassword ? 'autofocus' : '' ?>>
            <button type="button" class="toggle-pass" data-toggle-pass="password" aria-controls="password" aria-pressed="false">Show</button>
          </div>
          <p class="field-msg" id="password-msg" hidden></p>
        </div>

        <button type="submit" class="btn-submit">
          <span class="btn-label">Sign in</span>
          <svg class="btn-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="13 17 18 12 13 7"/><path d="M6 12h12"/>
          </svg>
        </button>
      </form>

      <div class="back-link"><a href="../index.html">&larr; Back to home</a></div>
    </section>
  </main>

  <section class="pitch">
    <p class="pitch-title"><span>Ride.</span><span>Measure.</span><span>Improve.</span></p>
    <p class="pitch-sub">Audit every street, score every segment, and publish reports your city can act on.</p>
    <span class="tag">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3zM9 3v15M15 6v15"/></svg>
      Cycle infrastructure audits
    </span>
  </section>

  <footer class="credit">
    <img src="../assets/parisar-logo.png" alt="Parisar" width="60" height="20">
    <span>An initiative by Parisar, Pune, Maharashtra</span>
  </footer>

</div>

<script src="../js/login.js?v=1"></script>
</body>
</html>
