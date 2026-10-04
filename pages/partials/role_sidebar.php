<?php
// Shared sidebar for the Admin and City Leader dashboards.
// Expects: $CURRENT_USER_NAME, $CURRENT_USER_ROLE, $CURRENT_USER_PIC, $activeNav
$rsInitials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
$rsHome     = roleHomePage($CURRENT_USER_ROLE);
$rsItem = static function (string $key, string $href, string $label) use ($activeNav): string {
    $cls = 'nav-item' . ($activeNav === $key ? ' active' : '');
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
};
?>
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>
  <nav>
    <div class="nav-section">Main</div>
    <?= $rsItem('home', $rsHome, $CURRENT_USER_ROLE === 'national_admin' ? 'Platform Dashboard' : 'City Dashboard') ?>
    <?php if ($CURRENT_USER_ROLE !== 'city_admin'): ?>
    <?= $rsItem('overview', 'dashboard.php?overview=1', 'Program Overview') ?>
    <?= $rsItem('map', 'map.php', 'Map View') ?>
    <?= $rsItem('leaderboard', 'leaderboard.php', 'Leaderboard') ?>
    <div class="nav-section">Admin</div>
    <?= $rsItem('roads', 'admin.php', 'Roads') ?>
    <?= $rsItem('users', 'admin_surveyors.php', 'Users') ?>
    <?= $rsItem('activity', 'admin_activity.php', 'Activity Log') ?>
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
        <?php if ($CURRENT_USER_ROLE !== 'city_admin'): /* City Leaders cannot open the profile page yet */ ?>
        <a class="popup-item" href="profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          My Profile
        </a>
        <a class="popup-item" href="profile.php#tab-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </a>
        <div class="popup-divider"></div>
        <?php endif; ?>
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
