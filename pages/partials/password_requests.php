<?php
// "Password requests" panel for the City Leader and Admin dashboards.
// People who forgot their password pick a new one and get a 6-digit code; the approver
// phones them, checks it is really them, asks for the code and approves (helpers/PasswordRequest.php).
// Expects: $pdo, $CURRENT_USER_ROLE, $CURRENT_USER_ID, $CURRENT_USER_CITY_ID. Renders nothing when no request is open,
// and never breaks the dashboard if migrations 026/027 have not been run yet.
require_once __DIR__ . '/../../helpers/PasswordRequest.php';
require_once __DIR__ . '/../../helpers/CityDashboard.php'; // cityLocalTime(): UTC -> India time

$prRows = [];
try {
    $prRows = passwordRequestOpenFor($pdo, (string)$CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID, (int)$CURRENT_USER_ID);
} catch (Throwable $e) {
    error_log('password_requests partial: ' . $e->getMessage());
}
if ($prRows):
    $prH     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $prNonce = $prH($_SESSION['csp_nonce'] ?? '');
    $prAdmin = $CURRENT_USER_ROLE === 'admin';
?>
<section class="pr-panel" id="prPanel" data-csrf="<?= $prH($_SESSION['csrf_token'] ?? '') ?>" aria-labelledby="prTitle">
  <h3 id="prTitle">Password requests <span class="pr-count" id="prCount"><?= count($prRows) ?></span></h3>
  <p class="pr-help">
    <b>Contact the <?= $prAdmin ? 'person' : 'surveyor' ?> on a number you already know</b> (not one they send you), check it is really them,
    then ask them to read out the 6-digit code shown on their screen. Approve only if you recognise them.
    Their new password starts working once you approve; until then their old one still works.
  </p>
  <ul class="pr-list">
    <?php foreach ($prRows as $r): ?>
    <li class="pr-item" data-id="<?= (int)$r['id'] ?>">
      <div class="pr-who">
        <b><?= $prH($r['name']) ?></b>
        <span class="pr-tag"><?= (string)$r['role'] === 'city_leader' ? 'City Leader' : 'Surveyor' ?></span>
        <?php if (!$prAdmin && (string)$r['role'] === 'surveyor'): ?>
          <?php if ((int)($r['team_leader_id'] ?? 0) === (int)$CURRENT_USER_ID): ?>
            <span class="pr-tag pr-team">In your team</span>
          <?php else: ?>
            <span class="pr-tag pr-noteam" title="Every City Leader of this city can see this request until someone adds the surveyor to their team">No team yet</span>
          <?php endif; ?>
        <?php endif; ?>
        <small><?= $prH($r['email']) ?> · <?= $prH($r['city_name'] ?? 'No city') ?> · asked <?= $prH(cityLocalTime($r['created_at'])) ?></small>
        <small>
          <?php if (trim((string)($r['phone'] ?? '')) !== ''): ?>
            Phone: <a href="tel:<?= $prH(preg_replace('/[^0-9+]/', '', (string)$r['phone'])) ?>"><?= $prH($r['phone']) ?></a>
          <?php else: ?>
            No phone number on file.
          <?php endif; ?>
          · from IP <?= $prH($r['requested_ip']) ?>
        </small>
      </div>
      <div class="pr-act">
        <input class="pr-code" type="text" inputmode="numeric" autocomplete="off" maxlength="9" placeholder="6-digit code" aria-label="6-digit code for <?= $prH($r['name']) ?>">
        <button type="button" class="pr-approve">Approve</button>
        <button type="button" class="pr-dismiss">Dismiss</button>
      </div>
      <p class="pr-msg" role="status" aria-live="polite"></p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<style nonce="<?= $prNonce ?>">
.pr-panel{background:#fff;border:1px solid #e3ded0;border-left:4px solid #d97706;border-radius:12px;padding:16px 18px;margin:0 0 16px}
.pr-panel h3{margin:0 0 6px;font-size:1.05rem;display:flex;align-items:center;gap:8px}
.pr-team{background:#e4f2dc;color:#1f4d14}
.pr-noteam{background:#f3ece0;color:#7a4b0c}
.pr-count{padding:2px 9px;border-radius:99px;background:#d97706;color:#fff;font-size:.72rem;font-weight:700}
.pr-help{margin:0 0 12px;font-size:.88rem;line-height:1.5;color:#4b5a3f}
.pr-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.pr-item{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px;background:#faf8f1;border:1px solid #e3ded0;border-radius:10px}
.pr-who{display:flex;flex-direction:column;gap:2px;min-width:200px;flex:1}
.pr-who small{color:#4b5a3f;font-size:.8rem}
.pr-tag{align-self:flex-start;padding:1px 8px;border-radius:99px;background:#f1f3ec;font-size:.7rem;font-weight:600;color:#4b5a3f}
.pr-act{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.pr-code{width:130px;height:38px;padding:0 10px;border:1px solid #cfc9b8;border-radius:8px;font-size:1rem;letter-spacing:.1em;text-align:center}
.pr-approve,.pr-dismiss{height:38px;padding:0 14px;border-radius:8px;font:inherit;font-weight:600;cursor:pointer}
.pr-approve{background:#3d8a24;color:#fff;border:0}
.pr-dismiss{background:#fff;color:#4b5a3f;border:1px solid #cfc9b8}
.pr-approve:disabled,.pr-dismiss:disabled{opacity:.55;cursor:default}
.pr-msg{flex-basis:100%;margin:0;font-size:.85rem;min-height:0}
.pr-msg.err{color:#b42318}
.pr-msg.ok{color:#1f4d14;font-weight:600}
.pr-item.done{opacity:.8}
</style>
<script nonce="<?= $prNonce ?>" src="../js/password_requests.js?v=<?= filemtime(__DIR__ . '/../../js/password_requests.js') ?>"></script>
<?php endif; ?>
