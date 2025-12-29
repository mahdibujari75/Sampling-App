<?php
/************************************************************
 * PROJECTS PAGE — Grouped projects + subproject detail
 * File: public_html/app/pages/projects.php
 *
 * UI:
 * - Main list: project rows only (NO step/status here)
 * - Chevron (SVG) at far right expands subprojects
 * - Subprojects table shows Type/Step/Status/Open
 *
 * Logic:
 * - Open subprojects
 * - Detail view shows Step at top-right
 * - Admin: next/prev step + status update
 * - Generators card per step (step 5 rules implemented)
 * - Documents card: Mails(Received/Sent), PI, SCF or SFF
 * - Logs card: activityLog stored in projects.json
 ************************************************************/

/************************************************************
 * SECTION 1 — Auth Guard
 ************************************************************/
require_once APP_ROOT . "/includes/auth.php";
require_login();

/************************************************************
 * SECTION 2 — Shared Layout
 ************************************************************/
require_once APP_ROOT . "/includes/layout.php";

/************************************************************
 * SECTION 3 — Config
 ************************************************************/
$PROJECTS_FILE = PROJECTS_DB_FILE;

/************************************************************
 * SECTION 4 — Steps (EDIT HERE)
 ************************************************************/
$STEP_NAMES = [
  1  => "Pre Agreement",
  2  => "Data Gathering",
  3  => "Detailed Proposal",
  4  => "Initial Confirmation",
  5  => "Performa Invoice and Formulation Check",
  6  => "Customer Confirmation",
  7  => "Supply",
  8  => "Production and Delivery",
  9  => "Shipment",
  10 => "Payments",
];

function step_label(int $step, array $STEP_NAMES): string {
  $name = $STEP_NAMES[$step] ?? ("Step " . $step);
  return $step . " — " . $name;
}

/************************************************************
 * SECTION 5 — Helpers
 ************************************************************/
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: "[]", true);
  return is_array($data) ? $data : [];
}

function save_json_array_atomic(string $file, array $data): void {
  $dir = dirname($file);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $tmp = $file . ".tmp";
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  rename($tmp, $file);
}

function find_project_index_by_id(array $projects, int $id): int {
  foreach ($projects as $i => $p) {
    if ((int)($p["id"] ?? 0) === $id) return $i;
  }
  return -1;
}

function normalize_status(string $s): string {
  $s = trim($s);

  // Accept both legacy statuses (older PMA versions) and new workflow statuses
  $map = [
    "Active"    => "In progress",
    "On Hold"   => "Waiting",
    "Completed" => "Closed",
    "Canceled"  => "Closed",
  ];
  if (isset($map[$s])) return $map[$s];

  // Normalize common variants
  $sLower = strtolower($s);
  if ($sLower === "in progress" || $sLower === "inprogress") return "In progress";
  if ($sLower === "open") return "Open";
  if ($sLower === "waiting" || $sLower === "onhold" || $sLower === "on hold") return "Waiting";
  if ($sLower === "closed" || $sLower === "done") return "Closed";

  $allowed = ["Open","In progress","Waiting","Closed"];
  return in_array($s, $allowed, true) ? $s : "Open";
}
function normalize_step(int $step): int {
  if ($step < 1) return 1;
  if ($step > 10) return 10;
  return $step;
}

function safe_group_id(string $key): string {
  // stable short id for DOM
  return "g_" . substr(sha1($key), 0, 10);
}

function count_files(string $dir): int {
  if (!is_dir($dir)) return 0;
  $n = 0;
  $dh = @opendir($dir);
  if (!$dh) return 0;
  while (($f = readdir($dh)) !== false) {
    if ($f === "." || $f === "..") continue;
    if (is_file($dir . "/" . $f)) $n++;
  }
  closedir($dh);
  return $n;
}

function latest_files(string $dir, int $limit = 8): array {
  if (!is_dir($dir)) return [];
  $items = [];
  $dh = @opendir($dir);
  if (!$dh) return [];
  while (($f = readdir($dh)) !== false) {
    if ($f === "." || $f === "..") continue;
    $path = $dir . "/" . $f;
    if (is_file($path)) $items[] = ["name" => $f, "mtime" => @filemtime($path) ?: 0];
  }
  closedir($dh);
  usort($items, fn($a,$b) => $b["mtime"] <=> $a["mtime"]);
  $items = array_slice($items, 0, $limit);
  return array_map(fn($x) => $x["name"], $items);
}

/************************************************************
 * SECTION 6 — Activity Log (stored in projects.json)
 ************************************************************/
function append_activity_log(array &$project, array $event): void {
  if (!isset($project["activityLog"]) || !is_array($project["activityLog"])) {
    $project["activityLog"] = [];
  }
  // prepend newest
  array_unshift($project["activityLog"], $event);
  // keep last 200
  if (count($project["activityLog"]) > 200) {
    $project["activityLog"] = array_slice($project["activityLog"], 0, 200);
  }
}

/************************************************************
 * SECTION 7 — Current User + Role
 ************************************************************/
$me = current_user();
$role = (string)($me["role"] ?? "Observer");
$isAdmin = ($role === "Admin");
$isObserver = ($role === "Observer");
$isClient = ($role === "Client");
$myUsername = (string)($me["username"] ?? "");

/************************************************************
 * SECTION 8 — CSRF
 ************************************************************/
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION["csrf_projects_manage"])) {
  $_SESSION["csrf_projects_manage"] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION["csrf_projects_manage"];

function require_csrf(string $expected): void {
  $posted = (string)($_POST["csrf"] ?? "");
  if (!$posted || !hash_equals($expected, $posted)) {
    http_response_code(400);
    echo "Bad request (CSRF).";
    exit;
  }
}

/************************************************************
 * SECTION 9 — Load Projects
 ************************************************************/
$projects = load_json_array($PROJECTS_FILE);

/************************************************************
 * SECTION 10 — Admin Actions (step/status + logging)
 ************************************************************/
$flash_ok = "";
$flash_err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!$isAdmin) { http_response_code(403); echo "Forbidden"; exit; }
  require_csrf($CSRF);

  $action = trim((string)($_POST["action"] ?? ""));
  $id = (int)($_POST["id"] ?? 0);

  $idx = find_project_index_by_id($projects, $id);
  if ($idx < 0) {
    $flash_err = "Subproject not found.";
  } else {
    $actor = (string)($me["username"] ?? "Admin");
    $now   = date("Y-m-d H:i:s");

    if ($action === "step_delta") {
      $delta = (int)($_POST["delta"] ?? 0);
      $cur = (int)($projects[$idx]["step"] ?? 1);
      $new = normalize_step($cur + $delta);

      $projects[$idx]["step"] = $new;
      $projects[$idx]["updatedAt"] = $now;

      append_activity_log($projects[$idx], [
        "ts" => $now,
        "by" => $actor,
        "type" => "STEP_UPDATE",
        "msg" => "Step changed from {$cur} to {$new} by Admin.",
      ]);

      save_json_array_atomic($PROJECTS_FILE, $projects);
      $projects = load_json_array($PROJECTS_FILE);
      $flash_ok = "Step updated.";
    }

    
    elseif ($action === "set_workflow") {
      $curStep = (int)($projects[$idx]["step"] ?? 1);
      $curStatus = (string)($projects[$idx]["status"] ?? "Open");

      $newStep = normalize_step((int)($_POST["step"] ?? $curStep));
      $newStatus = normalize_status((string)($_POST["status"] ?? $curStatus));

      $projects[$idx]["step"] = $newStep;
      $projects[$idx]["status"] = $newStatus;
      $projects[$idx]["updatedAt"] = $now;

      append_activity_log($projects[$idx], [
        "ts" => $now,
        "by" => $actor,
        "type" => "WORKFLOW_UPDATE",
        "msg" => "Workflow updated (Step {$curStep} → {$newStep}, Status {$curStatus} → {$newStatus}) by Admin.",
      ]);

      save_json_array_atomic($PROJECTS_FILE, $projects);
      $projects = load_json_array($PROJECTS_FILE);
      $flash_ok = "Workflow updated.";
    }
if ($action === "set_status") {
      $old = (string)($projects[$idx]["status"] ?? "Active");
      $new = normalize_status((string)($_POST["status"] ?? "Active"));
      $projects[$idx]["status"] = $new;
      $projects[$idx]["updatedAt"] = $now;

      append_activity_log($projects[$idx], [
        "ts" => $now,
        "by" => $actor,
        "type" => "STATUS_UPDATE",
        "msg" => "Status changed from {$old} to {$new} by Admin.",
      ]);

      save_json_array_atomic($PROJECTS_FILE, $projects);
      $projects = load_json_array($PROJECTS_FILE);
      $flash_ok = "Status updated.";
    }
  }
}

/************************************************************
 * SECTION 11 — Filter Projects by Role
 ************************************************************/
$visible = [];
foreach ($projects as $p) {
  if ($isAdmin || $isObserver) { $visible[] = $p; continue; }
  if ($isClient && (string)($p["customerUsername"] ?? "") === $myUsername) $visible[] = $p;
}

/************************************************************
 * SECTION 12 — Group Subprojects into One Row
 * Group key = customerUsername + code + projectName
 ************************************************************/
$groups = [];
foreach ($visible as $p) {
  $cust = (string)($p["customerUsername"] ?? "");
  $code = (string)($p["code"] ?? "");
  $name = (string)($p["projectName"] ?? "");
  $key  = $cust . "|" . $code . "|" . $name;

  if (!isset($groups[$key])) {
    $groups[$key] = [
      "key" => $key,
      "customerUsername" => $cust,
      "code" => $code,
      "projectName" => $name,
      "subs" => [],
    ];
  }
  $groups[$key]["subs"][] = $p;
}

// Sort subprojects C/F/O
$order = ["C"=>1,"F"=>2,"O"=>3];
foreach ($groups as &$g) {
  usort($g["subs"], function($a,$b) use ($order){
    $ta = (string)($a["type"] ?? "");
    $tb = (string)($b["type"] ?? "");
    return ($order[$ta] ?? 99) <=> ($order[$tb] ?? 99);
  });
}
unset($g);

$groupList = array_values($groups);

/************************************************************
 * SECTION 13 — Detail View (?id=subproject_id)
 ************************************************************/
$viewId = (int)($_GET["id"] ?? 0);
$viewProject = null;

if ($viewId > 0) {
  foreach ($visible as $p) {
    if ((int)($p["id"] ?? 0) === $viewId) { $viewProject = $p; break; }
  }
}

/************************************************************
 * SECTION 14 — Render
 ************************************************************/
render_header("Projects", $role);

if ($flash_ok)  echo '<div class="flash ok">'.h($flash_ok).'</div>';
if ($flash_err) echo '<div class="flash err">'.h($flash_err).'</div>';
?>

<style>
/* UI helpers */
.p-topbar{ display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
.p-inline{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
.p-btnbar a, .p-btnbar button{ height:42px; display:inline-flex; align-items:center; }
.p-kv{ display:grid; grid-template-columns:160px 1fr; gap:10px; }
.p-kv .k{ color:rgba(0,0,0,.6); font-size:13px; }
.p-kv .v{ font-weight:600; }
.p-wrap{ overflow-wrap:anywhere; word-break:break-word; }
.p-mini-title{ font-size:16px; margin:0; }

.chev-btn{
  width:34px; height:34px; border-radius:10px;
  border:1px solid rgba(0,0,0,.12);
  background:rgba(255,255,255,.55);
  display:inline-flex; align-items:center; justify-content:center;
  cursor:pointer;
}
.chev-btn svg{ width:16px; height:16px; opacity:.75; transition: transform .15s ease; }
tr[data-open="1"] .chev-btn svg{ transform: rotate(180deg); }
.subrow{ display:none; }
tr[data-open="1"] + tr.subrow{ display:table-row; }
.subcard{ padding:10px 0 0 0; }
.mini-pill{ font-size:12px; padding:3px 10px; border-radius:999px; border:1px solid rgba(0,0,0,.08); background:rgba(255,255,255,.55); display:inline-block; }
</style>

<script>
function toggleGroup(rowId){
  const mainRow = document.getElementById(rowId);
  if(!mainRow) return;
  const isOpen = mainRow.getAttribute("data-open") === "1";
  mainRow.setAttribute("data-open", isOpen ? "0" : "1");
}
</script>

<!-- =======================================================
     SECTION A — Projects List (Grouped) — NO step/status here
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Projects</h2>
    <div class="hint">Expand a project row to see its subprojects (C/F/O).</div>
  </div>

  <table style="margin-top:10px;">
    <thead>
      <tr>
        <th>Project</th>
        <th style="width:180px;">Customer</th>
        <th style="width:100px;">Code</th>
        <th style="width:120px;">Types</th>
        <th style="width:60px; text-align:right;"> </th>
      </tr>
    </thead>

    <tbody>
      <?php if (count($groupList) === 0): ?>
        <tr><td colspan="5" class="hint">No projects available for your account.</td></tr>
      <?php else: ?>
        <?php foreach ($groupList as $g): ?>
          <?php
            $subs = (array)($g["subs"] ?? []);
            $cust = (string)($g["customerUsername"] ?? "");
            $code = (string)($g["code"] ?? "");
            $name = (string)($g["projectName"] ?? "");
            $groupId = safe_group_id($g["key"]);

            $typesSet = [];
            foreach ($subs as $sp) {
              $t = (string)($sp["type"] ?? "");
              if ($t !== "") $typesSet[$t] = true;
            }
            $orderTypes = ["C","F","O"];
            $types = [];
            foreach ($orderTypes as $t) if (!empty($typesSet[$t])) $types[] = $t;
            foreach (array_keys($typesSet) as $t) if (!in_array($t, $types, true)) $types[] = $t;
            $typesLabel = count($types) ? implode("/", $types) : "-";
          ?>

          <!-- Main Project Row -->
          <tr id="<?= h($groupId) ?>" data-open="0">
            <td><?= h($name) ?></td>
            <td><?= h($cust) ?></td>
            <td><?= h($code) ?></td>
            <td><span class="pill"><?= h($typesLabel) ?></span></td>
            <td style="text-align:right;">
              <button class="chev-btn" type="button" onclick="toggleGroup('<?= h($groupId) ?>')" aria-label="Toggle subprojects">
                <!-- Tiny Chevron SVG -->
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </td>
          </tr>

          <!-- Subprojects Row (collapsible) -->
          <tr class="subrow">
            <td colspan="5" class="subcard">
              <div class="card" style="margin:0;">
                <div class="row">
                  <h2 class="p-mini-title">Subprojects</h2>
                  <div class="hint"><?= count($subs) ?> item(s)</div>
                </div>

                <table style="margin-top:10px;">
                  <thead>
                    <tr>
                      <th style="width:90px;">Type</th>
                      <th style="width:260px;">Step</th>
                      <th style="width:160px;">Status</th>
                      <th style="width:140px;">Open</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subs as $sp): ?>
                      <?php
                        $sid = (int)($sp["id"] ?? 0);
                        $type = (string)($sp["type"] ?? "");
                        $step = normalize_step((int)($sp["step"] ?? 1));
                        $status = (string)($sp["status"] ?? "Active");
                      ?>
                      <tr>
                        <td><span class="pill"><?= h($type) ?></span></td>
                        <td><?= h(step_label($step, $STEP_NAMES)) ?></td>
                        <td><?= h($status) ?></td>
                        <td>
                          <a class="btn btn-ghost" href="/projects?id=<?= h((string)$sid) ?>" style="text-decoration:none;">Open</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

              </div>
            </td>
          </tr>

        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($viewProject): ?>
  <?php
    // Internal baseDir for scanning docs (NOT shown)
    $base = (string)($viewProject["baseDir"] ?? "");
    $type = (string)($viewProject["type"] ?? "");
    $step = normalize_step((int)($viewProject["step"] ?? 1));
    $status = normalize_status((string)($viewProject["status"] ?? "Open"));
    $pid = (int)($viewProject["id"] ?? 0);

    // Documents directories (NOT shown)
    $piDir   = $base . "/PI";
    $scfDir  = $base . "/SCF";
    $sffDir  = $base . "/SFF";
    $inDir   = $base . "/Attachments/Incoming";
    $outDir  = $base . "/Attachments/Outgoing";

    $rmcDir  = $base . "/RMC";

    // Docs selection: C => SCF, F => SFF, O => none (for now)
    $techDocLabel = ($type === "F") ? "SFF" : "SCF";
    $techDocDir   = ($type === "F") ? $sffDir : $scfDir;

    // Latest filenames only
    $piLatest   = latest_files($piDir, 10);
    $techLatest = latest_files($techDocDir, 10);
    $inLatest   = latest_files($inDir, 10);
    $outLatest  = latest_files($outDir, 10);

    $rmcLatest  = latest_files($rmcDir, 10);
    // Activity log from JSON
    $activity = (is_array($viewProject["activityLog"] ?? null)) ? $viewProject["activityLog"] : [];    // Generators by step + subproject type
    $generators = [];
    if ($step === 5) {
      $pid = (int)($viewProject["id"] ?? 0);

      if ($type === "C") {
        $generators = [
          ["label" => "PI Generator",  "url" => "/pi?id="  . $pid],
          ["label" => "SCF Generator", "url" => "/scf?id=" . $pid],
        ];
      } elseif ($type === "F") {
        $generators = [
          ["label" => "PI Generator",  "url" => "/pi?id="  . $pid],
          ["label" => "SFF Generator", "url" => "/sff?id=" . $pid],
        ];
      } else {
        $generators = [];
      }
    } elseif ($step === 7) {
      // Supply step: Raw Material Check (RMC)
      $pid = (int)($viewProject["id"] ?? 0);
      $generators = [
        ["label" => "RMC Generator", "url" => "/rmc?id=" . $pid],
      ];
    } elseif ($step === 8) {
      // Production and Delivery step
      $pid = (int)($viewProject["id"] ?? 0);
      $generators = [
        ["label" => "Production Plan", "url" => "/production_plan?id=" . $pid],
        ["label" => "Production Log",  "url" => "/production_log?id="  . $pid],
      ];
    } 
?>

  <!-- =======================================================
       SECTION B — Subproject Details
       ======================================================= -->
  <div class="card">
    <div class="p-topbar">
      <div>
        <h2 style="margin:0;">Subproject Details</h2>
        <div class="hint" style="margin-top:4px;">
          <?= h((string)($viewProject["projectName"] ?? "")) ?>
          — <span class="pill"><?= h((string)($viewProject["code"] ?? "")) ?></span>
          <span class="pill"><?= h($type) ?></span>
        </div>
      </div>

      <!-- Step shown on top-right (requested) -->
      <div style="text-align:right;">
        <div class="hint">Current Step</div>
        <div class="mini-pill"><?= h(step_label($step, $STEP_NAMES)) ?></div>
      </div>
    </div>

    <div class="p-kv" style="margin-top:12px;">
      <div class="k">Customer</div><div class="v"><?= h((string)($viewProject["customerUsername"] ?? "")) ?></div>
      <div class="k">Status</div><div class="v"><?= h($status) ?></div>
    </div>

    <?php if ($isAdmin): ?>
      <!-- Workflow controls: Step + Status (Admin only) -->
      <div class="card" style="margin-top:14px;">
        <div class="row">
          <h2 class="p-mini-title">Steps</h2>
          <div class="hint">Workflow controls</div>
        </div>

        <form method="post" class="p-inline" style="margin-top:10px; gap:12px; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="set_workflow">
          <input type="hidden" name="id" value="<?= h((string)$pid) ?>">

          <div style="display:flex; flex-direction:column; gap:6px;">
            <div class="hint" style="font-weight:700;">Step</div>
            <select name="step" style="min-width:320px; height:42px;">
              <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?= $i ?>" <?= ((int)$step === $i) ? "selected" : "" ?>>
                  <?= h($i . " - " . ($STEP_NAMES[$i] ?? ("Step " . $i))) ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div style="display:flex; flex-direction:column; gap:6px;">
            <div class="hint" style="font-weight:700;">Status</div>
            <select name="status" style="min-width:200px; height:42px;">
              <option value="Open" <?= ($status==="Open")?"selected":"" ?>>Open</option>
              <option value="In progress" <?= ($status==="In progress")?"selected":"" ?>>In progress</option>
              <option value="Waiting" <?= ($status==="Waiting")?"selected":"" ?>>Waiting</option>
              <option value="Closed" <?= ($status==="Closed")?"selected":"" ?>>Closed</option>
            </select>
          </div>

          <div style="display:flex; align-items:flex-end;">
            <button class="btn" type="submit">Update</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <!-- Generators card per step -->
    <div class="card" style="margin-top:14px;">
      <div class="row">
        <h2 class="p-mini-title">Tools</h2>
        <div class="hint">Available tools for this step</div>
      </div>

      <div class="p-inline p-btnbar" style="margin-top:10px; justify-content:flex-start;">
        <?php if (!count($generators)): ?>
          <span class="hint">No generators configured for this step/subproject.</span>
        <?php else: ?>
          <?php foreach ($generators as $g): ?>
            <a class="btn btn-ghost" href="<?= h($g["url"]) ?>" style="text-decoration:none;"><?= h($g["label"]) ?></a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Documents card -->
    <div class="card" style="margin-top:14px;">
      <div class="row">
        <h2 class="p-mini-title">Documents</h2>
        <div class="hint">All docs and mails for this subproject</div>
      </div>

      <div class="p-kv" style="margin-top:10px;">
        <div class="k">Mails — Received</div>
        <div class="v">
          <?= h((string)count_files($inDir)) ?> file(s)
          <?php if (count($inLatest)): ?>
            <ul class="hint" style="margin:6px 0 0 18px;">
              <?php foreach ($inLatest as $nm): ?><li class="p-wrap"><?= h($nm) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="k">Mails — Sent</div>
        <div class="v">
          <?= h((string)count_files($outDir)) ?> file(s)
          <?php if (count($outLatest)): ?>
            <ul class="hint" style="margin:6px 0 0 18px;">
              <?php foreach ($outLatest as $nm): ?><li class="p-wrap"><?= h($nm) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="k">PI</div>
        <div class="v">
          <?= h((string)count_files($piDir)) ?> file(s)
          <?php if (count($piLatest)): ?>
            <ul class="hint" style="margin:6px 0 0 18px;">
              <?php foreach ($piLatest as $nm): ?><li class="p-wrap"><?= h($nm) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        
        <div class="k">RMC</div>
        <div class="v">
          <?= h((string)count_files($rmcDir)) ?> file(s)
          <?php if (count($rmcLatest)): ?>
            <ul class="hint" style="margin:6px 0 0 18px;">
              <?php foreach ($rmcLatest as $nm): ?><li class="p-wrap"><?= h($nm) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

<div class="k"><?= h($techDocLabel) ?></div>
        <div class="v">
          <?= h((string)count_files($techDocDir)) ?> file(s)
          <?php if (count($techLatest)): ?>
            <ul class="hint" style="margin:6px 0 0 18px;">
              <?php foreach ($techLatest as $nm): ?><li class="p-wrap"><?= h($nm) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Logs card -->
    <div class="card" style="margin-top:14px;">
      <div class="row">
        <h2 class="p-mini-title">Logs</h2>
        <div class="hint">Activity records</div>
      </div>

      <?php if (!count($activity)): ?>
        <div class="hint" style="margin-top:10px;">No activity recorded yet.</div>
      <?php else: ?>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:180px;">Date/Time</th>
              <th style="width:160px;">By</th>
              <th style="width:160px;">Type</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($activity, 0, 30) as $e): ?>
              <tr>
                <td><?= h((string)($e["ts"] ?? "")) ?></td>
                <td><?= h((string)($e["by"] ?? "")) ?></td>
                <td><?= h((string)($e["type"] ?? "")) ?></td>
                <td class="p-wrap"><?= h((string)($e["msg"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="hint" style="margin-top:10px;">
        Note: Generators can append logs later (e.g., “File Generated by Client #N”) using the same activityLog mechanism.
      </div>
    </div>

    <div style="margin-top:12px;">
      <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Back to Projects</a>
    </div>

  </div>
<?php endif; ?>

<?php render_footer(); ?>
