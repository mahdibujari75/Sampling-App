<?php
/************************************************************
 * Production Plan — production_plan.php
 * Route: /production_plan   (or index.php?route=production_plan)
 *
 * Uses shared UI:
 *   require_once APP_ROOT . "/includes/layout.php";
 *
 * Data storage:
 *   /public_html/database/production/production_days.json
 *
 * Generates:
 *   - CP-RMC (per selected subproject+SF) — identical layout as RMC
 *   - DP-RMC (day aggregated) — identical layout as RMC, multi-page if >12 items
 ************************************************************/

if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/.."));
}

require_once APP_ROOT . "/includes/auth.php";
require_login();
require_once APP_ROOT . "/includes/layout.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: "[]", true);
  return is_array($data) ? $data : [];
}

function save_json_array_atomic(string $file, array $data): bool {
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
  $tmp = $file . ".tmp";
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  return @rename($tmp, $file);
}

function safe_slug(string $s): string {
  $s = trim($s);
  $s = preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
  return $s ?: "";
}

function safe_code(string $s): string {
  $s = trim($s);
  $s = preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
  return $s ?: "";
}

function safe_filename(string $s): string {
  $s = trim($s);
  $s = str_replace(["\\", "/"], "_", $s);
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  return $s ?: "file.xlsx";
}

function normalize_subcode(string $code, string $type): string {
  $code = trim($code);
  $type = strtoupper(trim($type));
  if ($code === "") return "";
  if ($type !== "" && strpos($code, "-") === false) return $code . "-" . $type;
  return $code;
}

function public_root(): string {
  $root = realpath(APP_ROOT . "/..");
  return $root ?: (APP_ROOT . "/..");
}

$me = current_user();
$role = (string)($me["role"] ?? "Observer");
$isAdmin = ($role === "Admin");
$isObserver = ($role === "Observer");
$isClient = ($role === "Client");
$myUsername = (string)($me["username"] ?? "");

if (!$isAdmin && !$isObserver) {
  http_response_code(403);
  echo "Access denied.";
  exit;
}

$PROJECTS_FILE = defined("PROJECTS_DB_FILE") ? PROJECTS_DB_FILE : (APP_ROOT . "/database/projects.json");

function is_project_visible(array $p, bool $isAdmin, bool $isObserver, bool $isClient, string $myUsername): bool {
  if ($isAdmin || $isObserver) return true;
  if ($isClient && (string)($p["customerUsername"] ?? "") === $myUsername) return true;
  return false;
}

function get_visible_projects(string $PROJECTS_FILE, bool $isAdmin, bool $isObserver, bool $isClient, string $myUsername): array {
  $projects = load_json_array($PROJECTS_FILE);
  $out = [];
  foreach ($projects as $p) {
    if (!is_array($p)) continue;
    if (!is_project_visible($p, $isAdmin, $isObserver, $isClient, $myUsername)) continue;

    

    $step = (int)($p["step"] ?? ($p["currentStep"] ?? ($p["stepIndex"] ?? 0)));
    $status = (string)($p["status"] ?? ($p["projectStatus"] ?? ""));
    // Only "in progress" subprojects for Production Plan: steps 6, 7, 8 and not Closed
    if (!in_array($step, [6,7,8], true)) continue;
    if (strcasecmp($status, "Closed") === 0) continue;
$type = strtoupper(trim((string)($p["type"] ?? "")));
    $codeRaw = trim((string)($p["code"] ?? ""));
    $subCode = normalize_subcode($codeRaw, $type);

    $out[] = [
      "id" => (int)($p["id"] ?? 0),
      "type" => $type,
      "code" => $codeRaw,
      "subCode" => safe_code($subCode),
      "clientSlug" => safe_slug((string)($p["customerUsername"] ?? "")),
      "baseDir" => (string)($p["baseDir"] ?? ""),
      "name" => (string)($p["name"] ?? ""),
    ];
  }
  return $out;
}

function find_project_by_id(string $PROJECTS_FILE, int $id, bool $isAdmin, bool $isObserver, bool $isClient, string $myUsername): ?array {
  $projects = load_json_array($PROJECTS_FILE);
  foreach ($projects as $p) {
    if (!is_array($p)) continue;
    if ((int)($p["id"] ?? 0) !== $id) continue;
    if (!is_project_visible($p, $isAdmin, $isObserver, $isClient, $myUsername)) return null;

    $type = strtoupper(trim((string)($p["type"] ?? "")));
    $codeRaw = trim((string)($p["code"] ?? ""));
    $subCode = normalize_subcode($codeRaw, $type);

    return [
      "id" => (int)($p["id"] ?? 0),
      "type" => $type,
      "code" => $codeRaw,
      "subCode" => safe_code($subCode),
      "clientSlug" => safe_slug((string)($p["customerUsername"] ?? "")),
      "baseDir" => (string)($p["baseDir"] ?? ""),
      "name" => (string)($p["name"] ?? ""),
    ];
  }
  return null;
}

function sf_dir_for_project(array $proj): string {
  $type = strtoupper(trim((string)($proj["type"] ?? "")));
  $baseDir = trim((string)($proj["baseDir"] ?? ""));
  $clientSlug = safe_slug((string)($proj["clientSlug"] ?? ""));
  $subCode = safe_code((string)($proj["subCode"] ?? ""));

  $folder = ($type === "F") ? "SFF" : "SCF";

  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/" . $folder;
  }
  if ($clientSlug === "" || $subCode === "") return "";

  return rtrim(public_root(), "/") . "/database/projects/" . $clientSlug . "/" . $subCode . "/" . $folder;
}

function rmc_dir_for_project(array $proj): string {
  $baseDir = trim((string)($proj["baseDir"] ?? ""));
  $clientSlug = safe_slug((string)($proj["clientSlug"] ?? ""));
  $subCode = safe_code((string)($proj["subCode"] ?? ""));

  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/RMC";
  }
  if ($clientSlug === "" || $subCode === "") return "";

  return rtrim(public_root(), "/") . "/database/projects/" . $clientSlug . "/" . $subCode . "/RMC";
}

function list_sf_files(array $proj): array {
  $dir = sf_dir_for_project($proj);
  if ($dir === "" || !is_dir($dir)) return [];
  $files = @glob(rtrim($dir, "/") . "/*.xlsx") ?: [];
  $out = [];
  foreach ($files as $f) {
    $bn = basename($f);
    if (!preg_match('/\bSF\d{2}\b/i', $bn)) continue;
    $out[] = $bn;
  }
  usort($out, function($a, $b) {
    $ma = []; $mb = [];
    preg_match('/\bSF(\d{2})\b/i', $a, $ma);
    preg_match('/\bSF(\d{2})\b/i', $b, $mb);
    $na = isset($ma[1]) ? (int)$ma[1] : 0;
    $nb = isset($mb[1]) ? (int)$mb[1] : 0;
    if ($na === $nb) return strcmp($a, $b);
    return ($na < $nb) ? -1 : 1;
  });
  return $out;
}

function production_db_file(): string {
  return rtrim(public_root(), "/") . "/database/production/production_days.json";
}

function find_plan_by_date(array $plans, string $jalaliDate): ?array {
  foreach ($plans as $p) {
    if (!is_array($p)) continue;
    if ((string)($p["jalaliDate"] ?? "") === $jalaliDate) return $p;
  }
  return null;
}

function next_day_no(array $plans): int {
  $max = 0;
  foreach ($plans as $p) {
    $n = (int)($p["dayNo"] ?? 0);
    if ($n > $max) $max = $n;
  }
  return $max + 1;
}

/* =========================
   ACTIONS (AJAX)
   ========================= */
$action = (string)($_GET["action"] ?? "");
if ($action === "list_projects") {
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["ok" => true, "projects" => get_visible_projects($PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername)]);
  exit;
}

if ($action === "list_sf") {
  header("Content-Type: application/json; charset=utf-8");
  $pid = (int)($_GET["projectId"] ?? 0);
  $proj = find_project_by_id($PROJECTS_FILE, $pid, $isAdmin, $isObserver, $isClient, $myUsername);
  if (!$proj) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Project not found."]);
    exit;
  }
  echo json_encode(["ok" => true, "files" => list_sf_files($proj)]);
  exit;
}

if ($action === "get_sf") {
  $pid = (int)($_GET["projectId"] ?? 0);
  $file = safe_filename((string)($_GET["file"] ?? ""));
  $proj = find_project_by_id($PROJECTS_FILE, $pid, $isAdmin, $isObserver, $isClient, $myUsername);
  if (!$proj) {
    http_response_code(404);
    echo "Project not found.";
    exit;
  }
  $dir = sf_dir_for_project($proj);
  $path = rtrim($dir, "/") . "/" . $file;
  if (!is_file($path)) {
    http_response_code(404);
    echo "File not found.";
    exit;
  }
  header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
  header('Content-Disposition: inline; filename="' . basename($path) . '"');
  readfile($path);
  exit;
}

if ($action === "get_plan") {
  header("Content-Type: application/json; charset=utf-8");
  $date = trim((string)($_GET["date"] ?? ""));
  $db = production_db_file();
  $plans = load_json_array($db);
  $plan = $date ? find_plan_by_date($plans, $date) : null;
  $resp = ["ok" => true, "plan" => $plan];
  if (!$plan && $date) $resp["suggestedDayNo"] = next_day_no($plans);
  echo json_encode($resp);
  exit;
}

if ($action === "list_plans") {
  header("Content-Type: application/json; charset=utf-8");
  $db = production_db_file();
  $plans = load_json_array($db);
  $list = [];
  foreach ($plans as $p) {
    if (!is_array($p)) continue;
    $list[] = [
      "jalaliDate" => (string)($p["jalaliDate"] ?? ""),
      "dayNo" => (int)($p["dayNo"] ?? 0),
      "status" => (string)($p["status"] ?? "Open"),
      "updatedAt" => (string)($p["updatedAt"] ?? ""),
      "updatedBy" => (string)($p["updatedBy"] ?? ""),
    ];
  }
  usort($list, function($a, $b) {
    return strcmp((string)$b["jalaliDate"], (string)$a["jalaliDate"]);
  });
  echo json_encode(["ok" => true, "plans" => $list]);
  exit;
}



if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "save_plan") {
  header("Content-Type: application/json; charset=utf-8");

  $payload = (string)($_POST["payload"] ?? "");
  $data = json_decode($payload ?: "{}", true);
  if (!is_array($data)) $data = [];

  $originalDate = trim((string)($data["originalDate"] ?? ""));
  $jalaliDate = trim((string)($data["jalaliDate"] ?? ""));
  if ($jalaliDate === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Date is required."]);
    exit;
  }

  $db = production_db_file();
  $plans = load_json_array($db);

  $needle = $originalDate !== "" ? $originalDate : $jalaliDate;

  $existingIndex = -1;
  for ($i=0; $i<count($plans); $i++) {
    if (!is_array($plans[$i])) continue;
    if ((string)($plans[$i]["jalaliDate"] ?? "") === $needle) { $existingIndex = $i; break; }
  }

  // If editing an existing plan and date changed, prevent duplicates
  if ($existingIndex >= 0 && $originalDate !== "" && $originalDate !== $jalaliDate) {
    foreach ($plans as $p2) {
      if (!is_array($p2)) continue;
      if ((string)($p2["jalaliDate"] ?? "") === $jalaliDate) {
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "A plan already exists for this date. Choose another date."]);
        exit;
      }
    }
  }

  // Day number is immutable once created
  $dayNo = (int)($data["dayNo"] ?? 0);
  if ($existingIndex >= 0) {
    $dayNo = (int)($plans[$existingIndex]["dayNo"] ?? $dayNo);
    if ($dayNo <= 0) $dayNo = (int)($data["dayNo"] ?? 0);
  } else {
    if ($dayNo <= 0) $dayNo = next_day_no($plans);
  }

  $record = [
    "jalaliDate" => $jalaliDate,
    "dayNo" => $dayNo,
    "status" => (string)($data["status"] ?? "Open"),
    "entries" => is_array($data["entries"] ?? null) ? $data["entries"] : [],
    "dpMaterials" => is_array($data["dpMaterials"] ?? null) ? $data["dpMaterials"] : [],
    "updatedAt" => gmdate("c"),
    "updatedBy" => $GLOBALS["myUsername"],
    "createdAt" => $existingIndex >= 0 ? (string)($plans[$existingIndex]["createdAt"] ?? gmdate("c")) : gmdate("c"),
    "createdBy" => $existingIndex >= 0 ? (string)($plans[$existingIndex]["createdBy"] ?? $GLOBALS["myUsername"]) : $GLOBALS["myUsername"],
    "log" => $existingIndex >= 0 ? ($plans[$existingIndex]["log"] ?? new stdClass()) : new stdClass(),
  ];

  if ($existingIndex >= 0) $plans[$existingIndex] = $record;
  else $plans[] = $record;

  if (!save_json_array_atomic($db, $plans)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to save production plan (write permission)."]);
    exit;
  }

  echo json_encode(["ok" => true, "dayNo" => $dayNo]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_dp_rmc") {
  header("Content-Type: application/json; charset=utf-8");

  $jalaliDate = trim((string)($_POST["jalaliDate"] ?? ""));
  $fileName = safe_filename((string)($_POST["fileName"] ?? ""));

  if ($jalaliDate === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Date is required."]);
    exit;
  }

  if (!isset($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No file uploaded."]);
    exit;
  }

  $root = rtrim(public_root(), "/");
  $targetDir = $root . "/database/production/" . safe_code(str_replace("/", "-", $jalaliDate));
  if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Cannot create production folder."]);
    exit;
  }

  $targetPath = rtrim($targetDir, "/") . "/" . $fileName;

  if (!@move_uploaded_file($_FILES["file"]["tmp_name"], $targetPath)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to save file on server."]);
    exit;
  }

  echo json_encode(["ok" => true, "savedTo" => $targetPath]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_cp_rmc") {
  header("Content-Type: application/json; charset=utf-8");

  $pid = (int)($_POST["projectId"] ?? 0);
  $fileName = safe_filename((string)($_POST["fileName"] ?? ""));

  if (!isset($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No file uploaded."]);
    exit;
  }

  $proj = find_project_by_id($PROJECTS_FILE, $pid, $isAdmin, $isObserver, $isClient, $myUsername);
  if (!$proj) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Project not found."]);
    exit;
  }

  $targetDir = rmc_dir_for_project($proj);
  if ($targetDir === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Cannot resolve project RMC directory."]);
    exit;
  }
  if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Cannot create project RMC folder."]);
    exit;
  }

  $targetPath = rtrim($targetDir, "/") . "/" . $fileName;
  if (file_exists($targetPath)) {
    @unlink($_FILES["file"]["tmp_name"]);
    echo json_encode(["ok" => true, "savedTo" => $targetPath, "note" => "Already exists; not overwritten."]);
    exit;
  }

  if (!@move_uploaded_file($_FILES["file"]["tmp_name"], $targetPath)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to save file on server."]);
    exit;
  }

  echo json_encode(["ok" => true, "savedTo" => $targetPath]);
  exit;
}

/* =========================
   PAGE RENDER
   ========================= */
render_header("Production Plan", $role);
?>
<div class="page" style="max-width:100%;">
  <div class="card">
    <div style="display:flex; gap:12px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap;">
      <div style="flex:1; min-width:280px;">
        <div class="title" style="margin-bottom:8px;">Production Day</div>
        <div class="pp-grid">
          <div>
            <label class="pp-label">Planned Date (Jalali)</label>
            <input id="ppDate" type="text" class="pp-input" placeholder="1404/10/10">
          </div>
          <div>
            <label class="pp-label">Existing Plans</label>
            <select id="ppPlanSelect" class="pp-select">
              <option value="">Select...</option>
            </select>
          </div>

          <div>
            <label class="pp-label">Day No.</label>
            <input id="ppDayNo" type="text" class="pp-input" readonly>
          </div>
          <div>
            <label class="pp-label">Status</label>
            <select id="ppStatus" class="pp-select">
              <option>Open</option>
              <option>In progress</option>
              <option>Waiting</option>
              <option>Closed</option>
            </select>
          </div>
        </div>
        <div class="pp-hint">
          Select subprojects and formulas (SF files). The page will calculate Card-level material needs and Day-level totals, and can generate DP-RMC (multi-page if >12 materials).
        </div>
      </div>

      <div class="pp-actions">
        <button id="btnLoadPlan" class="btn-ghost" type="button">Load</button>
        <button id="btnNewPlan" class="btn-ghost" type="button">New</button>
        <button id="btnSavePlan" class="btn" type="button">Save</button>
        <button id="btnDonePlan" class="btn" type="button">Done</button>
      </div>
    </div>

    <div id="ppMsg" style="margin-top:10px; font-size:13px;"></div>
  </div>

  <div class="card">
    <div class="title" style="margin-bottom:10px;">Select Subprojects</div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input id="ppFilter" type="text" placeholder="Filter (e.g., 302, 401-F, client...)" style="flex:1; min-width:260px; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
      <button id="btnAddSelected" class="btn" type="button">Add Selected</button>
      <button id="btnClearCards" class="btn-ghost" type="button">Clear</button>
    </div>

    <div style="margin-top:12px; overflow:auto;">
      <table class="table" style="min-width:760px;">
        <thead>
          <tr>
            <th style="width:56px;">Select</th>
            <th style="width:110px;">Type</th>
            <th style="width:140px;">Subproject</th>
            <th>Client</th>
          </tr>
        </thead>
        <tbody id="ppProjectRows"></tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="title" style="margin-bottom:10px;">Planned Cards (CP-RMC)</div>
    <div id="ppCards" style="display:grid; grid-template-columns: 1fr; gap:12px;"></div>
  </div>

  <div class="card">
    <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
      <div>
        <div class="title" style="margin-bottom:6px;">Day Production Raw Material Check (DP-RMC)</div>
        <div style="color:var(--muted); font-size:12px;">Aggregated totals from all selected SF files.</div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button id="btnDownloadDp" class="btn" type="button">Download DP-RMC</button>
        <button id="btnSaveDpServer" class="btn-ghost" type="button">Save DP-RMC to Server</button>
      </div>
    </div>

    <div id="dpSummary" style="margin-top:12px; overflow:auto;">
      <table class="table" style="min-width:760px;">
        <thead>
          <tr>
            <th style="width:56px;">#</th>
            <th>Material</th>
            <th style="width:160px;">Required (kg)</th>
          </tr>
        </thead>
        <tbody id="dpRows"></tbody>
      </table>
    </div>
    <div id="dpMsg" style="margin-top:10px; font-size:13px;"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js"></script>
<script>
const RMC_TEMPLATE_B64 = "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";

function setMsg(el, msg, ok=true){
  if(!el) return;
  el.textContent = msg || "";
  el.style.color = ok ? "var(--ok)" : "var(--danger)";
}

function b64ToUint8Array(b64){
  const binary = atob(b64);
  const len = binary.length;
  const bytes = new Uint8Array(len);
  for(let i=0;i<len;i++) bytes[i]=binary.charCodeAt(i);
  return bytes;
}

function cellText(v){
  if(v==null) return "";
  if(typeof v === "object" && v.richText) {
    return v.richText.map(x=>x.text).join("");
  }
  if(typeof v === "object" && v.text) return String(v.text);
  return String(v).trim();
}

function cellNumber(v){
  if(v==null) return null;
  if(typeof v === "number") return isFinite(v) ? v : null;
  if(typeof v === "object" && typeof v.result === "number") return isFinite(v.result) ? v.result : null;
  const s = String(v).replace(/,/g,"").trim();
  if(!s) return null;
  const n = Number(s);
  return isFinite(n) ? n : null;
}

function formatShortJalaliForFile(dateFull){
  const s = String(dateFull||"").trim();
  if(!s) return "";
  const m = s.match(/^(\d{2,4})[\.\/\-](\d{1,2})[\.\/\-](\d{1,2})$/);
  if(m){
    let yy = m[1];
    const mm = String(m[2]).padStart(2,"0");
    const dd = String(m[3]).padStart(2,"0");
    if(yy.length===4) yy = yy.slice(2);
    return `${yy}.${mm}.${dd}`;
  }
  const m2 = s.match(/(\d{2})\.(\d{2})\.(\d{2})/);
  if(m2) return `${m2[1]}.${m2[2]}.${m2[3]}`;
  return s.replaceAll("/",".").replaceAll("-",".");
}

function normalizeDateFullFromAny(d){
  const s = String(d||"").trim();
  const m = s.match(/^(\d{2,4})[\.\/\-](\d{1,2})[\.\/\-](\d{1,2})$/);
  if(m){
    let yyyy = m[1];
    const mm = String(m[2]).padStart(2,"0");
    const dd = String(m[3]).padStart(2,"0");
    if(yyyy.length===2) yyyy = "14" + yyyy;
    return `${yyyy}/${mm}/${dd}`;
  }
  return s;
}

function codeCompact(subCode){
  return String(subCode||"").replace(/[^0-9A-Za-z]/g,"");
}

async function fetchJson(url){
  const res = await fetch(url, {method:"GET"});
  const j = await res.json();
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Request failed");
  return j;
}

async function fetchSfFile(projectId, fileName){
  const url = new URL(window.location.href);
  url.searchParams.set("action","get_sf");
  url.searchParams.set("projectId", projectId);
  url.searchParams.set("file", fileName);
  const res = await fetch(url.toString(), {method:"GET"});
  if(!res.ok) throw new Error("Cannot read selected SF file from server.");
  return await res.arrayBuffer();
}

async function extractFromScf(sfWb){
  const ws = sfWb.getWorksheet("Sheet1") || sfWb.worksheets[0];
  const cols = ["D","E","F","G","H","I"];
  const items = [];
  for(let i=0;i<cols.length;i++) {
    const name = cellText(ws.getCell(cols[i]+"13").value);
    const qty  = cellNumber(ws.getCell(cols[i]+"15").value);
    if(name && qty!==null && qty!==0) items.push({ name, qty });
  }
  return {
    items,
    dateFull: cellText(ws.getCell("D4").value)
  };
}

async function extractFromSff(sfWb){
  const ws = sfWb.getWorksheet("Sheet1") || sfWb.worksheets[0];

  const totalMass = cellNumber(ws.getCell("F4").value) || 0;
  const thIn  = cellNumber(ws.getCell("D8").value) || 0;
  const thMid = cellNumber(ws.getCell("G8").value) || 0;
  const thOut = cellNumber(ws.getCell("J8").value) || 0;
  const thTot = (thIn + thMid + thOut) || (cellNumber(ws.getCell("G7").value) || 0) || 1;

  function collectLayer(namesRowA, pctRowA, namesRowB, pctRowB, layerMass){
    const cols = ["D","E","F","G","H","I"];
    const out = [];
    for(let i=0;i<6;i++) {
      const nameA = cellText(ws.getCell(cols[i]+String(namesRowA)).value);
      const pctA  = cellNumber(ws.getCell(cols[i]+String(pctRowA)).value);
      if(nameA && pctA!==null && pctA!==0) out.push({name:nameA, qty: layerMass * pctA });

      const nameB = cellText(ws.getCell(cols[i]+String(namesRowB)).value);
      const pctB  = cellNumber(ws.getCell(cols[i]+String(pctRowB)).value);
      if(nameB && pctB!==null && pctB!==0) out.push({name:nameB, qty: layerMass * pctB });
    }
    return out;
  }

  const inMass  = totalMass * (thIn  / thTot);
  const midMass = totalMass * (thMid / thTot);
  const outMass = totalMass * (thOut / thTot);

  const raw = []
    .concat(collectLayer(16,17,20,21,inMass))
    .concat(collectLayer(28,29,32,33,midMass))
    .concat(collectLayer(40,41,44,45,outMass));

  const map = new Map();
  for(const it of raw){
    const key = String(it.name||"").trim();
    if(!key) continue;
    const prev = map.get(key) || 0;
    const add = (typeof it.qty === "number" && isFinite(it.qty)) ? it.qty : 0;
    map.set(key, prev + add);
  }
  const items = Array.from(map.entries()).map(([name, qty]) => ({ name, qty }));

  return {
    items,
    dateFull: cellText(ws.getCell("D4").value)
  };
}

function toFixed3(n){
  if(typeof n !== "number" || !isFinite(n)) return "";
  const x = Math.round(n*1000)/1000;
  return String(x);
}

function mapToItems(map){
  return Array.from(map.entries()).map(([name, qty])=>({name, qty})).sort((a,b)=>a.name.localeCompare(b.name));
}

function sliceItems(items, n){
  const out = [];
  for(let i=0;i<items.length;i+=n) out.push(items.slice(i,i+n));
  return out;
}

function cloneSheet(workbook, sourceWs, name){
  const ws = workbook.addWorksheet(name);
  ws.model = JSON.parse(JSON.stringify(sourceWs.model));
  ws.name = name;
  return ws;
}

function fillRmcSheet(ws, headerCode, customer, sfRef, dateFull, items, headings){
  if(headings) {
    if(headings.c5) ws.getCell("C5").value = headings.c5;
    if(headings.e5) ws.getCell("E5").value = headings.e5;
    if(headings.g5) ws.getCell("G5").value = headings.g5;
    if(headings.i5) ws.getCell("I5").value = headings.i5;
  }

  ws.getCell("B2").value = headerCode || "";
  ws.getCell("E3").value = customer || "";
  ws.getCell("H3").value = sfRef || "";
  ws.getCell("J3").value = dateFull || "";

  for(let r=6;r<=17;r++) {
    ws.getCell("C"+r).value = null;
    ws.getCell("E"+r).value = null;
    ws.getCell("G"+r).value = null;
    ws.getCell("I"+r).value = null;
  }

  for(let i=0;i<items.length && i<12;i++) {
    const r = 6 + i;
    ws.getCell("C"+r).value = String(items[i].name||"");
    ws.getCell("E"+r).value = (typeof items[i].qty === "number" && isFinite(items[i].qty)) ? Number(items[i].qty) : null;
  }
}

async function buildRmcWorkbook(params){
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(b64ToUint8Array(RMC_TEMPLATE_B64));
  const base = wb.worksheets[0];
  base.name = "Page 1";

  const chunks = sliceItems(params.items||[], 12);
  const pages = chunks.length || 1;

  fillRmcSheet(base, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[0]||[], params.headings);

  for(let p=2;p<=pages;p++) {
    const newWs = cloneSheet(wb, base, "Page " + p);
    fillRmcSheet(newWs, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[p-1]||[], params.headings);
  }

  return await wb.xlsx.writeBuffer();
}

async function uploadDpRmc(buffer, fileName, jalaliDate){
  const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
  const fd = new FormData();
  fd.append("action","upload_dp_rmc");
  fd.append("fileName", fileName);
  fd.append("jalaliDate", jalaliDate);
  fd.append("file", blob, fileName);

  const res = await fetch(window.location.href, {method:"POST", body:fd});
  const j = await res.json().catch(()=>null);
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Upload failed");
  return j.savedTo || "";
}

async function uploadCpRmc(buffer, fileName, projectId){
  const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
  const fd = new FormData();
  fd.append("action","upload_cp_rmc");
  fd.append("fileName", fileName);
  fd.append("projectId", String(projectId));
  fd.append("file", blob, fileName);

  const res = await fetch(window.location.href, {method:"POST", body:fd});
  const j = await res.json().catch(()=>null);
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Upload failed");
  return j.savedTo || "";
}

function downloadBuffer(buffer, fileName){
  const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = fileName;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/* =========================
   UI State
   ========================= */
let ALL_PROJECTS = [];
let PLANS = [];
let ORIGINAL_DATE = "";
let PLAN_LOCKED = false;
let CARDS = []; // {project, sfFile, extracted:{items,dateFull}}

function renderProjectsTable(){
  const tbody = document.getElementById("ppProjectRows");
  const q = (document.getElementById("ppFilter").value||"").trim().toLowerCase();

  const rows = ALL_PROJECTS.filter(p=>{
    if(!q) return true;
    return (
      String(p.subCode||"").toLowerCase().includes(q) ||
      String(p.type||"").toLowerCase().includes(q) ||
      String(p.clientSlug||"").toLowerCase().includes(q) ||
      String(p.name||"").toLowerCase().includes(q)
    );
  });

  tbody.innerHTML = rows.map(p=>`
    <tr>
      <td><input type="checkbox" class="ppChk" data-id="${p.id}"></td>
      <td>${p.type || ""}</td>
      <td><b>${p.subCode || ""}</b></td>
      <td>${p.clientSlug || ""}</td>
    </tr>
  `).join("");
}

async function ensureSfList(projectId){
  const url = new URL(window.location.href);
  url.searchParams.set("action","list_sf");
  url.searchParams.set("projectId", projectId);
  const j = await fetchJson(url.toString());
  return (j.files || []);
}

function renderCards(){
  const host = document.getElementById("ppCards");
  host.innerHTML = "";

  CARDS.forEach((c, idx)=>{
    const card = document.createElement("div");
    card.className = "card";
    card.style.margin = "0";
    card.innerHTML = `
      <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <div>
          <div style="font-weight:800; font-size:16px;">${c.project.subCode} (Type: ${c.project.type})</div>
          <div style="color:var(--muted); font-size:12px;">Client: ${c.project.clientSlug}</div>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button class="btn-ghost" type="button" data-act="remove" data-idx="${idx}">Remove</button>
        </div>
      </div>

      <div style="margin-top:12px;" class="grid">
        <div>
          <label style="display:block; font-weight:600; margin-bottom:6px;">Select SF (Formulation) File</label>
          <select data-act="sfSelect" data-idx="${idx}" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
            <option value="">Select...</option>
          </select>
          <div style="margin-top:6px; color:var(--muted); font-size:12px;">Files are loaded from the server folder for this subproject.</div>
        </div>

        <div>
          <label style="display:block; font-weight:600; margin-bottom:6px;">Actions</label>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn" type="button" data-act="downloadCp" data-idx="${idx}">Download CP-RMR</button>
            <button class="btn-ghost" type="button" data-act="saveCp" data-idx="${idx}">Save CP-RMR to Server</button>
          </div>
          <div style="margin-top:6px; color:var(--muted); font-size:12px;" data-act="cpMsg" data-idx="${idx}"></div>
        </div>
      </div>

      <div style="margin-top:12px; overflow:auto;">
        <table class="table" style="min-width:760px;">
          <thead>
            <tr>
              <th style="width:56px;">#</th>
              <th>Material</th>
              <th style="width:160px;">Required (kg)</th>
            </tr>
          </thead>
          <tbody data-act="matBody" data-idx="${idx}"></tbody>
        </table>
      </div>
    `;
    host.appendChild(card);
  });

  host.querySelectorAll("button[data-act]").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      const act = btn.getAttribute("data-act");
      const idx = Number(btn.getAttribute("data-idx"));
      if(!isFinite(idx)) return;

      if(act==="remove") {
        CARDS.splice(idx,1);
        renderCards();
        renderDpSummary();
        return;
      }

      if(act==="downloadCp") {
        await handleDownloadCp(idx, false);
        return;
      }
      if(act==="saveCp") {
        await handleDownloadCp(idx, true);
        return;
      }
    });
  });

  host.querySelectorAll("select[data-act='sfSelect']").forEach(sel=>{
    sel.addEventListener("change", async ()=>{
      const idx = Number(sel.getAttribute("data-idx"));
      const file = sel.value || "";
      await handleSelectSf(idx, file);
    });
  });
}

async function populateCardSfDropdown(idx){
  const c = CARDS[idx];
  const host = document.querySelector(`select[data-act='sfSelect'][data-idx='${idx}']`);
  if(!host) return;
  host.innerHTML = `<option value="">Loading...</option>`;
  try {
    const files = await ensureSfList(c.project.id);
    host.innerHTML = `<option value="">Select...</option>` + files.map(f=>`<option value="${f}">${f}</option>`).join("");
    if(c.sfFile) host.value = c.sfFile;
  } catch(e) {
    host.innerHTML = `<option value="">(No files)</option>`;
  }
}

function renderCardMaterials(idx){
  const c = CARDS[idx];
  const body = document.querySelector(`tbody[data-act='matBody'][data-idx='${idx}']`);
  if(!body) return;
  const items = c.extracted && c.extracted.items ? c.extracted.items.slice() : [];
  items.sort((a,b)=>String(a.name).localeCompare(String(b.name)));
  body.innerHTML = items.map((it,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${it.name}</td>
      <td>${toFixed3(Number(it.qty||0))}</td>
    </tr>
  `).join("") || `<tr><td colspan="3" style="color:var(--muted);">No SF selected.</td></tr>`;
}

function renderDpSummary(){
  const body = document.getElementById("dpRows");
  const map = new Map();
  CARDS.forEach(c=>{
    if(!c.extracted || !Array.isArray(c.extracted.items)) return;
    c.extracted.items.forEach(it=>{
      const name = String(it.name||"").trim();
      const qty = Number(it.qty||0);
      if(!name || !isFinite(qty)) return;
      map.set(name, (map.get(name)||0)+qty);
    });
  });
  const items = mapToItems(map);
  body.innerHTML = items.map((it,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${it.name}</td>
      <td>${toFixed3(it.qty)}</td>
    </tr>
  `).join("") || `<tr><td colspan="3" style="color:var(--muted);">No formulas selected.</td></tr>`;

  window.__DP_ITEMS__ = items;
}

async function handleSelectSf(idx, fileName){
  const c = CARDS[idx];
  c.sfFile = fileName;
  c.extracted = null;
  renderCardMaterials(idx);
  renderDpSummary();

  if(!fileName) return;

  try {
    const buf = await fetchSfFile(c.project.id, fileName);
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(buf);

    let ex;
    if(String(c.project.type||"").toUpperCase()==="F") ex = await extractFromSff(wb);
    else ex = await extractFromScf(wb);

    c.extracted = ex;
    renderCardMaterials(idx);
    renderDpSummary();
  } catch(e) {
    console.error(e);
    alert("Failed to read SF file: " + (e.message||e));
  }
}

async function handleDownloadCp(idx, alsoSaveServer){
  const c = CARDS[idx];
  const msgEl = document.querySelector(`[data-act='cpMsg'][data-idx='${idx}']`);
  setMsg(msgEl, "", true);

  if(!c.sfFile || !c.extracted) {
    setMsg(msgEl, "Select an SF file first.", false);
    return;
  }

  const m = String(c.sfFile).match(/\bSF(\d{2})\b/i);
  if(!m) {
    setMsg(msgEl, "Selected file name does not contain SF##.", false);
    return;
  }
  const sfVer = m[1];
  const dateFull = normalizeDateFullFromAny(c.extracted.dateFull);
  const dateShort = formatShortJalaliForFile(dateFull);
  const compact = codeCompact(c.project.subCode);

  const headerCode = `${compact}- RMC${sfVer}`;
  const customer = c.project.clientSlug || "";
  const sfRef = `${compact}- SF${sfVer}`;

  const items = (c.extracted.items || []).map(it=>({name:String(it.name||""), qty:Number(it.qty||0)}));

  try {
    const buf = await buildRmcWorkbook({
      headerCode,
      customer,
      sfRef,
      dateFull,
      items
    });

    const fileName = `${compact}- RMC${sfVer} ${dateShort}.xlsx`;
    downloadBuffer(buf, fileName);

    if(alsoSaveServer) {
      await uploadCpRmc(buf, fileName, c.project.id);
      setMsg(msgEl, "Saved on server (project RMC folder).", true);
    } else {
      setMsg(msgEl, "Downloaded.", true);
    }
  } catch(e) {
    console.error(e);
    setMsg(msgEl, e.message || String(e), false);
  }
}

async function handleDownloadDp(alsoSaveServer){
  const dpMsg = document.getElementById("dpMsg");
  setMsg(dpMsg, "", true);

  const jalaliDate = (document.getElementById("ppDate").value||"").trim();
  const dayNo = (document.getElementById("ppDayNo").value||"").trim();
  if(!jalaliDate) {
    setMsg(dpMsg, "Planned date is required.", false);
    return;
  }
  if(!dayNo) {
    setMsg(dpMsg, "Day No. is missing; click Load Date.", false);
    return;
  }

  const items = (window.__DP_ITEMS__ || []);
  if(!items.length) {
    setMsg(dpMsg, "No formulas selected.", false);
    return;
  }

  const dayNo2 = String(dayNo).padStart(2,"0");
  const dateShort = formatShortJalaliForFile(jalaliDate);
  const headerCode = `DP- RMC${dayNo2}`;
  const sfRef = "MULTI";
  const customer = "Production";
  const dateFull = normalizeDateFullFromAny(jalaliDate);

  try {
    const buf = await buildRmcWorkbook({
      headerCode,
      customer,
      sfRef,
      dateFull,
      items
    });
    const fileName = `DP- RMC${dayNo2} ${dateShort}.xlsx`;
    downloadBuffer(buf, fileName);

    if(alsoSaveServer) {
      await uploadDpRmc(buf, fileName, jalaliDate);
      setMsg(dpMsg, "Saved on server (database/production/<date>/).", true);
    } else {
      setMsg(dpMsg, "Downloaded.", true);
    }
  } catch(e) {
    console.error(e);
    setMsg(dpMsg, e.message || String(e), false);
  }
}

async function loadPlanForDate(dateStr){
  const msg = document.getElementById("ppMsg");
  setMsg(msg, "", true);

  const url = new URL(window.location.href);
  url.searchParams.set("action","get_plan");
  url.searchParams.set("date", dateStr || "");

  try {
    const j = await fetchJson(url.toString());
    const plan = j.plan || null;
    ORIGINAL_DATE = dateStr;

    if(plan) {
      document.getElementById("ppDayNo").value = String(plan.dayNo||"");
      document.getElementById("ppStatus").value = String(plan.status||"Open");

      
      setLocked(String(plan.status||"") === "Closed");
CARDS = [];
      const entries = Array.isArray(plan.entries) ? plan.entries : [];
      entries.forEach(en=>{
        const pid = Number(en.projectId||0);
        const proj = ALL_PROJECTS.find(p=>Number(p.id)===pid);
        if(!proj) return;
        CARDS.push({
          project: proj,
          sfFile: String(en.sfFile||""),
          extracted: null
        });
      });
      renderCards();
      for(let i=0;i<CARDS.length;i++) {
        await populateCardSfDropdown(i);
      }
      for(let i=0;i<CARDS.length;i++) {
        if(CARDS[i].sfFile) await handleSelectSf(i, CARDS[i].sfFile);
      }
      setMsg(msg, "Plan loaded.", true);
    } else {
      ORIGINAL_DATE = "";
      setLocked(false);
      document.getElementById("ppDayNo").value = String(j.suggestedDayNo || "");
      document.getElementById("ppStatus").value = "Open";
      setMsg(msg, "No existing plan for this date. Ready to create.", true);
      CARDS = [];
      renderCards();
      renderDpSummary();
    }
  } catch(e) {
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}

async function saveCurrentPlan(){
  const msg = document.getElementById("ppMsg");
  setMsg(msg, "", true);

  const jalaliDate = (document.getElementById("ppDate").value||"").trim();
  if(!jalaliDate) {
    setMsg(msg, "Planned date is required.", false);
    return;
  }

  const entries = CARDS.filter(c=>c.sfFile).map(c=>({
    projectId: c.project.id,
    subCode: c.project.subCode,
    type: c.project.type,
    clientSlug: c.project.clientSlug,
    sfFile: c.sfFile
  }));

  const payload = {
    originalDate: ORIGINAL_DATE || "",
    jalaliDate,
    dayNo: Number(document.getElementById("ppDayNo").value||0),
    status: (document.getElementById("ppStatus").value||"Open"),
    entries,
    dpMaterials: (window.__DP_ITEMS__ || [])
  };

  const fd = new FormData();
  fd.append("action","save_plan");
  fd.append("payload", JSON.stringify(payload));

  try {
    const res = await fetch(window.location.href, {method:"POST", body:fd});
    const j = await res.json().catch(()=>null);
    if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Save failed");
    document.getElementById("ppDayNo").value = String(j.dayNo||payload.dayNo||"");
    ORIGINAL_DATE = (document.getElementById("ppDate").value||"").trim();
    await refreshPlans();
    setLocked((document.getElementById("ppStatus").value||"") === "Closed");
    setMsg(msg, "Saved.", true);
} catch(e) {
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}


async function refreshPlans(){
  const url = new URL(window.location.href);
  url.searchParams.set("action","list_plans");
  try{
    const j = await fetchJson(url.toString());
    PLANS = (j.plans || []);
  }catch(e){
    PLANS = [];
    const msg = document.getElementById("ppMsg");
    if(msg) setMsg(msg, "Cannot load existing plans (check production_days.json permissions).", false);
  }
  renderPlanSelect();
}

function renderPlanSelect(){
  const sel = document.getElementById("ppPlanSelect");
  if(!sel) return;
  const current = sel.value || "";
  sel.innerHTML = `<option value="">Select...</option>` + PLANS.map(p=>{
    const d = p.jalaliDate || "";
    const n = String(p.dayNo||"");
    const st = p.status || "";
    return `<option value="${d}">${d} — Day ${n} (${st})</option>`;
  }).join("");
  if(current) sel.value = current;
}

function setLocked(locked){
  PLAN_LOCKED = !!locked;

  const dateInp = document.getElementById("ppDate");
  const planSel = document.getElementById("ppPlanSelect");
  const statusSel = document.getElementById("ppStatus");
  const btnSave = document.getElementById("btnSavePlan");
  const btnDone = document.getElementById("btnDonePlan");

  if(dateInp) dateInp.disabled = locked;
  if(planSel) planSel.disabled = false;
if(statusSel) statusSel.disabled = locked;

  // selection controls
  document.querySelectorAll(".ppChk, .ppSfSelect").forEach(el=>{ el.disabled = locked; });

  // action buttons
  if(btnSave) btnSave.disabled = locked;
  if(btnDone) btnDone.disabled = locked;
  const btnAdd = document.getElementById("btnAddSelected");
  const btnClear = document.getElementById("btnClearCards");
  if(btnAdd) btnAdd.disabled = locked;
  if(btnClear) btnClear.disabled = locked;
}

async function newPlan(){
  if(PLAN_LOCKED) return;

  CARDS = [];
  renderCards();
  renderDpSummary();

  document.getElementById("ppStatus").value = "Open";
  ORIGINAL_DATE = "";
  setLocked(false);

  // propose next day number from plans list
  let max = 0;
  for(const p of PLANS){ max = Math.max(max, Number(p.dayNo||0)); }
  document.getElementById("ppDayNo").value = String(max + 1).padStart(2,"0");

  const msg = document.getElementById("ppMsg");
  setMsg(msg, "New plan initialized.", true);
}

async function markDone(){
  if(PLAN_LOCKED) return;
  document.getElementById("ppStatus").value = "Closed";
  await saveCurrentPlan();
  setLocked(true);
  const msg = document.getElementById("ppMsg");
  setMsg(msg, "Done. Plan is locked. Use Production Log for actuals.", true);
}

async function init(){
  await refreshPlans();
  const url = new URL(window.location.href);
  url.searchParams.set("action","list_projects");
  const j = await fetchJson(url.toString());
  ALL_PROJECTS = (j.projects || []).filter(p=>p.id);

  renderProjectsTable();

  document.getElementById("ppFilter").addEventListener("input", renderProjectsTable);

  const ppPlanSelect = document.getElementById("ppPlanSelect");
  if(ppPlanSelect){
    ppPlanSelect.addEventListener("change", async ()=>{
      const d = (ppPlanSelect.value||"").trim();
      if(d){
        document.getElementById("ppDate").value = d;
        await loadPlanForDate(d);
      }
    });
  }


  document.getElementById("btnAddSelected").addEventListener("click", async ()=>{
    const ids = Array.from(document.querySelectorAll(".ppChk:checked")).map(x=>Number(x.getAttribute("data-id")));
    const toAdd = ALL_PROJECTS.filter(p=>ids.includes(Number(p.id)));
    toAdd.forEach(p=>{
      if(CARDS.find(c=>Number(c.project.id)===Number(p.id))) return;
      CARDS.push({ project:p, sfFile:"", extracted:null });
    });
    renderCards();
    for(let i=0;i<CARDS.length;i++) await populateCardSfDropdown(i);
  });

  document.getElementById("btnClearCards").addEventListener("click", ()=>{
    if(!confirm("Clear all cards?")) return;
    CARDS = [];
    renderCards();
    renderDpSummary();
  });

  document.getElementById("btnLoadPlan").addEventListener("click", async ()=>{
    const d = (document.getElementById("ppDate").value||"").trim();
    if(!d) return;
    await loadPlanForDate(d);
  });

  document.getElementById("btnSavePlan").addEventListener("click", saveCurrentPlan);

  const btnNew = document.getElementById("btnNewPlan");
  if(btnNew) btnNew.addEventListener("click", newPlan);
  const btnDone = document.getElementById("btnDonePlan");
  if(btnDone) btnDone.addEventListener("click", markDone);


  document.getElementById("btnDownloadDp").addEventListener("click", ()=>handleDownloadDp(false));
  document.getElementById("btnSaveDpServer").addEventListener("click", ()=>handleDownloadDp(true));

  const params = new URLSearchParams(window.location.search||"");
  const prefillId = Number(params.get("id")||0);
  const prefillDate = params.get("date") || "";
  if(prefillDate) document.getElementById("ppDate").value = prefillDate;

  if(prefillId) {
    const proj = ALL_PROJECTS.find(p=>Number(p.id)===prefillId);
    if(proj) {
      CARDS.push({project:proj, sfFile:"", extracted:null});
      renderCards();
      await populateCardSfDropdown(0);
    }
  }

  if((document.getElementById("ppDate").value||"").trim()) {
    await loadPlanForDate((document.getElementById("ppDate").value||"").trim());
  }
}

document.addEventListener("DOMContentLoaded", init);
</script>

<?php render_footer(); ?>
