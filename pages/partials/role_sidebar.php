<?php
// Shared sidebar for the Platform Admin and City Leader dashboards.
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
    <?= $rsItem('overview', 'dashboard.php?overview=1', 'Program Overview') ?>
    <?= $rsItem('map', 'map.php', 'Map View') ?>
    <?= $rsItem('leaderboard', 'leaderboard.php', 'Leaderboard') ?>
    <div class="nav-section">Admin</div>
    <?= $rsItem('roads', 'admin.php', 'Roads') ?>
    <?= $rsItem('users', 'admin_surveyors.php', 'Users') ?>
    <?= $rsItem('activity', 'admin_activity.php', 'Activity Log') ?>
  </nav>
  <div class="sb-user">
    <a class="sb-user-btn" href="profile.php" style="text-decoration:none">
      <div class="sb-avatar">
        <?php if ($CURRENT_USER_PIC): ?><img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt=""><?php else: ?><?= htmlspecialchars($rsInitials) ?><?php endif; ?>
      </div>
      <div class="sb-uinfo">
        <div class="sb-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
        <div class="sb-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
      </div>
    </a>
    <a class="nav-item" href="../auth/logout.php" style="margin-top:6px">Sign Out</a>
  </div>
</aside>
