<?php
// Notifications: a collapsible panel on the dashboard. Collapsed it is one slim bar with an
// unread badge; click it to open the list, click again to close it. Read notifications stay in
// the list (dimmed), so nothing disappears when you press "Mark all as read".
// Expects: $pdo, $CURRENT_USER_ID. Renders nothing when the user has no notifications at all,
// and never breaks the dashboard if the notifications table is not there yet.
require_once __DIR__ . '/../../repositories/NotificationRepository.php';
require_once __DIR__ . '/../../helpers/CityDashboard.php'; // cityLocalTime(): UTC -> India time

$ntRows = [];
try {
    $ntRows = (new NotificationRepository($pdo))->recent((int)$CURRENT_USER_ID, 30);
} catch (Throwable $e) {
    error_log('notifications partial: ' . $e->getMessage());
}
if ($ntRows):
    $ntH      = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ntUnread = count(array_filter($ntRows, static fn(array $n): bool => !$n['is_read']));
?>
<div class="nt-panel" id="notifCard" data-open="0">
  <button type="button" class="nt-bar" id="notifToggle" aria-expanded="false" aria-controls="notifBody">
    <span class="nt-bell" aria-hidden="true">🔔</span>
    <span class="nt-title">Notifications</span>
    <span class="nt-count" id="notifCount" <?= $ntUnread ? '' : 'hidden' ?>><?= $ntUnread ?> new</span>
    <span class="nt-chev" aria-hidden="true">▾</span>
  </button>
  <div class="nt-body" id="notifBody" hidden>
    <div class="nt-tools">
      <span class="nt-sub"><?= $ntUnread ? $ntUnread . ' unread' : 'You are all caught up' ?></span>
      <button type="button" class="nt-readall" id="notifReadAll" <?= $ntUnread ? '' : 'disabled' ?>>Mark all as read</button>
    </div>
    <ul class="nt-list">
      <?php foreach ($ntRows as $n): ?>
        <li class="nt-item<?= $n['is_read'] ? ' is-read' : '' ?>" data-id="<?= (int)$n['id'] ?>"<?= $n['link'] ? ' data-link="' . $ntH($n['link']) . '"' : '' ?>>
          <span class="nt-dot" aria-hidden="true"></span>
          <div class="nt-text">
            <b><?= $ntH($n['title']) ?></b>
            <span><?= $ntH($n['body']) ?></span>
            <small><?= $ntH(cityLocalTime($n['created_at'])) ?></small>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<style nonce="<?= $ntH($_SESSION['csp_nonce'] ?? '') ?>">
  .nt-panel{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:16px;overflow:hidden}
  .nt-bar{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:10px;width:100%;padding:12px 16px;cursor:pointer;font-weight:600;font-size:.95rem}
  .nt-bar:hover{background:#f7f8f3}
  .nt-bar:focus-visible{outline:2px solid #3f7d20;outline-offset:-2px}
  .nt-title{flex:1}
  .nt-count{padding:2px 9px;border-radius:99px;background:#dc2626;color:#fff;font-size:.72rem;font-weight:700}
  .nt-count[hidden]{display:none}
  .nt-chev{transition:transform .2s;color:#7a7f72}
  .nt-panel[data-open="1"] .nt-chev{transform:rotate(180deg)}
  .nt-body{border-top:1px solid rgba(0,0,0,.08)}
  .nt-body[hidden]{display:none}
  .nt-tools{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 16px;background:#fafaf6}
  .nt-sub{font-size:.8rem;color:#7a7f72}
  .nt-readall{font:inherit;font-size:.78rem;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid rgba(0,0,0,.16);background:#fff;color:inherit;cursor:pointer}
  .nt-readall:hover:not(:disabled){background:#f5f5f2}
  .nt-readall:disabled{opacity:.5;cursor:default}
  .nt-list{list-style:none;margin:0;padding:0;max-height:360px;overflow-y:auto}
  .nt-item{display:flex;gap:12px;align-items:flex-start;padding:12px 16px;border-top:1px solid rgba(0,0,0,.06);font-size:.88rem;line-height:1.5;background:#f4faee}
  .nt-item[data-link]{cursor:pointer}
  .nt-item.is-read{background:#fff;opacity:.72}
  .nt-dot{flex:none;width:8px;height:8px;margin-top:7px;border-radius:50%;background:#3f7d20}
  .nt-item.is-read .nt-dot{background:transparent;border:1px solid #c9cdc0}
  .nt-text{display:flex;flex-direction:column;gap:2px;min-width:0}
  .nt-item.is-read .nt-text b{font-weight:500}
  .nt-text small{color:#7a7f72;font-size:.74rem}
</style>
<?php endif; ?>
