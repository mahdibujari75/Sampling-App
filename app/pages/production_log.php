<?php
/************************************************************
 * Production Log — production_log.php
 * Route: /production_log   (or index.php?route=production_log)
 *
 * Reads/writes:
 *   /public_html/database/production/production_days.json
 *
 * Allows:
 *   - View planned DP materials
 *   - Enter actual consumed amounts (مصرفی) and compute remaining (باقیمانده)
 *   - Mark executed formulas (done flags per entry)
 *   - Delete plan
 *   - Download DP-RMR (log) file (same template as RMC, different column titles)
 ************************************************************/

if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/.."));
}

require_once APP_ROOT . "/includes/auth.php";
require_login();
require_once APP_ROOT . "/includes/layout.php";

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

function public_root(): string {
  $root = realpath(APP_ROOT . "/..");
  return $root ?: (APP_ROOT . "/..");
}

function production_db_file(): string {
  return rtrim(public_root(), "/") . "/database/production/production_days.json";
}

$me = current_user();
$role = (string)($me["role"] ?? "Observer");
$isAdmin = ($role === "Admin");
$isObserver = ($role === "Observer");
$myUsername = (string)($me["username"] ?? "");

if (!$isAdmin && !$isObserver) {
  http_response_code(403);
  echo "Access denied.";
  exit;
}

/* =========================
   ACTIONS (AJAX)
   ========================= */
$action = (string)($_GET["action"] ?? "");
if ($action === "list_plans") {
  header("Content-Type: application/json; charset=utf-8");
  $db = production_db_file();
  $plans = load_json_array($db);
  $out = [];
  foreach ($plans as $p) {
    if (!is_array($p)) continue;
    $out[] = [
      "jalaliDate" => (string)($p["jalaliDate"] ?? ""),
      "dayNo" => (int)($p["dayNo"] ?? 0),
      "status" => (string)($p["status"] ?? "Open"),
      "updatedAt" => (string)($p["updatedAt"] ?? ""),
    ];
  }
  usort($out, function($a, $b) {
    return strcmp((string)$b["jalaliDate"], (string)$a["jalaliDate"]);
  });
  echo json_encode(["ok" => true, "plans" => $out]);
  exit;
}

if ($action === "get_plan") {
  header("Content-Type: application/json; charset=utf-8");
  $date = trim((string)($_GET["date"] ?? ""));
  $db = production_db_file();
  $plans = load_json_array($db);
  $plan = null;
  foreach ($plans as $p) {
    if (!is_array($p)) continue;
    if ((string)($p["jalaliDate"] ?? "") === $date) { $plan = $p; break; }
  }
  echo json_encode(["ok" => true, "plan" => $plan]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "save_log") {
  header("Content-Type: application/json; charset=utf-8");
  $payload = (string)($_POST["payload"] ?? "");
  $data = json_decode($payload ?: "{}", true);
  if (!is_array($data)) $data = [];

  $date = trim((string)($data["jalaliDate"] ?? ""));
  if ($date === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Date is required."]);
    exit;
  }

  $db = production_db_file();
  $plans = load_json_array($db);

  $idx = -1;
  for ($i=0; $i<count($plans); $i++) {
    if (!is_array($plans[$i])) continue;
    if ((string)($plans[$i]["jalaliDate"] ?? "") === $date) { $idx = $i; break; }
  }
  if ($idx < 0) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Plan not found for this date."]);
    exit;
  }

  $log = [
    "materials" => is_array($data["materials"] ?? null) ? $data["materials"] : [],
    "rmfMaterials" => is_array($data["rmfMaterials"] ?? null) ? $data["rmfMaterials"] : [],
    "entriesDone" => is_array($data["entriesDone"] ?? null) ? $data["entriesDone"] : [],
    "notes" => (string)($data["notes"] ?? ""),
    "updatedAt" => gmdate("c"),
    "updatedBy" => $GLOBALS["myUsername"],
  ];

  $plans[$idx]["log"] = $log;
  $plans[$idx]["status"] = (string)($data["status"] ?? ($plans[$idx]["status"] ?? "Open"));
  $plans[$idx]["updatedAt"] = gmdate("c");
  $plans[$idx]["updatedBy"] = $GLOBALS["myUsername"];

  if (!save_json_array_atomic($db, $plans)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to save production log."]);
    exit;
  }

  echo json_encode(["ok" => true]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "delete_plan") {
  header("Content-Type: application/json; charset=utf-8");
  $date = trim((string)($_POST["jalaliDate"] ?? ""));
  if ($date === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Date is required."]);
    exit;
  }

  $db = production_db_file();
  $plans = load_json_array($db);
  $new = [];
  $deleted = false;
  foreach ($plans as $p) {
    if (!is_array($p)) continue;
    if ((string)($p["jalaliDate"] ?? "") === $date) { $deleted = true; continue; }
    $new[] = $p;
  }
  if (!$deleted) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Plan not found."]);
    exit;
  }

  if (!save_json_array_atomic($db, $new)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to delete plan."]);
    exit;
  }

  echo json_encode(["ok" => true]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_dp_rmr") {
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

if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_rmf") {
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


/* =========================
   PAGE RENDER
   ========================= */
render_header("Production Log", $role);
?>
<div class="page">
  <div class="card">
    <div style="display:flex; gap:12px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap;">
      <div style="flex:1; min-width:280px;">
        <div class="title" style="margin-bottom:8px;">Select Production Day</div>
        <div class="grid" style="grid-template-columns: 1fr 220px; gap:12px;">
          <div>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Production Day (date)</label>
            <select id="plDaySelect" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
              <option value="">Loading...</option>
            </select>
          </div>
          <div>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Status</label>
            <select id="plStatus" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
              <option>Open</option>
              <option>In progress</option>
              <option>Waiting</option>
              <option>Closed</option>
            </select>
          </div>
        </div>
        <div id="plMeta" style="margin-top:10px; color:var(--muted); font-size:12px;"></div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <button id="btnOpenPlan" class="btn-ghost" type="button">Open Plan Page</button>
        <button id="btnSaveLog" class="btn" type="button">Save Log</button>
        <button id="btnDeletePlan" class="btn-danger" type="button">Delete Plan</button>
      </div>
    </div>
    <div id="plMsg" style="margin-top:10px; font-size:13px;"></div>
  </div>

  <div class="card">
    <div class="title" style="margin-bottom:10px;">Executed Formulas</div>
    <div id="plEntries" style="display:grid; grid-template-columns: 1fr; gap:10px;"></div>
  </div>

  <div class="card">
    <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
      <div>
        <div class="title" style="margin-bottom:6px;">DP-RMR (Log) — Materials</div>
        <div style="color:var(--muted); font-size:12px;">Columns: ورودی (planned input), مصرفی (actual used), باقیمانده (remaining).</div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button id="btnDownloadLog" class="btn" type="button">Download DP-RMR (Log)</button>
        <button id="btnSaveLogServer" class="btn-ghost" type="button">Save DP-RMR to Server</button>
      </div>
    </div>

    <div style="margin-top:12px; overflow:auto;">
      <table class="table" style="min-width:900px;">
        <thead>
          <tr>
            <th style="width:56px;">#</th>
            <th>Material</th>
            <th style="width:160px;">ورودی (kg)</th>
            <th style="width:160px;">مصرفی (kg)</th>
            <th style="width:160px;">باقیمانده (kg)</th>
          </tr>
        </thead>
        <tbody id="plMatRows"></tbody>
      </table>
    </div>

    <div style="margin-top:12px;">
      <label style="display:block; font-weight:600; margin-bottom:6px;">Notes</label>
      <textarea id="plNotes" rows="3" style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;"></textarea>
    </div>

    <div id="plFileMsg" style="margin-top:10px; font-size:13px;"></div>
  </div>
</div>


<div class="card" style="margin-top:14px;">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <div>
      <div class="title" style="margin-bottom:6px;">RMF — Raw Material Flow</div>
      <div style="color:var(--muted); font-size:12px;">
        Generates an RMF file (based on the RMC template) with columns: مقدار مورد نیاز / مقدار دریافت شده / مقدار باقیمانده.
        Columns 1–3 are prefilled from the plan and locked. You can enter columns 4–5.
      </div>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <button id="btnOpenRMF" class="btn" type="button">RMF Generator</button>
    </div>
  </div>
  <div id="rmfMsg" style="margin-top:10px; font-size:13px;"></div>
</div>

<!-- RMF Modal -->
<div id="rmfModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999; padding:20px;">
  <div class="card" style="max-width:1100px; margin:0 auto; max-height:85vh; overflow:auto;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <div class="title" style="margin-bottom:6px;">RMF Form</div>
        <div style="color:var(--muted); font-size:12px;">
          Fill <b>مقدار دریافت شده</b> and/or <b>مقدار باقیمانده</b>. Required quantities are from the plan (DP materials).
        </div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button id="btnRMFDownload" class="btn" type="button">Download RMF</button>
        <button id="btnRMFSaveServer" class="btn-ghost" type="button">Save RMF to Server</button>
        <button id="btnRMFClose" class="btn-ghost" type="button">Close</button>
      </div>
    </div>

    <div style="margin-top:12px; overflow:auto;">
      <table class="table" style="min-width:980px;">
        <thead>
          <tr>
            <th style="width:56px;">#</th>
            <th>نام ماده</th>
            <th style="width:200px;">مقدار مورد نیاز</th>
            <th style="width:200px;">مقدار دریافت شده</th>
            <th style="width:200px;">مقدار باقیمانده</th>
          </tr>
        </thead>
        <tbody id="rmfRows"></tbody>
      </table>
    </div>

    <div id="rmfModalMsg" style="margin-top:10px; font-size:13px;"></div>
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

function toFixed3(n){
  if(typeof n !== "number" || !isFinite(n)) return "";
  const x = Math.round(n*1000)/1000;
  return String(x);
}

function escapeHtml(s){
  return String(s ?? "").replace(/[&<>"']/g, (c)=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;" }[c]));
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

function fillLogSheet(ws, headerCode, customer, sfRef, dateFull, items){
  // Override headings for log
  ws.getCell("E5").value = "ورودی";
  ws.getCell("G5").value = "مصرفی";
  ws.getCell("I5").value = "باقیمانده";

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
    ws.getCell("E"+r).value = (typeof items[i].input === "number" && isFinite(items[i].input)) ? Number(items[i].input) : null;
    ws.getCell("G"+r).value = (typeof items[i].consumed === "number" && isFinite(items[i].consumed)) ? Number(items[i].consumed) : null;
    ws.getCell("I"+r).value = (typeof items[i].remaining === "number" && isFinite(items[i].remaining)) ? Number(items[i].remaining) : null;
  }
}

function fillRmfSheet(ws, headerCode, customer, sfRef, dateFull, items){
  // Override headings for RMF (Raw Material Flow)
  // Column mapping (same as template):
  // C: نام ماده | E: مقدار مورد نیاز | G: مقدار دریافت شده | I: مقدار باقیمانده
  ws.getCell("E5").value = "مقدار مورد نیاز";
  ws.getCell("G5").value = "مقدار دریافت شده";
  ws.getCell("I5").value = "مقدار باقیمانده";

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
    ws.getCell("E"+r).value = (typeof items[i].required === "number" && isFinite(items[i].required)) ? Number(items[i].required) : null;
    ws.getCell("G"+r).value = (typeof items[i].received === "number" && isFinite(items[i].received)) ? Number(items[i].received) : null;
    ws.getCell("I"+r).value = (typeof items[i].remaining === "number" && isFinite(items[i].remaining)) ? Number(items[i].remaining) : null;
  }
}

async function buildRmfWorkbook(params){
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(b64ToUint8Array(RMC_TEMPLATE_B64));
  const base = wb.worksheets[0];
  base.name = "Page 1";

  const chunks = sliceItems(params.items||[], 12);
  const pages = chunks.length || 1;

  fillRmfSheet(base, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[0]||[]);

  for(let p=2;p<=pages;p++) {
    const newWs = cloneSheet(wb, base, "Page " + p);
    fillRmfSheet(newWs, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[p-1]||[]);
  }

  return await wb.xlsx.writeBuffer();
}


async function buildLogWorkbook(params){
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(b64ToUint8Array(RMC_TEMPLATE_B64));
  const base = wb.worksheets[0];
  base.name = "Page 1";

  const chunks = sliceItems(params.items||[], 12);
  const pages = chunks.length || 1;

  fillLogSheet(base, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[0]||[]);

  for(let p=2;p<=pages;p++) {
    const newWs = cloneSheet(wb, base, "Page " + p);
    fillLogSheet(newWs, params.headerCode, params.customer, params.sfRef, params.dateFull, chunks[p-1]||[]);
  }

  return await wb.xlsx.writeBuffer();
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

async function uploadDpRmr(buffer, fileName, jalaliDate){
  const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
  const fd = new FormData();
  fd.append("action","upload_dp_rmr");
  fd.append("fileName", fileName);
  fd.append("jalaliDate", jalaliDate);
  fd.append("file", blob, fileName);

  const res = await fetch(window.location.href, {method:"POST", body:fd});
  const j = await res.json().catch(()=>null);
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Upload failed");
  return j.savedTo || "";
}

async function fetchJson(url){
  const res = await fetch(url, {method:"GET"});
  const j = await res.json();
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Request failed");
  return j;
}

/* =========================
   State
   ========================= */
let PLANS = [];
let CURRENT = null; // full plan record
let MATERIAL_ROWS = []; // {name, input, consumed, remaining}
let RMF_ROWS = []; // {name, required, received, remaining}

function renderDaySelect(){
  const sel = document.getElementById("plDaySelect");
  sel.innerHTML = `<option value="">Select...</option>` + PLANS.map(p=>{
    const d = p.jalaliDate || "";
    const n = String(p.dayNo||"");
    const st = p.status || "";
    return `<option value="${d}">${d} — Day ${n} (${st})</option>`;
  }).join("");
}

function renderEntries(){
  const host = document.getElementById("plEntries");
  host.innerHTML = "";
  if(!CURRENT || !Array.isArray(CURRENT.entries) || CURRENT.entries.length===0){
    host.innerHTML = `<div style="color:var(--muted);">No entries.</div>`;
    return;
  }

  const doneMap = new Map();
  const log = CURRENT.log || {};
  (log.entriesDone || []).forEach(x=>{
    if(!x) return;
    doneMap.set(String(x.key||""), !!x.done);
  });

  CURRENT.entries.forEach((e, idx)=>{
    const key = `${e.projectId}|${e.sfFile}`;
    const done = doneMap.get(key) || false;

    const div = document.createElement("div");
    div.className = "card";
    div.style.margin = "0";
    div.innerHTML = `
      <div style="display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <div>
          <div style="font-weight:800;">${e.subCode || ""}</div>
          <div style="color:var(--muted); font-size:12px;">${e.sfFile || ""}</div>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
          <label style="display:flex; gap:8px; align-items:center; font-weight:700;">
            <input type="checkbox" class="entryDone" data-key="${key}" ${done ? "checked" : ""}>
            Done
          </label>
        </div>
      </div>
    `;
    host.appendChild(div);
  });
}

function renderMaterials(){
  const body = document.getElementById("plMatRows");
  body.innerHTML = "";

  if(MATERIAL_ROWS.length===0){
    body.innerHTML = `<tr><td colspan="5" style="color:var(--muted);">No DP materials.</td></tr>`;
    return;
  }

  body.innerHTML = MATERIAL_ROWS.map((r,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${r.name}</td>
      <td>${toFixed3(r.input)}</td>
      <td>
        <input type="number" step="0.001" min="0" value="${isFinite(r.consumed) ? r.consumed : ""}"
          class="consumedInp" data-idx="${i}"
          style="width:140px; padding:8px 10px; border:1px solid var(--line); border-radius:10px;">
      </td>
      <td>${toFixed3(r.remaining)}</td>
    </tr>
  `).join("");

  body.querySelectorAll(".consumedInp").forEach(inp=>{
    inp.addEventListener("input", ()=>{
      const idx = Number(inp.getAttribute("data-idx"));
      const v = Number(inp.value);
      if(!isFinite(idx) || idx<0 || idx>=MATERIAL_ROWS.length) return;
      MATERIAL_ROWS[idx].consumed = isFinite(v) ? v : 0;
      MATERIAL_ROWS[idx].remaining = (MATERIAL_ROWS[idx].input || 0) - (MATERIAL_ROWS[idx].consumed || 0);
      renderMaterials(); // re-render to update remaining
    });
  });
}

function loadMaterialsFromPlan(){
  MATERIAL_ROWS = [];
  if(!CURRENT) return;

  const dp = Array.isArray(CURRENT.dpMaterials) ? CURRENT.dpMaterials : [];
  const log = CURRENT.log || {};
  const logged = Array.isArray(log.materials) ? log.materials : [];

  const consumedMap = new Map();
  logged.forEach(x=>{
    const name = String(x.name||"").trim();
    const consumed = Number(x.consumed||0);
    if(name) consumedMap.set(name, isFinite(consumed) ? consumed : 0);
  });

  dp.forEach(it=>{
    const name = String(it.name||"").trim();
    const input = Number(it.qty||0);
    const consumed = consumedMap.has(name) ? consumedMap.get(name) : 0;
    const remaining = (isFinite(input)?input:0) - (isFinite(consumed)?consumed:0);
    if(!name) return;
    MATERIAL_ROWS.push({name, input: isFinite(input)?input:0, consumed: isFinite(consumed)?consumed:0, remaining});
  });

  MATERIAL_ROWS.sort((a,b)=>a.name.localeCompare(b.name));
}

function loadRmfFromPlan(){
  RMF_ROWS = [];
  const body = document.getElementById("rmfRows");
  if(body) body.innerHTML = "";

  if(!CURRENT) return;

  const dp = Array.isArray(CURRENT.dpMaterials) ? CURRENT.dpMaterials : [];
  const log = CURRENT.log || {};
  const saved = Array.isArray(log.rmfMaterials) ? log.rmfMaterials : [];

  const map = new Map();
  saved.forEach(x=>{
    const name = String(x.name||"").trim();
    if(!name) return;
    const received = Number(x.received ?? x.input ?? 0);
    const remaining = Number(x.remaining ?? 0);
    map.set(name, {
      received: isFinite(received) ? received : 0,
      remaining: isFinite(remaining) ? remaining : 0
    });
  });

  dp.forEach(it=>{
    const name = String(it.name||"").trim();
    if(!name) return;
    const required = Number(it.qty ?? it.input ?? 0);
    const req = isFinite(required) ? required : 0;

    const savedRow = map.get(name);
    const received = savedRow ? savedRow.received : 0;
    const remaining = savedRow ? savedRow.remaining : (req - received);

    RMF_ROWS.push({
      name,
      required: req,
      received: isFinite(received) ? received : 0,
      remaining: isFinite(remaining) ? remaining : (req - (isFinite(received)?received:0))
    });
  });
}

function renderRmfRows(){
  const body = document.getElementById("rmfRows");
  const msg = document.getElementById("rmfModalMsg");
  if(!body) return;

  body.innerHTML = "";
  setMsg(msg, "", true);

  if(RMF_ROWS.length===0){
    body.innerHTML = `<tr><td colspan="5" style="color:var(--muted);">No DP materials for this plan.</td></tr>`;
    return;
  }

  body.innerHTML = RMF_ROWS.map((r,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${escapeHtml(r.name)}</td>
      <td>${toFixed3(r.required)}</td>
      <td>
        <input class="rmfReceived" data-idx="${i}" type="number" step="0.001" min="0"
          value="${isFinite(r.received) ? r.received : ""}"
          style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
      </td>
      <td>
        <input class="rmfRemaining" data-idx="${i}" type="number" step="0.001" min="0"
          value="${isFinite(r.remaining) ? r.remaining : ""}"
          style="width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:12px;">
      </td>
    </tr>
  `).join("");

  // events
  document.querySelectorAll(".rmfReceived").forEach(inp=>{
    inp.addEventListener("input", ()=>{
      const idx = Number(inp.getAttribute("data-idx"));
      const v = Number(inp.value);
      if(!isFinite(idx) || idx<0 || idx>=RMF_ROWS.length) return;
      RMF_ROWS[idx].received = isFinite(v) ? v : 0;
      // auto-calc remaining as default behavior
      RMF_ROWS[idx].remaining = (RMF_ROWS[idx].required || 0) - (RMF_ROWS[idx].received || 0);
      // update only remaining input value without full rerender:
      const rem = document.querySelector(`.rmfRemaining[data-idx="${idx}"]`);
      if(rem) rem.value = isFinite(RMF_ROWS[idx].remaining) ? RMF_ROWS[idx].remaining : "";
    });
  });

  document.querySelectorAll(".rmfRemaining").forEach(inp=>{
    inp.addEventListener("input", ()=>{
      const idx = Number(inp.getAttribute("data-idx"));
      const v = Number(inp.value);
      if(!isFinite(idx) || idx<0 || idx>=RMF_ROWS.length) return;
      RMF_ROWS[idx].remaining = isFinite(v) ? v : 0;
    });
  });
}

function openRmfModal(){
  const msg = document.getElementById("rmfMsg");
  setMsg(msg, "", true);

  if(!CURRENT){
    setMsg(msg, "Select a production day first.", false);
    return;
  }

  // ensure rows are loaded
  loadRmfFromPlan();
  renderRmfRows();

  document.getElementById("rmfModal").style.display = "block";
}

function closeRmfModal(){
  document.getElementById("rmfModal").style.display = "none";
  const m = document.getElementById("rmfModalMsg");
  setMsg(m, "", true);
}


async function loadSelectedPlan(date){
  const msg = document.getElementById("plMsg");
  setMsg(msg, "", true);

  if(!date){
    CURRENT = null;
    document.getElementById("plMeta").textContent = "";
    document.getElementById("plEntries").innerHTML = "";
    document.getElementById("plMatRows").innerHTML = "";
    RMF_ROWS = [];
    const rb = document.getElementById("rmfRows"); if(rb) rb.innerHTML = "";
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set("action","get_plan");
  url.searchParams.set("date", date);

  try{
    const j = await fetchJson(url.toString());
    CURRENT = j.plan || null;
    if(!CURRENT){
      setMsg(msg, "Plan not found.", false);
      return;
    }

    const dayNo2 = String(CURRENT.dayNo||"").padStart(2,"0");
    document.getElementById("plStatus").value = String(CURRENT.status||"Open");
    document.getElementById("plMeta").textContent = `Day No: ${dayNo2} | Updated: ${CURRENT.updatedAt || ""} | By: ${CURRENT.updatedBy || ""}`;

    document.getElementById("plNotes").value = (CURRENT.log && CURRENT.log.notes) ? String(CURRENT.log.notes) : "";

    renderEntries();
    loadMaterialsFromPlan();
    renderMaterials();
    loadRmfFromPlan();

    setMsg(msg, "Loaded.", true);
  }catch(e){
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}

function collectEntriesDone(){
  const arr = [];
  document.querySelectorAll(".entryDone").forEach(chk=>{
    const key = String(chk.getAttribute("data-key")||"");
    arr.push({key, done: !!chk.checked});
  });
  return arr;
}

async function saveLog(){
  const msg = document.getElementById("plMsg");
  setMsg(msg, "", true);

  if(!CURRENT){
    setMsg(msg, "Select a production day.", false);
    return;
  }

  const date = String(CURRENT.jalaliDate||"");
  const status = String(document.getElementById("plStatus").value||"Open");

  const materials = MATERIAL_ROWS.map(r=>({
    name: r.name,
    input: r.input,
    consumed: r.consumed,
    remaining: r.remaining
  }));

  const payload = {
    jalaliDate: date,
    status,
    entriesDone: collectEntriesDone(),
    notes: String(document.getElementById("plNotes").value||""),
    materials,
    rmfMaterials: RMF_ROWS.map(r=>({name:r.name, required:r.required, received:r.received, remaining:r.remaining}))
  };

  const fd = new FormData();
  fd.append("action","save_log");
  fd.append("payload", JSON.stringify(payload));

  try{
    const res = await fetch(window.location.href, {method:"POST", body:fd});
    const j = await res.json().catch(()=>null);
    if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Save failed");
    setMsg(msg, "Saved.", true);
    await refreshPlans();
    await loadSelectedPlan(date);
  }catch(e){
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}

async function deletePlan(){
  const msg = document.getElementById("plMsg");
  setMsg(msg, "", true);

  if(!CURRENT){
    setMsg(msg, "Select a production day.", false);
    return;
  }
  const date = String(CURRENT.jalaliDate||"");
  if(!confirm("Delete this production plan and log?")) return;

  const fd = new FormData();
  fd.append("action","delete_plan");
  fd.append("jalaliDate", date);

  try{
    const res = await fetch(window.location.href, {method:"POST", body:fd});
    const j = await res.json().catch(()=>null);
    if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Delete failed");
    setMsg(msg, "Deleted.", true);
    await refreshPlans();
    document.getElementById("plDaySelect").value = "";
    await loadSelectedPlan("");
  }catch(e){
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}

async function downloadDpRmr(alsoSaveServer){
  const msg = document.getElementById("plFileMsg");
  setMsg(msg, "", true);

  if(!CURRENT){
    setMsg(msg, "Select a production day.", false);
    return;
  }

  const dateFull = normalizeDateFullFromAny(CURRENT.jalaliDate || "");
  const dateShort = formatShortJalaliForFile(CURRENT.jalaliDate || "");
  const dayNo2 = String(CURRENT.dayNo||"").padStart(2,"0");

  const headerCode = `DP- RMR${dayNo2}`;
  const customer = "Production";
  const sfRef = "MULTI";

  const items = MATERIAL_ROWS.map(r=>({
    name: r.name,
    input: Number(r.input||0),
    consumed: Number(r.consumed||0),
    remaining: Number(r.remaining||0)
  }));

  try{
    const buf = await buildLogWorkbook({
      headerCode,
      customer,
      sfRef,
      dateFull,
      items
    });

    const fileName = `DP- RMR${dayNo2} ${dateShort}.xlsx`;
    downloadBuffer(buf, fileName);

    if(alsoSaveServer){
      await uploadDpRmr(buf, fileName, CURRENT.jalaliDate || "");
      setMsg(msg, "Saved on server (database/production/<date>/).", true);
    }else{
      setMsg(msg, "Downloaded.", true);
    }
  }catch(e){
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}

async function downloadRmf(alsoSaveServer){
  const msg = document.getElementById("rmfModalMsg");
  setMsg(msg, "", true);

  if(!CURRENT){
    setMsg(msg, "Select a production day.", false);
    return;
  }

  if(RMF_ROWS.length===0){
    setMsg(msg, "No DP materials.", false);
    return;
  }

  const dateFull = normalizeDateFullFromAny(CURRENT.jalaliDate || "");
  const dateShort = formatShortJalaliForFile(CURRENT.jalaliDate || "");
  const dayNo2 = String(CURRENT.dayNo||"").padStart(2,"0");

  const headerCode = `DP- RMF${dayNo2}`;
  const customer = "Production";
  const sfRef = "MULTI";

  // build items
  const items = RMF_ROWS.map(r=>({
    name: r.name,
    required: Number(r.required||0),
    received: Number(r.received||0),
    remaining: Number(r.remaining||0),
  }));

  const fileName = `DP- RMF${dayNo2} ${dateShort}.xlsx`;

  try{
    const buffer = await buildRmfWorkbook({headerCode, customer, sfRef, dateFull, items});

    // Always download to client
    downloadBuffer(buffer, fileName);

    if(alsoSaveServer){
      const fd = new FormData();
      fd.append("action","upload_rmf");
      fd.append("jalaliDate", String(CURRENT.jalaliDate||""));
      fd.append("fileName", fileName);
      fd.append("file", new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}), fileName);

      const res = await fetch(window.location.href, {method:"POST", body:fd});
      const j = await res.json().catch(()=>null);
      if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Server save failed");
      setMsg(msg, "RMF downloaded and saved on server.", true);
    }else{
      setMsg(msg, "RMF downloaded.", true);
    }

    // Persist RMF rows in log payload so they can be reused later
    // (this does not replace your normal Save Log flow)
    // Keep in memory:
    if(!CURRENT.log) CURRENT.log = {};
    CURRENT.log.rmfMaterials = RMF_ROWS.map(r=>({
      name: r.name,
      required: r.required,
      received: r.received,
      remaining: r.remaining
    }));

  }catch(e){
    console.error(e);
    setMsg(msg, e.message || String(e), false);
  }
}


async function refreshPlans(){
  const url = new URL(window.location.href);
  url.searchParams.set("action","list_plans");
  const j = await fetchJson(url.toString());
  PLANS = (j.plans || []);
  renderDaySelect();
}

async function init(){
  await refreshPlans();

  const params = new URLSearchParams(window.location.search||"");
  const prefillDate = params.get("date") || "";
  if(prefillDate){
    document.getElementById("plDaySelect").value = prefillDate;
    await loadSelectedPlan(prefillDate);
  }

  document.getElementById("plDaySelect").addEventListener("change", async ()=>{
    const d = document.getElementById("plDaySelect").value || "";
    await loadSelectedPlan(d);
  });

  document.getElementById("btnSaveLog").addEventListener("click", saveLog);
  document.getElementById("btnDeletePlan").addEventListener("click", deletePlan);

  document.getElementById("btnOpenPlan").addEventListener("click", ()=>{
    if(!CURRENT) return;
    const d = encodeURIComponent(CURRENT.jalaliDate || "");
    window.location.href = "production_plan.php?date=" + d;
  });

  
  // RMF
  document.getElementById("btnOpenRMF").addEventListener("click", openRmfModal);
  document.getElementById("btnRMFClose").addEventListener("click", closeRmfModal);
  document.getElementById("btnRMFDownload").addEventListener("click", ()=>downloadRmf(false));
  document.getElementById("btnRMFSaveServer").addEventListener("click", ()=>downloadRmf(true));

  // Close modal when clicking outside card
  document.getElementById("rmfModal").addEventListener("click", (e)=>{
    if(e.target && e.target.id === "rmfModal") closeRmfModal();
  });

document.getElementById("btnDownloadLog").addEventListener("click", ()=>downloadDpRmr(false));
  document.getElementById("btnSaveLogServer").addEventListener("click", ()=>downloadDpRmr(true));
}

document.addEventListener("DOMContentLoaded", init);
</script>


</div>

<?php render_footer(); ?>
