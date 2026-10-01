<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_dashboard.php  —  City Leader home
//  city_admin sees their own city. national_admin can open any city
//  with ?city_id=N (from the Platform Dashboard).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';

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

$stats = ['surveyors' => 0, 'roads' => 0, 'segs' => 0, 'done' => 0];
$roads = [];
$surveyors = [];
if ($city) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE city_id = ? AND role = 'surveyor'");
    $q->execute([$cityId]);
    $stats['surveyors'] = (int)$q->fetchColumn();

    $q = $pdo->prepare('SELECT COUNT(*) FROM road_groups WHERE city_id = ?');
    $q->execute([$cityId]);
    $stats['roads'] = (int)$q->fetchColumn();

    $q = $pdo->prepare(
        "SELECT COUNT(*) AS segs, COALESCE(SUM(s.status = 'completed'), 0) AS done
           FROM segments s
           JOIN roads r        ON r.id = s.road_id
           JOIN road_groups rg ON rg.id = r.road_group_id
          WHERE rg.city_id = ?"
    );
    $q->execute([$cityId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $stats['segs'] = (int)$row['segs'];
    $stats['done'] = (int)$row['done'];

    $q = $pdo->prepare(
        "SELECT r.id, r.name, r.total_length, r.finalized_at,
                COUNT(s.id) AS segs,
                COALESCE(SUM(s.status = 'completed'), 0) AS done
           FROM roads r
           JOIN road_groups rg ON rg.id = r.road_group_id
           LEFT JOIN segments s ON s.road_id = r.id
          WHERE rg.city_id = ?
          GROUP BY r.id
          ORDER BY r.created_at DESC
          LIMIT 10"
    );
    $q->execute([$cityId]);
    $roads = $q->fetchAll(PDO::FETCH_ASSOC);

    $q = $pdo->prepare(
        "SELECT name, organisation, last_login
           FROM users
          WHERE city_id = ? AND role = 'surveyor' AND is_active = 1
          ORDER BY name ASC
          LIMIT 10"
    );
    $q->execute([$cityId]);
    $surveyors = $q->fetchAll(PDO::FETCH_ASSOC);
}

$pct       = $stats['segs'] > 0 ? (string)round($stats['done'] * 100 / $stats['segs']) . '%' : '—';
$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'home';
$cityName  = $city ? (string)$city['name'] : 'No city assigned';
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
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1><?= $h($cityName) ?></h1>
      <p><?= $isNational ? '<a href="platform_dashboard.php">← All cities</a>' : 'Your city at a glance.' ?></p>
    </div>
  </div>
  <div class="content">
  <?php if (!$city): ?>
    <div class="card"><p class="rd-empty">No city is assigned to your account yet. Please ask a Platform Admin to assign one.</p></div>
  <?php else: ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">🛣️</div><div><div class="stat-val"><?= $stats['roads'] ?></div><div class="stat-lbl">Roads</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">📍</div><div><div class="stat-val"><?= $stats['segs'] ?></div><div class="stat-lbl">Segments</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val"><?= $pct ?></div><div class="stat-lbl">Completed</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">👥</div><div><div class="stat-val"><?= $stats['surveyors'] ?></div><div class="stat-lbl">Surveyors</div></div></div>
    </div>

    <div class="card">
      <div class="card-head"><h3>🛣️ Recent Roads</h3><a href="admin.php">Manage roads →</a></div>
      <div class="rd-scroll">
        <table class="rd-table">
          <thead><tr><th>Road</th><th>Length</th><th>Segments</th><th>Done</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$roads): ?><tr><td colspan="5" class="rd-empty">No roads yet.</td></tr><?php endif; ?>
          <?php foreach ($roads as $r): ?>
            <tr>
              <td><strong><?= $h($r['name']) ?></strong></td>
              <td><?= $r['total_length'] !== null ? $h(number_format((float)$r['total_length'])) . ' m' : '—' ?></td>
              <td><?= (int)$r['segs'] ?></td>
              <td><?= (int)$r['done'] ?></td>
              <td><?= $r['finalized_at'] !== null ? 'Finalised' : 'In progress' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>👥 Surveyors</h3><a href="admin_surveyors.php">Manage users →</a></div>
      <div class="rd-scroll">
        <table class="rd-table">
          <thead><tr><th>Name</th><th>Organisation</th><th>Last login</th></tr></thead>
          <tbody>
          <?php if (!$surveyors): ?><tr><td colspan="3" class="rd-empty">No active surveyors yet.</td></tr><?php endif; ?>
          <?php foreach ($surveyors as $u): ?>
            <tr>
              <td><strong><?= $h($u['name']) ?></strong></td>
              <td><?= $h($u['organisation'] ?? '—') ?></td>
              <td><?= $h($u['last_login'] ?? 'Never') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  </div>
</main>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
