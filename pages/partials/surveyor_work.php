<?php
// "My assigned segments" for surveyors on the dashboard.
// Expects: $pdo, $CURRENT_USER_ID. Renders nothing if the surveyor has no assignments.
require_once __DIR__ . '/../../repositories/SurveyorWorkRepository.php';

$swRows = (new SurveyorWorkRepository($pdo))->forSurveyor((int)$CURRENT_USER_ID);
if ($swRows):
    $swSum = surveyorWorkSummary($swRows);
    $swH   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="card" id="assignedWork">
  <div class="card-head">
    <h3>📋 My Assigned Segments</h3>
  </div>
  <div class="sw-summary">
    <span class="sw-pill sw-todo">To do <?= $swSum['todo'] ?></span>
    <span class="sw-pill sw-in_progress">In progress <?= $swSum['in_progress'] ?></span>
    <span class="sw-pill sw-needs_revisit">Needs revisit <?= $swSum['needs_revisit'] ?></span>
    <span class="sw-pill sw-submitted">Submitted <?= $swSum['submitted'] ?></span>
    <span class="sw-pill sw-approved">Approved <?= $swSum['approved'] ?></span>
  </div>
  <div class="rd-scroll">
    <table class="rd-table">
      <thead><tr><th>Audit</th><th>Road</th><th>Segment</th><th>Length</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($swRows as $r): ?>
        <tr>
          <td><?= $swH($r['audit_name']) ?> (<?= $swH($r['audit_year']) ?>)</td>
          <td><?= $swH($r['road_name']) ?></td>
          <td>#<?= (int)$r['segment_number'] ?></td>
          <td><?= $swH(number_format((float)$r['length'])) ?> m</td>
          <td>
            <span class="sw-pill sw-<?= $swH($r['state_key']) ?>"><?= $swH($r['state_label']) ?></span>
            <?php if ($r['state_key'] === 'needs_revisit' && !empty($r['review_note'])): ?>
              <div class="sw-note"><?= $swH($r['review_note']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['can_audit']): ?>
              <a class="action-btn btn-audit" href="segment.php?road_id=<?= (int)$r['road_id'] ?>">✏️ Audit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
