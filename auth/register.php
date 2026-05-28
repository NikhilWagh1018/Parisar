<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/register.php — CycleAudit Registration
//  Supports: local email/password + Google OAuth sign-up
//  Schema: parisar_db → users table
//
//  FIXES APPLIED:
//    1. Auto-login + redirect to dashboard after successful registration
//    2. Sets last_login on registration
//    3. Generates public_id (SURV-NNNN) in PHP after INSERT (no trigger needed)
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/rate_limit.php';

startSecureSession();

// Already logged in — go straight to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_config.php';

// ── Rate limit registrations (reuse login_attempts table) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip       = getClientIp();
    $rl_check = checkLoginRateLimit($pdo, $ip);
    if (!$rl_check['allowed']) {
        $errors[] = $rl_check['message'];
    }
}

$errors  = [];
$success = false;

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']           ?? '');
    $email  = trim($_POST['email']          ?? '');
    $phone  = trim($_POST['phone']          ?? '');
    $org    = trim($_POST['organisation']   ?? '');
    $gender = $_POST['gender']              ?? '';
    $age    = trim($_POST['age']            ?? '');
    $pass   = $_POST['password']            ?? '';
    $conf   = $_POST['confirm_password']    ?? '';

    // ── Validation ────────────────────────────────────────────
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (!preg_match('/^[A-Za-z\s\'\-\.]{2,80}$/', $name)) {
        $errors['name'] = 'Name may only contain letters, spaces, hyphens or apostrophes (2–80 chars).';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($phone !== '') {
        $digits = preg_replace('/[\s\-\+\(\)]/', '', $phone);
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            $errors['phone'] = 'Enter a valid 10-digit Indian mobile number starting with 6–9.';
        }
    }

    $allowedGenders = ['Male', 'Female', 'Other'];
    if (!in_array($gender, $allowedGenders, true)) {
        $errors['gender'] = 'Please select your gender.';
    }

    if ($age === '') {
        $errors['age'] = 'Age is required.';
    } elseif (!ctype_digit($age) || (int)$age < 16 || (int)$age > 80) {
        $errors['age'] = 'Age must be between 16 and 80.';
    }

    if ($org !== '' && strlen($org) > 200) {
        $errors['organisation'] = 'Organisation name must be under 200 characters.';
    }

    if (strlen($pass) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
        $errors['password'] = 'Password must contain at least one letter and one number.';
    }

    if ($pass !== $conf) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // ── Duplicate email check ─────────────────────────────────
    if (empty($errors)) {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    // ── Insert ────────────────────────────────────────────────
    if (empty($errors)) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users
               (name, email, phone, organisation, gender, age, password,
                role, auth_provider, email_verified, last_login)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'surveyor\', \'local\', 1, NOW())'
        )->execute([
            $name,
            $email,
            $phone  ?: null,
            $org    ?: null,
            $gender,
            (int)$age,
            $hash,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // ── FIX 1: Generate public_id in PHP (no trigger needed) ──
        $publicId = 'SURV-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE users SET public_id = ? WHERE id = ?')
            ->execute([$publicId, $newId]);

        // ── FIX 2: Auto-login the new user immediately ────────────
        session_regenerate_id(true);
        $_SESSION['user_id']         = $newId;
        $_SESSION['user_name']       = $name;
        $_SESSION['user_email']      = $email;
        $_SESSION['user_role']       = 'surveyor';
        $_SESSION['profile_picture'] = null;
        $_SESSION['auth_provider']   = 'local';

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        header('Location: ../pages/dashboard.php');
        exit;
    }
}

// ── Helpers ────────────────────────────────────────────────────
function fe(string $k, array $e): string
{
    return isset($e[$k])
        ? '<div class="ferr">' . htmlspecialchars($e[$k]) . '</div>'
        : '';
}
function fc(string $k, array $e): string
{
    return isset($e[$k]) ? ' err' : '';
}

$googleUrl = getGoogleAuthUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/auth.css">
<link rel="stylesheet" href="../css/register-inline.css">
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

  <h2 class="left-headline">Join the<br><span class="hi">Audit Network.</span></h2>
  <p class="left-sub">Register as a surveyor and contribute to Pune's cycle infrastructure dataset. Your field data drives Parisar's advocacy with municipal bodies.</p>

  <div class="feature-pills">
    <div class="pill">
      <div class="pill-icon">🗺️</div>
      <div class="pill-text"><strong>Geo-Referenced Segments</strong><span>Each audit linked to precise road segments with GPS</span></div>
    </div>
    <div class="pill">
      <div class="pill-icon">📊</div>
      <div class="pill-text"><strong>Multi-Dimensional Scoring</strong><span>Safety, Continuity and Comfort from your field notes</span></div>
    </div>
    <div class="pill">
      <div class="pill-icon">📄</div>
      <div class="pill-text"><strong>PDF Reports</strong><span>Auto-generate stakeholder reports from submissions</span></div>
    </div>
    <div class="pill">
      <div class="pill-icon">🌱</div>
      <div class="pill-text"><strong>Drive Real Impact</strong><span>Parisar uses your data to advocate for better cycling</span></div>
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
    <h1 class="form-title">Create Account</h1>
    <p class="form-subtitle">Already have an account? <a href="login.php">Sign in here</a></p>

    <a href="<?= htmlspecialchars($googleUrl) ?>" class="btn-google">
      <svg width="20" height="20" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        <path fill="none" d="M0 0h48v48H0z"/>
      </svg>
      Sign up with Google
    </a>

    <div class="divider-row">or register with email</div>

    <form method="POST" novalidate>

      <div class="sdiv">Personal Information</div>

      <div class="form-group<?= fc('name', $errors) ?>">
        <label for="inp-name">Full Name <span style="color:var(--red)">*</span> <small style="color:#a0b090;font-weight:400">letters only, 2–80 chars</small></label>
        <input type="text" name="name" id="inp-name"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               placeholder="Enter your full name" maxlength="80" autocomplete="off">
        <?= fe('name', $errors) ?>
      </div>

      <div class="form-row-2">
        <div class="form-group<?= fc('age', $errors) ?>">
          <label for="inp-age">Age <span style="color:var(--red)">*</span> <small style="color:#a0b090;font-weight:400">16–80</small></label>
          <input type="number" name="age" id="inp-age"
                 value="<?= htmlspecialchars($_POST['age'] ?? '') ?>"
                 placeholder="Your age" min="16" max="80">
          <?= fe('age', $errors) ?>
        </div>
        <div class="form-group<?= fc('gender', $errors) ?>">
          <label for="inp-gender">Gender <span style="color:var(--red)">*</span></label>
          <select name="gender" id="inp-gender">
            <option value="">— Select —</option>
            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
            <option <?= ($_POST['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
          <?= fe('gender', $errors) ?>
        </div>
      </div>

      <div class="sdiv">Contact Details</div>

      <div class="form-group<?= fc('email', $errors) ?>">
        <label for="inp-email">Email Address <span style="color:var(--red)">*</span></label>
        <input type="email" name="email" id="inp-email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" autocomplete="off">
        <?= fe('email', $errors) ?>
      </div>

      <div class="form-row-2">
        <div class="form-group<?= fc('phone', $errors) ?>">
          <label for="inp-phone">Mobile <small style="color:#a0b090;font-weight:400">optional</small></label>
          <input type="tel" name="phone" id="inp-phone"
                 value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                 placeholder="9XXXXXXXXX" maxlength="10">
          <?= fe('phone', $errors) ?>
        </div>
        <div class="form-group<?= fc('organisation', $errors) ?>">
          <label for="inp-org">Organisation <small style="color:#a0b090;font-weight:400">optional</small></label>
          <input type="text" name="organisation" id="inp-org"
                 value="<?= htmlspecialchars($_POST['organisation'] ?? '') ?>"
                 placeholder="Your NGO or institute" maxlength="200">
          <?= fe('organisation', $errors) ?>
        </div>
      </div>

      <div class="sdiv">Set Password</div>

      <div class="form-group<?= fc('password', $errors) ?>">
        <label for="inp-pass">Password <span style="color:var(--red)">*</span> <small style="color:#a0b090;font-weight:400">min 8 chars, 1 letter + 1 number</small></label>
        <div class="pw-wrap">
          <input type="password" name="password" id="inp-pass"
                 placeholder="Create a strong password" oninput="checkStr(this.value)">
          <button type="button" class="eye-btn" onclick="tog('inp-pass',this)">Show</button>
        </div>
        <div class="str-wrap">
          <div class="str-bar"><div class="str-fill" id="sf"></div></div>
          <div class="str-lbl" id="sl">Enter a password</div>
        </div>
        <?= fe('password', $errors) ?>
      </div>

      <div class="form-group<?= fc('confirm_password', $errors) ?>">
        <label for="inp-conf">Confirm Password <span style="color:var(--red)">*</span></label>
        <div class="pw-wrap">
          <input type="password" name="confirm_password" id="inp-conf"
                 placeholder="Re-enter your password">
          <button type="button" class="eye-btn" onclick="tog('inp-conf',this)">Show</button>
        </div>
        <?= fe('confirm_password', $errors) ?>
      </div>

      <button type="submit" class="sub-btn">Create Surveyor Account →</button>
    </form>

    <div class="back-link"><a href="../index.html">← Back to Home</a></div>
  </div>
</div>

<script src="../js/register.js"></script>
</body>
</html>