<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit.php?id=N  —  one city audit
//  City Leader: add roads from the city's road list, generate their
//  segments, remove a road while nothing is audited on it.
//  Platform Admin can open any audit read-only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$repo       = new CityAuditRepository($pdo);
$audit      = $repo->find((int)($_GET['id'] ?? 0));

if ($audit === null || (!$isNational && (int)$audit['city_id'] !== (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}

$canEdit   = !$isNational && $repo->isEditable($audit);
$available = $canEdit ? $repo->availableRoadGroups((int)$audit['city_id'], (int)$audit['id']) : [];
$roads     = $repo->roadsWithSegments((int)$audit['id']);
$backUrl   = 'city_dashboard.php' . ($isNational ? '?city_id=' . (int)$audit['city_id'] : '');

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$num       = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$statusLabel = ucfirst(str_replace('_', ' ', (string)$audit['status']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title><?= $h($audit['name']) ?> — CycleAudit</title>
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
      <h1><?= $h($audit['name']) ?></h1>
      <p><a href="<?= $h($backUrl) ?>">← All audits</a></p>
    </div>
  </div>
  <div class="content" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= (int)$audit['id'] ?>">

    <div class="card">
      <div class="card-head">
        <h3>Audit details</h3>
        <span class="ca-badge <?= $h($audit['status']) ?>"><?= $h($statusLabel) ?></span>
      </div>
      <div class="ca-meta">
        <span>City: <b><?= $h($audit['city_name']) ?></b></span>
        <span>State: <b><?= $h($audit['state']) ?></b></span>
        <span>Year: <b><?= (int)$audit['audit_year'] ?></b></span>
        <?php if ($audit['created_by_name']): ?><span>Created by: <b><?= $h($audit['created_by_name']) ?></b></span><?php endif; ?>
      </div>
      <?php if ($audit['programme_info']): ?>
        <p style="margin:12px 0 0;font-size:.85rem;white-space:pre-line"><?= $h($audit['programme_info']) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-head"><h3>Add a road</h3></div>
      <?php if (!$available): ?>
        <p class="rd-empty">Every road in this city is already part of this audit.</p>
      <?php else: ?>
      <form id="caRoadForm" class="ca-form" novalidate>
        <div class="ca-field full">
          <label for="caRoad">Road *</label>
          <select id="caRoad" name="road_group_id">
            <option value="">— Select a road —</option>
            <?php foreach ($available as $rg): ?>
              <option value="<?= (int)$rg['id'] ?>"><?= $h($rg['canonical_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caLen">Total road length (metres) *</label>
          <input id="caLen" name="total_length" type="number" min="50" step="any" inputmode="decimal" placeholder="e.g. 1500">
          <div class="ca-hint">Minimum 50 m. Used to work out the segments.</div>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caSeg">Standard segment length *</label>
          <select id="caSeg" name="segment_choice">
            <option value="100">100 meters</option>
            <option value="200">200 meters</option>
            <option value="300">300 meters</option>
            <option value="500" selected>500 meters</option>
            <option value="custom">Custom length…</option>
          </select>
          <div id="caCustomWrap" style="display:none;margin-top:8px">
            <input name="segment_custom" type="number" min="10" step="any" inputmode="decimal" placeholder="e.g. 150">
          </div>
          <div class="ca-err"></div>
        </div>
        <div class="full ca-preview" id="caPreview">
          <div><span>Estimated segments</span><b id="caPvCount">–</b></div>
          <div><span>Each segment</span><b id="caPvEach">–</b></div>
          <div><span>Last segment</span><b id="caPvLast">–</b></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">+ Generate &amp; Save Segments</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Roads in this audit (<?= count($roads) ?>)</h3></div>
      <?php if (!$roads): ?>
        <p class="rd-empty"><?= $canEdit ? 'No roads yet. Add the first road above.' : 'No roads in this audit yet.' ?></p>
      <?php endif; ?>
      <?php foreach ($roads as $r): ?>
        <div class="ca-road">
          <div class="ca-road-head">
            <div>
              <h4><?= $h($r['name']) ?></h4>
              <small><?= $h($num($r['total_length'])) ?> m · <?= count($r['segments']) ?> segments of <?= $h($num($r['segment_length'])) ?> m</small>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
              <button class="ca-toggle" type="button">Show segments</button>
              <?php if ($canEdit): ?>
                <button class="ca-btn danger ca-remove" type="button"
                        data-road-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>">Remove</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="ca-road-body">
            <div class="rd-scroll">
              <table class="rd-table">
                <thead><tr><th>#</th><th>From</th><th>To</th><th>Length</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($r['segments'] as $s): ?>
                  <tr>
                    <td><?= (int)$s['segment_number'] ?></td>
                    <td><?= $h($num($s['start_distance'])) ?> m</td>
                    <td><?= $h($num($s['end_distance'])) ?> m</td>
                    <td><?= $h($num($s['length'])) ?> m</td>
                    <td><?= $h(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

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
