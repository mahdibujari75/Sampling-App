<?php
/************************************************************
 * Production — production.php
 * Production Days workspace (list + detail shell)
 ************************************************************/

if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/acl.php';
require_login();
require_once APP_ROOT . '/includes/layout.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: '[]', true);
  return is_array($data) ? $data : [];
}

function production_db_file_path(): string {
  $root = realpath(APP_ROOT . '/..');
  $root = $root ?: (APP_ROOT . '/..');
  return rtrim($root, '/') . '/database/production/production_days.json';
}

function projects_db_file_path(): string {
  return defined('PROJECTS_DB_FILE') ? PROJECTS_DB_FILE : (APP_ROOT . '/../database/projects_files/projects.json');
}

function find_project(int $projectId): ?array {
  if ($projectId <= 0) return null;
  return acl_find_project_by_id($projectId);
}

function normalize_state_label(string $state): string {
  $s = strtoupper(trim($state));
  return $s !== '' ? $s : 'UNKNOWN';
}

$me = current_user();
$role = current_role();
$isAdmin = is_admin();
$isManager = has_role('Manager');
$isOffice = has_role('Office');
$isRandD = has_role('R&D');
$isCustomer = has_role('Customer');
$isInternal = has_role(['Admin','Manager','Office','R&D']);

if (!($isInternal || $isCustomer)) {
  acl_access_denied();
}

$PROJECTS_FILE = projects_db_file_path();
$projectId = (int)($_GET['projectId'] ?? ($_GET['id'] ?? 0));
$selectedDate = trim((string)($_GET['date'] ?? ''));
$selectedDayNo = (int)($_GET['dayNo'] ?? 0);

$projectScope = null;
if ($projectId > 0) {
  if ($isCustomer) {
    $projectScope = require_subproject_scope($projectId);
  } else {
    $projectScope = find_project($projectId);
  }
  if (!$projectScope && $isCustomer) {
    acl_access_denied();
  }
}

$productionFile = production_db_file_path();
$productionDataAvailable = is_file($productionFile);
$productionDataNote = $productionDataAvailable ? '' : 'Production tables not available yet';
$productionDays = [];

if ($productionDataAvailable) {
  $productionDays = load_json_array($productionFile);
  if (!is_array($productionDays)) {
    $productionDataAvailable = false;
    $productionDataNote = 'Production tables not available yet';
    $productionDays = [];
  }
}

$accessibleProjectIds = [];
if ($isCustomer) {
  $accessibleProjects = apply_scope_filter_to_project_list_query(acl_load_projects());
  foreach ($accessibleProjects as $p) {
    if (is_array($p)) $accessibleProjectIds[] = (int)($p['id'] ?? 0);
  }
}

$list = [];
foreach ($productionDays as $pd) {
  if (!is_array($pd)) continue;
  $entries = is_array($pd['entries'] ?? null) ? $pd['entries'] : [];
  $matchesScope = false;

  if ($projectId > 0) {
    foreach ($entries as $en) {
      if ((int)($en['projectId'] ?? 0) === $projectId) { $matchesScope = true; break; }
    }
  } else {
    $matchesScope = ($projectId === 0);
  }

  if ($isCustomer) {
    $hasAccessible = false;
    foreach ($entries as $en) {
      if (in_array((int)($en['projectId'] ?? 0), $accessibleProjectIds, true)) { $hasAccessible = true; break; }
    }
    if ($projectId > 0) {
      $matchesScope = $matchesScope && $hasAccessible;
    } else {
      $matchesScope = $hasAccessible;
    }
  }

  if ($matchesScope) {
    $list[] = [
      'jalaliDate' => (string)($pd['jalaliDate'] ?? ''),
      'dayNo' => (int)($pd['dayNo'] ?? 0),
      'status' => (string)($pd['status'] ?? 'Open'),
      'entries' => $entries,
    ];
  }
}

usort($list, function($a, $b){
  $d1 = (string)($a['jalaliDate'] ?? '');
  $d2 = (string)($b['jalaliDate'] ?? '');
  return strcmp($d2, $d1);
});

$selectedDay = null;
foreach ($productionDays as $pd) {
  if (!is_array($pd)) continue;
  $dateMatch = $selectedDate !== '' && (string)($pd['jalaliDate'] ?? '') === $selectedDate;
  $dayNoMatch = $selectedDayNo > 0 && (int)($pd['dayNo'] ?? 0) === $selectedDayNo;
  if ($dateMatch || $dayNoMatch) {
    $selectedDay = $pd;
    break;
  }
}

if ($selectedDay) {
  $entries = is_array($selectedDay['entries'] ?? null) ? $selectedDay['entries'] : [];
  $ok = ($projectId === 0);
  foreach ($entries as $en) {
    if ($projectId > 0 && (int)($en['projectId'] ?? 0) === $projectId) { $ok = true; }
    if ($isCustomer && in_array((int)($en['projectId'] ?? 0), $accessibleProjectIds, true)) { $ok = true; }
  }
  if (!$ok) {
    $selectedDay = null;
  }
}

$stateLabel = normalize_state_label((string)($projectScope['state'] ?? ''));
$stateAllowsCreation = in_array($stateLabel, ['CONFIRMED','EXECUTION','COMPLETED'], true);

$createBlocked = [];
if (!$productionDataAvailable) $createBlocked[] = $productionDataNote;
if (!($isAdmin || $isManager)) $createBlocked[] = 'Creation restricted to Manager/Admin';
if ($projectId <= 0) $createBlocked[] = 'Select a subproject to create a production day';
if ($projectId > 0 && !$stateAllowsCreation) $createBlocked[] = 'Subproject must be in Confirmed or later state';

$canCreate = count($createBlocked) === 0;
$createTitle = $canCreate ? 'Create Production Day' : implode('; ', $createBlocked);

$officeCanIssueToggle = false; // TODO(PAGE-IMP-07): integrate Office issue toggle when available.
$officeCanIssue = $isOffice && $officeCanIssueToggle;

render_header('Production', $role);
?>

  <div class="grid" style="grid-template-columns: repeat(2, minmax(260px, 1fr)); gap:12px;">
    <a class="card" href="/production_plan" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px; ">Production Plans</div>
      <div style="color:var(--muted); font-size:13px;">
        Create, load, and manage daily production plans and generate DP-RMC outputs.
      </div>
    </a>

    <a class="card" href="/production_log" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px; ">Production Log</div>
      <div style="color:var(--muted); font-size:13px;">
        Record execution results and material consumption for completed production days.
      </div>
    </a>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2 style="margin:0;">Production Days</h2>
        <div class="hint">List is scoped to selected subproject when provided.</div>
        <?php if ($projectScope): ?>
          <div class="hint" style="margin-top:6px; font-weight:600; color:var(--text);">
            Subproject: <span class="pill" style="background:#fff;"><?= h((string)($projectScope['code'] ?? '')) ?></span>
            <span class="pill" style="background:#fff;">State: <?= h($stateLabel) ?></span>
          </div>
        <?php elseif ($projectId > 0): ?>
          <div class="hint" style="margin-top:6px;">Subproject not found or out of scope.</div>
        <?php endif; ?>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
        <button class="btn" type="button" <?= $canCreate ? '' : 'disabled' ?> title="<?= h($createTitle) ?>">Create Production Day</button>
        <?php if (!$canCreate && !$productionDataAvailable): ?>
          <div class="hint" style="margin-top:8px; max-width:240px;">Production tables not available yet</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$productionDataAvailable): ?>
      <div class="hint" style="margin-top:10px;">Production tables not available yet. TODO(PAGE-IMP-07): wire creation/list once tables are available.</div>
    <?php elseif (!count($list)): ?>
      <div class="hint" style="margin-top:10px;">No production days found for this scope.</div>
    <?php else: ?>
      <table style="margin-top:10px;">
        <thead>
          <tr>
            <th style="width:120px;">Day No.</th>
            <th style="width:160px;">Date</th>
            <th style="width:160px;">Status</th>
            <th>Subprojects</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $pd): ?>
            <?php
              $dateStr = (string)($pd['jalaliDate'] ?? '');
              $dayNo = (int)($pd['dayNo'] ?? 0);
              $entries = is_array($pd['entries'] ?? null) ? $pd['entries'] : [];
              $subCodes = [];
              foreach ($entries as $en) {
                $subCodes[] = (string)($en['subCode'] ?? '');
              }
              $subCodes = array_filter($subCodes);
              $detailUrl = '/production?date=' . urlencode($dateStr) . ($projectId>0 ? ('&projectId=' . $projectId) : '') . ($dayNo>0 ? ('&dayNo=' . $dayNo) : '');
            ?>
            <tr>
              <td><a href="<?= h($detailUrl) ?>" style="text-decoration:none; color:inherit;">Day <?= h((string)$dayNo) ?></a></td>
              <td><?= h($dateStr) ?></td>
              <td><?= h((string)($pd['status'] ?? '')) ?></td>
              <td><?= count($subCodes) ? h(implode(', ', $subCodes)) : '<span class="hint">No linked subprojects</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2 style="margin:0;">Production Day Detail</h2>
        <div class="hint">Panels render even when empty. Actions are role-gated.</div>
      </div>
      <?php if ($selectedDay): ?>
        <div class="pill" style="background:#fff;">Day <?= h((string)($selectedDay['dayNo'] ?? '')) ?> — <?= h((string)($selectedDay['jalaliDate'] ?? '')) ?></div>
      <?php endif; ?>
    </div>

    <?php if (!$selectedDay): ?>
      <div class="hint" style="margin-top:10px;">Select a production day from the list to view details.</div>
    <?php else: ?>
      <div class="row" style="margin-top:10px; gap:12px; flex-wrap:wrap;">
        <?php $status = (string)($selectedDay['status'] ?? 'Planned'); ?>
        <div class="pill" style="background:#fff;">Status: <?= h($status) ?></div>
      </div>

      <div class="card" style="margin-top:12px; padding:14px; background:#fff;">
        <div class="row" style="align-items:flex-start;">
          <div>
            <h3 style="margin:0; font-size:15px;">Runs</h3>
            <div class="hint">Run Type and Status with SCF/SFF linkage (display only).</div>
          </div>
          <?php if ($isAdmin || $isManager): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <button class="btn btn-ghost" type="button" <?= $productionDataAvailable ? '' : 'disabled' ?> title="<?= h($productionDataAvailable ? 'Add run' : $productionDataNote) ?>">Add run</button>
              <button class="btn btn-ghost" type="button" <?= $productionDataAvailable ? '' : 'disabled' ?> title="<?= h($productionDataAvailable ? 'Remove run' : $productionDataNote) ?>">Remove run</button>
            </div>
          <?php endif; ?>
        </div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:140px;">Run Type</th>
              <th style="width:140px;">Run Status</th>
              <th>Linked SCF/SFF</th>
              <?php if ($isAdmin || $isManager): ?><th style="width:160px;">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php
              $runs = is_array($selectedDay['runs'] ?? null) ? $selectedDay['runs'] : [];
              if (!count($runs)):
            ?>
              <tr>
                <td colspan="<?= ($isAdmin || $isManager) ? 4 : 3 ?>"><div class="hint">No runs recorded. TODO(PAGE-IMP-07): Load runs when production tables are available.</div></td>
              </tr>
            <?php else: ?>
              <?php foreach ($runs as $run): ?>
                <?php
                  $runType = (string)($run['type'] ?? 'Sample');
                  $runStatus = (string)($run['status'] ?? 'Planned');
                  $linkage = (string)($run['link'] ?? '');
                  $linkDisplay = $linkage !== '' ? h($linkage) : '<span class="hint">None</span>';
                  $canMarkProduced = ($isAdmin || $isManager) && $productionDataAvailable;
                  $markTitle = $canMarkProduced ? 'Mark as Produced' : ($productionDataAvailable ? 'Admin/Manager only' : $productionDataNote);
                ?>
                <tr>
                  <td><?= h($runType) ?></td>
                  <td><?= h($runStatus) ?></td>
                  <td><?= $linkDisplay ?></td>
                  <?php if ($isAdmin || $isManager): ?>
                    <td>
                      <button class="btn btn-ghost" type="button" <?= $canMarkProduced ? '' : 'disabled' ?> title="<?= h($markTitle) ?>">Mark Produced</button>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top:12px; padding:14px; background:#fff;">
        <h3 style="margin:0; font-size:15px;">Materials Aggregation Preview</h3>
        <?php if (isset($selectedDay['materialsPreview'])): ?>
          <div style="margin-top:8px;">Preview available.</div>
        <?php else: ?>
          <div class="hint" style="margin-top:8px;">TODO(PAGE-IMP-07): Render computed materials aggregation preview when data is available.</div>
        <?php endif; ?>
      </div>

      <div class="card" style="margin-top:12px; padding:14px; background:#fff;">
        <div class="row" style="align-items:flex-start;">
          <div>
            <h3 style="margin:0; font-size:15px;">Inventory Documents</h3>
            <div class="hint">MRQ / MRS / RMC placeholders.</div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php
              $canDraft = ($isAdmin || $isManager || $isOffice) && $productionDataAvailable;
              $canIssue = ($isAdmin || $isManager || $officeCanIssue) && $productionDataAvailable;
              $draftTitle = $canDraft ? 'Generate draft' : ($productionDataAvailable ? 'Admin/Manager/Office only' : $productionDataNote);
              $issueTitle = $canIssue ? 'Issue' : ($productionDataAvailable ? ($officeCanIssue ? 'Office issue enabled' : 'Manager/Admin only by default') : $productionDataNote);
            ?>
            <button class="btn btn-ghost" type="button" <?= $canDraft ? '' : 'disabled' ?> title="<?= h($draftTitle) ?>">Generate MRQ draft</button>
            <button class="btn btn-ghost" type="button" <?= $canDraft ? '' : 'disabled' ?> title="<?= h($draftTitle) ?>">Generate MRS draft</button>
            <button class="btn btn-ghost" type="button" <?= $canIssue ? '' : 'disabled' ?> title="<?= h($issueTitle) ?>">Issue MRQ</button>
            <button class="btn btn-ghost" type="button" <?= $canIssue ? '' : 'disabled' ?> title="<?= h($issueTitle) ?>">Issue MRS</button>
            <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-07): Wire RMC auto-generation when MRQ/MRS are locked.">Auto-generate RMC</button>
          </div>
        </div>
        <div class="hint" style="margin-top:8px;">TODO(PAGE-IMP-07): Display MRQ/MRS/RMC metadata once doc tables are available.</div>
      </div>

      <div class="card" style="margin-top:12px; padding:14px; background:#fff;">
        <h3 style="margin:0; font-size:15px;">Reconciliation Summary</h3>
        <?php if (isset($selectedDay['reconciliation'])): ?>
          <div style="margin-top:8px;">Summary available.</div>
        <?php else: ?>
          <div class="hint" style="margin-top:8px;">TODO(PAGE-IMP-07): Render MRQ vs MRS vs RMC totals when data exists.</div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
        <div class="card" style="margin-top:12px; padding:14px; background:#fff;">
          <div class="row" style="align-items:flex-start;">
            <div>
              <h3 style="margin:0; font-size:15px;">PP Panel</h3>
              <div class="hint">Admin only.</div>
            </div>
            <button class="btn btn-ghost" type="button" <?= $productionDataAvailable ? '' : 'disabled' ?> title="<?= h($productionDataAvailable ? 'Generate PP draft' : $productionDataNote) ?>">Generate PP draft</button>
          </div>
          <div class="hint" style="margin-top:8px;">TODO(PAGE-IMP-07): Display PP metadata when available.</div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>

</div>

<?php render_footer(); ?>
