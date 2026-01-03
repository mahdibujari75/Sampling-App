<?php
/************************************************************
 * SCF Generator — scf.php (port of SCF Generator v1.10.html)
 * Location: public_html/app/pages/scf.php
 *
 * Rules aligned with PI Generator:
 *  - Auth protected + uses your header/footer
 *  - Prefill from project context (?id=SUBPROJECT_ID or HTTP_REFERER)
 *  - Auto SCF Order No from server directory scan (per project code)
 *  - Save duplicate on server:
 *      (preferred) <baseDir>/SCF/<filename>
 *      (fallback)  /public_html/database/projects/<clientSlug>/<subCode>/SCF/<filename>
 *
 ************************************************************/

if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/..")); // app/
}

require_once APP_ROOT . "/includes/auth.php";
require_once APP_ROOT . "/includes/acl.php";
require_once APP_ROOT . "/includes/layout.php";
require_login();

/* ---------------- Helpers ---------------- */
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

function subtag_from_subcode(string $subCode): string {
  $subCode = trim($subCode);
  if ($subCode === "") return "";
  // "302-C" => "302C"
  $subCode = str_replace(["-", " "], "", $subCode);
  $subCode = preg_replace('/[^A-Za-z0-9]/', '', $subCode);
  return $subCode ?: "";
}

function safe_filename(string $s): string {
  $s = trim($s);
  $s = str_replace(["\\", "/"], "_", $s);
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  return $s ?: "SCF.xlsx";
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
  return $code;
}

function extract_projectcode_3digits(string $subCode): string {
  $parts = explode("-", $subCode);
  $p = preg_replace('/\D/', '', (string)($parts[0] ?? ""));
  $p = substr($p, 0, 3);
  return str_pad($p, 3, "0", STR_PAD_LEFT);
}

/* ---------------- Project Context ---------------- */
$me = current_user();
$role = current_role();
$myUsername = (string)($me["username"] ?? "");

$PROJECTS_FILE = defined("PROJECTS_DB_FILE") ? PROJECTS_DB_FILE : (APP_ROOT . "/database/projects.json");

function subcode_type(string $subCode): string {
  $parts = explode("-", $subCode);
  $t = strtoupper(trim($parts[1] ?? ""));
  if ($t === "" && preg_match('/([CFO])$/i', $subCode, $m)) {
    $t = strtoupper($m[1]);
  }
  return $t;
}

function project_ctx_from_record(array $p): array {
  $type = strtoupper(trim((string)($p["type"] ?? "")));
  $codeRaw = trim((string)($p["code"] ?? ""));
  $subCode = normalize_subcode($codeRaw, $type);

  return [
    "projectId"   => (int)($p["id"] ?? 0),
    "clientSlug"  => safe_slug((string)($p["customerSlug"] ?? ($p["customerUsername"] ?? ""))),
    "type"        => $type,
    "codeRaw"     => $codeRaw,
    "subCode"     => safe_code($subCode),
    "projectCode" => extract_projectcode_3digits($subCode),
    "baseDir"     => (string)($p["baseDir"] ?? ""),
  ];
}

function ctx_from_scope(int $projectId, string $customerSlug = "", string $subCode = ""): array {
  $empty = [
    "projectId" => 0,
    "clientSlug" => "",
    "type" => "",
    "codeRaw" => "",
    "subCode" => "",
    "projectCode" => "",
    "baseDir" => "",
  ];

  if ($projectId > 0) {
    $project = require_subproject_scope($projectId);
    return project_ctx_from_record($project);
  }

  $customerSlug = safe_slug($customerSlug);
  $subCode = safe_code($subCode);
  if ($customerSlug === "" || $subCode === "") return $empty;

  $type = subcode_type($subCode);
  $codeRaw = preg_replace('/\\D+/', '', $subCode);
  if ($type === "" || $codeRaw === "") {
    acl_access_denied();
  }

  $project = require_project_scope_by_slug_code($customerSlug, $codeRaw, $type);
  return project_ctx_from_record($project);
}

function scf_dir_from_ctx(array $ctx): string {
  $baseDir = trim((string)($ctx["baseDir"] ?? ""));
  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/SCF";
  }

  $clientSlug = (string)($ctx["clientSlug"] ?? "");
  $subCode = (string)($ctx["subCode"] ?? "");
  if ($clientSlug === "" || $subCode === "") return "";

  $publicRoot = realpath(APP_ROOT . "/.."); // public_html
  $root = rtrim($publicRoot ?: (APP_ROOT . "/.."), "/");
  return $root . "/database/projects/" . $clientSlug . "/" . $subCode . "/SCF";
}

/**
 * Returns next SCF orderNo (2 digits) by scanning files in SCF directory,
 * filtered by projectCode (per SCF v1.10 behavior).
 *
 * Supports file names like:
 *   301-01 SCF 1404.09.18.xlsx
 * and also tolerates:
 *   ... SCF 01 ...xlsx
 */
function next_scf_order_from_dir(string $dir, string $subTag): string {
  $subTag = strtoupper(trim($subTag));
  $subTag = preg_replace('/[^A-Z0-9]/', '', $subTag);
  $subTag = $subTag ?: "";

if ($dir === "" || !is_dir($dir)) return "01";

  $max = 0;
  $files = @glob(rtrim($dir, "/") . "/*.xlsx") ?: [];
  foreach ($files as $path) {
    $name = basename($path);

    // New preferred: "302C- SF01 04.10.05.xlsx"
    if (preg_match('/^([A-Za-z0-9]+)\-\s+SF0*([0-9]{1,3})\b/i', $name, $mNew)) {
      $tag = strtoupper($mNew[1]);
      $ver = (int)$mNew[2];
      if ($subTag === "" || $tag === $subTag) {
        if ($ver > $max) $max = $ver;
      }
      continue;
    }

    // Legacy: "301-01 SCF ..."
    if (preg_match('/^(\d+)-(\d{2,3})\s+SCF\b/i', $name, $m)) {
      $ver = (int)$m[2];
      if ($ver > $max) $max = $ver;
      continue;
    }

    // Fallback: "... SCF 01 ..."
    if (preg_match('/\b(?:SCF|SF)\s*0*([0-9]{1,3})\b/i', $name, $m2)) {
      $ver = (int)$m2[1];
      if ($ver > $max) $max = $ver;
      continue;
    }
  }

  $next = $max + 1;
  return str_pad((string)$next, 2, "0", STR_PAD_LEFT);
}

/* ---------------- AJAX: get next SCF orderNo ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "get_next_scf") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);
  $projectCode = (string)($_POST["projectCode"] ?? "");

  $ctx = ctx_from_scope(
    $projectId,
    (string)($_POST["clientSlug"] ?? ""),
    (string)($_POST["subCode"] ?? "")
  );
  if (!$projectCode) $projectCode = (string)($ctx["projectCode"] ?? "");

  $dir = scf_dir_from_ctx($ctx);
  $subTag = subtag_from_subcode((string)($ctx["subCode"] ?? ""));
  $next = next_scf_order_from_dir($dir, $subTag);

  echo json_encode(["ok" => true, "nextScf" => $next]);
  exit;
}

/* ---------------- AJAX: upload duplicate SCF ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_scf") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);

  $ctx = ctx_from_scope(
    $projectId,
    (string)($_POST["clientSlug"] ?? ""),
    (string)($_POST["subCode"] ?? "")
  );

  $fileName = safe_filename((string)($_POST["fileName"] ?? ""));

  if (!isset($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No file uploaded."]);
    exit;
  }

  $targetDir = scf_dir_from_ctx($ctx);
  if ($targetDir === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing project context (cannot resolve SCF directory)."]);
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

/* ---------------- Render Page ---------------- */
$viewId = (int)($_GET["id"] ?? 0);
if ($viewId <= 0) $viewId = referrer_project_id();
$ctx = ctx_from_scope($viewId);

$scfDir = scf_dir_from_ctx($ctx);
$subTag = subtag_from_subcode((string)($ctx["subCode"] ?? ""));
$autoOrder = next_scf_order_from_dir($scfDir, $subTag);
render_header("SCF Generator", $role);
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
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js"></script>
<div class="generator-shell" data-help="toggle" dir="ltr">
  <div class="card" style="margin-top:0;">
    <div class="page-head">
      <div>
        <h2>SCF Generator</h2>
        <div class="sub">Fill in the fields and generate the SCF Excel file.</div>
      </div>
      <div class="help-toggle">
        <label><input id="helpToggle" type="checkbox"> Show help</label>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Basic Information</h2>
    <div class="form-grid">
      <div>
        <label for="sampleName">Sample name</label>
        <input type="text" id="sampleName" value="Anti Rudent" />
      </div>

      <div>
        <label for="projectCode">Project code (3 digits)</label>
        <input type="number" id="projectCode" value="<?= h((string)($ctx["projectCode"] ?: "301")) ?>" />
        <div class="small-hint">Example: 301</div>
      </div>

      <div>
        <label for="jalaliDate">Date (Jalali)</label>
        <input type="text" id="jalaliDate" value="1404/09/18" />
        <div class="small-hint">In file name it becomes 1404.09.18</div>
      </div>
<div>
        <label for="machineType">Machine type (Xinda / Steer) | نوع دستگاه</label>
        <select id="machineType">
          <option value="Xinda">Xinda</option>
          <option value="Steer">Steer</option>
        </select>
      </div>

      <div>
        <label for="baseMaterialG11">Base material (G11) | ماده پایه</label>
        <input type="text" id="baseMaterialG11" value="MLDPE" />
      </div>


      <div>
        <label for="totalMass">Total mass (kg)</label>
        <input type="number" id="totalMass" value="1" step="0.001" />
      </div>

      <div>
        <label for="orderNo">Order / version No. (2 digits)</label>
        <input type="text" id="orderNo" placeholder="e.g., 01" value="<?= h($autoOrder) ?>" />
        <div class="small-hint">Auto-suggested from server files.</div>
      </div>

      <div>
        <label for="mixingOption">Mixing</label>
        <select id="mixingOption">
          <option value="YES" selected>YES</option>
          <option value="NO">NO</option>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Process Temperatures</h2>
    <div class="small-hint">Enter temperatures for zones 1–8.</div>
    <div class="temp-table-wrap" style="margin-top:10px;">
      <table class="temp-table">
        <thead>
          <tr>
            <th>Zone 1</th><th>Zone 2</th><th>Zone 3</th><th>Zone 4</th>
            <th>Zone 5</th><th>Zone 6</th><th>Zone 7</th><th>Zone 8</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><input type="number" id="t1" value="160" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t2" value="160" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t3" value="170" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t4" value="170" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t5" value="180" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t6" value="180" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t7" value="190" style="width:78px;text-align:center;" /></td>
            <td><input type="number" id="t8" value="190" style="width:78px;text-align:center;" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="form-grid" style="margin-top:12px;">
      <div>
        <label for="limitTemp">Limit temperature (°C)</label>
        <input type="number" id="limitTemp" value="190" />
        <div class="small-hint">Written to the template as the max temperature.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Formulation</h2>
    <div class="small-hint">Enter up to 6 materials. Percent is wt% (e.g., 25 means 25%).</div>

    <div class="temp-table-wrap" style="margin-top:10px;">
      <table class="temp-table">
        <thead>
          <tr>
            <th style="min-width:72px;">Item</th>
            <th>Material name</th>
            <th style="min-width:140px;">Percent (%)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1</td>
            <td><input type="text" id="matName1" value="PP MR230" /></td>
            <td><input type="number" id="matPct1" value="100" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
          <tr>
            <td>2</td>
            <td><input type="text" id="matName2" value="" /></td>
            <td><input type="number" id="matPct2" value="" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
          <tr>
            <td>3</td>
            <td><input type="text" id="matName3" value="" /></td>
            <td><input type="number" id="matPct3" value="" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
          <tr>
            <td>4</td>
            <td><input type="text" id="matName4" value="" /></td>
            <td><input type="number" id="matPct4" value="" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
          <tr>
            <td>5</td>
            <td><input type="text" id="matName5" value="" /></td>
            <td><input type="number" id="matPct5" value="" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
          <tr>
            <td>6</td>
            <td><input type="text" id="matName6" value="" /></td>
            <td><input type="number" id="matPct6" value="" step="0.01" style="width:120px;text-align:center;" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div style="margin-top:12px;">
      <label for="notes">Notes</label>
      <textarea id="notes" style="min-height:90px;">ممکن است کامپاند بوی نامطبوع تولید کند یا موجب بروز حساسیت شود، هنگام تولید از دستکش و ماسک استفاده شود.</textarea>
      <div class="hint">Percent values are stored as decimals in Excel but shown as percentages.</div>
    </div>
  </div>

  <div class="card">
    <h2>Export</h2>
    <div class="btn-row">
      <button id="generateBtn" type="button">Generate SCF (Excel)</button>
      <button id="resetBtn" type="button">Reset</button>
    </div>
    <div class="status" id="status"></div>
  </div>

  <input type="hidden" id="ctxProjectId" value="<?= h((string)$ctx["projectId"]) ?>">
  <input type="hidden" id="ctxClientSlug" value="<?= h((string)$ctx["clientSlug"]) ?>">
  <input type="hidden" id="ctxSubCode" value="<?= h((string)$ctx["subCode"]) ?>">
</div>
<script>
  /* Help toggle (same style as your HTML) */
  (function(){
    const t = document.getElementById("helpToggle");
    if (!t) return;
    try {
      const saved = localStorage.getItem("scf_showHelp") === "1";
      t.checked = saved;
      document.body.classList.toggle("show-help", saved);
    } catch(e){}
    t.addEventListener("change", function(){
      const on = !!t.checked;
      document.body.classList.toggle("show-help", on);
      try { localStorage.setItem("scf_showHelp", on ? "1" : "0"); } catch(e){}
    });
  })();

  /* ---------- Border helpers (identical logic) ---------- */
  function setCellBorderSide(ws, addr, sides) {
    const cell = ws.getCell(addr);
    const existing = cell.border || {};
    const out = {
      top: existing.top,
      bottom: existing.bottom,
      left: existing.left,
      right: existing.right
    };
    if (Object.prototype.hasOwnProperty.call(sides, "top")) {
      out.top = sides.top === null ? undefined : sides.top;
    }
    if (Object.prototype.hasOwnProperty.call(sides, "bottom")) {
      out.bottom = sides.bottom === null ? undefined : sides.bottom;
    }
    if (Object.prototype.hasOwnProperty.call(sides, "left")) {
      out.left = sides.left === null ? undefined : sides.left;
    }
    if (Object.prototype.hasOwnProperty.call(sides, "right")) {
      out.right = sides.right === null ? undefined : sides.right;
    }
    cell.border = out;
  }

  function applyRectBorder(ws, r1, c1, r2, c2) {
    for (let r = r1; r <= r2; r++) {
      for (let c = c1; c <= c2; c++) {
        const cell = ws.getRow(r).getCell(c);
        const border = {};

        if (r === r1) {
          border.top = { style: "thick" };
        } else if (r > r1) {
          border.top = { style: "thin" };
        }

        if (r === r2) {
          border.bottom = { style: "thick" };
        }

        if (c === c1) {
          border.left = { style: "thick" };
        } else if (c > c1) {
          border.left = { style: "thin" };
        }

        if (c === c2) {
          border.right = { style: "thick" };
        }

        cell.border = border;
      }
    }
  }

  /* ---------- PMA integration (kept) ---------- */
  function applyPMAContext(ctx) {
    try {
      if (!ctx) return;
      if (ctx.projectCode) document.getElementById("projectCode").value = String(ctx.projectCode);
      if (ctx.name && !document.getElementById("sampleName").value.trim()) {
        document.getElementById("sampleName").value = String(ctx.name);
      }
      // only auto-fill if orderNo empty
      const orderInput = document.getElementById("orderNo");
      if (!orderInput.value) refreshNextScfFromServer(true);
    } catch(e){}
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
      if (ctx.projectCode || ctx.customer || ctx.name) applyPMAContext(ctx);
    } catch(e){}
  })();

  window.addEventListener("message", function(ev) {
    try {
      const data = ev.data;
      if (!data || data.type !== "PMA_CONTEXT") return;
      applyPMAContext(data.payload || {});
    } catch(e){}
  });

  /* ---------- Server-based next SCF (PI rules) ---------- */
  function refreshNextScfFromServer(onlyIfEmpty=false){
    try {
      const orderInput = document.getElementById("orderNo");
      if (onlyIfEmpty && orderInput.value) return Promise.resolve();

      const fd = new FormData();
      fd.append("action", "get_next_scf");
      fd.append("projectId", (document.getElementById("ctxProjectId").value || "").trim());
      fd.append("clientSlug", (document.getElementById("ctxClientSlug").value || "").trim());
      fd.append("subCode", (document.getElementById("ctxSubCode").value || "").trim());
      fd.append("projectCode", (document.getElementById("projectCode").value || "").trim());

      const url = window.location.pathname + window.location.search;
      return fetch(url, { method:"POST", body: fd })
        .then(res => res.json().catch(()=>null).then(j => ({res,j})))
        .then(({res,j}) => {
          if (!res.ok || !j || !j.ok) return;
          const next = String(j.nextScf || "").trim();
          if (next) orderInput.value = next;
        })
        .catch(()=>{});
    } catch(e){ return Promise.resolve(); }
  }

  // Ensure initial value sync (server scan wins)
  refreshNextScfFromServer(false);

  // When project code changes, suggest next version IF orderNo empty (same UX rule as v1.10)
  document.getElementById("projectCode").addEventListener("change", () => {
    const orderInput = document.getElementById("orderNo");
    if (!orderInput.value) refreshNextScfFromServer(false);
  });

  function uploadDuplicate(buffer, fileName){
    try {
      const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
      const fd = new FormData();
      fd.append("action","upload_scf");
      fd.append("fileName", fileName);
      fd.append("projectId",(document.getElementById("ctxProjectId").value||"").trim());
      fd.append("clientSlug",(document.getElementById("ctxClientSlug").value||"").trim());
      fd.append("subCode",(document.getElementById("ctxSubCode").value||"").trim());
      fd.append("file", blob, fileName);

      const url = window.location.pathname + window.location.search;
      return fetch(url, { method:"POST", body: fd })
        .then(res => res.json().catch(()=>null).then(j => ({res,j})))
        .then(({res,j}) => {
          if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : ("Upload failed (HTTP "+res.status+")"));
          return j;
        });
    } catch(e){
      return Promise.reject(e);
    }
  }

  /* ---------- EXACT workbook creation from SCF v1.10 ---------- */
  /* -------- Date helpers (needed for file naming) -------- */
  function _pad2(n){
    const v = Number(n);
    if (!isFinite(v)) return "00";
    return String(Math.trunc(v)).padStart(2, "0");
  }

  // Accepts: 1404/10/05 , 04/10/05 , 1404.10.05 , 04.10.05
  // Returns: 04.10.05 (YY.MM.DD)
  function formatShortJalaliForFile(input){
    let s = (input || "").toString().trim();
    if (!s) return "";
    s = s.replace(/[-]/g, "/").replace(/[.]/g, "/");
    const parts = s.split("/").map(x => x.trim()).filter(Boolean);
    if (parts.length < 3) return s.replace(/\//g, ".");
    let y = (parts[0] || "").replace(/\D/g, "");
    let m = (parts[1] || "").replace(/\D/g, "");
    let d = (parts[2] || "").replace(/\D/g, "");
    if (y.length >= 4) y = y.slice(-2); // 1404 -> 04
    return `${_pad2(y)}.${_pad2(m)}.${_pad2(d)}`;
  }


  
  // Load Master template (keeps all borders/merges/fonts/print settings)

function loadMasterTemplate(){
  return fetch("/get_template?type=scf", {
    cache: "no-store",
    credentials: "include"
  })
  .then(r => {
    if (!r.ok) throw new Error("Cannot load Master template: " + r.status);
    return r.arrayBuffer();
  })
  .then(buf => {
    const wb = new ExcelJS.Workbook();
    wb.creator = "SCF Generator";
    return wb.xlsx.load(buf).then(() => wb);
  });
}


function createWorkbookFromInputs() {
    const projectCode = (document.getElementById("projectCode")?.value || "").toString().trim();
    const orderNo = (document.getElementById("orderNo")?.value || "").toString().trim();
    const sampleName = (document.getElementById("sampleName")?.value || "").toString().trim();
    const machineType = (document.getElementById("machineType")?.value || "").toString().trim();

    // One-time ask: Base material -> write to G11
    const baseMaterialG11 = (document.getElementById("baseMaterialG11")?.value || "").toString().trim();

    // Total mass exists in UI as totalMass
    const totalMass = Number(document.getElementById("totalMass")?.value || 0);

    // Zone temperatures exist in UI as t1..t8
    const zones = [];
    for (let i = 1; i <= 8; i++) {
      zones.push(Number(document.getElementById("t" + i)?.value || 0));
    }

    // Optional fields: die/adapter. If the UI doesn't include them, keep blank.
    const die = Number(document.getElementById("die")?.value || 0);
    const adapter = Number(document.getElementById("adapter")?.value || 0);

    // Limit temperature exists in UI as limitTemp and is written to J14.
    const limitTemp = Number(document.getElementById("limitTemp")?.value || 0);

    // Rule (per your requirement): Limit temperature must be >= ALL zone temperatures
    // i.e., if any zone is greater than limit, raise an error.
    const maxZone = Math.max.apply(null, zones.filter(v => Number.isFinite(v)));
    if (Number.isFinite(limitTemp) && Number.isFinite(maxZone) && limitTemp < maxZone) {
      throw new Error("Limit temperature (J14) must be >= the maximum zone temperature.");
    }

    // version like: SF01 => "01"
    const versionRaw = (document.getElementById("sfVersion")?.value || "1").toString().trim();
    const versionTwoDigits = String(parseInt(versionRaw, 10) || 1).padStart(2,"0");

    const jalaliDate = (document.getElementById("jalaliDate")?.value || "").toString().trim();
    const jalaliForFile = jalaliDate ? jalaliDate.replaceAll("/", ".") : "";

    return loadMasterTemplate().then(wb => {
      const ws = wb.getWorksheet(1) || wb.worksheets[0];

      // Fill master cells (visuals already come from template)
      if (projectCode) ws.getCell("E2").value = projectCode;            // کد پروژه
      if (orderNo) ws.getCell("E3").value = orderNo;                    // کد سفارش
      if (sampleName) ws.getCell("E4").value = sampleName;              // نام نمونه
      if (machineType) ws.getCell("G3").value = machineType;            // نوع دستگاه
      if (baseMaterialG11) ws.getCell("G11").value = baseMaterialG11;   // ماده پایه

      // Zones to C8:J8 (8 zones)
      const zoneCells = ["C8","D8","E8","F8","G8","H8","I8","J8"];
      for (let i=0; i<8; i++) ws.getCell(zoneCells[i]).value = zones[i] || "";

      // Only write die/adapter if present/non-zero, otherwise keep template as-is
      if (die) ws.getCell("K8").value = die;
      if (adapter) ws.getCell("L8").value = adapter;

      // Write limit temp to J14
      ws.getCell("J14").value = limitTemp || "";

      // Add Excel validation: J14 >= MAX(C8:J8)
      try {
        ws.getCell("J14").dataValidation = {
          type: "custom",
          allowBlank: true,
          showErrorMessage: true,
          errorStyle: "stop",
          errorTitle: "Invalid value",
          error: "Limit temperature (J14) must be >= the maximum zone temperature.",
          formulae: ["J14>=MAX($C$8:$J$8)"]
        };
      } catch(e){}

      // (Optional) if the template uses total mass somewhere in a known cell, set it here.
      // Not writing totalMass currently because the master cell address was not specified.

      return { workbook: wb, projectCode, versionTwoDigits, jalaliForFile };
    });
  }




  function generateExcel() {
    const statusEl = document.getElementById("status");
    statusEl.textContent = "در حال ساخت فایل اکسل...";
    try {
      return createWorkbookFromInputs()
        .then(({ workbook, projectCode, versionTwoDigits, jalaliForFile }) => {
          const ctxSubCode = (document.getElementById("ctxSubCode")?.value || "").trim();
          const subTag = (ctxSubCode ? ctxSubCode.replaceAll("-", "") : String(projectCode || "")).trim();
          const prefix = subTag ? (subTag + "-") : "";
          const fileName = `${prefix ? (prefix + " ") : ""}SF${versionTwoDigits} ${jalaliForFile || "date"}.xlsx`;

          return workbook.xlsx.writeBuffer().then(buffer => ({ buffer, fileName }));
        })
        .then(({ buffer, fileName }) => {
          // Download
          const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
          const url = URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.href = url;
          a.download = fileName;
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(()=>URL.revokeObjectURL(url), 2000);

          // Upload duplicate (optional). Do not block user if it fails.
          return uploadDuplicate(buffer, fileName)
            .then(()=>refreshNextScfFromServer(false))
            .catch(e=>{ console.error(e); })
            .then(()=>{ statusEl.textContent = `فایل با نام "${fileName}" ایجاد و دانلود شد.`; });
        })
        .catch(err => {
          console.error(err);
          statusEl.textContent = (err && err.message) ? err.message : "خطا در ساخت فایل اکسل.";
        });
    } catch (err) {
      console.error(err);
      statusEl.textContent = (err && err.message) ? err.message : "خطا در ساخت فایل اکسل.";
      return Promise.resolve();
    }
  }

  document.getElementById("generateBtn").addEventListener("click", generateExcel);

  document.getElementById("resetBtn").addEventListener("click", () => {
    if (!confirm("فرم ریست شود؟")) return;
    document.getElementById("sampleName").value = "Anti Rudent";
    document.getElementById("projectCode").value = "301";
    document.getElementById("jalaliDate").value = "1404/09/18";
    document.getElementById("totalMass").value = "1";
    document.getElementById("orderNo").value = ""; // then auto-suggest
    document.getElementById("mixingOption").value = "YES";

    const defaultsT = [160,160,170,170,180,180,190,190];
    for (let i = 1; i <= 8; i++) document.getElementById("t" + i).value = defaultsT[i - 1];
    document.getElementById("limitTemp").value = "190";

    document.getElementById("matName1").value = "PP MR230";
    document.getElementById("matPct1").value = "100";
    for (let i = 2; i <= 6; i++) {
      document.getElementById("matName" + i).value = "";
      document.getElementById("matPct" + i).value = "";
    }

    document.getElementById("notes").value =
      "ممکن است کامپاند بوی نامطبوع تولید کند یا موجب بروز حساسیت شود، هنگام تولید از دستکش و ماسک استفاده شود.";

    document.getElementById("status").textContent = "";
    refreshNextScfFromServer(false);
  });
</script>
<?php render_footer(); ?>
