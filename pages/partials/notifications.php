<?php
// "New for you": the surveyor's unread notifications, on the dashboard.
// Expects: $pdo, $CURRENT_USER_ID. Renders nothing when there is nothing new,
// and never breaks the dashboard if the notifications table is not there yet.
require_once __DIR__ . '/../../repositories/NotificationRepository.php';
require_once __DIR__ . '/../../helpers/CityDashboard.php'; // cityLocalTime(): UTC -> India time

$ntRows = [];
try {
    $ntRows = (new NotificationRepository($pdo))->unread((int)$CURRENT_USER_ID, 10);
} catch (Throwable $e) {
    error_log('notifications partial: ' . $e->getMessage());
}
if ($ntRows):
    $ntH = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="card" id="notifCard">
  <div class="card-head">
    <h3>🔔 New for you <span class="nt-count"><?= count($ntRows) ?></span></h3>
    <button type="button" class="nt-readall" id="notifReadAll">Mark all as read</button>
  </div>
  <ul class="nt-list">
    <?php foreach ($ntRows as $n): ?>
      <li class="nt-item">
        <b><?= $ntH($n['title']) ?></b>
        <span><?= $ntH($n['body']) ?></span>
        <small><?= $ntH(cityLocalTime($n['created_at'])) ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<style nonce="<?= $ntH($_SESSION['csp_nonce'] ?? '') ?>">
  .nt-count{display:inline-block;min-width:20px;padding:1px 7px;margin-left:6px;border-radius:99px;background:#dc2626;color:#fff;font-size:.72rem;font-weight:700;text-align:center}
  .nt-readall{font:inherit;font-size:.78rem;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid rgba(0,0,0,.16);background:#fff;color:inherit;cursor:pointer}
  .nt-readall:hover{background:#f5f5f2}
  .nt-readall:disabled{opacity:.6;cursor:default}
  .nt-list{list-style:none;margin:0;padding:0}
  .nt-item{display:flex;flex-direction:column;gap:2px;padding:12px 4px;border-top:1px solid rgba(0,0,0,.08);font-size:.9rem;line-height:1.5}
  .nt-item:first-child{border-top:none}
  .nt-item small{color:#7a7f72;font-size:.74rem}
</style>
<?php endif; ?>
