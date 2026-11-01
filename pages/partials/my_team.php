<?php
// "My team" panel for the City Leader dashboard.
// When a city has more than one City Leader, each one chooses which surveyors are in their own team.
// A surveyor's "forgot password" request then goes only to their team leader (helpers/TeamRoster.php,
// helpers/PasswordRequest.php). Surveyors in no team are shown to every City Leader of the city.
// Expects: $pdo, $CURRENT_USER_ID, $CURRENT_USER_CITY_ID. Renders nothing for a city with a single
// City Leader, and never breaks the dashboard if migration 027 has not been run yet.
require_once __DIR__ . '/../../helpers/TeamRoster.php';

$tmRows = [];
try {
    if ($CURRENT_USER_CITY_ID !== null) {
        $tmLeaders = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'city_leader' AND is_active = 1 AND city_id = ?");
        $tmLeaders->execute([(int)$CURRENT_USER_CITY_ID]);
        if ((int)$tmLeaders->fetchColumn() > 1) {
            $tmRows = teamRoster($pdo, (int)$CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID);
        }
    }
} catch (Throwable $e) {
    error_log('my_team partial: ' . $e->getMessage());
}
if ($tmRows):
    $tmH     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tmNonce = $tmH($_SESSION['csp_nonce'] ?? '');
    $tmMine  = count(array_filter($tmRows, static fn(array $r): bool => $r['state'] === 'mine'));
?>
<section class="tm-panel" id="tmPanel" data-csrf="<?= $tmH($_SESSION['csrf_token'] ?? '') ?>" aria-labelledby="tmTitle">
  <details<?= $tmMine === 0 ? ' open' : '' ?>>
    <summary id="tmTitle">My team <span class="tm-count" id="tmCount"><?= $tmMine ?></span></summary>
    <p class="tm-help">
      Your city has more than one City Leader. Choose the surveyors who are in <b>your</b> team: their
      forgot-password requests then come to you only. A surveyor in no team is shown to every City Leader
      of the city until someone adds them.
    </p>
    <ul class="tm-list">
      <?php foreach ($tmRows as $r): ?>
      <li class="tm-item" data-id="<?= (int)$r['id'] ?>" data-state="<?= $tmH($r['state']) ?>">
        <div class="tm-who">
          <b><?= $tmH($r['name']) ?></b>
          <small><?= $tmH($r['email']) ?></small>
        </div>
        <span class="tm-tag tm-<?= $tmH($r['state']) ?>">
          <?= $r['state'] === 'mine' ? 'In your team' : ($r['state'] === 'other' ? 'In ' . $tmH($r['other_leader']) . "'s team" : 'No team yet') ?>
        </span>
        <?php if ($r['state'] === 'other'): ?>
          <span class="tm-note">Only that leader can change this.</span>
        <?php else: ?>
          <button type="button" class="tm-btn"><?= $r['state'] === 'mine' ? 'Remove' : 'Add to my team' ?></button>
        <?php endif; ?>
        <p class="tm-msg" role="status" aria-live="polite"></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
</section>
<style nonce="<?= $tmNonce ?>">
.tm-panel{background:#fff;border:1px solid #e3ded0;border-left:4px solid #3d8a24;border-radius:12px;padding:14px 18px;margin:0 0 16px}
.tm-panel summary{cursor:pointer;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:8px;list-style:none}
.tm-panel summary::-webkit-details-marker{display:none}
.tm-count{padding:2px 9px;border-radius:99px;background:#3d8a24;color:#fff;font-size:.72rem;font-weight:700}
.tm-help{margin:10px 0 12px;font-size:.88rem;line-height:1.5;color:#4b5a3f}
.tm-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.tm-item{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 12px;background:#faf8f1;border:1px solid #e3ded0;border-radius:10px}
.tm-who{display:flex;flex-direction:column;gap:2px;min-width:180px;flex:1}
.tm-who small{color:#4b5a3f;font-size:.8rem}
.tm-tag{padding:2px 9px;border-radius:99px;background:#f1f3ec;font-size:.72rem;font-weight:600;color:#4b5a3f}
.tm-tag.tm-mine{background:#e4f2dc;color:#1f4d14}
.tm-tag.tm-other{background:#f3ece0;color:#7a4b0c}
.tm-note{font-size:.78rem;color:#6b7660}
.tm-btn{height:36px;padding:0 14px;border-radius:8px;font:inherit;font-weight:600;cursor:pointer;background:#3d8a24;color:#fff;border:0}
.tm-item[data-state="mine"] .tm-btn{background:#fff;color:#4b5a3f;border:1px solid #cfc9b8}
.tm-btn:disabled{opacity:.55;cursor:default}
.tm-msg{flex-basis:100%;margin:0;font-size:.85rem}
.tm-msg.err{color:#b42318}
</style>
<script nonce="<?= $tmNonce ?>" src="../js/my_team.js?v=<?= filemtime(__DIR__ . '/../../js/my_team.js') ?>"></script>
<?php endif; ?>
