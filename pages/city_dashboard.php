<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_dashboard.php  —  City Leader home: the city's audits
//  city_admin sees their own city and can create audits.
//  national_admin can open any city with ?city_id=N (read only).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$cityId     = $isNational ? (int)($_GET['city_id'] ?? 0) : (int)($CURRENT_USER_CITY_ID ?? 0);

if ($isNational && $cityId <= 0) {
    header('Location: platform_dashboard.php');
    exit;
}

$city = null;
if ($cityId > 0) {
    $s = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
    $s->execute([$cityId]);
    $city = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$audits = $city ? (new CityAuditRepository($pdo))->listForCity((int)$city['id']) : [];
$canCreate = $city && !$isNational;

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$cityName  = $city ? (string)$city['name'] : 'No city assigned';
$statusLabel = static fn(string $s): string => ucfirst(str_replace('_', ' ', $s));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>City Dashboard — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_audit.css?v=<?= filemtime(__DIR__ . '/../css/city_audit.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1><?= $h($cityName) ?></h1>
      <p><?= $isNational ? '<a href="platform_dashboard.php">← All cities</a>' : 'City Leader workspace' ?></p>
    </div>
    <?php if ($canCreate): ?>
      <button class="btn-new" id="caNewBtn" type="button">+ New Audit</button>
    <?php endif; ?>
  </div>
  <div class="content" id="caApp" data-csrf="<?= $csrf ?>">
  <?php if (!$city): ?>
    <div class="card"><p class="rd-empty">No city is assigned to your account yet. Please ask an Admin to assign one.</p></div>
  <?php else: ?>

    <?php if ($canCreate): ?>
    <div class="card" id="caNewCard" style="display:none">
      <div class="card-head"><h3>New audit for <?= $h($cityName) ?></h3></div>
      <form id="caNewForm" class="ca-form" novalidate>
        <div class="ca-field">
          <label for="caName">Audit name *</label>
          <input id="caName" name="name" type="text" maxlength="150" placeholder="e.g. Pune Cycle Infrastructure Audit">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caYear">Audit year *</label>
          <input id="caYear" name="audit_year" type="number" min="2000" max="2100" value="<?= (int)date('Y') ?>">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caCity">City</label>
          <input id="caCity" type="text" value="<?= $h($cityName) ?>" disabled>
        </div>
        <div class="ca-field">
          <label for="caState">State *</label>
          <input id="caState" name="state" type="text" maxlength="100" placeholder="e.g. Maharashtra">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field full">
          <label for="caInfo">Programme info</label>
          <textarea id="caInfo" name="programme_info" maxlength="2000" placeholder="Programme, funder or scope notes (optional)"></textarea>
          <div class="ca-err"></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">Create audit</button>
          <button class="ca-btn ghost" type="button" id="caNewCancel">Cancel</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Audits</h3></div>
      <div class="rd-scroll">
        <table class="rd-table">
          <thead><tr><th>Audit</th><th>Year</th><th>State</th><th>Status</th><th>Roads</th><th>Segments</th><th></th></tr></thead>
          <tbody>
          <?php if (!$audits): ?>
            <tr><td colspan="7" class="rd-empty"><?= $canCreate ? 'No audits yet. Use “+ New Audit” to start one.' : 'No audits yet.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($audits as $a): ?>
            <tr>
              <td><strong><?= $h($a['name']) ?></strong></td>
              <td><?= (int)$a['audit_year'] ?></td>
              <td><?= $h($a['state']) ?></td>
              <td><span class="ca-badge <?= $h($a['status']) ?>"><?= $h($statusLabel((string)$a['status'])) ?></span></td>
              <td><?= (int)$a['road_count'] ?></td>
              <td><?= (int)$a['segment_count'] ?></td>
              <td><a href="city_audit.php?id=<?= (int)$a['id'] ?>">Open →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  </div>
</main>
<div class="ca-toast" id="caToast"></div>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
<script nonce="<?= $nonce ?>" src="../js/city_audit.js?v=<?= filemtime(__DIR__ . '/../js/city_audit.js') ?>"></script>
</body>
</html>
