<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/collaborations.php  —  Collaborations (admin only)
//  The organisations that work with Parisar, shown under "Collaborations"
//  on the home page. The Admin adds, edits, orders, hides and removes them.
//  The home page section only appears while at least one is shown.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/Collaborators.php';
require_once __DIR__ . '/../repositories/CollaboratorRepository.php';

if ($CURRENT_USER_ROLE !== 'admin') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$rows      = (new CollaboratorRepository($pdo))->all();
$shown     = count(array_filter($rows, static fn(array $r): bool => (int)$r['is_visible'] === 1));
$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'collab';
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$initials  = static function (string $name): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $name, $m);
    $words = $m[0];
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out === '' ? '?' : $out;
};
$notices = [
    'collab_added'   => ['Collaborator added.', 'It now appears on the home page.'],
    'collab_updated' => ['Changes saved.', 'The home page shows the new details.'],
    'collab_shown'   => ['Shown on the home page.', 'Visitors can see it again.'],
    'collab_hidden'  => ['Hidden from the home page.', 'Nothing was deleted. Use Show to bring it back.'],
    'collab_removed' => ['Collaborator removed.', 'It no longer appears on the home page.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Collaborations — CycleAudit</title>
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
      <h1>Collaborations</h1>
      <p>The organisations working with Parisar, shown on the home page.</p>
    </div>
    <button class="btn-new" id="coAddBtn" type="button">+ Add collaborator</button>
  </div>
  <div class="content" id="npApp" data-csrf="<?= $csrf ?>">

    <?php if (isset($notices[$_GET['notice'] ?? ''])): $cn = $notices[$_GET['notice']]; ?>
    <div class="card" role="status" style="border-left:4px solid #16a34a;background:#f0fdf4">
      <p style="margin:0;font-size:.92rem;line-height:1.5"><b><?= $h($cn[0]) ?></b> <?= $h($cn[1]) ?></p>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">
        <h3>Collaborators<?= $rows ? ' (' . count($rows) . ')' : '' ?></h3>
      </div>
      <p class="pd-card-note">
        Visitors see the ones marked “Shown”, in this order, in a Collaborations section on the home page.
        <?php if (!$shown): ?><b>Nothing is shown yet, so the section is not on the home page.</b><?php endif; ?>
        Hide takes one off the page without deleting it. Logos can be PNG, JPG, WebP or GIF, up to 1 MB, and are shown in a circle; the size of each can be set when you add or edit it.
      </p>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>Logo</th><th>Organisation</th><th>Website</th><th>Status</th><th></th></tr></thead>
          <tbody id="coRows">
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="pd-empty">No collaborators yet. Add the first one with “+ Add collaborator”.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $i => $r): $vis = (int)$r['is_visible'] === 1; ?>
            <tr<?= $vis ? '' : ' style="opacity:.62"' ?>>
              <td>
                <?php if ((int)$r['has_logo'] === 1): ?>
                  <span class="co-disc" style="--ls:<?= $h(collaboratorScaleOf($r['logo_scale'] ?? null) / 100) ?>"><img src="../api/admin/collaborator_logo.php?id=<?= (int)$r['id'] ?>&amp;v=<?= (int)strtotime((string)$r['updated_at']) ?>" alt="" loading="lazy"></span>
                <?php else: ?>
                  <span class="co-disc co-disc-empty" aria-hidden="true"><?= $h($initials((string)$r['name'])) ?></span>
                <?php endif; ?>
              </td>
              <td><strong><?= $h($r['name']) ?></strong><?php if (($r['role_label'] ?? '') !== ''): ?><span class="pd-sub"><?= $h($r['role_label']) ?></span><?php endif; ?></td>
              <td><?php if (($r['website_url'] ?? '') !== ''): ?><a class="pd-link" href="<?= $h($r['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h(preg_replace('#^https?://(www\.)?#i', '', (string)$r['website_url'])) ?></a><?php else: ?><span class="pd-sub">No link</span><?php endif; ?></td>
              <td><span class="pd-chip <?= $vis ? 'pd-chip-published' : 'pd-chip-past' ?>"><?= $vis ? 'Shown' : 'Hidden' ?></span></td>
              <td class="pd-nowrap"><div class="pd-actions">
                <button type="button" class="pd-link coMove" data-dir="up" data-id="<?= (int)$r['id'] ?>" aria-label="Move <?= $h($r['name']) ?> up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                <button type="button" class="pd-link coMove" data-dir="down" data-id="<?= (int)$r['id'] ?>" aria-label="Move <?= $h($r['name']) ?> down"<?= $i === count($rows) - 1 ? ' disabled' : '' ?>>↓</button>
                <button type="button" class="pd-link coEdit" data-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>" data-role="<?= $h($r['role_label'] ?? '') ?>" data-url="<?= $h($r['website_url'] ?? '') ?>" data-logo="<?= (int)$r['has_logo'] ?>" data-scale="<?= collaboratorScaleOf($r['logo_scale'] ?? null) ?>" data-logo-src="../api/admin/collaborator_logo.php?id=<?= (int)$r['id'] ?>&amp;v=<?= (int)strtotime((string)$r['updated_at']) ?>">Edit</button>
                <button type="button" class="pd-link coToggle" data-id="<?= (int)$r['id'] ?>" data-do="<?= $vis ? 'hide' : 'show' ?>"><?= $vis ? 'Hide' : 'Show' ?></button>
                <button type="button" class="pd-link pd-link-danger coDelete" data-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>">Remove</button>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>
<div class="ca-modal-bg" id="coFormBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="coFormTitle">
    <h3 id="coFormTitle">Add a collaborator</h3>
    <p>It appears on the home page as soon as you save it.</p>
    <form id="coForm" class="ca-form" novalidate>
      <input type="hidden" name="collaborator_id" value="">
      <div class="ca-field full">
        <label for="coName">Organisation name *</label>
        <input id="coName" name="name" type="text" maxlength="120" autocomplete="off" placeholder="e.g. Pune Municipal Corporation">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="coRole">How they work with Parisar <span style="font-weight:400">(optional)</span></label>
        <input id="coRole" name="role_label" type="text" maxlength="60" autocomplete="off" placeholder="e.g. Funding partner, Knowledge partner">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="coUrl">Website <span style="font-weight:400">(optional)</span></label>
        <input id="coUrl" name="website_url" type="text" maxlength="255" autocomplete="off" inputmode="url" placeholder="https://example.org">
        <div class="ca-hint">Their card on the home page opens this address in a new tab.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="coLogo">Logo <span style="font-weight:400">(optional, PNG, JPG, WebP or GIF, up to 1 MB)</span></label>
        <div class="co-logo-row">
          <span class="co-disc co-disc-lg" id="coDisc" style="--ls:1"><img id="coLogoPrev" alt="" hidden><span class="co-mark" id="coLogoMark" aria-hidden="true">?</span></span>
          <button type="button" class="pd-link pd-link-danger" id="coLogoRemove" hidden>Remove logo</button>
        </div>
        <input id="coLogo" name="logo" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
        <input type="hidden" name="remove_logo" id="coRemoveFlag" value="">
        <div class="ca-hint">Any shape or size works: blank margins are trimmed and the logo is resized and centred in a circle automatically, so every logo looks alike on the home page. Without one, the card shows the organisation's initials.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full co-scale-field" id="coScaleField" hidden>
        <label for="coScale">Logo size in the circle</label>
        <div class="co-scale">
          <input id="coScale" name="logo_scale" type="range" min="<?= COLLAB_SCALE_MIN ?>" max="<?= COLLAB_SCALE_MAX ?>" step="5" value="<?= COLLAB_SCALE_DEFAULT ?>">
          <output id="coScaleOut" for="coScale"><?= COLLAB_SCALE_DEFAULT ?>%</output>
          <button type="button" class="pd-link" id="coScaleReset">Automatic</button>
        </div>
        <div class="ca-hint">The preview above is exactly how it looks on the home page. 100% is the automatic fit; make it smaller or larger if this logo looks too big or too small next to the others.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-modal-actions full">
        <button type="button" class="ca-btn ghost" id="coFormCancel">Cancel</button>
        <button type="submit" class="ca-btn" id="coFormSave">Add collaborator</button>
      </div>
    </form>
  </div>
</div>
<div class="ca-modal-bg" id="coConfirmBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="coConfTitle">
    <h3 id="coConfTitle">Remove this collaborator?</h3>
    <p id="coConfText"></p>
    <div class="ca-modal-actions">
      <button type="button" class="ca-btn ghost" id="coConfCancel">Cancel</button>
      <button type="button" class="ca-btn ca-btn-danger" id="coConfGo">Remove</button>
    </div>
  </div>
</div>
<div class="sb-overlay" id="sb-overlay"></div>
<div class="ca-toast" id="caToast"></div>
<script nonce="<?= $nonce ?>" src="../js/admin_collaborations.js?v=<?= filemtime(__DIR__ . '/../js/admin_collaborations.js') ?>"></script>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
