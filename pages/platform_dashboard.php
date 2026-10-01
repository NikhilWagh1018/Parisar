<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/platform_dashboard.php  —  Platform Admin home
//  national_admin only: every city at a glance.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';

if ($CURRENT_USER_ROLE !== 'national_admin') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$stmt = $pdo->query(
    "SELECT c.id, c.name,
        (SELECT COUNT(*) FROM users u WHERE u.city_id = c.id AND u.role = 'city_admin')  AS leaders,
        (SELECT COUNT(*) FROM users u WHERE u.city_id = c.id AND u.role = 'surveyor')    AS surveyors,
        (SELECT COUNT(*) FROM road_groups rg WHERE rg.city_id = c.id)                    AS road_groups,
        (SELECT COUNT(*) FROM segments s
           JOIN roads r ON r.id = s.road_id
           JOIN road_groups rg ON rg.id = r.road_group_id
          WHERE rg.city_id = c.id)                                                       AS segs,
        (SELECT COUNT(*) FROM segments s
           JOIN roads r ON r.id = s.road_id
           JOIN road_groups rg ON rg.id = r.road_group_id
          WHERE rg.city_id = c.id AND s.status = 'completed')                            AS done
       FROM cities c
      ORDER BY c.name ASC, c.id ASC"
);
$cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tot = ['cities' => count($cities), 'leaders' => 0, 'surveyors' => 0, 'segs' => 0, 'done' => 0];
foreach ($cities as $c) {
    $tot['leaders']   += (int)$c['leaders'];
    $tot['surveyors'] += (int)$c['surveyors'];
    $tot['segs']      += (int)$c['segs'];
    $tot['done']      += (int)$c['done'];
}
$pct = static fn(int $d, int $t): string => $t > 0 ? (string)round($d * 100 / $t) . '%' : '—';

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Platform Dashboard — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1>Platform Dashboard</h1>
      <p>All cities, one view.</p>
    </div>
  </div>
  <div class="content">
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">🏙️</div><div><div class="stat-val"><?= $tot['cities'] ?></div><div class="stat-lbl">Cities</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">🧑‍💼</div><div><div class="stat-val"><?= $tot['leaders'] ?></div><div class="stat-lbl">City Leaders</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">👥</div><div><div class="stat-val"><?= $tot['surveyors'] ?></div><div class="stat-lbl">Surveyors</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val"><?= $pct($tot['done'], $tot['segs']) ?></div><div class="stat-lbl">Segments Done</div></div></div>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>🏙️ Cities</h3>
        <a href="admin_surveyors.php">Manage users →</a>
      </div>
      <div class="rd-scroll">
        <table class="rd-table">
          <thead><tr><th>City</th><th>City Leaders</th><th>Surveyors</th><th>Roads</th><th>Segments</th><th>Done</th><th></th></tr></thead>
          <tbody>
          <?php if (!$cities): ?>
            <tr><td colspan="7" class="rd-empty">No cities yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($cities as $c): ?>
            <tr>
              <td><strong><?= $h($c['name']) ?></strong></td>
              <td><?= (int)$c['leaders'] ?></td>
              <td><?= (int)$c['surveyors'] ?></td>
              <td><?= (int)$c['road_groups'] ?></td>
              <td><?= (int)$c['segs'] ?></td>
              <td><?= $pct((int)$c['done'], (int)$c['segs']) ?></td>
              <td><a href="city_dashboard.php?city_id=<?= (int)$c['id'] ?>">Open →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
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
