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
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/login.css?v=4">
  <script>document.documentElement.classList.add('js')</script>
</head>
<body class="login">

<div class="stage">

  <a class="brand rise" style="--i:0" href="../index.html" aria-label="CycleAudit home">
    <span class="brand-mark"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M19 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M12 19l0 -4l-3 -3l5 -4l2 3l3 0" /> <path d="M17 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg></span>CycleAudit
  </a>

  <section class="pitch">
    <p class="pitch-title"><span class="rise" style="--i:1">Ride.</span> <span class="rise" style="--i:2">Measure.</span> <span class="accent rise" style="--i:3">Improve.</span></p>
    <p class="pitch-sub rise" style="--i:4">Audit every street, score every segment, and publish reports your city can act on.</p>
    <span class="tag rise" style="--i:5"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 18.5l-3 -1.5l-6 3v-13l6 -3l6 3l6 -3v7.5" /> <path d="M9 4v13" /> <path d="M15 7v5.5" /> <path d="M21.121 20.121a3 3 0 1 0 -4.242 0c.418 .419 1.125 1.045 2.121 1.879c1.051 -.89 1.759 -1.516 2.121 -1.879z" /> <path d="M19 18v.01" /></svg>Cycle infrastructure audits</span>
  </section>

  <main class="zone">

    <!-- Large bicycle wheel: hub sits behind the card, rim bleeds off the right edge -->
    <svg class="wheel wheel-in" viewBox="-280 -280 560 560" aria-hidden="true" focusable="false">
      <g class="rot">
        <circle r="262" fill="none" stroke="#1f4d14" stroke-width="8" stroke-dasharray="3 9"/>
        <circle r="246" fill="none" stroke="#3d8a24" stroke-width="18"/>
        <circle r="232" fill="none" stroke="#1f4d14" stroke-width="2"/>
        <g stroke="#1f4d14" stroke-width="1.6" stroke-opacity=".55"><line x1="26.0" y1="0.0" x2="230.3" y2="27.8"/><line x1="25.6" y1="4.5" x2="222.0" y2="67.3"/><line x1="24.4" y1="8.9" x2="206.9" y2="104.9"/><line x1="22.5" y1="13.0" x2="185.6" y2="139.2"/><line x1="19.9" y1="16.7" x2="158.6" y2="169.3"/><line x1="16.7" y1="19.9" x2="126.8" y2="194.3"/><line x1="13.0" y1="22.5" x2="91.1" y2="213.4"/><line x1="8.9" y1="24.4" x2="52.7" y2="225.9"/><line x1="4.5" y1="25.6" x2="12.6" y2="231.7"/><line x1="0.0" y1="26.0" x2="-27.8" y2="230.3"/><line x1="-4.5" y1="25.6" x2="-67.3" y2="222.0"/><line x1="-8.9" y1="24.4" x2="-104.9" y2="206.9"/><line x1="-13.0" y1="22.5" x2="-139.2" y2="185.6"/><line x1="-16.7" y1="19.9" x2="-169.3" y2="158.6"/><line x1="-19.9" y1="16.7" x2="-194.3" y2="126.8"/><line x1="-22.5" y1="13.0" x2="-213.4" y2="91.1"/><line x1="-24.4" y1="8.9" x2="-225.9" y2="52.7"/><line x1="-25.6" y1="4.5" x2="-231.7" y2="12.6"/><line x1="-26.0" y1="0.0" x2="-230.3" y2="-27.8"/><line x1="-25.6" y1="-4.5" x2="-222.0" y2="-67.3"/><line x1="-24.4" y1="-8.9" x2="-206.9" y2="-104.9"/><line x1="-22.5" y1="-13.0" x2="-185.6" y2="-139.2"/><line x1="-19.9" y1="-16.7" x2="-158.6" y2="-169.3"/><line x1="-16.7" y1="-19.9" x2="-126.8" y2="-194.3"/><line x1="-13.0" y1="-22.5" x2="-91.1" y2="-213.4"/><line x1="-8.9" y1="-24.4" x2="-52.7" y2="-225.9"/><line x1="-4.5" y1="-25.6" x2="-12.6" y2="-231.7"/><line x1="-0.0" y1="-26.0" x2="27.8" y2="-230.3"/><line x1="4.5" y1="-25.6" x2="67.3" y2="-222.0"/><line x1="8.9" y1="-24.4" x2="104.9" y2="-206.9"/><line x1="13.0" y1="-22.5" x2="139.2" y2="-185.6"/><line x1="16.7" y1="-19.9" x2="169.3" y2="-158.6"/><line x1="19.9" y1="-16.7" x2="194.3" y2="-126.8"/><line x1="22.5" y1="-13.0" x2="213.4" y2="-91.1"/><line x1="24.4" y1="-8.9" x2="225.9" y2="-52.7"/><line x1="25.6" y1="-4.5" x2="231.7" y2="-12.6"/></g>
        <circle r="26" fill="#1f4d14"/>
        <circle r="11" fill="#f6efe0"/>
      </g>
    </svg>

    <section class="card card-in" aria-labelledby="login-title">
      <h1 class="card-title" id="login-title">Sign in</h1>

      <?php if ($error !== ''): ?>
      <div class="alert" id="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <!-- Google Sign-In -->
      <a href="<?= htmlspecialchars($googleUrl, ENT_QUOTES, 'UTF-8') ?>" class="f f--google"><svg class="ti g-logo" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>Continue with Google</a>

      <form method="POST" novalidate id="login-form" class="card-form">
        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /> <path d="M3 7l9 6l9 -6" /></svg>
            <label class="sr-only" for="email">Email address</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="you@example.com" autocomplete="email" inputmode="email"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusEmail ? 'autofocus' : '' ?>>
          </div>
          <p class="field-msg" id="email-msg" hidden></p>
        </div>

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="password">Password</label>
            <input type="password" id="password" name="password"
                   placeholder="Password" autocomplete="current-password"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusPassword ? 'autofocus' : '' ?>>
            <button type="button" class="eye" data-toggle-pass="password" aria-controls="password" aria-pressed="false" aria-label="Show password">
              <span class="eye-on"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg></span><span class="eye-off"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /> <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /> <path d="M3 3l18 18" /></svg></span>
            </button>
          </div>
          <p class="field-msg" id="password-msg" hidden></p>
        </div>

        <button type="submit" class="btn-submit"><span class="btn-label">Sign in</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>

      <div class="card-links">
        <a href="#" id="forgot-link" aria-controls="forgot-note">Forgot password?</a>
        <a href="register.php">Register</a>
      </div>
      <p class="forgot-note" id="forgot-note" hidden>Password reset isn't available yet. Please contact your City Leader or an administrator.</p>
    </section>
  </main>

  <footer class="credit rise" style="--i:7">
    <img src="../assets/parisar-logo.png" alt="Parisar" width="60" height="20">
    <span>An initiative by Parisar, Pune, Maharashtra</span>
  </footer>

</div>

<script src="../js/login.js?v=4"></script>
</body>
</html>
