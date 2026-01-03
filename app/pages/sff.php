<?php
/************************************************************
 * sff.php — SFF Generator (server + download)
 * Based on SFF Generator v1.04.html.
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
function safe_filename(string $s): string {
  $s = trim($s);
  $s = str_replace(["\\", "/"], "_", $s);
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  return $s ?: "SFF.xlsx";
}

function subtag_from_subcode(string $subCode): string {
  $subCode = trim($subCode);
  if ($subCode === "") return "";
  $subCode = str_replace(["-", " "], "", $subCode); // "302-F" => "302F"
  $subCode = preg_replace('/[^A-Za-z0-9]/', '', $subCode);
  return $subCode ?: "";
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

function sff_dir_from_ctx(array $ctx): string {
  $baseDir = trim((string)($ctx["baseDir"] ?? ""));
  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/SFF";
  }

  $clientSlug = (string)($ctx["clientSlug"] ?? "");
  $subCode = (string)($ctx["subCode"] ?? "");
  if ($clientSlug === "" || $subCode === "") return "";

  $publicRoot = realpath(APP_ROOT . "/.."); // public_html
  $root = rtrim($publicRoot ?: (APP_ROOT . "/.."), "/");
  return $root . "/database/projects/" . $clientSlug . "/" . $subCode . "/SFF";
}

function next_sff_order_from_dir(string $dir, string $subTag): string {
  $subTag = strtoupper(trim($subTag));
  $subTag = preg_replace('/[^A-Z0-9]/', '', $subTag);
  $subTag = $subTag ?: "";
  if ($dir === "" || !is_dir($dir)) return "01";

  $max = 0;
  $files = @glob(rtrim($dir, "/") . "/*.xlsx") ?: [];
  foreach ($files as $path) {
    $name = basename($path);
        // New preferred: "302F- SF01 04.10.05.xlsx"
    if (preg_match('/^([A-Za-z0-9]+)\-\s+SF0*([0-9]{1,3})\b/i', $name, $mNew)) {
      $tag = strtoupper($mNew[1]);
      $ver = (int)$mNew[2];
      if ($subTag === "" || $tag === $subTag) {
        if ($ver > $max) $max = $ver;
      }
      continue;
    }

if (preg_match('/^(\d+)-(\d+)\s+SFF\b/i', $name, $m)) {
      $ver = (int)$m[2];
      if ($ver > $max) $max = $ver;
    }
  }
  $next = $max + 1;
  return str_pad((string)$next, 2, "0", STR_PAD_LEFT);
}

/* ---------------- AJAX: get next SFF orderNo ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "get_next_sff") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);
  $projectCode = (string)($_POST["projectCode"] ?? "");

  $ctx = ctx_from_scope(
    $projectId,
    (string)($_POST["clientSlug"] ?? ""),
    (string)($_POST["subCode"] ?? "")
  );
  if (!$projectCode) $projectCode = (string)($ctx["projectCode"] ?? "");

  $dir = sff_dir_from_ctx($ctx);
  $subTag = subtag_from_subcode((string)($ctx["subCode"] ?? ""));
  $next = next_sff_order_from_dir($dir, $subTag);

  echo json_encode(["ok" => true, "nextSff" => $next]);
  exit;
}

/* ---------------- AJAX: upload duplicate SFF ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_sff") {
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

  $targetDir = sff_dir_from_ctx($ctx);
  if ($targetDir === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing project context (cannot resolve SFF directory)."]);
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

$viewId = (int)($_GET["id"] ?? 0);
if ($viewId <= 0) $viewId = referrer_project_id();
$ctx = ctx_from_scope($viewId);

$sffDir = sff_dir_from_ctx($ctx);
$subTag = subtag_from_subcode((string)($ctx["subCode"] ?? ""));
$autoOrder = next_sff_order_from_dir($sffDir, $subTag);

render_header("SFF Generator", $role);
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
<div class="generator-shell">
  

  <div class="card" style="margin-top:0;">
    <div class="page-head">
      <div>
        <h2>SFF Generator v1.04</h2>
        <div class="sub">Fill in the fields and generate the SFF Excel file.</div>
      </div>
      
    </div>
  </div>
<h1>SFF Generator v1.04</h1>

  <div class="card">
    <h2>Basic Information</h2>
    <div class="form-grid">
      <div>
        <label for="projectCode">Project Code</label>
        <input id="projectCode" type="number" inputmode="numeric" placeholder="e.g., 401" value="<?php echo h((string)($ctx["projectCode"] ?? "")); ?>" />
        <div id="pmaPrefillNote" class="pma-note">Prefilled from PMA (editable).</div>
      </div>
      <div>
        <label for="orderNo">Order / Version No.</label>
        <input id="orderNo" type="number" inputmode="numeric" placeholder="e.g., 1" value="<?php echo h((string)($autoOrder ?? "1")); ?>" />
        <div class="small-hint">Used in the file name (e.g., 401-1).</div>
      </div>
      <div>
        <label for="jalaliDate">Date (Jalali)</label>
        <input id="jalaliDate" type="text" placeholder="e.g., 1404/09/24" />
      </div>
      <div>
        <label for="totalMass">Total Mass (kg)</label>
        <input id="totalMass" type="number" inputmode="decimal" placeholder="e.g., 20" />
      </div>
      <div>
        <label for="thInner">Inner layer thickness (µm)</label>
        <input id="thInner" type="number" inputmode="decimal" placeholder="e.g., 15" />
      </div>
      <div>
        <label for="thMid">Middle layer thickness (µm)</label>
        <input id="thMid" type="number" inputmode="decimal" placeholder="e.g., 7.5" />
      </div>
      <div>
        <label for="thOuter">Outer layer thickness (µm)</label>
        <input id="thOuter" type="number" inputmode="decimal" placeholder="e.g., 7.5" />
      </div>
      <div>
        <label for="width">Film width (mm)</label>
        <input id="width" type="text" placeholder="optional (kept as template if blank)" />
        <div class="small-hint">If empty, template value is kept.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Film Specifications</h2>
    <div class="form-grid">
      <div>
        <label for="customerName">Customer Name</label>
        <input id="customerName" type="text" placeholder="Customer / client name" />
        <div class="small-hint">Written to D3 in the template.</div>
      </div>

      <div>
        <label for="filmType">Film Type</label>
        <select id="filmType">
          <option value="">Select...</option>
          <option value="تیوب">تیوب</option>
          <option value="بغل باز">بغل باز</option>
          <option value="تخت(تک لایه)">تخت(تک لایه)</option>
        </select>
        <div class="small-hint">Sets Yes in D9 / H9 / J9 depending on the type.</div>
      </div>

      <div>
        <label for="gussetWidth">Gusset Width (cm) — only for تیوب</label>
        <input id="gussetWidth" type="number" inputmode="decimal" placeholder="0 to 20" />
        <div class="small-hint">If Film Type is تیوب, F9 will be filled like “10 cm”.</div>
      </div>

      <div>
        <label for="coronaTreatment">Corona Treatment</label>
        <select id="coronaTreatment">
          <option value="NO">NO</option>
          <option value="YES">YES</option>
        </select>
        <div class="small-hint">Written to D10.</div>
      </div>

      <div>
        <label for="nipRollRotation">Nip Roll Rotation</label>
        <select id="nipRollRotation">
          <option value="NO">NO</option>
          <option value="YES">YES</option>
        </select>
        <div class="small-hint">Written to I10.</div>
      </div>

      <div>
        <label for="packaging">Packaging</label>
        <input id="packaging" type="text" placeholder="e.g., roll / box / pallet ..." />
        <div class="small-hint">Written to D11.</div>
      </div>

      <div>
        <label for="minLength">Minimum Length</label>
        <input id="minLength" type="text" placeholder="e.g., 200 m" />
        <div class="small-hint">Written to I11.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Layer Settings</h2>
    <div class="three-col">
      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Inner layer</h2>
        <div class="form-grid" style="grid-template-columns:1fr;">
          <div>
            <label for="innerBase">Inner Layer Base Material</label>
            <input id="innerBase" type="text" placeholder="Written to G14" />
          </div>
          <div>
            <label for="innerDry">Inner Layer Drying</label>
            <select id="innerDry">
              <option value="NO">NO</option>
              <option value="YES">YES</option>
            </select>
            <div class="small-hint">Written to J14.</div>
          </div>
          <div>
            <label for="innerMaxTemp">Inner Layer Max Temperature</label>
            <input id="innerMaxTemp" type="number" inputmode="decimal" placeholder="°C (written to J20)" />
          </div>
        </div>
      </div>

      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Middle layer</h2>
        <div class="form-grid" style="grid-template-columns:1fr;">
          <div>
            <label for="midBase">Middle Layer Base Material</label>
            <input id="midBase" type="text" placeholder="Written to G26" />
          </div>
          <div>
            <label for="midDry">Middle Layer Drying</label>
            <select id="midDry">
              <option value="NO">NO</option>
              <option value="YES">YES</option>
            </select>
            <div class="small-hint">Written to J26.</div>
          </div>
          <div>
            <label for="midMaxTemp">Middle Layer Max Temperature</label>
            <input id="midMaxTemp" type="number" inputmode="decimal" placeholder="°C (written to J32)" />
          </div>
        </div>
      </div>

      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Outer layer</h2>
        <div class="form-grid" style="grid-template-columns:1fr;">
          <div>
            <label for="outBase">Outer Layer Base Material</label>
            <input id="outBase" type="text" placeholder="Written to G38" />
          </div>
          <div>
            <label for="outDry">Outer Layer Drying</label>
            <select id="outDry">
              <option value="NO">NO</option>
              <option value="YES">YES</option>
            </select>
            <div class="small-hint">Written to J38.</div>
          </div>
          <div>
            <label for="outMaxTemp">Outer Layer Max Temperature</label>
            <input id="outMaxTemp" type="number" inputmode="decimal" placeholder="°C (written to J44)" />
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Formulation</h2>
    <div class="small-hint">Enter 12 components per layer (Name + %). Percent is wt% (e.g., 25 means 25%).</div>
    <div class="section-divider"></div>

    <div class="three-col">
      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Inner layer (12 items)</h2>
        <div class="layer-grid">
          <div class="hdr">Material name</div><div class="hdr">wt%</div>
          <input id="inName1" type="text" placeholder="Name 1" /><input id="inPct1" type="number" inputmode="decimal" placeholder="%" /><input id="inName2" type="text" placeholder="Name 2" /><input id="inPct2" type="number" inputmode="decimal" placeholder="%" /><input id="inName3" type="text" placeholder="Name 3" /><input id="inPct3" type="number" inputmode="decimal" placeholder="%" /><input id="inName4" type="text" placeholder="Name 4" /><input id="inPct4" type="number" inputmode="decimal" placeholder="%" /><input id="inName5" type="text" placeholder="Name 5" /><input id="inPct5" type="number" inputmode="decimal" placeholder="%" /><input id="inName6" type="text" placeholder="Name 6" /><input id="inPct6" type="number" inputmode="decimal" placeholder="%" /><input id="inName7" type="text" placeholder="Name 7" /><input id="inPct7" type="number" inputmode="decimal" placeholder="%" /><input id="inName8" type="text" placeholder="Name 8" /><input id="inPct8" type="number" inputmode="decimal" placeholder="%" /><input id="inName9" type="text" placeholder="Name 9" /><input id="inPct9" type="number" inputmode="decimal" placeholder="%" /><input id="inName10" type="text" placeholder="Name 10" /><input id="inPct10" type="number" inputmode="decimal" placeholder="%" /><input id="inName11" type="text" placeholder="Name 11" /><input id="inPct11" type="number" inputmode="decimal" placeholder="%" /><input id="inName12" type="text" placeholder="Name 12" /><input id="inPct12" type="number" inputmode="decimal" placeholder="%" />
        </div>
        <label for="noteInner" style="margin-top:12px;">Notes (Inner)</label>
        <textarea id="noteInner" placeholder="Optional notes..."></textarea>
      </div>

      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Middle layer (12 items)</h2>
        <div class="layer-grid">
          <div class="hdr">Material name</div><div class="hdr">wt%</div>
          <input id="midName1" type="text" placeholder="Name 1" /><input id="midPct1" type="number" inputmode="decimal" placeholder="%" /><input id="midName2" type="text" placeholder="Name 2" /><input id="midPct2" type="number" inputmode="decimal" placeholder="%" /><input id="midName3" type="text" placeholder="Name 3" /><input id="midPct3" type="number" inputmode="decimal" placeholder="%" /><input id="midName4" type="text" placeholder="Name 4" /><input id="midPct4" type="number" inputmode="decimal" placeholder="%" /><input id="midName5" type="text" placeholder="Name 5" /><input id="midPct5" type="number" inputmode="decimal" placeholder="%" /><input id="midName6" type="text" placeholder="Name 6" /><input id="midPct6" type="number" inputmode="decimal" placeholder="%" /><input id="midName7" type="text" placeholder="Name 7" /><input id="midPct7" type="number" inputmode="decimal" placeholder="%" /><input id="midName8" type="text" placeholder="Name 8" /><input id="midPct8" type="number" inputmode="decimal" placeholder="%" /><input id="midName9" type="text" placeholder="Name 9" /><input id="midPct9" type="number" inputmode="decimal" placeholder="%" /><input id="midName10" type="text" placeholder="Name 10" /><input id="midPct10" type="number" inputmode="decimal" placeholder="%" /><input id="midName11" type="text" placeholder="Name 11" /><input id="midPct11" type="number" inputmode="decimal" placeholder="%" /><input id="midName12" type="text" placeholder="Name 12" /><input id="midPct12" type="number" inputmode="decimal" placeholder="%" />
        </div>
        <label for="noteMid" style="margin-top:12px;">Notes (Middle)</label>
        <textarea id="noteMid" placeholder="Optional notes..."></textarea>
      </div>

      <div class="card" style="margin:0;">
        <h2 style="font-size:16px;">Outer layer (12 items)</h2>
        <div class="layer-grid">
          <div class="hdr">Material name</div><div class="hdr">wt%</div>
          <input id="outName1" type="text" placeholder="Name 1" /><input id="outPct1" type="number" inputmode="decimal" placeholder="%" /><input id="outName2" type="text" placeholder="Name 2" /><input id="outPct2" type="number" inputmode="decimal" placeholder="%" /><input id="outName3" type="text" placeholder="Name 3" /><input id="outPct3" type="number" inputmode="decimal" placeholder="%" /><input id="outName4" type="text" placeholder="Name 4" /><input id="outPct4" type="number" inputmode="decimal" placeholder="%" /><input id="outName5" type="text" placeholder="Name 5" /><input id="outPct5" type="number" inputmode="decimal" placeholder="%" /><input id="outName6" type="text" placeholder="Name 6" /><input id="outPct6" type="number" inputmode="decimal" placeholder="%" /><input id="outName7" type="text" placeholder="Name 7" /><input id="outPct7" type="number" inputmode="decimal" placeholder="%" /><input id="outName8" type="text" placeholder="Name 8" /><input id="outPct8" type="number" inputmode="decimal" placeholder="%" /><input id="outName9" type="text" placeholder="Name 9" /><input id="outPct9" type="number" inputmode="decimal" placeholder="%" /><input id="outName10" type="text" placeholder="Name 10" /><input id="outPct10" type="number" inputmode="decimal" placeholder="%" /><input id="outName11" type="text" placeholder="Name 11" /><input id="outPct11" type="number" inputmode="decimal" placeholder="%" /><input id="outName12" type="text" placeholder="Name 12" /><input id="outPct12" type="number" inputmode="decimal" placeholder="%" />
        </div>
        <label for="noteOuter" style="margin-top:12px;">Notes (Outer)</label>
        <textarea id="noteOuter" placeholder="Optional notes..."></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Export</h2>
    <div class="btn-row">
      <button id="resetBtn" class="secondary" type="button">Reset</button>
      <button id="generateBtn" class="primary" type="button">Generate Excel (SFF)</button>
    </div>
    <div id="status"></div>
  </div>

  <input type="hidden" id="ctxProjectId" value="<?php echo h((string)($ctx['projectId'] ?? 0)); ?>">
  <input type="hidden" id="ctxClientSlug" value="<?php echo h((string)($ctx['clientSlug'] ?? '')); ?>">
  <input type="hidden" id="ctxSubCode" value="<?php echo h((string)($ctx['subCode'] ?? '')); ?>">
</div>

<script>

  const TEMPLATE_B64 = "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";

  // Converts Jalali date to YY.MM.DD for file naming.
  // Accepts: 1404/10/05, 04/10/05, 1404.10.05, 04.10.05
  function formatShortJalaliForFile(input) {
    const s = String(input || "").trim();
    if (!s) return "";
    const parts = s.includes("/") ? s.split("/") : (s.includes(".") ? s.split(".") : []);
    if (parts.length !== 3) return s.replaceAll("/", "."); // fallback
    let y = parts[0].trim();
    const m = parts[1].trim().padStart(2, "0");
    const d = parts[2].trim().padStart(2, "0");
    if (y.length >= 4) y = y.slice(-2);
    y = y.padStart(2, "0");
    return `${y}.${m}.${d}`;
  }


  function setStatus(msg, isError=false){
    const el=document.getElementById("status");
    el.textContent = msg || "";
    el.className = isError ? "error" : "ok";
  }

  function b64ToUint8Array(b64){
    const binary = atob(b64);
    const len = binary.length;
    const bytes = new Uint8Array(len);
    for(let i=0;i<len;i++) bytes[i]=binary.charCodeAt(i);
    return bytes;
  }

  function readNum(id){
    const v = (document.getElementById(id)?.value || "").toString().trim();
    if(v==="") return null;
    const n = Number(v);
    return isFinite(n) ? n : null;
  }

  function readText(id){
    return (document.getElementById(id)?.value || "").toString();
  }

  function pctToDecimal(pct){
    if(pct===null || pct===undefined || pct==="") return null;
    const n = Number(pct);
    if(!isFinite(n)) return null;
    return n/100.0;
  }

  async function refreshNextSffFromServer(onlyIfEmpty=false){
    try {
      const orderEl = document.getElementById("orderNo");
      if (onlyIfEmpty && orderEl.value) return;

      const fd = new FormData();
      fd.append("action","get_next_sff");
      fd.append("projectId",(document.getElementById("ctxProjectId").value||"").trim());
      fd.append("clientSlug",(document.getElementById("ctxClientSlug").value||"").trim());
      fd.append("subCode",(document.getElementById("ctxSubCode").value||"").trim());
      fd.append("projectCode",(document.getElementById("projectCode").value||"").trim());

      const url = window.location.pathname + window.location.search;
      const res = await fetch(url, { method:"POST", body: fd });
      const j = await res.json().catch(()=>null);
      if (!res.ok || !j || !j.ok) return;

      const next = String(j.nextSff || "").trim();
      if (next) orderEl.value = next;
    } catch(e) {}
  }

  async function uploadDuplicate(buffer, fileName){
    const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
    const fd = new FormData();
    fd.append("action","upload_sff");
    fd.append("fileName", fileName);
    fd.append("projectId",(document.getElementById("ctxProjectId").value||"").trim());
    fd.append("clientSlug",(document.getElementById("ctxClientSlug").value||"").trim());
    fd.append("subCode",(document.getElementById("ctxSubCode").value||"").trim());
    fd.append("file", blob, fileName);

    const url = window.location.pathname + window.location.search;
    const res = await fetch(url, { method:"POST", body: fd });
    const j = await res.json().catch(()=>null);
    if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : ("Upload failed (HTTP "+res.status+")"));
    return j.savedTo || "";
  }

  async function generate(){
    try{
      setStatus("Generating Excel...");
      const projectCode = readText("projectCode").trim();
      const orderNo = readNum("orderNo");
      const jalaliDate = readText("jalaliDate").trim();
      const totalMass = readNum("totalMass");
      const thInner = readNum("thInner");
      const thMid = readNum("thMid");
      const thOuter = readNum("thOuter");
      const width = readText("width").trim();

      const customerName = readText("customerName").trim();
      const filmType = readText("filmType").trim();
      const gussetWidth = readNum("gussetWidth");
      const coronaTreatment = readText("coronaTreatment").trim() || "NO";
      const nipRollRotation = readText("nipRollRotation").trim() || "NO";
      const packaging = readText("packaging").trim();
      const minLength = readText("minLength").trim();

      const innerBase = readText("innerBase").trim();
      const midBase = readText("midBase").trim();
      const outBase = readText("outBase").trim();

      const innerDry = readText("innerDry").trim() || "NO";
      const midDry = readText("midDry").trim() || "NO";
      const outDry = readText("outDry").trim() || "NO";

      const innerMaxTemp = readNum("innerMaxTemp");
      const midMaxTemp = readNum("midMaxTemp");
      const outMaxTemp = readNum("outMaxTemp");

      if(!projectCode) throw new Error("Project Code is required.");
      if(!orderNo) throw new Error("Order/Version No. is required.");
      if(!jalaliDate) throw new Error("Date is required.");
      if(totalMass===null) throw new Error("Total Mass is required.");
      if(thInner===null || thMid===null || thOuter===null) throw new Error("All 3 layer thicknesses are required.");

      if(filmType === "تیوب"){
        if(gussetWidth===null) throw new Error("Gusset Width is required for تیوب.");
        if(gussetWidth < 0 || gussetWidth > 20) throw new Error("Gusset Width must be between 0 and 20 cm.");
      }

      const wb = new ExcelJS.Workbook();
      await wb.xlsx.load(b64ToUint8Array(TEMPLATE_B64));
      const ws = wb.getWorksheet("Sheet1") || wb.worksheets[0];

      // Basic cells (match your template)

      // --- Formats fix: rows that must be General (not Percentage) in D..I
      const generalRows = [15,19,27,31,39,43];
      const genCols = ["D","E","F","G","H","I"];
      generalRows.forEach(r => genCols.forEach(c => { ws.getCell(`${c}${r}`).numFmt = "General"; }));

      // Customer name
      if(customerName) ws.getCell("D3").value = customerName;

      // G7: total thickness = inner + middle + outer
      ws.getCell("G7").value = Number(thInner) + Number(thMid) + Number(thOuter);

      // Film Type flags (Yes)
      ws.getCell("D9").value = null;
      ws.getCell("H9").value = null;
      ws.getCell("J9").value = null;
      ws.getCell("F9").value = null;

      if(filmType === "تیوب"){
        ws.getCell("D9").value = "Yes";
        ws.getCell("F9").value = `${gussetWidth} cm`;
      }else if(filmType === "بغل باز"){
        ws.getCell("H9").value = "Yes";
      }else if(filmType === "تخت(تک لایه)"){
        ws.getCell("J9").value = "Yes";
      }

      // Corona + Nip Roll
      ws.getCell("D10").value = coronaTreatment;
      ws.getCell("I10").value = nipRollRotation;

      // Packaging + Minimum Length
      if(packaging) ws.getCell("D11").value = packaging;
      if(minLength) ws.getCell("I11").value = minLength;

      // Base materials
      if(innerBase) ws.getCell("G14").value = innerBase;
      if(midBase) ws.getCell("G26").value = midBase;
      if(outBase) ws.getCell("G38").value = outBase;

      // Drying flags
      ws.getCell("J14").value = innerDry;
      ws.getCell("J26").value = midDry;
      ws.getCell("J38").value = outDry;

      // Max temperatures
      if(innerMaxTemp!==null) ws.getCell("J20").value = Number(innerMaxTemp);
      if(midMaxTemp!==null) ws.getCell("J32").value = Number(midMaxTemp);
      if(outMaxTemp!==null) ws.getCell("J44").value = Number(outMaxTemp);

      ws.getCell("I3").value = Number(projectCode);
      ws.getCell("I4").value = Number(orderNo);
      ws.getCell("D4").value = jalaliDate;
      ws.getCell("F4").value = Number(totalMass);

      ws.getCell("D8").value = Number(thInner);
      ws.getCell("G8").value = Number(thMid);
      ws.getCell("J8").value = Number(thOuter);

      if(width) ws.getCell("C7").value = width;

      // Notes blocks
      ws.getCell("D23").value = readText("noteInner");
      ws.getCell("D35").value = readText("noteMid");
      ws.getCell("D47").value = readText("noteOuter");

      function fillLayer(namePrefix, pctPrefix, nameRowA, pctRowA, nameRowB, pctRowB, colStart){
        const cols = ["D","E","F","G","H","I"];
        for(let i=1;i<=12;i++){
          const name = readText(`${namePrefix}Name${i}`).trim();
          const pct = readText(`${pctPrefix}Pct${i}`).trim();
          const pctDec = pctToDecimal(pct);

          // map 1..6 to first block, 7..12 to second block
          const block = (i<=6) ? 0 : 1;
          const idx = (i<=6) ? (i-1) : (i-7);
          const col = cols[idx];

          const rName = block===0 ? nameRowA : nameRowB;
          const rPct  = block===0 ? pctRowA  : pctRowB;

          ws.getCell(`${col}${rName}`).value = name || null;
          // percent cells in template are percent-formatted; use decimal storage (e.g. 0.25)
          ws.getCell(`${col}${rPct}`).value = (pctDec===null ? null : pctDec);
        }
      }

      // Inner: names rows 16 & 20; percents rows 17 & 21
      fillLayer("in", "in", 16,17,20,21);
      // Middle: names 28 & 32; percents 29 & 33
      fillLayer("mid", "mid", 28,29,32,33);
      // Outer: names 40 & 44; percents 41 & 45
      fillLayer("out", "out", 40,41,44,45);

// File name
      const jalaliForFile = formatShortJalaliForFile(jalaliDate);

      const ctxSubCode = (document.getElementById("ctxSubCode").value || "").trim();
      const subTag = (ctxSubCode ? ctxSubCode.replaceAll("-", "") : String(projectCode || "")).trim();
      const prefix = subTag ? (subTag + "-") : "";
      const verTwo = String(orderNo).padStart(2, "0");
      const fileName = `${prefix ? (prefix + " ") : ""}SF${verTwo} ${jalaliForFile}.xlsx`;
      const buf = await wb.xlsx.writeBuffer();
      const blob = new Blob([buf], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      // Server duplicate (same rules as PI/SCF)
      try {
        await uploadDuplicate(buf, fileName);
        await refreshNextSffFromServer(false);
      } catch(e) {
        console.error(e);
      }

      setStatus(`File "${fileName}" created. Download started.`);
    } catch(err) {
      console.error(err);
      setStatus(err.message || String(err), true);
    }
  }

  async function resetForm(){
    if(!confirm("Reset the form?")) return;
    document.querySelectorAll("input, textarea").forEach(el=>el.value="");
    setStatus("");
    await refreshNextSffFromServer(false);
  }

  // PMA context prefill (projectCode)
  function sff_applyContext(ctx){
    if(!ctx) return;
    const codeEl = document.getElementById("projectCode");
    if(codeEl && ctx.projectCode){
      if(!codeEl.value || String(codeEl.value).trim()==="") codeEl.value = ctx.projectCode;
      const note = document.getElementById("pmaPrefillNote");
      if(note) note.style.display="block";
    }
    try{ localStorage.setItem("PMA_CONTEXT", JSON.stringify(ctx)); }catch(e){}
  }
  function sff_readUrlContext(){
    try{
      const params = new URLSearchParams(window.location.search || "");
      const ctx = {
        projectCode: params.get("projectCode") || "",
        subType: params.get("type") || "",
        projectName: params.get("name") || "",
        customerName: params.get("customer") || ""
      };
      if(ctx.projectCode || ctx.subType || ctx.projectName || ctx.customerName) return ctx;
    }catch(e){}
    return null;
  }

  document.addEventListener("DOMContentLoaded", ()=>{
    document.getElementById("generateBtn").addEventListener("click", generate);
    document.getElementById("resetBtn").addEventListener("click", resetForm);

    // Auto-suggest next order/version based on server directory
    refreshNextSffFromServer(true);
    document.getElementById("projectCode").addEventListener("change", ()=>refreshNextSffFromServer(true));

    const urlCtx = sff_readUrlContext();
    if(urlCtx) sff_applyContext(urlCtx);
    try {
      const raw = localStorage.getItem("PMA_CONTEXT");
      if(raw) sff_applyContext(JSON.parse(raw));
    } catch(e){}

    window.addEventListener("message", (ev)=>{
      if(ev && ev.data && ev.data.type==="PMA_CONTEXT") sff_applyContext(ev.data.payload);
    });
  });
</script>
<?php render_footer(); ?>
