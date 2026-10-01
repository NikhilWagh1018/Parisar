<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/choose_city.php — "Which city are you in?"
//  Shown once, right after a NEW Google sign-up, when more than one
//  city exists. (Email sign-up asks on the registration form itself.)
//  Only sets city_id while it is still empty, so it can never move
//  someone who already has a city; an admin changes it from the
//  Users page.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Cities.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare('SELECT city_id, role, is_active FROM users WHERE id = ? LIMIT 1');
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

if ($me === false || (int)$me['is_active'] === 0) {
    header('Location: login.php');
    exit;
}

$cities = listCities($pdo);

// Nothing to choose: already has a city, is a national admin (city-less
// by design), or there are not yet two cities to choose between.
if ($me['city_id'] !== null || $me['role'] === 'national_admin' || count($cities) < 2) {
    header('Location: ../pages/dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        [$cityId, $cityError] = resolveSignupCity($cities, $_POST['city_id'] ?? '');
        if ($cityError !== null) {
            $error = $cityError;
        } else {
            $pdo->prepare('UPDATE users SET city_id = ? WHERE id = ? AND city_id IS NULL')
                ->execute([$cityId, $userId]);
            header('Location: ../pages/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Choose your city — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/auth.css">
<link rel="stylesheet" href="../css/register-inline.css">
</head>
<body>

<div class="left-panel">
  <div class="brand">
    <div class="brand-mark">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.5 2 6 5 6 8.5c0 4.5 6 11.5 6 11.5s6-7 6-11.5C18 5 15.5 2 12 2zm0 9.5a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>
    </div>
    <span class="brand-name">CycleAudit</span>
  </div>
  <h2 class="left-headline">One last<br><span class="hi">step.</span></h2>
  <p class="left-sub">Tell us which city you will be auditing in, so you see the right roads and your work is counted for the right city.</p>
</div>

<div class="right-panel">
  <div class="form-box">
    <h1 class="form-title">Choose your city</h1>
    <p class="form-subtitle">You can ask an admin to change this later.</p>

    <?php if ($error !== null): ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <div class="form-group<?= $error !== null ? ' err' : '' ?>">
        <label for="inp-city">City <span style="color:var(--red)">*</span></label>
        <select name="city_id" id="inp-city" required>
          <option value="">— Select your city —</option>
          <?php foreach ($cities as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="sub-btn">Continue →</button>
    </form>
  </div>
</div>

</body>
</html>
