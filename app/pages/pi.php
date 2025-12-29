<?php
/************************************************************
 * PI Generator — pi.php
 * Location: public_html/app/pages/pi.php
 *
 * - Generates Excel (ExcelJS) + downloads in browser
 * - Uploads duplicate to server:
 *   (preferred) <baseDir>/PI/<filename>
 *   (fallback)  /public_html/database/projects/<clientSlug>/<subCode>/PI/<filename>
 *
 * NEW (as requested):
 * - PI No field auto-fills from server-side folder by scanning existing PI files
 *   and picking next PI number (e.g., PI 01, PI 02 => auto PI 03)
 * - Also refreshes PI No after successful upload.
 *
 * NOTE: No other behavior changed.
 ************************************************************/

/************************************************************
 * Bootstrap (NO app_bootstrap.php)
 ************************************************************/
if (!defined("APP_ROOT")) {
  // pages/.. => app
  define("APP_ROOT", realpath(__DIR__ . "/.."));
}

/************************************************************
 * Auth Guard + Layout
 ************************************************************/
require_once APP_ROOT . "/includes/auth.php";
require_once APP_ROOT . "/includes/layout.php";
require_login();

/************************************************************
 * Helpers
 ************************************************************/
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: "[]", true);
  return is_array($data) ? $data : [];
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
  return $s ?: "PI.xlsx";
}

function referrer_project_id(): int {
  $ref = (string)($_SERVER["HTTP_REFERER"] ?? "");
  if (!$ref) return 0;
  $u = @parse_url($ref);
  if (!is_array($u)) return 0;
  $q = $u["query"] ?? "";
  if (!$q) return 0;
  parse_str($q, $arr);
  return (int)($arr["id"] ?? 0);
}

function normalize_subcode(string $code, string $type): string {
  $code = trim($code);
  $type = strtoupper(trim($type));
  if ($code === "") return "";
  if ($type !== "" && strpos($code, "-") === false) return $code . "-" . $type; // 302 + C => 302-C
  return $code; // already 302-C (or has dash)
}

function extract_projectcode_3digits(string $subCode): string {
  $parts = explode("-", $subCode);
  $p = preg_replace('/\D/', '', (string)($parts[0] ?? ""));
  $p = substr($p, 0, 3);
  return str_pad($p, 3, "0", STR_PAD_LEFT);
}

/************************************************************
 * Resolve project context from projects DB (if available)
 ************************************************************/
$me = current_user();
$role = (string)($me["role"] ?? "Observer");
$isAdmin = ($role === "Admin");
$isObserver = ($role === "Observer");
$isClient = ($role === "Client");
$myUsername = (string)($me["username"] ?? "");

$PROJECTS_FILE = defined("PROJECTS_DB_FILE") ? PROJECTS_DB_FILE : (APP_ROOT . "/database/projects.json");

function find_visible_project_by_id(array $projects, int $id, bool $isAdmin, bool $isObserver, bool $isClient, string $myUsername): ?array {
  foreach ($projects as $p) {
    if ((int)($p["id"] ?? 0) !== $id) continue;
    if ($isAdmin || $isObserver) return $p;
    if ($isClient && (string)($p["customerUsername"] ?? "") === $myUsername) return $p;
    return null;
  }
  return null;
}

function resolve_ctx(int $id, string $PROJECTS_FILE, bool $isAdmin, bool $isObserver, bool $isClient, string $myUsername): array {
  $ctx = [
    "projectId"   => 0,
    "clientSlug"  => "",
    "type"        => "",
    "codeRaw"     => "",
    "subCode"     => "",
    "projectCode" => "",
    "baseDir"     => "",
  ];
  if ($id <= 0) return $ctx;
  $projects = load_json_array($PROJECTS_FILE);
  $p = find_visible_project_by_id($projects, $id, $isAdmin, $isObserver, $isClient, $myUsername);
  if (!$p) return $ctx;

  $type = strtoupper(trim((string)($p["type"] ?? "")));
  $codeRaw = trim((string)($p["code"] ?? ""));
  $subCode = normalize_subcode($codeRaw, $type);

  $ctx["projectId"]   = $id;
  $ctx["clientSlug"]  = safe_slug((string)($p["customerUsername"] ?? ""));
  $ctx["type"]        = $type;
  $ctx["codeRaw"]     = $codeRaw;
  $ctx["subCode"]     = safe_code($subCode);
  $ctx["projectCode"] = extract_projectcode_3digits($subCode);
  $ctx["baseDir"]     = (string)($p["baseDir"] ?? "");

  return $ctx;
}

/************************************************************
 * PI directory + next PI number (server-side scan)
 ************************************************************/
function pi_dir_from_ctx(array $ctx): string {
  $baseDir = trim((string)($ctx["baseDir"] ?? ""));
  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/PI";
  }

  $clientSlug = (string)($ctx["clientSlug"] ?? "");
  $subCode = (string)($ctx["subCode"] ?? "");
  if ($clientSlug === "" || $subCode === "") return "";

  // /public_html/database/projects/<clientSlug>/<subCode>/PI
  $publicRoot = realpath(APP_ROOT . "/.."); // public_html
  $root = rtrim($publicRoot ?: (APP_ROOT . "/.."), "/");
  return $root . "/database/projects/" . $clientSlug . "/" . $subCode . "/PI";
}

function next_pi_number_from_dir(string $dir): string {
  if ($dir === "" || !is_dir($dir)) return "01";

  $max = 0;

  // scan only .xlsx
  $files = @glob(rtrim($dir, "/") . "/*.xlsx") ?: [];
  foreach ($files as $path) {
    $name = basename($path);

    // match:
    // "302C- PI01 04.10.01.xlsx"
    // or other variants containing "PI 01"
    if (preg_match('/\bPI\s*0*([0-9]{1,3})\b/i', $name, $m)) {
      $n = (int)$m[1];
      if ($n > $max) $max = $n;
      continue;
    }

    // also allow "PI-01"
    if (preg_match('/\bPI\-0*([0-9]{1,3})\b/i', $name, $m2)) {
      $n = (int)$m2[1];
      if ($n > $max) $max = $n;
      continue;
    }
  }

  $next = $max + 1;
  // pad to 2 digits, but allow >99 naturally (e.g. 100)
  return (strlen((string)$next) >= 2) ? str_pad((string)$next, 2, "0", STR_PAD_LEFT) : "0" . $next;
}

/************************************************************
 * AJAX endpoint — get next PI number
 ************************************************************/
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "get_next_pi") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);

  // prefer DB-based ctx (prevents spoofing)
  if ($projectId > 0) {
    $ctx = resolve_ctx($projectId, $GLOBALS["PROJECTS_FILE"], $GLOBALS["isAdmin"], $GLOBALS["isObserver"], $GLOBALS["isClient"], $GLOBALS["myUsername"]);
  } else {
    $ctx = [
      "projectId" => 0,
      "clientSlug" => safe_slug((string)($_POST["clientSlug"] ?? "")),
      "subCode" => safe_code((string)($_POST["subCode"] ?? "")),
      "baseDir" => "",
      "type" => "",
      "codeRaw" => "",
      "projectCode" => "",
    ];
  }

  $dir = pi_dir_from_ctx($ctx);
  $nextPi = next_pi_number_from_dir($dir);

  echo json_encode(["ok" => true, "nextPi" => $nextPi]);
  exit;
}

/************************************************************
 * AJAX upload endpoint — save duplicate on server
 ************************************************************/
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_pi") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);

  // Prefer resolving from DB using projectId (prevents spoofing paths)
  if ($projectId > 0) {
    $ctx = resolve_ctx($projectId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);
  } else {
    $ctx = [
      "projectId" => 0,
      "clientSlug" => safe_slug((string)($_POST["clientSlug"] ?? "")),
      "subCode" => safe_code((string)($_POST["subCode"] ?? "")),
      "baseDir" => "",
      "type" => "",
      "codeRaw" => "",
      "projectCode" => "",
    ];
  }

  $fileName = safe_filename((string)($_POST["fileName"] ?? ""));

  if (!isset($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No file uploaded."]);
    exit;
  }

  // Target directory:
  // 1) baseDir/PI if baseDir exists
  // 2) fallback: /public_html/database/projects/<client>/<subCode>/PI
  $targetDir = pi_dir_from_ctx($ctx);
  if ($targetDir === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing project context (cannot resolve PI directory)."]);
    exit;
  }

  if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Cannot create target directory."]);
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

/************************************************************
 * Page Context (prefill)
 ************************************************************/
$viewId = (int)($_GET["id"] ?? 0);
if ($viewId <= 0) $viewId = referrer_project_id();

$ctx = resolve_ctx($viewId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);

// Optional overrides
if (isset($_GET["projectCode"])) $ctx["projectCode"] = extract_projectcode_3digits((string)$_GET["projectCode"]);
if (isset($_GET["type"])) $ctx["type"] = strtoupper(trim((string)$_GET["type"]));

$piDir = pi_dir_from_ctx($ctx);
$autoPi = next_pi_number_from_dir($piDir);

render_header("PI Generator", $role);
?>
<style>
/* Generator UI — aligned with layout v1.00 tokens */
.generator-shell{ margin-top:14px; }
.generator-shell .page-head{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.generator-shell .page-head h2{ margin:0; font-size:16px; }
.generator-shell .page-head .sub{ margin-top:6px; color:var(--muted); font-size:12px; }
.help-toggle label{ display:flex; align-items:center; gap:8px; font-weight:600; color:var(--muted); }
.help-toggle input{ width:auto; }
.form-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 12px; }
@media (max-width:900px){ .form-grid{ grid-template-columns:1fr; } }
.three-col{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
@media (max-width:1100px){ .three-col{ grid-template-columns:1fr; } }
.layer-grid{ display:grid; grid-template-columns:2fr 1fr; gap:8px; align-items:end; }
.layer-grid .hdr{ font-size:12px; font-weight:700; color:var(--muted); padding:8px 10px; border:1px solid var(--line); border-radius:12px; background:#fff; }
.btn-row{ display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
.section-divider{ height:1px; background:var(--line); margin:14px 0; }
textarea{ min-height:84px; resize:vertical; }

/* Help toggles (PI/SCF) — only applies when data-help="toggle" exists */
.small-hint{ font-size:12px; color:var(--muted); margin-top:6px; line-height:1.35; }
.hint{ font-size:12px; color:var(--muted); margin-top:6px; line-height:1.35; }

.generator-shell[data-help="toggle"] .small-hint{ display:none; }
body.show-help .generator-shell[data-help="toggle"] .small-hint{ display:block; }

.generator-shell[data-help="toggle"] .hint{ display:none; }
body.show-help .generator-shell[data-help="toggle"] .hint{ display:block; }

/* Notes */
.pma-note{
  display:none;
  padding:8px 10px;
  border-radius:12px;
  border:1px solid rgba(122,168,116,0.35);
  background:rgba(122,168,116,0.10);
  color:var(--text);
  margin-top:8px;
  font-size:12px;
}

/* Tables (SCF) */
.temp-table-wrap{ overflow:auto; border:1px solid var(--line); border-radius:var(--radius); background:#fff; }
.temp-table{ width:100%; border-collapse:collapse; font-size:13px; }
.temp-table th,.temp-table td{ border:1px solid var(--line); padding:8px 10px; text-align:center; white-space:nowrap; }
.temp-table th{ background:rgba(255,255,255,0.65); }
</style>
<div class="generator-shell" data-help="toggle">
  

  <div class="card" style="margin-top:0;">
    <div class="page-head">
      <div>
        <h2>PI Generator</h2>
        <div class="sub">Fill in the fields and generate the PI Excel file.</div>
      </div>
      
      <div class="help-toggle">
        <label><input id="helpToggle" type="checkbox"> Show help</label>
      </div>
    </div>
  </div>
<h1>PI Generator</h1>
  <div class="help-toggle"><label><input id="helpToggle" type="checkbox"> Show help</label></div>

  <div class="card">
    <h2>Basic Information</h2>
    <div class="form-grid">
      <div>
        <label for="projectCode">Project code (3 digits)</label>
        <input id="projectCode" type="text" placeholder="e.g. 302" value="<?= h((string)$ctx["projectCode"]) ?>" />
      </div>

      <div>
        <label for="projectType">Type (C / F / O)</label>
        <select id="projectType">
          <option value="C" <?= ((string)$ctx["type"]==="C") ? "selected" : "" ?>>C (Compound)</option>
          <option value="F" <?= ((string)$ctx["type"]==="F") ? "selected" : "" ?>>F (Film)</option>
          <option value="O" <?= ((string)$ctx["type"]==="O") ? "selected" : "" ?>>O (Other)</option>
        </select>
      </div>

      <div>
        <label for="sampleOrder">Sample / SCF No. (2 digits)</label>
        <input id="sampleOrder" type="number" min="1" max="99" placeholder="e.g. 2" />
      </div>

      <div>
        <label for="piNumber">PI No. (2 digits, optional)</label>
        <!-- AUTO-FILLED from server directory scan -->
        <input id="piNumber" type="number" min="1" max="999" value="<?= h($autoPi) ?>" />
      </div>

      <div>
        <label for="invoiceDate">Date (Jalali)</label>
        <div class="inline-date">
          <input id="invoiceDate" type="text" placeholder="1404/09/22" />
          <input id="invoiceDateCal" type="date" />
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Customer Information</h2>
    <div class="form-grid">
      <div>
        <label for="customerName">Customer name</label>
        <input id="customerName" type="text" placeholder="e.g. برنا پلیمر" />
      </div>
      <div>
        <label for="customerAddress">Customer address</label>
        <textarea id="customerAddress" placeholder='Address (without "نشانی:")'></textarea>
      </div>
      <div>
        <label for="customerPhone">Customer phone</label>
        <input id="customerPhone" type="text" placeholder="e.g. 01732556784" />
      </div>
      <div>
        <label for="customerFax">Customer fax</label>
        <input id="customerFax" type="text" placeholder="e.g. 02177824562" />
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Item & Sample Information</h2>
    <div class="form-grid">
      <div>
        <label for="itemDesc">Item description</label>
        <input id="itemDesc" type="text" placeholder="e.g. Sample Compound Anti rodent 301-02" />
      </div>
      <div>
        <label for="itemQty">Invoice quantity</label>
        <input id="itemQty" type="number" step="0.001" placeholder="e.g. 1" />
      </div>
      <div>
        <label for="itemUnit">Unit</label>
        <input id="itemUnit" type="text" placeholder="kg" value="kg" />
      </div>
      <div>
        <label for="itemUnitPrice">Unit price (Rial)</label>
        <input id="itemUnitPrice" type="number" step="1" placeholder="e.g. 40000000" />
      </div>
      <div>
        <label for="sampleQty">Sample compound total (kg, for note)</label>
        <input id="sampleQty" type="number" step="0.1" placeholder="e.g. 4" />
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Notes (B18 – توضیحات)</h2>
    <div class="form-grid">
      <div style="grid-column: 1 / -1;">
        <label for="notesB18">Notes text (cell B18)</label>
        <textarea id="notesB18" placeholder="اگر خالی باشد، متن پیش‌فرض استفاده می‌شود."></textarea>
      </div>
    </div>
  </div>

  <div class="btn-row">
    <button id="generateBtn">Generate PI (Excel .xlsx)</button>
  </div>
  <div id="status" class="status"></div>

  <input type="hidden" id="ctxProjectId" value="<?= h((string)$ctx["projectId"]) ?>">
  <input type="hidden" id="ctxClientSlug" value="<?= h((string)$ctx["clientSlug"]) ?>">
  <input type="hidden" id="ctxSubCode" value="<?= h((string)$ctx["subCode"]) ?>">
</div>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js"></script>

<script>
  function padNumber(numStr, width) {
    const clean = String(numStr || "").replace(/\D/g, "");
    if (!clean) return "".padStart(width, "0");
    return clean.padStart(width, "0");
  }

  function formatShortJalaliForFile(jalaliDate) {
    if (!jalaliDate) return "";
    const m = jalaliDate.match(/(\d{2,4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/);
    if (!m) return jalaliDate.replace(/\//g, ".");
    let y = m[1];
    const mo = m[2].padStart(2, "0");
    const d  = m[3].padStart(2, "0");
    const yy = y.length === 4 ? y.slice(2) : y.padStart(2, "0");
    return yy + "." + mo + "." + d;
  }

  function parseNumber(inputId) {
    const v = document.getElementById(inputId).value.trim();
    if (!v) return NaN;
    const normalized = v.replace(/,/g, "").replace("،", "").replace(/\s+/g, "");
    return parseFloat(normalized);
  }

  function setStatus(msg, isError = false) {
    const el = document.getElementById("status");
    el.textContent = msg || "";
    el.className = "status " + (isError ? "error" : msg ? "ok" : "");
  }

  // --- Column width conversion ---
  // ExcelJS column width is in "Excel width units" (approx characters).
  // Convert mm -> px (96 dpi) -> width units using approximation:
  // px ≈ width*7 + 5  => width ≈ (px-5)/7
  function mmToPixels(mm) { return mm * (96 / 25.4); }
  function excelWidthFromPixels(px) { return Math.max(0, (px - 5) / 7); }
  function excelWidthFromMm(mm) { return excelWidthFromPixels(mmToPixels(mm)); }

  // --- Jalali conversion (Gregorian -> Jalali) ---
  function div(a,b){ return Math.floor(a/b); }

  function g2j(gy, gm, gd) {
    const g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    let jy;
    if (gy > 1600) { jy = 979; gy -= 1600; }
    else { jy = 0; gy -= 621; }

    let gy2 = (gm > 2) ? (gy + 1) : gy;
    let days = (365 * gy) + div((gy2 + 3), 4) - div((gy2 + 99), 100) + div((gy2 + 399), 400)
              - 80 + gd + g_d_m[gm - 1];

    jy += 33 * div(days, 12053);
    days %= 12053;

    jy += 4 * div(days, 1461);
    days %= 1461;

    if (days > 365) {
      jy += div((days - 1), 365);
      days = (days - 1) % 365;
    }

    let jm = (days < 186) ? (1 + div(days, 31)) : (7 + div((days - 186), 30));
    let jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));

    return [jy + 1, jm, jd];
  }

  function setJalaliFromGregorianISO(iso) {
    if (!iso) return;
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return;
    const gy = parseInt(m[1],10), gm = parseInt(m[2],10), gd = parseInt(m[3],10);
    const j = g2j(gy, gm, gd);
    const jy = String(j[0]).padStart(4,"0");
    const jm = String(j[1]).padStart(2,"0");
    const jd = String(j[2]).padStart(2,"0");
    document.getElementById("invoiceDate").value = `${jy}/${jm}/${jd}`;
  }

  (function initDatePrefill(){
    const jal = document.getElementById("invoiceDate");
    const cal = document.getElementById("invoiceDateCal");
    if (!jal.value.trim()) {
      const now = new Date();
      const yyyy = now.getFullYear();
      const mm = String(now.getMonth()+1).padStart(2,"0");
      const dd = String(now.getDate()).padStart(2,"0");
      cal.value = `${yyyy}-${mm}-${dd}`;
      setJalaliFromGregorianISO(cal.value);
    }
    cal.addEventListener("change", () => setJalaliFromGregorianISO(cal.value));
  })();

  // --- Refresh next PI number from server (directory scan) ---
  async function refreshNextPiFromServer() {
    try {
      const fd = new FormData();
      fd.append("action", "get_next_pi");
      fd.append("projectId", (document.getElementById("ctxProjectId").value || "").trim());
      fd.append("clientSlug", (document.getElementById("ctxClientSlug").value || "").trim());
      fd.append("subCode", (document.getElementById("ctxSubCode").value || "").trim());

      const url = window.location.pathname + window.location.search;
      const res = await fetch(url, { method: "POST", body: fd });
      const j = await res.json().catch(()=>null);
      if (!res.ok || !j || !j.ok) return;

      const nextPi = String(j.nextPi || "").trim();
      if (nextPi) {
        // keep numeric value in the input; "03" becomes 3 visually,
        // but we always pad during filename generation anyway.
        document.getElementById("piNumber").value = parseInt(nextPi, 10);
      }
    } catch (e) {}
  }

  // Ensure field stays synced with server at page load
  refreshNextPiFromServer();

  // --- Upload duplicate to server ---
  async function uploadDuplicate(buffer, fileName) {
    const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
    const fd = new FormData();
    fd.append("action", "upload_pi");
    fd.append("fileName", fileName);

    fd.append("projectId", (document.getElementById("ctxProjectId").value || "").trim());
    fd.append("clientSlug", (document.getElementById("ctxClientSlug").value || "").trim());
    fd.append("subCode", (document.getElementById("ctxSubCode").value || "").trim());

    fd.append("file", blob, fileName);

    const url = window.location.pathname + window.location.search; // keep ?id=...
    const res = await fetch(url, { method:"POST", body: fd });
    const j = await res.json().catch(()=>null);
    if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : ("Upload failed (HTTP " + res.status + ")"));
    return j.savedTo || "";
  }

  // --- PMA integration (kept) ---
  function applyPMAContext(ctx) {
    try {
      if (!ctx) return;
      if (ctx.projectCode) document.getElementById("projectCode").value = String(ctx.projectCode);
      if (ctx.type) document.getElementById("projectType").value = String(ctx.type).toUpperCase();
      if (ctx.customer) document.getElementById("customerName").value = String(ctx.customer);

      const name = (ctx.name || "").toString().trim();
      const proj = (ctx.projectCode || "").toString().trim();
      if (name && proj && !document.getElementById("itemDesc").value.trim()) {
        document.getElementById("itemDesc").value = "Sample " + name + " " + proj + "-__";
      }
    } catch (e) {}
  }

  (function prefillFromQueryString() {
    try {
      const p = new URLSearchParams(window.location.search);
      const ctx = {
        projectCode: p.get("projectCode"),
        type: p.get("type"),
        customer: p.get("customer"),
        name: p.get("name")
      };
      if (ctx.projectCode || ctx.type || ctx.customer || ctx.name) applyPMAContext(ctx);
    } catch (e) {}
  })();

  window.addEventListener("message", function(ev) {
    try {
      const data = ev.data;
      if (!data || data.type !== "PMA_CONTEXT") return;
      applyPMAContext(data.payload || {});
    } catch (e) {}
  });

  // --- Main generator ---
  async function generatePIExcel() {
    try {
      setStatus("");

      const projectCodeRaw = document.getElementById("projectCode").value.trim();
      const projectType = document.getElementById("projectType").value.trim().toUpperCase();
      const sampleOrderRaw = document.getElementById("sampleOrder").value.trim();

      // PI number MUST be filled (auto-filled from server)
      let piNumberRaw = document.getElementById("piNumber").value.trim();

      const invoiceDate = document.getElementById("invoiceDate").value.trim();

      const customerName = document.getElementById("customerName").value.trim();
      const customerAddress = document.getElementById("customerAddress").value.trim();
      const customerPhone = document.getElementById("customerPhone").value.trim();
      const customerFax = document.getElementById("customerFax").value.trim();

      let itemDesc = document.getElementById("itemDesc").value.trim();
      const itemQty = parseNumber("itemQty");
      const itemUnit = document.getElementById("itemUnit").value.trim() || "kg";
      const itemUnitPrice = parseNumber("itemUnitPrice");
      let sampleQty = parseNumber("sampleQty");
      const notesB18 = document.getElementById("notesB18").value.trim();

      if (!projectCodeRaw) { setStatus("Please enter project code.", true); return; }
      if (!sampleOrderRaw) { setStatus("Please enter Sample / SCF No.", true); return; }
      if (!invoiceDate) { setStatus("Please enter Jalali date.", true); return; }
      if (!customerName) { setStatus("Please enter customer name.", true); return; }
      if (!piNumberRaw) { setStatus("PI No is empty. Refresh the page or check PI folder permissions.", true); return; }

      if (isNaN(itemQty) || itemQty <= 0) { setStatus("Item quantity must be a positive number.", true); return; }
      if (isNaN(itemUnitPrice) || itemUnitPrice <= 0) { setStatus("Unit price must be a positive number.", true); return; }
      if (isNaN(sampleQty) || sampleQty <= 0) sampleQty = itemQty;

      const projectCode = padNumber(projectCodeRaw, 3);
      const sampleOrder = padNumber(sampleOrderRaw, 2);

      // Use server-derived PI number (pad to 2 digits)
      const piNumber = padNumber(String(piNumberRaw), 2);

      const dateShortForFile = formatShortJalaliForFile(invoiceDate);

      const sampleCode = projectType + sampleOrder;
      const productCode = "140" + projectCode + sampleOrder;
      const fullSampleCode = projectCode + "-" + sampleOrder;

      if (!itemDesc) itemDesc = "Sample Compound " + fullSampleCode;

      const ctxSubCode = (document.getElementById("ctxSubCode").value || "").trim();
      const subCodeRawForName = ctxSubCode || (projectCode + "-" + projectType);
      const subTag = (subCodeRawForName || "").replaceAll("-", "").trim();
      const prefix = subTag ? (subTag + "-") : "";
      const fileName = (prefix ? (prefix + " ") : "") + ("PI" + piNumber + " " + dateShortForFile + ".xlsx");

      const workbook = new ExcelJS.Workbook();
      const sheet = workbook.addWorksheet("Page 1");

      sheet.views = [{ rightToLeft: true }];
      sheet.pageSetup.orientation = "landscape";
      sheet.pageSetup.paperSize = 9;
      sheet.pageSetup.fitToPage = true;
      sheet.pageSetup.fitToWidth = 1;
      sheet.pageSetup.fitToHeight = 1;

      // Columns sizing: B..J = 17.55 mm (as you requested previously)
      const w17_55mm = excelWidthFromMm(17.55);
      sheet.columns = [
        { key:"A", width: 2.54 },
        { key:"B", width: w17_55mm },
        { key:"C", width: w17_55mm },
        { key:"D", width: w17_55mm },
        { key:"E", width: w17_55mm },
        { key:"F", width: w17_55mm },
        { key:"G", width: w17_55mm },
        { key:"H", width: w17_55mm },
        { key:"I", width: w17_55mm },
        { key:"J", width: w17_55mm }
      ];

      const rowHeights = {
        1: 20.0, 2: 43.0, 3: 43.0, 4: 1.5, 5: 43.0, 6: 43.0, 7: 1.5,
        8: 43.0, 9: 43.0, 10: 0.5, 11: 43.0, 12: 43.0, 13: 43.0, 14: 43.0,
        15: 43.0, 16: 43.0, 17: 43.0, 18: 26.5, 19: 26.5, 20: 26.5, 21: 26.5,
        22: 26.5, 23: 20.0, 24: 20.0
      };
      Object.keys(rowHeights).forEach(r => sheet.getRow(parseInt(r,10)).height = rowHeights[r]);

      sheet.mergeCells("B2:D3");
      sheet.mergeCells("E2:H3");
      sheet.mergeCells("B5:J5");
      sheet.mergeCells("B6:C6");
      sheet.mergeCells("F6:H6");
      sheet.mergeCells("B8:J8");
      sheet.mergeCells("B9:C9");
      sheet.mergeCells("F9:H9");
      sheet.mergeCells("B11:J11");
      sheet.mergeCells("B17:G17");
      sheet.mergeCells("B18:F20");
      sheet.mergeCells("G18:J20");

      for (let r=1; r<=24; r++) {
        const row = sheet.getRow(r);
        for (let c=1; c<=10; c++) row.getCell(c).font = { name:"Dana", size:12 };
      }

      function setCell(address, value, opts={}) {
        const cell = sheet.getCell(address);
        if (value !== undefined && value !== null) cell.value = value;

        cell.font = {
          name: "Dana",
          size: opts.size || (cell.font?.size || 12),
          bold: (opts.bold !== undefined) ? opts.bold : (cell.font?.bold || false)
        };

        const prevAlign = cell.alignment || {};
        cell.alignment = {
          horizontal: opts.alignH || "center",
          vertical: opts.alignV || "middle",
          wrapText: (opts.wrapText !== undefined) ? opts.wrapText : (prevAlign.wrapText || false)
        };

        if (opts.numFmt) cell.numFmt = opts.numFmt;
        return cell;
      }

      function addBox(sr, sc, er, ec) {
        for (let r=sr; r<=er; r++) {
          const row = sheet.getRow(r);
          for (let c=sc; c<=ec; c++) {
            const cell = row.getCell(c);
            const isTop = r===sr, isBottom = r===er, isLeft = c===sc, isRight = c===ec;
            cell.border = {
              top: {style: isTop ? "medium" : "thin"},
              bottom: {style: isBottom ? "medium" : "thin"},
              left: {style: isLeft ? "medium" : "thin"},
              right: {style: isRight ? "medium" : "thin"},
            };
          }
        }
      }

      setCell("E2", "پیش فاکتور فروش کالا و خدمات", { bold:true, size:20 });
      setCell("I2", "تاریخ :", { size:12 });
      setCell("J2", invoiceDate, { size:12 });

      const fullPiCode = projectCode + "-" + sampleCode + " PI-" + piNumber;
      setCell("B2", fullPiCode, { bold:true, size:14 });

      setCell("B5", "مشخصات فروشنده", { bold:true });
      setCell("B6", "نام شرکت:");
      setCell("D6", "مانا پاک دانا");
      setCell("E6", "نشانی:");
      setCell("F6", "نشانی: تهران، شیراز جنوبی - کوچه ژاله - پلاک 5 - طبقه 6", { wrapText:true });
      setCell("I6", "تلفن: 1431743769    ");
      setCell("J6", " فکس: 2145493500");

      setCell("B8", "مشخصات خریدار", { bold:true });
      setCell("B9", "نام شرکت:");
      setCell("D9", customerName);
      setCell("E9", "نشانی:");
      setCell("F9", customerAddress ? ("نشانی: " + customerAddress) : "", { wrapText:true });
      setCell("I9", customerPhone ? ("تلفن: " + customerPhone) : "");
      setCell("J9", customerFax ? (" فکس: " + customerFax) : "");

      setCell("B11", "مشخصات کالا", { bold:true });

      setCell("B12", "ردیف", { bold:true });
      setCell("C12", "شرح کالا", { bold:true });
      setCell("D12", "کد کالا", { bold:true });
      setCell("E12", "تعداد/مقدار", { bold:true });
      setCell("F12", "واحد", { bold:true });
      setCell("G12", "فی واحد (ریال)", { bold:true });
      setCell("H12", "تخفیف (ریال)", { bold:true });
      setCell("I12", "مبلغ کل (ریال)", { bold:true });
      setCell("J12", "ملاحظات", { bold:true });

      setCell("B13", "1");
      setCell("C13", itemDesc);
      setCell("D13", productCode);
      setCell("E13", itemQty, { numFmt: "#,##0.###" });
      setCell("F13", itemUnit);
      setCell("G13", itemUnitPrice, { numFmt: "#,##0" });
      setCell("H13", 0, { numFmt: "#,##0" });

      const total = Math.round(itemQty * itemUnitPrice);
      setCell("I13", total, { numFmt: "#,##0" });

      setCell("B17", "جمع کل (ریال)", { bold:true });
      setCell("I17", total, { bold:true, numFmt:"#,##0" });

      const defaultNotes =
        "1- مبلغ پیش فاکتور فوق صرفا بابت  " + sampleQty + " کیلوگرم نمونه کامپاند می باشد.\n" +
        "2- نمونه فوق در دو حالت Dry Blend (مخلوط خشک) و یا به صورت گرانول شده قابل ارسال می باشد.\n" +
        "3- زمان تحویل Dry Blend 3 روز کاری و گرانول شده 7 روز کاری می باشد.\n" +
        "4- هزینه گرانول سازی هر کیلوگرم 4،000،000 ریال می باشد.";
      setCell("B18", notesB18 ? notesB18 : defaultNotes, { wrapText:true });

      const paymentText =
        "در صورت تائید پیش فاکتور لطفا مبلغ به حساب زیر واریز گردد:\n" +
        "3024454935001 \n" +
        "IR91  0590  0302  0044  5493  5000  01  \n" +
        "بانک سینا  -  شرکت مانا پاک دانا\n" +
        "اعتبار این پیش فاکتور از زمان صدور 24 ساعت می باشد.";
      setCell("G18", paymentText, { wrapText:true });

      addBox(5, 2, 6, 10);
      addBox(8, 2, 9, 10);
      addBox(11, 2, 17, 10);
      addBox(18, 2, 20, 6);
      addBox(18, 7, 20, 10);

      [4,7,10].forEach(r => {
        const row = sheet.getRow(r);
        for (let c=1; c<=10; c++) row.getCell(c).border = {};
      });

      for (let r=1; r<=24; r++) {
        const row = sheet.getRow(r);
        for (let c=1; c<=10; c++) {
          const cell = row.getCell(c);
          const pa = cell.alignment || {};
          cell.alignment = { horizontal:"center", vertical:"middle", wrapText: pa.wrapText || false };
        }
      }

      const buffer = await workbook.xlsx.writeBuffer();

      // Download
      const blob = new Blob([buffer], { type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      // Upload duplicate
      try {
        await uploadDuplicate(buffer, fileName);
        setStatus("PI generated and saved on server. File: " + fileName, false);

        // After saving, refresh PI No from database folder (so next one becomes PI+1)
        await refreshNextPiFromServer();

      } catch (e) {
        setStatus("PI generated (downloaded), but server save failed: " + e.message, true);
      }

    } catch (err) {
      console.error(err);
      setStatus("Error while generating PI file: " + err.message, true);
    }
  }

  document.getElementById("generateBtn").addEventListener("click", generatePIExcel);

  (function(){
    const t = document.getElementById("helpToggle");
    if (!t) return;
    try {
      const saved = localStorage.getItem("pi_showHelp") === "1";
      t.checked = saved;
      document.body.classList.toggle("show-help", saved);
    } catch (e) {}
    t.addEventListener("change", function(){
      const on = !!t.checked;
      document.body.classList.toggle("show-help", on);
      try { localStorage.setItem("pi_showHelp", on ? "1" : "0"); } catch (e) {}
    });
  })();
</script>

<?php render_footer(); ?>
<?php render_footer(); ?>
