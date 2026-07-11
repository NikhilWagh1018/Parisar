<?php
declare(strict_types=1);

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  auth/login.php â€” CycleAudit Login
//  Supports: local email/password + Google OAuth
//  Schema: parisar_db â†’ users table
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

// Already logged in â†’ dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../config/rate_limit.php';

$error     = '';
$clientIp  = getClientIp();

// â”€â”€ Handle POST (local login) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {

        // â”€â”€ Rate limit check â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $rl = checkLoginRateLimit($pdo, $clientIp);
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
                // â”€â”€ Successful local login â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                clearLoginAttempts($pdo, $clientIp);

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
                // Wrong password or unknown email â€” record the failure
                recordFailedAttempt($pdo, $clientIp);
                $remaining = remainingAttempts($pdo, $clientIp);

                $error = 'Invalid email or password. Please try again.';
                if ($remaining > 0 && $remaining <= 2) {
                    $error .= " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining before lockout)";
                }
            }
        }
    }
}

// â”€â”€ Map OAuth error codes â†’ readable messages â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In â€” CycleAudit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/auth.css">
  <link rel="stylesheet" href="../css/login-inline.css">
</head>
<body>

<!-- LEFT PANEL -->
<div class="left-panel">
  <div class="brand">
    <div class="brand-mark">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.5 2 6 5 6 8.5c0 4.5 6 11.5 6 11.5s6-7 6-11.5C18 5 15.5 2 12 2zm0 9.5a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>
    </div>
    <span class="brand-name">CycleAudit</span>
  </div>

  <h2 class="left-headline">Welcome back,<br><span class="hi">Surveyor.</span></h2>
  <p class="left-sub">Log in to access your audit dashboard and continue mapping Pune's cycle track network.</p>

  <div class="feature-pills">
    <div class="pill">
      <div class="pill-icon">🗺️</div>
      <div class="pill-text"><strong>Segment Mapping</strong><span>Define and audit road segments precisely</span></div>
    </div>
    <div class="pill">
      <div class="pill-icon">📊</div>
      <div class="pill-text"><strong>Live Scoring</strong><span>Safety, Continuity &amp; Comfort scores</span></div>
    </div>
    <div class="pill">
      <div class="pill-icon">📄</div>
      <div class="pill-text"><strong>PDF Reports</strong><span>Export professional audit reports</span></div>
    </div>
  </div>

  <div style="margin-top:auto;padding-top:28px;border-top:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:12px;">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:24px;width:auto;filter:brightness(0) invert(1);opacity:.75;">
    <span style="font-size:.72rem;color:rgba(255,255,255,.45);line-height:1.5;">An initiative by Parisar,<br>Pune, Maharashtra</span>
  </div>
</div>

<!-- RIGHT PANEL -->
<div class="right-panel">
  <div class="form-box">
    <h1 class="form-title">Sign In</h1>
    <p class="form-subtitle">Don't have an account? <a href="register.php">Register here</a></p>

    <?php if ($error !== ''): ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Google Sign-In -->
    <a href="<?= htmlspecialchars($googleUrl) ?>" class="btn-google">
      <svg width="20" height="20" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        <path fill="none" d="M0 0h48v48H0z"/>
      </svg>
      Continue with Google
    </a>

    <div class="divider-row">or sign in with email</div>

    <form method="POST" novalidate>
      <div class="form-group">
        <label for="email">Email Address</label>
        <div class="input-icon-wrap">
          <span class="icon">✉️</span>
          <input type="email" id="email" name="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 placeholder="you@example.com" autocomplete="email">
        </div>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-icon-wrap">
          <span class="icon">🔒</span>
          <input type="password" id="password" name="password"
                 placeholder="Enter your password" autocomplete="current-password">
          <button type="button" class="toggle-pass" onclick="togglePass('password', this)">Show</button>
        </div>
      </div>

      <button type="submit" class="btn-submit">
        Sign In
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="13 17 18 12 13 7"/><path d="M6 12h12"/>
        </svg>
      </button>
    </form>

    <div class="back-link"><a href="../index.html">â† Back to Home</a></div>
  </div>
</div>

<script src="../js/login.js"></script>
</body>
</html>