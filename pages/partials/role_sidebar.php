<?php
// Shared sidebar for the Admin and City Leader dashboards.
// Expects: $CURRENT_USER_NAME, $CURRENT_USER_ROLE, $CURRENT_USER_PIC, $activeNav
$rsInitials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
$rsHome     = roleHomePage($CURRENT_USER_ROLE);
require_once __DIR__ . '/../../helpers/SidebarCounts.php';
// Small numbers beside the links: what needs you (amber) and how much is already finished (grey).
$rsCounts = ['awaiting' => 0, 'published' => 0, 'review' => 0, 'completed' => 0];
if (isset($pdo) && $pdo instanceof PDO) {
    if ($CURRENT_USER_ROLE === 'admin') {
        $rsCounts = array_merge($rsCounts, sidebarAdminCounts($pdo));
    } elseif ($CURRENT_USER_ROLE === 'city_leader' && !empty($CURRENT_USER_ID)) {
        $rsCounts = array_merge($rsCounts, sidebarLeaderCounts($pdo, (int)$CURRENT_USER_ID));
    }
}
$rsItem = static function (string $key, string $href, string $label, int $badge = 0, bool $hot = false) use ($activeNav): string {
    $cls = 'nav-item' . ($activeNav === $key ? ' active' : '');
    $b   = $badge > 0 ? '<span class="nav-badge' . ($hot ? ' hot' : '') . '">' . ($badge > 99 ? '99+' : $badge) . '</span>' : '';
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
         . '<span class="nav-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $b . '</a>';
};
?>
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>
  <nav>
    <div class="nav-section">Overview</div>
    <?php if ($CURRENT_USER_ROLE === 'admin'): ?>
    <?= $rsItem('home', $rsHome, 'Dashboard', (int)$rsCounts['awaiting'], true) ?>
    <div class="nav-section">Completed</div>
    <?= $rsItem('published', 'published_audits.php', 'Published Audits', (int)$rsCounts['published']) ?>
    <?php else: ?>
    <?= $rsItem('home', $rsHome, 'City Dashboard', (int)$rsCounts['review'], true) ?>
    <?php endif; ?>
    <?php if ($CURRENT_USER_ROLE === 'city_leader'): ?>
    <div class="nav-section">Completed</div>
    <?= $rsItem('completed', 'city_completed.php', 'Completed audits', (int)$rsCounts['completed']) ?>
    <div class="nav-section">Manage</div>
    <?= $rsItem('roads', 'admin.php', 'Roads') ?>
    <?= $rsItem('team', 'city_team.php', 'My team') ?>
    <?php elseif ($CURRENT_USER_ROLE === 'admin'): ?>
    <div class="nav-section">Admin</div>
    <?= $rsItem('users', 'admin_surveyors.php', 'Users') ?>
    <?= $rsItem('activity', 'admin_activity.php', 'Activity Log') ?>
    <div class="nav-section">Home page</div>
    <?= $rsItem('collab', 'collaborations.php', 'Collaborations') ?>
    <?php endif; ?>
  </nav>
  <div class="sb-user">
    <!-- Popup menu: same as the other pages (profile, password, sign out) -->
    <div class="sb-popup" id="sbPopup">
      <div class="popup-header">
        <div class="popup-avatar">
          <?php if ($CURRENT_USER_PIC): ?><img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt=""><?php else: ?><?= htmlspecialchars($rsInitials) ?><?php endif; ?>
        </div>
        <div style="min-width:0">
          <div class="popup-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
          <div class="popup-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
        </div>
      </div>
      <div class="popup-menu">
        <a class="popup-item" href="profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          My Profile
        </a>
        <a class="popup-item" href="profile.php#tab-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </a>
        <div class="popup-divider"></div>
        <a class="popup-item danger" href="../auth/logout.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </a>
      </div>
    </div>

    <!-- Trigger button -->
    <button class="sb-user-btn" id="sbUserBtn" type="button">
      <div class="sb-avatar">
        <?php if ($CURRENT_USER_PIC): ?><img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt=""><?php else: ?><?= htmlspecialchars($rsInitials) ?><?php endif; ?>
      </div>
      <div class="sb-uinfo">
        <div class="sb-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
        <div class="sb-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
      </div>
      <svg class="sb-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="18 15 12 9 6 15"/>
      </svg>
    </button>
  </div>
  <script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  (function () {
    var btn = document.getElementById('sbUserBtn'), pop = document.getElementById('sbPopup');
    if (!btn || !pop) return;
    btn.addEventListener('click', function () {
      btn.classList.toggle('open', pop.classList.toggle('show'));
    });
    document.addEventListener('click', function (e) {
      if (pop.classList.contains('show') && !btn.contains(e.target) && !pop.contains(e.target)) {
        pop.classList.remove('show'); btn.classList.remove('open');
      }
    });
  })();
  </script>
</aside>
