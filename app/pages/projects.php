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
if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_once APP_ROOT . "/includes/acl.php";
require_login();
require_role(["Admin","Manager","Office","R&D","Customer"]);

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

function state_equals(string $state, string $target): bool {
  return strtoupper(trim($state)) === strtoupper(trim($target));
}

function public_root(): string {
  $root = realpath(APP_ROOT . "/..");
  return $root ?: (APP_ROOT . "/..");
}

function production_db_file_path(): string {
  return rtrim(public_root(), "/") . "/database/production/production_days.json";
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
$role = current_role();
$isAdmin = is_admin();
$isManager = has_role("Manager");
$isCustomer = has_role("Customer");
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
  $action = trim((string)($_POST["action"] ?? ""));
  $id = (int)($_POST["id"] ?? 0);

  $adminOnlyActions = ["step_delta","set_workflow","set_status"];
  $isStateTransition = ($action === "transition_state");

  if (in_array($action, $adminOnlyActions, true) && !$isAdmin) { http_response_code(403); echo "Forbidden"; exit; }
  if ($isStateTransition && !($isAdmin || $isManager)) { http_response_code(403); echo "Forbidden"; exit; }

  require_csrf($CSRF);

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
    elseif ($action === "transition_state") {
      $currentState = (string)($projects[$idx]["state"] ?? "");
      $stream = (string)($projects[$idx]["stream"] ?? "");
      $mode = (string)($projects[$idx]["mode"] ?? "");
      $piRequiredFlag = $projects[$idx]["piRequired"] ?? null;
      $piRequired = ($piRequiredFlag !== null) ? (bool)$piRequiredFlag : (stripos($mode, "external") !== false);

      $targetState = trim((string)($_POST["target_state"] ?? ""));
      $normalizedCurrent = strtoupper(trim($currentState));
      $normalizedTarget = strtoupper(trim($targetState));

      $docMetadataAvailable = false; // TODO(PAGE-IMP-06): query doc metadata table for Issued/Locked statuses.
      $productionFile = production_db_file_path();
      $productionDataAvailable = is_file($productionFile);
      $openProductionDays = [];

      if ($productionDataAvailable) {
        $plans = load_json_array($productionFile);
        if (!is_array($plans)) {
          $productionDataAvailable = false;
        } else {
          foreach ($plans as $plan) {
            if (!is_array($plan)) continue;
            $entries = is_array($plan["entries"] ?? null) ? $plan["entries"] : [];
            $hasMatch = false;
            foreach ($entries as $en) {
              if (!is_array($en)) continue;
              if ((int)($en["projectId"] ?? 0) === $id) { $hasMatch = true; break; }
            }
            if ($hasMatch) {
              $planStatus = (string)($plan["status"] ?? "Open");
              if (strtolower($planStatus) !== "closed") {
                $openProductionDays[] = [
                  "jalaliDate" => (string)($plan["jalaliDate"] ?? ""),
                  "dayNo" => (int)($plan["dayNo"] ?? 0),
                  "status" => $planStatus,
                ];
              }
            }
          }
        }
      }

      $blockers = [];

      if ($normalizedCurrent === "CONFIRMED" && $normalizedTarget === "EXECUTION") {
        if (!$docMetadataAvailable) {
          $blockers[] = "Cannot validate required Issued/Locked docs (doc metadata not available)";
        } else {
          // TODO(PAGE-IMP-06): enforce SCF/SFF/PI Issued/Locked checks when doc metadata is available.
        }
      } elseif ($normalizedCurrent === "EXECUTION" && $normalizedTarget === "COMPLETED") {
        if (!$productionDataAvailable) {
          $blockers[] = "Cannot validate open production days (production tables not available)";
        } elseif (count($openProductionDays)) {
          $labels = array_map(function($p){
            $dn = (int)($p["dayNo"] ?? 0);
            $date = (string)($p["jalaliDate"] ?? "");
            $st = (string)($p["status"] ?? "");
            return trim(($dn>0?("Day ".$dn):"") . ($date!==""?(" ".$date):"") . ($st!==""?(" (".$st.")"):""));
          }, $openProductionDays);
          $blockers[] = "Production days still open: " . implode("; ", array_filter($labels));
        }
      } else {
        $blockers[] = "Invalid transition.";
      }

      if (count($blockers)) {
        $flash_err = implode(" ", $blockers);
      } else {
        $projects[$idx]["state"] = $targetState;
        $projects[$idx]["updatedAt"] = $now;

        append_activity_log($projects[$idx], [
          "ts" => $now,
          "by" => $actor,
          "type" => "STATE_UPDATE",
          "msg" => "State changed from {$currentState} to {$targetState} by {$role}.",
        ]);

        save_json_array_atomic($PROJECTS_FILE, $projects);
        $projects = load_json_array($PROJECTS_FILE);
        $flash_ok = "State updated.";
      }
    }
  }
}

/************************************************************
 * SECTION 11 — Filter Projects by Role
 ************************************************************/
$visible = apply_scope_filter_to_project_list_query($projects);

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
 * SECTION 13 — Documents Register Filters (PAGE-IMP-08)
 ************************************************************/
$docMetadataAvailable = false;
$docMetadataNote = "Document metadata table not available. TODO(PAGE-IMP-08): connect query with scope enforcement (project/subproject) before rendering results.";

$docFilters = [
  "project"     => trim((string)($_GET["doc_project"] ?? "")),
  "subproject"  => trim((string)($_GET["doc_subproject"] ?? "")),
  "type"        => trim((string)($_GET["doc_type"] ?? "")),
  "status"      => trim((string)($_GET["doc_status"] ?? "")),
  "version"     => trim((string)($_GET["doc_version"] ?? "")),
  "proposer"    => trim((string)($_GET["doc_proposer"] ?? "")),
  "confirmer"   => trim((string)($_GET["doc_confirmer"] ?? "")),
  "issuer"      => trim((string)($_GET["doc_issuer"] ?? "")),
  "issued_from" => trim((string)($_GET["doc_issued_from"] ?? "")),
  "issued_to"   => trim((string)($_GET["doc_issued_to"] ?? "")),
  "search"      => trim((string)($_GET["doc_search"] ?? "")),
];

$projectOptions = [];
$subprojectOptions = [];
foreach ($visible as $p) {
  $pid = (int)($p["id"] ?? 0);
  $projLabel = trim((string)($p["projectName"] ?? ""));
  $code = trim((string)($p["code"] ?? ""));
  $customerUser = trim((string)($p["customerUsername"] ?? ""));
  $projectKey = $customerUser . "|" . $code . "|" . $projLabel;

  if (!isset($projectOptions[$projectKey])) {
    $projectOptions[$projectKey] = [
      "label" => ($projLabel !== "" ? $projLabel : "Project") . ($code !== "" ? (" (" . $code . ")") : ""),
      "value" => $projectKey,
    ];
  }

  if ($pid > 0) {
    $subLabel = ($projLabel !== "" ? $projLabel . " — " : "") . ($code !== "" ? $code : ("Subproject " . $pid));
    $subprojectOptions[$pid] = [
      "label" => $subLabel,
      "value" => (string)$pid,
    ];
  }
}

$documentRows = [];
// TODO(PAGE-IMP-08): Load documents metadata (doc_ref, type, version, status, proposer, confirmer, issuer, timestamps, is_active_issued)
// from existing storage once available and apply scope filtering (require_project_scope / require_subproject_scope).

/************************************************************
 * SECTION 14 — Detail View (?id=subproject_id)
 ************************************************************/
$viewId = (int)($_GET["id"] ?? 0);
$viewProject = null;

if ($viewId > 0) {
  $viewProject = require_subproject_scope($viewId);
}

/************************************************************
 * SECTION 14B — Project View (?projectId=project_id)
 ************************************************************/
$projectViewId = (int)($_GET["projectId"] ?? 0);
$projectView = null;
$projectViewSubs = [];
$projectSnapshotDocs = [];

if ($projectViewId > 0) {
  $projectView = require_project_scope($projectViewId);

  $projCustomer = (string)($projectView["customerUsername"] ?? "");
  $projCode = (string)($projectView["code"] ?? "");
  $projName = (string)($projectView["projectName"] ?? "");

  foreach ($visible as $sp) {
    if (
      (string)($sp["customerUsername"] ?? "") === $projCustomer &&
      (string)($sp["code"] ?? "") === $projCode &&
      (string)($sp["projectName"] ?? "") === $projName
    ) {
      $projectViewSubs[] = $sp;
    }
  }

  if (empty($projectViewSubs)) {
    $projectViewSubs[] = $projectView;
  }

  // Snapshot doc types (placeholder until doc metadata table exists)
  $projectSnapshotDocs = [
    ["label" => "PI", "latest" => "—", "status" => "—", "note" => "TODO(PAGE-IMP-05): query latest PI version/status"],
    ["label" => "SCF", "latest" => "—", "status" => "—", "note" => "TODO(PAGE-IMP-05): query latest SCF version/status"],
    ["label" => "SFF", "latest" => "—", "status" => "—", "note" => "TODO(PAGE-IMP-05): query latest SFF version/status"],
    ["label" => "Attachments (Incoming)", "latest" => "—", "status" => "—", "note" => "TODO(PAGE-IMP-05): query latest incoming attachment status"],
    ["label" => "Attachments (Outgoing)", "latest" => "—", "status" => "—", "note" => "TODO(PAGE-IMP-05): query latest outgoing attachment status"],
  ];
}

/************************************************************
 * SECTION 15 — Render
 ************************************************************/
render_header("Documents Register", $role);

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
.filter-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; align-items:flex-end; }
.filter-grid label{ display:flex; flex-direction:column; gap:6px; font-weight:600; font-size:13px; color:rgba(0,0,0,.7); }
.doc-banner{ background:#fff8e1; border:1px solid #f3d27a; padding:6px 10px; border-radius:8px; color:#7a5b00; font-size:12px; margin-top:6px; display:inline-block; }
.doc-actions{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
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
     SECTION 0 — Documents Register (PAGE-IMP-08)
     ======================================================= -->
<div class="card" style="margin-bottom:14px;">
  <div class="row">
    <h2 class="p-mini-title" style="margin:0;">Documents Register</h2>
    <div class="hint">Scope enforced via existing ACL (PAGE-IMP-02). Customers only see their own projects/subprojects.</div>
  </div>

  <form method="get" class="filter-grid" style="margin-top:10px;">
    <label>
      Project
      <select name="doc_project" style="height:42px;">
        <option value="">All projects</option>
        <?php foreach ($projectOptions as $opt): ?>
          <option value="<?= h((string)$opt["value"]) ?>" <?= ($docFilters["project"] === (string)$opt["value"]) ? "selected" : "" ?>>
            <?= h((string)$opt["label"]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      Subproject
      <select name="doc_subproject" style="height:42px;">
        <option value="">All subprojects</option>
        <?php foreach ($subprojectOptions as $opt): ?>
          <option value="<?= h((string)$opt["value"]) ?>" <?= ($docFilters["subproject"] === (string)$opt["value"]) ? "selected" : "" ?>>
            <?= h((string)$opt["label"]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      Doc type
      <select name="doc_type" style="height:42px;">
        <option value="">All</option>
        <option value="PI" <?= ($docFilters["type"]==="PI")?"selected":"" ?>>PI</option>
        <option value="SCF" <?= ($docFilters["type"]==="SCF")?"selected":"" ?>>SCF</option>
        <option value="SFF" <?= ($docFilters["type"]==="SFF")?"selected":"" ?>>SFF</option>
        <option value="Attachment (Incoming)" <?= ($docFilters["type"]==="Attachment (Incoming)")?"selected":"" ?>>Attachment (Incoming)</option>
        <option value="Attachment (Outgoing)" <?= ($docFilters["type"]==="Attachment (Outgoing)")?"selected":"" ?>>Attachment (Outgoing)</option>
      </select>
    </label>
    <label>
      Status
      <select name="doc_status" style="height:42px;">
        <option value="">All</option>
        <option value="Proposed" <?= ($docFilters["status"]==="Proposed")?"selected":"" ?>>Proposed</option>
        <option value="Confirmed" <?= ($docFilters["status"]==="Confirmed")?"selected":"" ?>>Confirmed</option>
        <option value="Issued" <?= ($docFilters["status"]==="Issued")?"selected":"" ?>>Issued</option>
        <option value="Locked" <?= ($docFilters["status"]==="Locked")?"selected":"" ?>>Locked</option>
        <option value="Archived" <?= ($docFilters["status"]==="Archived")?"selected":"" ?>>Archived</option>
      </select>
    </label>
    <label>
      Version
      <input type="text" name="doc_version" value="<?= h($docFilters["version"]) ?>" placeholder="e.g., 1.2" style="height:42px;">
    </label>
    <label>
      Proposer
      <input type="text" name="doc_proposer" value="<?= h($docFilters["proposer"]) ?>" placeholder="username" style="height:42px;">
    </label>
    <label>
      Confirmer
      <input type="text" name="doc_confirmer" value="<?= h($docFilters["confirmer"]) ?>" placeholder="username" style="height:42px;">
    </label>
    <label>
      Issuer
      <input type="text" name="doc_issuer" value="<?= h($docFilters["issuer"]) ?>" placeholder="username" style="height:42px;">
    </label>
    <label>
      Issued from
      <input type="date" name="doc_issued_from" value="<?= h($docFilters["issued_from"]) ?>" style="height:42px;">
    </label>
    <label>
      Issued to
      <input type="date" name="doc_issued_to" value="<?= h($docFilters["issued_to"]) ?>" style="height:42px;">
    </label>
    <label>
      Search
      <input type="text" name="doc_search" value="<?= h($docFilters["search"]) ?>" placeholder="DocRef, type, status" style="height:42px;">
    </label>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <button class="btn" type="submit">Apply filters</button>
      <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Reset</a>
    </div>
  </form>

  <div class="hint" style="margin-top:6px;"><?= h($docMetadataNote) ?></div>

  <table style="margin-top:10px;">
    <thead>
      <tr>
        <th style="width:160px;">DocRef</th>
        <th style="width:120px;">Type</th>
        <th style="width:90px;">Version</th>
        <th style="width:120px;">Status</th>
        <th style="width:120px;">Active Issued?</th>
        <th style="width:140px;">Proposer</th>
        <th style="width:140px;">Confirmer</th>
        <th style="width:140px;">Issuer</th>
        <th style="width:140px;">Created At</th>
        <th style="width:140px;">Confirmed At</th>
        <th style="width:140px;">Issued At</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($documentRows)): ?>
        <tr>
          <td colspan="12">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No documents yet.</span>
              <div class="hint" style="font-size:11px;">TODO(PAGE-IMP-08): query document metadata with scope filters and render rows.</div>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($documentRows as $doc): ?>
          <?php
            $docRef   = (string)($doc["doc_ref"] ?? "");
            $docType  = (string)($doc["type"] ?? "");
            $version  = (string)($doc["version"] ?? "");
            $status   = (string)($doc["status"] ?? "");
            $statusUpper = strtoupper(trim($status));
            $isActiveIssued = !empty($doc["is_active_issued"]);
            $createdAt = (string)($doc["created_at"] ?? "");
            $confirmedAt = (string)($doc["confirmed_at"] ?? "");
            $issuedAt = (string)($doc["issued_at"] ?? "");
            $nonIssued = !in_array($statusUpper, ["ISSUED","LOCKED"], true);
          ?>
          <tr>
            <td><?= h($docRef) ?></td>
            <td><?= h($docType) ?></td>
            <td><?= h($version) ?></td>
            <td>
              <?= h($status) ?>
              <?php if ($nonIssued): ?>
                <div class="doc-banner">Not an official issued document</div>
              <?php endif; ?>
            </td>
            <td><?= $isActiveIssued ? "Yes" : "No" ?></td>
            <td><?= h((string)($doc["proposer"] ?? "")) ?></td>
            <td><?= h((string)($doc["confirmer"] ?? "")) ?></td>
            <td><?= h((string)($doc["issuer"] ?? "")) ?></td>
            <td><?= h($createdAt) ?></td>
            <td><?= h($confirmedAt) ?></td>
            <td><?= h($issuedAt) ?></td>
            <td>
              <div class="doc-actions">
                <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-08): open metadata view">View metadata</button>
                <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-08): implement scoped download endpoint with filename including status and version (e.g., DOCREF_STATUS_vVERSION)">Download</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

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

<?php if ($projectView): ?>
  <?php
    $projType = (string)($projectView["type"] ?? "");
    $projMode = (string)($projectView["mode"] ?? "");
    $projCode = (string)($projectView["code"] ?? "");
    $projName = (string)($projectView["projectName"] ?? "");
    $projCustomer = (string)($projectView["customerUsername"] ?? "");
  ?>
  <div class="card">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2>Project View</h2>
        <div class="hint">Project summary with subprojects and snapshot.</div>
      </div>
      <?php if ($isAdmin || $isManager): ?>
        <div class="p-btnbar">
          <button class="btn" type="button" disabled title="TODO(PAGE-IMP-05): Create subproject action">Create subproject</button>
          <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-05): Assign roles action">Assign roles</button>
          <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-05): State management entry">State management</button>
        </div>
      <?php endif; ?>
    </div>

    <div class="p-kv" style="margin-top:10px;">
      <div class="k">Project</div><div class="v p-wrap"><?= h($projName) ?></div>
      <div class="k">Customer</div><div class="v p-wrap"><?= h($projCustomer) ?></div>
      <div class="k">Code</div><div class="v"><?= h($projCode) ?></div>
      <div class="k">Type</div><div class="v"><?= h($projType) ?></div>
      <div class="k">Mode</div><div class="v"><?= h($projMode) ?></div>
    </div>

    <div class="row" style="align-items:flex-start; gap:20px; margin-top:16px; flex-wrap:wrap;">
      <div style="flex:2; min-width:320px;">
        <div class="row" style="align-items:center; gap:10px;">
          <h3 class="p-mini-title" style="margin:0;">Subprojects</h3>
          <div class="hint"><?= count($projectViewSubs) ?> item(s)</div>
        </div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:90px;">Type</th>
              <th style="width:160px;">State</th>
              <th style="width:200px;">Stream</th>
              <th style="width:160px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projectViewSubs as $sp): ?>
              <?php
                $sid = (int)($sp["id"] ?? 0);
                $stype = (string)($sp["type"] ?? "");
                $sstate = (string)($sp["state"] ?? "");
                $sstream = (string)($sp["stream"] ?? "");
              ?>
              <tr>
                <td><span class="pill"><?= h($stype !== "" ? $stype : "-") ?></span></td>
                <td><?= h($sstate !== "" ? $sstate : "-") ?></td>
                <td><?= h($sstream !== "" ? $sstream : "-") ?></td>
                <td>
                  <?php if ($sid > 0): ?>
                    <a class="btn btn-ghost" href="/projects?id=<?= h((string)$sid) ?>" style="text-decoration:none;">Open subproject workspace</a>
                  <?php else: ?>
                    <button class="btn btn-ghost" type="button" disabled title="Subproject id missing">Open subproject workspace</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="flex:1; min-width:260px;">
        <div class="row" style="align-items:center; gap:10px;">
          <h3 class="p-mini-title" style="margin:0;">Snapshot</h3>
          <div class="hint">Latest version/status per doc type</div>
        </div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:140px;">Doc Type</th>
              <th style="width:120px;">Latest</th>
              <th style="width:120px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projectSnapshotDocs as $doc): ?>
              <tr>
                <td><?= h((string)$doc["label"]) ?></td>
                <td><?= h((string)$doc["latest"]) ?></td>
                <td>
                  <?= h((string)$doc["status"]) ?>
                  <div class="hint" style="font-size:11px;"><?= h((string)$doc["note"]) ?></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

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

    $state = (string)($viewProject["state"] ?? "");
    $stateNormalized = strtoupper(trim($state));
    $stream = (string)($viewProject["stream"] ?? "");
    $mode = (string)($viewProject["mode"] ?? "");
    $piRequiredFlag = $viewProject["piRequired"] ?? null;
    $piRequired = ($piRequiredFlag !== null) ? (bool)$piRequiredFlag : (stripos($mode, "external") !== false);

    $docMetadataAvailable = false; // TODO(PAGE-IMP-06): Hook to doc metadata table for latest/status lookups.
    $docMetadataNote = "TODO(PAGE-IMP-06): Doc metadata not available; cannot list issued versions.";

    $requiredDocs = [];
    if (stripos($stream, "compound") !== false) $requiredDocs[] = "SCF";
    if (stripos($stream, "film") !== false) $requiredDocs[] = "SFF";
    if ($piRequired) $requiredDocs[] = "PI";

    $productionFile = production_db_file_path();
    $productionDataAvailable = is_file($productionFile);
    $productionDataNote = $productionDataAvailable ? "" : "TODO(PAGE-IMP-06): Production tables not available.";
    $linkedProductionDays = [];
    $openProductionDays = [];

    if ($productionDataAvailable) {
      $plans = load_json_array($productionFile);
      if (!is_array($plans)) {
        $productionDataAvailable = false;
        $productionDataNote = "TODO(PAGE-IMP-06): Cannot read production_days.json.";
      } else {
        foreach ($plans as $plan) {
          if (!is_array($plan)) continue;
          $entries = is_array($plan["entries"] ?? null) ? $plan["entries"] : [];
          $hasMatch = false;
          foreach ($entries as $en) {
            if (!is_array($en)) continue;
            if ((int)($en["projectId"] ?? 0) === $pid) { $hasMatch = true; break; }
          }
          if ($hasMatch) {
            $rec = [
              "jalaliDate" => (string)($plan["jalaliDate"] ?? ""),
              "dayNo" => (int)($plan["dayNo"] ?? 0),
              "status" => (string)($plan["status"] ?? "Open"),
            ];
            $linkedProductionDays[] = $rec;
            if (strtolower($rec["status"]) !== "closed") {
              $openProductionDays[] = $rec;
            }
          }
        }
      }
    }

    $confirmedBlockers = [];
    $executionBlockers = [];

    if ($stateNormalized === "CONFIRMED") {
      if (!$docMetadataAvailable) {
        $confirmedBlockers[] = "Cannot validate required Issued/Locked docs (doc metadata not available)";
      }
    }
    if ($stateNormalized === "EXECUTION") {
      if (!$productionDataAvailable) {
        $executionBlockers[] = "Cannot validate open production days (production tables not available)";
      } elseif (count($openProductionDays)) {
        foreach ($openProductionDays as $pday) {
          $lbl = "Production day " . ((int)($pday["dayNo"] ?? 0) ?: "");
          $date = (string)($pday["jalaliDate"] ?? "");
          $statusLabel = (string)($pday["status"] ?? "");
          $executionBlockers[] = trim($lbl . ($date ? (" on " . $date) : "") . ($statusLabel ? (" (" . $statusLabel . ")") : ""));
        }
      }
    }

    $canTransitionToExecution = ($isAdmin || $isManager) && $stateNormalized === "CONFIRMED" && count($confirmedBlockers) === 0;
    $canTransitionToCompleted = ($isAdmin || $isManager) && $stateNormalized === "EXECUTION" && count($executionBlockers) === 0;
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

    <div class="row" style="align-items:flex-start; gap:12px; margin-top:12px; flex-wrap:wrap;">
      <div style="flex:1; min-width:220px;">
        <div class="hint" style="font-weight:700;">State</div>
        <div class="pill" style="display:inline-block; margin-top:4px;"><?= h($state !== "" ? $state : "-") ?></div>
        <div class="hint" style="margin-top:6px;">Mode: <?= h($mode !== "" ? $mode : "—") ?></div>
      </div>
      <div style="flex:1; min-width:220px;">
        <div class="hint" style="font-weight:700;">Stream</div>
        <div class="pill" style="display:inline-block; margin-top:4px;"><?= h($stream !== "" ? $stream : "-") ?></div>
        <div class="hint" style="margin-top:6px;">
          Required issued docs: <?= h(count($requiredDocs) ? implode(", ", $requiredDocs) : "None") ?>
        </div>
      </div>
      <div style="flex:1.4; min-width:260px;">
        <div class="hint" style="font-weight:700;">Active issued versions</div>
        <div class="hint" style="margin-top:6px; color:var(--muted);"><?= h($docMetadataNote) ?></div>
      </div>
    </div>

    <div class="row" style="align-items:flex-start; gap:12px; margin-top:14px; flex-wrap:wrap;">
      <div class="card" style="flex:1.6; min-width:320px; margin-top:0;">
        <div class="row">
          <h2 class="p-mini-title">Document Board</h2>
          <div class="hint">Latest per doc type + actions</div>
        </div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:140px;">Doc Type</th>
              <th style="width:120px;">Latest</th>
              <th style="width:120px;">Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $docRows = [
                ["label" => "PI", "action" => true],
                ["label" => $techDocLabel, "action" => true],
                ["label" => "Attachments (Incoming)", "action" => false],
                ["label" => "Attachments (Outgoing)", "action" => false],
              ];
            ?>
            <?php foreach ($docRows as $row): ?>
              <tr>
                <td><?= h((string)$row["label"]) ?></td>
                <td>—</td>
                <td>—</td>
                <td>
                  <?php if (!empty($row["action"])): ?>
                    <?php if ($isAdmin || $isManager): ?>
                      <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-06): Wire to Issue/Lock once doc metadata is available">Issue/Lock</button>
                    <?php else: ?>
                      <button class="btn btn-ghost" type="button" disabled title="Manager/Admin only">Issue/Lock</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="hint">No actions available.</span>
                  <?php endif; ?>
                  <div class="hint" style="font-size:11px;"><?= h($docMetadataNote) ?></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="flex:1; min-width:260px; margin-top:0;">
        <div class="row">
          <h2 class="p-mini-title">Approvals</h2>
          <div class="hint">Proposed waiting; Confirmed ready to issue</div>
        </div>
        <div class="hint" style="margin-top:8px;">No approval metadata available.</div>
        <div class="hint" style="font-size:11px;"><?= h($docMetadataNote) ?></div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:200px;">Queue</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Proposed</td>
              <td>
                —
                <div class="hint" style="font-size:11px;">TODO(PAGE-IMP-06): load proposed docs awaiting confirmation.</div>
              </td>
            </tr>
            <tr>
              <td>Confirmed</td>
              <td>
                —
                <div class="hint" style="font-size:11px;">TODO(PAGE-IMP-06): load confirmed docs ready to issue.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="row" style="align-items:flex-start; gap:12px; margin-top:12px; flex-wrap:wrap;">
      <div class="card" style="flex:1; min-width:320px; margin-top:0;">
        <div class="row">
          <h2 class="p-mini-title">Version History</h2>
          <div class="hint">Full timeline per doc type</div>
        </div>
        <div class="hint" style="margin-top:8px;">No version history available.</div>
        <div class="hint" style="font-size:11px;"><?= h($docMetadataNote) ?></div>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th style="width:160px;">Doc Type</th>
              <th>Timeline</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>PI</td>
              <td><div class="hint" style="font-size:11px;">TODO(PAGE-IMP-06): render PI version history from metadata.</div></td>
            </tr>
            <tr>
              <td><?= h($techDocLabel) ?></td>
              <td><div class="hint" style="font-size:11px;">TODO(PAGE-IMP-06): render <?= h($techDocLabel) ?> version history from metadata.</div></td>
            </tr>
            <tr>
              <td>Attachments</td>
              <td><div class="hint" style="font-size:11px;">TODO(PAGE-IMP-06): render incoming/outgoing attachment history.</div></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card" style="flex:1; min-width:320px; margin-top:0;">
        <div class="row">
          <h2 class="p-mini-title">State Panel</h2>
          <div class="hint">State transitions + blockers</div>
        </div>

        <div style="margin-top:8px;">
          <div style="font-weight:700;">Confirmed → Execution</div>
          <div class="hint">Requires issued/locked SCF/SFF/PI depending on stream.</div>
          <div class="hint" style="font-size:11px;">Required docs: <?= h(count($requiredDocs) ? implode(", ", $requiredDocs) : "None") ?></div>
          <?php if ($stateNormalized !== "CONFIRMED"): ?>
            <div class="hint" style="margin-top:6px;">Available when state is Confirmed.</div>
          <?php else: ?>
            <?php if (count($confirmedBlockers)): ?>
              <ul class="hint" style="margin:6px 0 0 18px;">
                <?php foreach ($confirmedBlockers as $blk): ?>
                  <li><?= h($blk) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="transition_state">
              <input type="hidden" name="id" value="<?= h((string)$pid) ?>">
              <input type="hidden" name="target_state" value="Execution">
              <?php
                $btnDisabled = !$canTransitionToExecution;
                $btnTitle = $canTransitionToExecution ? "Move to Execution" : "Blocked";
                if (!($isAdmin || $isManager)) $btnTitle = "Manager/Admin only";
                if ($stateNormalized !== "CONFIRMED") $btnTitle = "Only valid from Confirmed state";
                if (count($confirmedBlockers)) $btnTitle = implode("; ", $confirmedBlockers);
              ?>
              <button class="btn" type="submit" <?= $btnDisabled ? "disabled" : "" ?> title="<?= h($btnTitle) ?>">Move to Execution</button>
            </form>
          <?php endif; ?>
        </div>

        <div style="margin-top:14px;">
          <div style="font-weight:700;">Execution → Completed</div>
          <div class="hint">Requires all linked production days closed (no open runs).</div>
          <?php if ($stateNormalized !== "EXECUTION"): ?>
            <div class="hint" style="margin-top:6px;">Available when state is Execution.</div>
          <?php else: ?>
            <?php if (count($executionBlockers)): ?>
              <ul class="hint" style="margin:6px 0 0 18px;">
                <?php foreach ($executionBlockers as $blk): ?>
                  <li><?= h($blk) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="transition_state">
              <input type="hidden" name="id" value="<?= h((string)$pid) ?>">
              <input type="hidden" name="target_state" value="Completed">
              <?php
                $btn2Disabled = !$canTransitionToCompleted;
                $btn2Title = $canTransitionToCompleted ? "Move to Completed" : "Blocked";
                if (!($isAdmin || $isManager)) $btn2Title = "Manager/Admin only";
                if ($stateNormalized !== "EXECUTION") $btn2Title = "Only valid from Execution state";
                if (count($executionBlockers)) $btn2Title = implode("; ", $executionBlockers);
                if (!$productionDataAvailable && $stateNormalized === "EXECUTION") $btn2Title = "Cannot validate open production days (production tables not available)";
              ?>
              <button class="btn" type="submit" <?= $btn2Disabled ? "disabled" : "" ?> title="<?= h($btn2Title) ?>">Move to Completed</button>
            </form>
          <?php endif; ?>
        </div>

        <div style="margin-top:14px; border-top:1px solid var(--line); padding-top:10px;">
          <div class="row" style="align-items:flex-start;">
            <h2 class="p-mini-title" style="margin:0;">Production Days</h2>
            <div class="hint">Link to Production Days filtered to this subproject.</div>
          </div>
          <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <a class="btn btn-ghost" href="/production_plan?projectId=<?= h((string)$pid) ?>&subproject=<?= h((string)($viewProject["code"] ?? "")) ?>" style="text-decoration:none;">Open Production Plan</a>
            <a class="btn btn-ghost" href="/production_log?projectId=<?= h((string)$pid) ?>&subproject=<?= h((string)($viewProject["code"] ?? "")) ?>" style="text-decoration:none;">Open Production Log</a>
          </div>
          <?php if ($productionDataAvailable): ?>
            <?php if (count($linkedProductionDays)): ?>
              <table style="margin-top:10px;">
                <thead>
                  <tr>
                    <th style="width:120px;">Day No.</th>
                    <th style="width:140px;">Date</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($linkedProductionDays as $pd): ?>
                    <tr>
                      <td><?= h((string)($pd["dayNo"] ?? "")) ?></td>
                      <td><?= h((string)($pd["jalaliDate"] ?? "")) ?></td>
                      <td><?= h((string)($pd["status"] ?? "")) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="hint" style="margin-top:8px;">No production days linked to this subproject yet.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="hint" style="margin-top:8px;"><?= h($productionDataNote) ?></div>
          <?php endif; ?>
        </div>
      </div>
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
