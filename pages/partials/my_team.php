<?php
// "My team" screen body (pages/city_team.php): the City Leader's own team on one side, the other
// surveyors of the city on the other, with search and filters. Rows move between the two lists
// when someone is added or removed (js/my_team.js, api/admin/team.php).
// A surveyor's forgot-password request goes only to their team leader; a surveyor in no team is
// shown to every City Leader of the city (helpers/TeamRoster.php, helpers/PasswordRequest.php).
// Expects: $pdo, $CURRENT_USER_ID, $CURRENT_USER_CITY_ID. Never breaks the page if migration 027
// has not been run yet.
require_once __DIR__ . '/../../helpers/TeamRoster.php';

$tmRows   = [];
$tmOthers = 0;
$tmFailed = false;
try {
    if ($CURRENT_USER_CITY_ID !== null) {
        $tmLeaders = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'city_leader' AND is_active = 1 AND city_id = ?");
        $tmLeaders->execute([(int)$CURRENT_USER_CITY_ID]);
        $tmOthers = (int)$tmLeaders->fetchColumn() - 1;
        $tmRows   = teamRoster($pdo, (int)$CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID);
    }
} catch (Throwable $e) {
    $tmFailed = true;
    error_log('my_team partial: ' . $e->getMessage());
}
$tmH     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tmNonce = $tmH($_SESSION['csp_nonce'] ?? '');
$tmJson  = json_encode($tmRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<?php if ($tmFailed): ?>
<section class="tm-box"><p class="tm-help" style="margin:0">The team list could not be loaded. Please try again in a moment.</p></section>
<?php elseif ($CURRENT_USER_CITY_ID === null): ?>
<section class="tm-box"><p class="tm-help" style="margin:0">No city is assigned to your account yet. Please ask an Admin to assign one.</p></section>
<?php else: ?>
<div id="tmApp" data-csrf="<?= $tmH($_SESSION['csrf_token'] ?? '') ?>">
  <p class="tm-intro">
    <?php if ($tmOthers < 1): ?>You are the only City Leader in your city, so every request from your surveyors comes to you. If another City Leader joins, you can split the surveyors here.
    <?php else: ?>Your city has <b><?= $tmOthers + 1 ?></b> City Leaders. Add the surveyors who are in <b>your</b> team: their forgot-password requests then come to you only.<?php endif; ?>
    A surveyor in no team is shown to every City Leader of the city until someone adds them.
  </p>

  <div class="tm-stats" aria-live="polite">
    <div class="tm-stat"><b id="tmStatMine">0</b><span>In my team</span></div>
    <div class="tm-stat"><b id="tmStatFree">0</b><span>No team yet</span></div>
    <div class="tm-stat"><b id="tmStatOther">0</b><span>In other teams</span></div>
  </div>

  <div class="tm-toolbar">
    <input id="tmSearch" class="tm-search" type="search" placeholder="Search by name or email" autocomplete="off" aria-label="Search surveyors">
    <div class="tm-chips" role="group" aria-label="Filter surveyors">
      <button type="button" class="tm-chip on" data-filter="all">All</button>
      <button type="button" class="tm-chip" data-filter="free">No team yet</button>
      <button type="button" class="tm-chip" data-filter="other">In other teams</button>
    </div>
  </div>
  <p class="tm-msg" id="tmMsg" role="status" aria-live="polite"></p>

  <div class="tm-cols">
    <section class="tm-box tm-mine" aria-labelledby="tmMineTitle">
      <h3 id="tmMineTitle">My team <span class="tm-count" id="tmCountMine">0</span></h3>
      <ul class="tm-list" id="tmListMine"></ul>
    </section>
    <section class="tm-box" aria-labelledby="tmAllTitle">
      <h3 id="tmAllTitle">Surveyors <span class="tm-count tm-count-grey" id="tmCountAll">0</span></h3>
      <ul class="tm-list" id="tmListAll"></ul>
    </section>
  </div>
  <script type="application/json" id="tmData" nonce="<?= $tmNonce ?>"><?= $tmJson ?></script>
</div>
<style nonce="<?= $tmNonce ?>">
.tm-intro{margin:0 0 14px;font-size:.9rem;line-height:1.55;color:#4b5a3f;max-width:820px}
.tm-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
.tm-stat{background:#fff;border:1px solid #e3ded0;border-radius:12px;padding:12px 16px;display:flex;flex-direction:column;gap:2px}
.tm-stat b{font-size:1.5rem;line-height:1.1;font-family:'Playfair Display',serif}
.tm-stat span{font-size:.78rem;color:#4b5a3f}
.tm-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:6px}
.tm-search{flex:1;min-width:220px;max-width:380px;height:40px;padding:0 12px;border:1px solid #cfc9b8;border-radius:10px;font:inherit;background:#fff}
.tm-chips{display:flex;flex-wrap:wrap;gap:6px}
.tm-chip{height:34px;padding:0 14px;border-radius:99px;border:1px solid #cfc9b8;background:#fff;color:#4b5a3f;font:inherit;font-size:.85rem;font-weight:600;cursor:pointer}
.tm-chip.on{background:#3d8a24;border-color:#3d8a24;color:#fff}
.tm-msg{min-height:1.2em;margin:4px 2px 8px;font-size:.85rem;color:#1f4d14}
.tm-msg.err{color:#b42318}
.tm-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
@media (max-width:900px){.tm-cols{grid-template-columns:1fr}.tm-stats{grid-template-columns:1fr 1fr 1fr}}
.tm-box{background:#fff;border:1px solid #e3ded0;border-radius:12px;padding:14px 16px}
.tm-mine{border-left:4px solid #3d8a24}
.tm-box h3{margin:0 0 10px;font-size:1.02rem;display:flex;align-items:center;gap:8px}
.tm-count{padding:2px 9px;border-radius:99px;background:#3d8a24;color:#fff;font-size:.72rem;font-weight:700}
.tm-count-grey{background:#8a927f}
.tm-help{font-size:.88rem;line-height:1.5;color:#4b5a3f}
.tm-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.tm-item{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 12px;background:#faf8f1;border:1px solid #e3ded0;border-radius:10px}
.tm-who{display:flex;flex-direction:column;gap:2px;min-width:150px;flex:1;overflow-wrap:anywhere}
.tm-who small{color:#4b5a3f;font-size:.8rem}
.tm-tag{padding:2px 9px;border-radius:99px;background:#f1f3ec;font-size:.72rem;font-weight:600;color:#4b5a3f;white-space:nowrap}
.tm-tag.tm-other{background:#f3ece0;color:#7a4b0c}
.tm-note{font-size:.76rem;color:#6b7660}
.tm-btn{height:34px;padding:0 14px;border-radius:8px;font:inherit;font-size:.88rem;font-weight:600;cursor:pointer;background:#3d8a24;color:#fff;border:0}
.tm-btn.tm-remove{background:#fff;color:#4b5a3f;border:1px solid #cfc9b8}
.tm-btn:disabled{opacity:.55;cursor:default}
.tm-empty{padding:18px 8px;text-align:center;font-size:.86rem;color:#6b7660}
</style>
<script nonce="<?= $tmNonce ?>" src="../js/my_team.js?v=<?= filemtime(__DIR__ . '/../../js/my_team.js') ?>"></script>
<?php endif; ?>
