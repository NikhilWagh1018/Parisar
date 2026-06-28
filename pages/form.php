<?php
function isValidGPS($val) {
    if (trim($val) === '') return true;
    return (bool) preg_match('/^-?[0-9]{1,3}[.][0-9]+[,][ ]*-?[0-9]{1,3}[.][0-9]+$/', trim($val));
}

declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';

$segmentIdParam = isset($_GET['segment_id']) ? (int)$_GET['segment_id'] : 0;

// Fetch segment + road context for the header
$roadIdForForm = 0;
$segNum        = 0;
$segStartDist  = 0.0;
$segEndDist    = 0.0;
$segLength     = 0.0;
$totalSegments = 0;
$roadName      = '';

if ($segmentIdParam > 0) {
    $stmtSeg = $pdo->prepare(
        'SELECT s.road_id, s.segment_number, s.start_distance, s.end_distance, s.length,
                r.name AS road_name
         FROM   segments s
         JOIN   roads r ON r.id = s.road_id
         WHERE  s.id = ?
         LIMIT  1'
    );
    $stmtSeg->execute([$segmentIdParam]);
    $rowSeg = $stmtSeg->fetch(PDO::FETCH_ASSOC);
    if ($rowSeg) {
        $roadIdForForm = (int)$rowSeg['road_id'];
        $segNum        = (int)$rowSeg['segment_number'];
        $segStartDist  = (float)$rowSeg['start_distance'];
        $segEndDist    = (float)$rowSeg['end_distance'];
        $segLength     = (float)$rowSeg['length'];
        $roadName      = $rowSeg['road_name'];

        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM segments WHERE road_id = ?');
        $stmtCount->execute([$roadIdForForm]);
        $totalSegments = (int)$stmtCount->fetchColumn();
    }
}

function fmtDist(float $m): string {
    return ((floor($m) == $m) ? (string)(int)$m : number_format($m, 0)) . 'm';
}

$startLabel = fmtDist($segStartDist);
$endLabel   = fmtDist($segEndDist);

if ($segNum > 0) {
    $em    = "\xe2\x80\x94";  // em-dash
    $arrow = "\xe2\x86\x92";  // right arrow
    $dot   = "\xc2\xb7";      // middle dot
    $pageTitle    = "Segment {$segNum} Audit {$em} {$startLabel} to {$endLabel}";
    $pageSubtitle = $totalSegments > 0
        ? "Segment {$segNum} of {$totalSegments} {$dot} " . fmtDist($segLength) . " long"
        : fmtDist($segLength) . " long";
    $topbarTitle  = "Segment {$segNum} Audit";
    $topbarSub    = "{$startLabel} {$arrow} {$endLabel}";
    $htmlTitle    = "Segment {$segNum} Audit {$em} CycleAudit";
    $breadcrumb   = $roadName ? htmlspecialchars($roadName, ENT_QUOTES, 'UTF-8') : 'Road Audit';
} else {
    $pageTitle    = 'Full Segment Audit';
    $pageSubtitle = 'Fill in all details for this segment. Required fields are marked with *';
    $topbarTitle  = 'Full Segment Audit';
    $topbarSub    = "CycleAudit · Parisar";
    $htmlTitle    = "Segment Audit Form — CycleAudit";
    $breadcrumb   = 'Road Audit';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf" id="csrf-meta" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($htmlTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/form.css">
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/form-overlay.css">
</head>
<body>

<!-- Top bar -->
<div class="form-topbar">
  <div class="form-topbar-logo">🚲</div>
  <div class="form-topbar-title">
    <?php echo htmlspecialchars($topbarTitle, ENT_QUOTES, 'UTF-8'); ?>
    <span><?php echo htmlspecialchars($topbarSub, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <?php if ($segNum > 0 && $totalSegments > 0): ?>
  <div class="topbar-seg-badge"><?php echo $segNum; ?> / <?php echo $totalSegments; ?></div>
  <?php endif; ?>
</div>

<form id="auditForm" onsubmit="event.preventDefault(); submitFullAudit();">

  <!-- Hidden fields — populated by form.js from URL params -->
  <input type="hidden" name="segment_id" id="segment_id">
  <input type="hidden" name="session_id" id="session_id">
  <input type="hidden" name="road_id"    id="road_id"    value="<?php echo $roadIdForForm; ?>">

  <!-- Confirm / Reset overlay -->
  <div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
      <div class="confirm-icon">🔄</div>
      <h4>Reset the Form?</h4>
      <p>All entered data will be permanently cleared.</p>
      <div class="confirm-btns">
        <button type="button" class="confirm-yes" onclick="doReset()">Yes, Reset</button>
        <button type="button" class="confirm-no"  onclick="closeConfirm()">Cancel</button>
      </div>
    </div>
  </div>

  <!-- Scroll-to-top -->
  <button type="button" id="scrollTopBtn"
          onclick="window.scrollTo({top:0,behavior:'smooth'})"
          title="Back to top">↑</button>

  <div class="container">

    <div class="form-page-heading">
      <?php if ($segNum > 0 && $totalSegments > 0): ?>
      <div class="seg-breadcrumb">
        <span><?php echo $breadcrumb; ?></span>
        <span class="seg-breadcrumb-sep">›</span>
        <span>Segment <?php echo $segNum; ?></span>
        <span class="seg-breadcrumb-sep">›</span>
        <span class="seg-breadcrumb-active">Audit Form</span>
      </div>
      <?php endif; ?>
      <h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
      <p><?php echo htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>


    <!-- Section nav -->
    <nav class="section-nav">
      <a href="#sec-landmarks">📍 Landmarks</a>
      <a href="#sec-fixed">🏗 Fixed Obs.</a>
      <a href="#sec-movable">🔄 Movable Obs.</a>
      <a href="#sec-parked">🚗 Parked Vehicles</a>
      <a href="#sec-surface">🛣 Track Surface</a>
      <a href="#sec-intersections">🔀 Intersections</a>
      <a href="#sec-footpath">🚶 Footpath Rating</a>
      <a href="#sec-additional">ℹ️ Additional Info</a>
      <a href="#sec-dimensions">📐 Dimensions</a>
    </nav>

    <!-- ══════════════════════════════════════
         1. LANDMARKS & GPS
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-landmarks">
        <div class="section-card-icon icon-green">📍</div>
        <div>
          <div class="section-card-title section-anchor">Landmarks &amp; GPS</div>
          <div class="section-card-subtitle">Define the start and end points of this segment</div>
        </div>
      </div>
      <div class="section-card-body">
        <div class="field-row">
          <div class="field-group req-field" id="wrap-startLandmark">
            <label>Start Point Landmark <span class="required-star">*</span></label>
            <input type="text" id="startLandmark" name="start_landmark"
                   oninput="clearError('wrap-startLandmark')"
                   autocomplete="off" placeholder="e.g. Near SBI Bank">
            <span class="error-msg">This field is required</span>
          </div>
          <div class="field-group req-field" id="wrap-endLandmark">
            <label>End Point Landmark <span class="required-star">*</span></label>
            <input type="text" id="endLandmark" name="end_landmark"
                   oninput="clearError('wrap-endLandmark')"
                   autocomplete="off" placeholder="e.g. Near Pune University Gate">
            <span class="error-msg">This field is required</span>
          </div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field-group req-field" id="wrap-gpsStart">
            <label>GPS Start Point <span class="required-star">*</span></label>
            <div class="gps-input-row">
              <input type="text" id="gpsStart" name="gps_start"
                     oninput="clearError('wrap-gpsStart'); validateGPSField('gpsStart','wrap-gpsStart')"
                     placeholder="e.g. 18.5204, 73.8567">
              <button type="button" class="gps-btn" onclick="fillGPS('gpsStart')" title="Use my current location">
                <span class="gps-btn-icon">GPS</span>
              </button>
            </div>
            <span class="error-msg" id="gpsStart-error">Enter valid coordinates (e.g. 18.5204, 73.8567)</span>
          </div>
          <div class="field-group req-field" id="wrap-gpsEnd">
            <label>GPS End Point <span class="required-star">*</span></label>
            <div class="gps-input-row">
              <input type="text" id="gpsEnd" name="gps_end"
                     oninput="clearError('wrap-gpsEnd'); validateGPSField('gpsEnd','wrap-gpsEnd')"
                     placeholder="e.g. 18.5214, 73.8577">
              <button type="button" class="gps-btn" onclick="fillGPS('gpsEnd')" title="Use my current location">
                <span class="gps-btn-icon">GPS</span>
              </button>
            </div>
            <span class="error-msg" id="gpsEnd-error">Enter valid coordinates (e.g. 18.5214, 73.8577)</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         2. FIXED OBSTRUCTIONS
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-fixed">
        <div class="section-card-icon icon-orange">🏗</div>
        <div>
          <div class="section-card-title section-anchor">Fixed Obstructions</div>
          <div class="section-card-subtitle">Permanent structures blocking the cycle track</div>
        </div>
      </div>
      <div class="section-card-body">
        <div class="field-group">
          <label class="field-label">Search Fixed Obstructions</label>
          <div class="search-wrapper" id="fixedWrapper">
            <input type="text"
                   placeholder="Click to see options or type to search…"
                   oninput="filterList('fixed',this)"
                   onfocus="openDropdown('fixed')"
                   autocomplete="off">
            <div class="checkbox-container" id="fixedList"></div>
          </div>
          <div class="selected-tags" id="fixedTags"></div>
          <div id="fixedInputs"></div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         3. MOVABLE OBSTRUCTIONS
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-movable">
        <div class="section-card-icon icon-yellow">🔄</div>
        <div>
          <div class="section-card-title section-anchor">Movable Obstructions</div>
          <div class="section-card-subtitle">Temporary or removable items on the track</div>
        </div>
      </div>
      <div class="section-card-body">
        <div class="field-group">
          <label class="field-label">Search Movable Obstructions</label>
          <div class="search-wrapper" id="movableWrapper">
            <input type="text"
                   placeholder="Click to see options or type to search…"
                   oninput="filterList('movable',this)"
                   onfocus="openDropdown('movable')"
                   autocomplete="off">
            <div class="checkbox-container" id="movableList"></div>
          </div>
          <div class="selected-tags" id="movableTags"></div>
          <div id="movableInputs"></div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         4. PARKED VEHICLES
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-parked">
        <div class="section-card-icon icon-red">🚗</div>
        <div>
          <div class="section-card-title section-anchor">Parked Vehicles</div>
          <div class="section-card-subtitle">Reason for vehicles parked on the cycle track</div>
        </div>
      </div>
      <div class="section-card-body">
        <div class="field-group">
          <label class="field-label">Search Parked Vehicle Reasons</label>
          <div class="search-wrapper" id="parkedWrapper">
            <input type="text"
                   placeholder="Click to see options or type to search…"
                   oninput="filterList('parked',this)"
                   onfocus="openDropdown('parked')"
                   autocomplete="off">
            <div class="checkbox-container" id="parkedList"></div>
          </div>
          <div class="selected-tags" id="parkedTags"></div>
          <div id="parkedInputs"></div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         5. TRACK SURFACE & OVERHEAD
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-surface">
        <div class="section-card-icon icon-teal">🛣</div>
        <div>
          <div class="section-card-title section-anchor">Track Surface &amp; Overhead Obstructions</div>
          <div class="section-card-subtitle">Condition of the cycle track surface</div>
        </div>
      </div>
      <div class="section-card-body">

        <div class="field-group">
          <label class="field-label">Cycle Track Missing</label>
          <div class="options">
            <label><input type="radio" name="cycle_track_missing" value="Yes"
                          onchange="toggleMissingLength(this)"> Yes</label>
            <label><input type="radio" name="cycle_track_missing" value="No"
                          onchange="toggleMissingLength(this)"> No</label>
          </div>
          <div id="missingLengthBox">
            <label>⚠ Missing Length (m)</label>
            <input type="text" inputmode="decimal" id="missingLength" name="missing_length"
                   placeholder="Enter missing cycle track length in meters"
                   oninput="missingLengthInput(this)" onblur="missingLengthBlur(this)">
          </div>
        </div>

        <hr class="field-divider">

        <div class="sub-section-label">Surface Issues</div>
        <div class="field-group">
          <div class="options">
            <label><input type="checkbox" name="surface_issues[]" value="gravel">
              Gravel / Sand / Debris / Dirt</label>
            <label><input type="checkbox" name="surface_issues[]" value="loose">
              Loose Interlock Blocks</label>
            <label><input type="checkbox" name="surface_issues[]" value="broken">
              Broken Surface</label>
            <label><input type="checkbox" name="surface_issues[]" value="water">
              Water Stagnation / Surface Undulation</label>
            <label><input type="checkbox" name="surface_issues[]" value="roots">
              Tree Roots</label>
            <label><input type="checkbox" name="surface_issues[]" value="manholes">
              Manholes</label>
            <label><input type="checkbox" name="surface_issues[]" value="cables">
              Exposed Underground Cables</label>
          </div>
        </div>

        <hr class="field-divider">

        <div class="sub-section-label">Overhead Obstructions</div>
        <div class="field-group">
          <div class="options">
            <label><input type="checkbox" name="overhead_issues[]" value="overheadCables">
              Cables</label>
            <label><input type="checkbox" name="overhead_issues[]" value="branches">
              Branches</label>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         6. INTERSECTIONS
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-intersections">
        <div class="section-card-icon icon-blue">🔀</div>
        <div>
          <div class="section-card-title section-anchor">Intersections</div>
          <div class="section-card-subtitle">Junctions and crossings within this segment</div>
        </div>
      </div>
      <div class="section-card-body">
        <div id="intersectionsContainer"></div>
        <button type="button" class="btn-add-intersection"
                onclick="addIntersection()">＋ Add Intersection</button>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         7. FOOTPATH RATING
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-footpath">
        <div class="section-card-icon icon-green">🚶</div>
        <div>
          <div class="section-card-title section-anchor">
            Footpath Rating
          </div>
          <div class="section-card-subtitle">Each criterion is worth 20% of the footpath score</div>
        </div>
        <span class="rating-score" id="footpathScore">0%</span>
      </div>
      <div class="section-card-body">
        <div class="field-group">
          <div class="options">
            <label><input type="checkbox" name="footpath_rating[]" value="minWidth"
                          onchange="updateFootpathScore()">
              Min 1.8m wide</label>
            <label><input type="checkbox" name="footpath_rating[]" value="continuous"
                          onchange="updateFootpathScore()">
              Continuous and levelled</label>
            <label><input type="checkbox" name="footpath_rating[]" value="obstructionFree"
                          onchange="updateFootpathScore()">
              Obstruction free</label>
            <label><input type="checkbox" name="footpath_rating[]" value="disabledFriendly"
                          onchange="updateFootpathScore()">
              Disabled friendly — access ramps with tactile tiles</label>
            <label><input type="checkbox" name="footpath_rating[]" value="comfort"
                          onchange="updateFootpathScore()">
              Comfort — tree shade with seating spaces</label>
          </div>
        </div>

        <hr class="field-divider">

        <div class="field-group">
          <label class="field-label">Can an average cyclist use the segment without getting off the cycle?</label>
          <div class="options">
            <label><input type="radio" name="cyclist_use" value="Yes"> Yes</label>
            <label><input type="radio" name="cyclist_use" value="No"> No</label>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Which surface is better to cycle on?</label>
          <div class="options">
            <label><input type="radio" name="better_surface" value="Cycle Track"> Cycle Track</label>
            <label><input type="radio" name="better_surface" value="Road"> Road</label>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Cycle track surface material</label>
          <div class="options">
            <label><input type="radio" name="surface_material" value="Interlock Blocks"> Interlock Blocks</label>
            <label><input type="radio" name="surface_material" value="Concrete"> Concrete</label>
            <label><input type="radio" name="surface_material" value="Asphalt"> Asphalt</label>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Were there people walking on the cycle track?</label>
          <div class="options">
            <label><input type="radio" name="people_walking" value="Yes"> Yes</label>
            <label><input type="radio" name="people_walking" value="No"> No</label>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Number of signages indicating presence of a cycle track</label>
          <div style="margin-top:8px">
            <div class="counter-row" style="padding:0">
              <div class="counter-ctrl">
                <button type="button" onclick="adjustCounter('signageCount',-1)">−</button>
                <input type="text" inputmode="numeric" pattern="[0-9]*"
                       id="signageCount" name="signage_count"
                       value="" placeholder="0"
                       oninput="counterInput('signageCount')"
                       onblur="counterBlur('signageCount')"
                       onwheel="event.preventDefault()">
                <button type="button" onclick="adjustCounter('signageCount',1)">+</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         8. ADDITIONAL INFORMATION
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-additional">
        <div class="section-card-icon icon-purple">ℹ️</div>
        <div>
          <div class="section-card-title section-anchor">Additional Information</div>
          <div class="section-card-subtitle">Environment and infrastructure details</div>
        </div>
      </div>
      <div class="section-card-body">

        <div class="field-row">
          <div class="field-group">
            <label class="field-label">Shade</label>
            <div class="options">
              <label><input type="radio" name="shade" value="Yes"> Yes</label>
              <label><input type="radio" name="shade" value="No"> No</label>
              <label><input type="radio" name="shade" value="Partial"> Partial</label>
            </div>
          </div>
          <div class="field-group">
            <label class="field-label">Light (after sunset)</label>
            <div class="options">
              <label><input type="radio" name="light_after_sunset" value="Yes"> Yes</label>
              <label><input type="radio" name="light_after_sunset" value="No"> No</label>
              <label><input type="radio" name="light_after_sunset" value="Partial"> Partial</label>
            </div>
          </div>
        </div>

        <hr class="field-divider">

        <div class="field-group">
          <label class="field-label">Geometry of Track</label>
          <div class="options">
            <label><input type="radio" name="track_geometry" value="Road Level"> Road Level</label>
            <label><input type="radio" name="track_geometry" value="Footpath Level"> Footpath Level</label>
            <label><input type="radio" name="track_geometry" value="Segregated from FP&amp;R">
              Segregated from FP&amp;R</label>
            <label><input type="radio" name="track_geometry" value="NA"> NA</label>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Presence of Buffer Zone</label>
          <div class="options">
            <label><input type="radio" name="buffer_zone" value="Segregated"> Segregated</label>
            <label><input type="radio" name="buffer_zone" value="Buffer Zone"> Buffer Zone</label>
            <label><input type="radio" name="buffer_zone" value="None"> None</label>
            <label><input type="radio" name="buffer_zone" value="NA"> NA</label>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════
         9. DIMENSIONS & COMMENTS
    ══════════════════════════════════════ -->
    <div class="section-card">
      <div class="section-card-header" id="sec-dimensions">
        <div class="section-card-icon icon-gray">📐</div>
        <div>
          <div class="section-card-title section-anchor">Segment Dimensions &amp; Comments</div>
          <div class="section-card-subtitle">Physical measurements and surveyor notes</div>
        </div>
      </div>
      <div class="section-card-body">
        <div class="field-row">
          <div class="field-group">
            <label class="field-label">Width of Segment (m)</label>
            <input type="number" name="segment_width"
                   placeholder="e.g. 2.5" min="0" step="0.1">
          </div>
          <div class="field-group">
            <label class="field-label">Length of Segment (m)</label>
            <input type="number" name="segment_length"
                   placeholder="e.g. 500" min="0" step="0.1">
          </div>
        </div>
        <div class="field-group" style="margin-top:14px">
          <label class="field-label">Surveyor's Comments</label>
          <textarea name="comments"
                    placeholder="Enter any observations, issues, or notes about this segment…"
                    rows="4"></textarea>
        </div>
      </div>
    </div>

    <!-- Submit & Reset -->
    <div class="form-actions">
      <button type="submit" class="btn">✓ Submit Full Audit</button>
      <button type="button" class="btn-reset" onclick="resetForm()">
        🔄 Reset / Clear Form
      </button>
    </div>

  </div><!-- /.container -->
</form>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/form.js"></script>

</body>
</html>