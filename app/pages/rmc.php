<?php
/************************************************************
 * RMC Generator — rmc.php
 * Location: public_html/app/pages/rmc.php
 ************************************************************/

if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/..")); // app/
}

require_once APP_ROOT . "/includes/auth.php";
require_login();

require_once APP_ROOT . "/includes/layout.php";

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
  return $s ?: "RMC.xlsx";
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

function subcode_to_prefix(string $subCode): string {
  // 302-C => 302C
  return preg_replace('/[^0-9A-Za-z]/', '', $subCode);
}

function extract_projectcode_3digits(string $subCode): string {
  $parts = explode("-", $subCode);
  $p = preg_replace('/\D/', '', (string)($parts[0] ?? ""));
  $p = substr($p, 0, 3);
  return str_pad($p, 3, "0", STR_PAD_LEFT);
}

/* ---------------- Project Context ---------------- */
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
    "prefix"      => "",
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
  $ctx["prefix"]      = subcode_to_prefix($subCode);
  $ctx["projectCode"] = extract_projectcode_3digits($subCode);
  $ctx["baseDir"]     = (string)($p["baseDir"] ?? "");

  return $ctx;
}

function sf_dir_from_ctx(array $ctx): string {
  $baseDir = trim((string)($ctx["baseDir"] ?? ""));
  $type = strtoupper(trim((string)($ctx["type"] ?? "")));

  $folder = ($type === "F") ? "SFF" : "SCF";

  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/" . $folder;
  }

  $clientSlug = (string)($ctx["clientSlug"] ?? "");
  $subCode = (string)($ctx["subCode"] ?? "");
  if ($clientSlug === "" || $subCode === "") return "";

  $publicRoot = realpath(APP_ROOT . "/.."); // public_html
  $root = rtrim($publicRoot ?: (APP_ROOT . "/.."), "/");
  return $root . "/database/projects/" . $clientSlug . "/" . $subCode . "/" . $folder;
}

function rmc_dir_from_ctx(array $ctx): string {
  $baseDir = trim((string)($ctx["baseDir"] ?? ""));
  if ($baseDir !== "") {
    return rtrim($baseDir, "/") . "/RMC";
  }

  $clientSlug = (string)($ctx["clientSlug"] ?? "");
  $subCode = (string)($ctx["subCode"] ?? "");
  if ($clientSlug === "" || $subCode === "") return "";

  $publicRoot = realpath(APP_ROOT . "/.."); // public_html
  $root = rtrim($publicRoot ?: (APP_ROOT . "/.."), "/");
  return $root . "/database/projects/" . $clientSlug . "/" . $subCode . "/RMC";
}

function list_sf_files(string $dir, string $prefix): array {
  if ($dir === "" || !is_dir($dir)) return [];
  $files = @glob(rtrim($dir, "/") . "/*.xlsx") ?: [];
  $out = [];

  foreach ($files as $path) {
    $name = basename($path);

    // Expected: 302C- SF02 04.10.05.xlsx
    if (preg_match('/^' . preg_quote($prefix, '/') . '\-\s+SF(\d{2})\s+(\d{2}\.\d{2}\.\d{2})\.xlsx$/i', $name, $m)) {
      $ver = $m[1];
      $dateShort = $m[2];
      $out[] = [
        "file" => $name,
        "ver" => $ver,
        "dateShort" => $dateShort,
        "label" => "SF" . $ver . "  " . $dateShort,
      ];
      continue;
    }

    // Backward-compatible: 302C- SF02 1404.10.05.xlsx
    if (preg_match('/^' . preg_quote($prefix, '/') . '\-\s+SF(\d{2})\s+(\d{4}\.\d{2}\.\d{2})\.xlsx$/i', $name, $m)) {
      $ver = $m[1];
      $d = $m[2];
      $dateShort = substr($d, 2); // 1404.10.05 -> 04.10.05
      $out[] = [
        "file" => $name,
        "ver" => $ver,
        "dateShort" => $dateShort,
        "label" => "SF" . $ver . "  " . $dateShort,
      ];
    }
  }

  usort($out, function($a, $b) { return strcmp($a["ver"], $b["ver"]); });
return $out;
}

/* ---------------- AJAX: list SF files ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "list_sf") {
  header("Content-Type: application/json; charset=utf-8");
  $projectId = (int)($_POST["projectId"] ?? 0);

  $ctx = resolve_ctx($projectId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);
  $sfDir = sf_dir_from_ctx($ctx);
  $list = list_sf_files($sfDir, (string)($ctx["prefix"] ?? ""));

  echo json_encode([
    "ok" => true,
    "sfDir" => $sfDir,
    "type" => (string)($ctx["type"] ?? ""),
    "prefix" => (string)($ctx["prefix"] ?? ""),
    "items" => $list,
  ]);
  exit;
}

/* ---------------- GET: download selected SF file (binary) ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "GET" && (string)($_GET["action"] ?? "") === "get_sf") {
  $projectId = (int)($_GET["projectId"] ?? 0);
  $file = safe_filename((string)($_GET["file"] ?? ""));

  $ctx = resolve_ctx($projectId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);
  $sfDir = sf_dir_from_ctx($ctx);
  if ($sfDir === "" || !is_dir($sfDir)) {
    http_response_code(404);
    echo "SF directory not found.";
    exit;
  }

  $full = rtrim($sfDir, "/") . "/" . $file;
  if (!file_exists($full) || !is_file($full)) {
    http_response_code(404);
    echo "File not found.";
    exit;
  }

  header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
  header("Content-Disposition: attachment; filename=\"" . basename($full) . "\"");
  header("Content-Length: " . filesize($full));
  readfile($full);
  exit;
}

/* ---------------- AJAX: upload duplicate RMC ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["action"] ?? "") === "upload_rmc") {
  header("Content-Type: application/json; charset=utf-8");

  $projectId = (int)($_POST["projectId"] ?? 0);
  $fileName = safe_filename((string)($_POST["fileName"] ?? ""));

  $ctx = resolve_ctx($projectId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);
  $targetDir = rmc_dir_from_ctx($ctx);

  if ($targetDir === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing project context (cannot resolve RMC directory)."]);
    exit;
  }

  if (!isset($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No file uploaded."]);
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
$ctx = resolve_ctx($viewId, $PROJECTS_FILE, $isAdmin, $isObserver, $isClient, $myUsername);

render_header("RMC Generator", $role);
?>

<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Raw Material Check (RMC)</h2>
      <div class="hint">Select an existing SF file (SCF/SFF) and generate an RMC Excel file based on it.</div>
    </div>
    <div class="pill">
      Project: <strong><?php echo h((string)($ctx["subCode"] ?? "")); ?></strong>
      &nbsp;|&nbsp; Type: <strong><?php echo h((string)($ctx["type"] ?? "")); ?></strong>
    </div>
  </div>
</div>

<div class="card">
  <h2>Context</h2>
  <div class="grid">
    <div>
      <label class="hint" style="font-weight:700;">Customer Name (editable)</label>
      <input id="customerName" type="text" value="<?php echo h((string)($ctx["clientSlug"] ?? "")); ?>" />
      <div class="hint" style="margin-top:6px;">Used for cell E3 in RMC template.</div>
    </div>
    <div>
      <label class="hint" style="font-weight:700;">Project Prefix</label>
      <input id="prefix" type="text" value="<?php echo h((string)($ctx["prefix"] ?? "")); ?>" readonly />
      <div class="hint" style="margin-top:6px;">Used in file naming: 302C- RMC01 04.10.05.xlsx</div>
    </div>
    <div>
      <label class="hint" style="font-weight:700;">Source Folder</label>
      <input id="sfFolder" type="text" value="" readonly />
      <div class="hint" style="margin-top:6px;">Auto detected: SCF for type C, SFF for type F.</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div>
      <h2 style="margin:0;">Select SF File</h2>
      <div class="hint">If no SF file exists, generate SCF/SFF first (Step 5) then return here (Step 7).</div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <button class="btn btn-ghost" id="refreshBtn" type="button">Refresh</button>
      <button class="btn" id="generateBtn" type="button">Generate RMC</button>
    </div>
  </div>

  <div style="margin-top:12px;" class="grid">
    <div>
      <label class="hint" style="font-weight:700;">Available SF versions</label>
      <select id="sfSelect"></select>
      <div id="sfNote" class="hint" style="margin-top:6px;"></div>
    </div>
    <div>
      <label class="hint" style="font-weight:700;">Detected Output Name</label>
      <input id="outName" type="text" readonly />
      <div class="hint" style="margin-top:6px;">This name is used for both download and server duplicate.</div>
    </div>
  </div>

  <div id="previewWrap" style="margin-top:14px; display:none;">
    <h2>Preview (extracted materials)</h2>
    <div class="hint" style="margin-bottom:10px;">Material names + required kg extracted from the selected SF.</div>
    <div style="overflow:auto;">
      <table style="width:100%; border-collapse:collapse; min-width:520px;">
        <thead>
          <tr>
            <th style="text-align:left; border-bottom:1px solid rgba(0,0,0,0.08); padding:8px;">#</th>
            <th style="text-align:left; border-bottom:1px solid rgba(0,0,0,0.08); padding:8px;">Material</th>
            <th style="text-align:left; border-bottom:1px solid rgba(0,0,0,0.08); padding:8px;">Required (kg)</th>
          </tr>
        </thead>
        <tbody id="previewBody"></tbody>
      </table>
    </div>
  </div>

  <div id="status" class="hint" style="margin-top:12px;"></div>

  <input type="hidden" id="ctxProjectId" value="<?php echo h((string)($ctx["projectId"] ?? 0)); ?>">
  <input type="hidden" id="ctxType" value="<?php echo h((string)($ctx["type"] ?? "")); ?>">
</div>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js"></script>
<script>
const RMC_TEMPLATE_B64 = "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";

function setStatus(msg, isErr=false){
  const el=document.getElementById("status");
  el.textContent = msg || "";
  el.style.color = isErr ? "#b91c1c" : "";
}

function b64ToU8(b64){
  const bin = atob(b64);
  const len = bin.length;
  const out = new Uint8Array(len);
  for(let i=0;i<len;i++) out[i] = bin.charCodeAt(i);
  return out;
}

function cellText(v){
  if(v === null || v === undefined) return "";
  if(typeof v === "string") return v.trim();
  if(typeof v === "number") return String(v);
  if(typeof v === "object"){
    if(v.text) return String(v.text).trim();
    if(Array.isArray(v.richText)) return v.richText.map(x=>x.text||"").join("").trim();
    if(v.result !== undefined && v.result !== null) return String(v.result).trim();
  }
  return String(v).trim();
}

function cellNumber(v){
  if(v === null || v === undefined) return null;
  if(typeof v === "number" && isFinite(v)) return v;
  if(typeof v === "string"){
    const n = Number(v.trim());
    return isFinite(n) ? n : null;
  }
  if(typeof v === "object"){
    if(typeof v.result === "number" && isFinite(v.result)) return v.result;
    if(typeof v.result === "string"){
      const n = Number(v.result.trim());
      return isFinite(n) ? n : null;
    }
  }
  return null;
}

function pad2(n){ return String(n).padStart(2,"0"); }

function normalizeDateShortFromFull(full){
  if(!full) return "";
  const s = String(full).trim();
  const m1 = s.match(/^(\d{4})[\/\.-](\d{1,2})[\/\.-](\d{1,2})$/);
  if(m1){
    const yy = m1[1].slice(2);
    const mm = pad2(m1[2]);
    const dd = pad2(m1[3]);
    return `${yy}.${mm}.${dd}`;
  }
  const m2 = s.match(/^(\d{2})[\/\.-](\d{2})[\/\.-](\d{2})$/);
  if(m2) return `${m2[1]}.${m2[2]}.${m2[3]}`;
  return s.replaceAll("/",".").replaceAll("-",".");
}

function normalizeDateFullFromAny(s){
  const t = String(s||"").trim();
  const m1 = t.match(/^(\d{4})[\/\.-](\d{1,2})[\/\.-](\d{1,2})$/);
  if(m1) return `${m1[1]}/${pad2(m1[2])}/${pad2(m1[3])}`;
  const m2 = t.match(/^(\d{2})[\/\.-](\d{2})[\/\.-](\d{2})$/);
  if(m2) return `14${m2[1]}/${m2[2]}/${m2[3]}`;
  return t;
}

async function apiListSf(){
  const fd = new FormData();
  fd.append("action","list_sf");
  fd.append("projectId",(document.getElementById("ctxProjectId").value||"").trim());
  const res = await fetch(window.location.pathname + window.location.search, { method:"POST", body: fd });
  const j = await res.json().catch(()=>null);
  if(!res.ok || !j || !j.ok) throw new Error("Failed to list SF files.");
  return j;
}

async function fetchSfFile(projectId, fileName){
  const url = new URL(window.location.href);
  url.searchParams.set("action","get_sf");
  url.searchParams.set("projectId", projectId);
  url.searchParams.set("file", fileName);
  const res = await fetch(url.toString(), { method:"GET" });
  if(!res.ok) throw new Error("Cannot read selected SF file from server.");
  return await res.arrayBuffer();
}

function renderPreview(items){
  const wrap = document.getElementById("previewWrap");
  const body = document.getElementById("previewBody");
  body.innerHTML = "";
  if(!items || !items.length){
    wrap.style.display = "none";
    return;
  }
  wrap.style.display = "";
  items.forEach((it, idx)=>{
    const tr=document.createElement("tr");
    tr.innerHTML = `
      <td style="padding:8px; border-bottom:1px solid rgba(0,0,0,0.06);">${idx+1}</td>
      <td style="padding:8px; border-bottom:1px solid rgba(0,0,0,0.06);">${it.name}</td>
      <td style="padding:8px; border-bottom:1px solid rgba(0,0,0,0.06);">${(it.qty===null||it.qty===undefined)?"":it.qty}</td>
    `;
    body.appendChild(tr);
  });
}

async function extractFromScf(sfWb){
  const ws = sfWb.getWorksheet("Sheet1") || sfWb.worksheets[0];
  const cols = ["D","E","F","G","H","I"];
  const items = [];
  for(let i=0;i<cols.length;i++){
    const name = cellText(ws.getCell(cols[i]+"13").value);
    const qty  = cellNumber(ws.getCell(cols[i]+"15").value);
    if(name && qty!==null && qty!==0) items.push({ name, qty });
  }
  return {
    items,
    dateFull: cellText(ws.getCell("D3").value)
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
    for(let i=0;i<6;i++){
      const nameA = cellText(ws.getCell(cols[i]+String(namesRowA)).value);
      const pctA  = cellNumber(ws.getCell(cols[i]+String(pctRowA)).value);
      if(nameA && pctA!==null && pctA!==0) out.push({name:nameA, qty: layerMass * pctA });
      const nameB = cellText(ws.getCell(cols[i]+String(namesRowB)).value);
      const pctB  = cellNumber(ws.getCell(cols[i]+String(pctRowB)).value);
      if(nameB && pctB!==null && pctB!==0) out.push({name:nameB, qty: layerMass * pctB });
    }
    return out;
  }

  const massIn  = totalMass * (thIn  / thTot);
  const massMid = totalMass * (thMid / thTot);
  const massOut = totalMass * (thOut / thTot);

  let raw = [];
  raw = raw.concat(collectLayer(16,17,20,21, massIn));
  raw = raw.concat(collectLayer(28,29,32,33, massMid));
  raw = raw.concat(collectLayer(40,41,44,45, massOut));

  const map = new Map();
  for(const it of raw){
    const key = it.name.trim();
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

function buildOutName(prefix, rmcVer, dateShort){
  return `${prefix}- RMC${rmcVer} ${dateShort}.xlsx`;
}

function sfBaseId(prefix, sfVer){
  return `${prefix}- SF${sfVer}`;
}

async function uploadDuplicate(projectId, fileName, buffer){
  const blob = new Blob([buffer], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
  const fd = new FormData();
  fd.append("action","upload_rmc");
  fd.append("projectId", projectId);
  fd.append("fileName", fileName);
  fd.append("file", blob, fileName);
  const res = await fetch(window.location.pathname + window.location.search, { method:"POST", body: fd });
  const j = await res.json().catch(()=>null);
  if(!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : "Upload failed.");
  return j.savedTo || "";
}

async function generateRmc(){
  try{
    setStatus("Working...");
    const projectId = (document.getElementById("ctxProjectId").value||"").trim();
    if(!projectId || projectId==="0") throw new Error("Missing project context (open from Projects page).");

    const sfSelect = document.getElementById("sfSelect");
    const selected = sfSelect.value || "";
    if(!selected) throw new Error("No SF file selected.");

    const type = (document.getElementById("ctxType").value||"").trim().toUpperCase();
    const prefix = (document.getElementById("prefix").value||"").trim();
    const customerName = (document.getElementById("customerName").value||"").trim();

    const m = selected.match(/\bSF(\d{2})\b/i);
    if(!m) throw new Error("Selected SF file name does not contain SF##.");
    const sfVer = m[1];

    const sfBuf = await fetchSfFile(projectId, selected);

    const sfWb = new ExcelJS.Workbook();
    await sfWb.xlsx.load(sfBuf);

    let ex;
    if(type === "F") ex = await extractFromSff(sfWb);
    else ex = await extractFromScf(sfWb);

    const dateFull = normalizeDateFullFromAny(ex.dateFull);
    const dateShort = normalizeDateShortFromFull(dateFull);

    const items = (ex.items || []).filter(x => x.name && x.qty!==null && x.qty!==0);
    if(!items.length) {
      setStatus("Selected SF contains no materials/quantities to extract.", true);
      renderPreview([]);
      return;
    }

    renderPreview(items.map(x => ({ name:x.name, qty: (typeof x.qty==="number" ? Math.round(x.qty*1000)/1000 : x.qty) })));

    const rmcWb = new ExcelJS.Workbook();
    await rmcWb.xlsx.load(b64ToU8(RMC_TEMPLATE_B64));
    const rws = rmcWb.getWorksheet("Page 1") || rmcWb.worksheets[0];

    rws.getCell("B2").value = `${prefix}- RMC${sfVer}`;
    rws.getCell("E3").value = customerName || "";
    rws.getCell("H3").value = sfBaseId(prefix, sfVer);
    rws.getCell("J3").value = dateFull;

    for(let i=0;i<12;i++) {
      rws.getCell(`C${6+i}`).value = null;
      rws.getCell(`E${6+i}`).value = null;
    }
    const max = Math.min(12, items.length);
    for(let i=0;i<max;i++) {
      rws.getCell(`C${6+i}`).value = items[i].name;
      const q = items[i].qty;
      rws.getCell(`E${6+i}`).value = (typeof q==="number" && isFinite(q)) ? q : null;
    }

    const outName = buildOutName(prefix, sfVer, dateShort || "00.00.00");
    document.getElementById("outName").value = outName;

    const outBuf = await rmcWb.xlsx.writeBuffer();

    const blob = new Blob([outBuf], {type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = outName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    try {
      await uploadDuplicate(projectId, outName, outBuf);
    } catch(e) {
      console.error(e);
    }

    setStatus(`RMC generated: ${outName}`);
  } catch(err) {
    console.error(err);
    setStatus(err.message || String(err), true);
  }
}

async function refreshList(){
  try {
    setStatus("Loading SF list...");
    const j = await apiListSf();
    document.getElementById("sfFolder").value = j.sfDir || "";
    const items = j.items || [];
    const sel = document.getElementById("sfSelect");
    sel.innerHTML = "";
    if(!items.length) {
      document.getElementById("sfNote").textContent = "No SF files found in the database for this project.";
      document.getElementById("outName").value = "";
      renderPreview([]);
      setStatus("No SF file generated yet.", true);
      return;
    }
    document.getElementById("sfNote").textContent = `${items.length} SF file(s) found. Select one to generate RMC.`;
    items.forEach(it => {
      const opt = document.createElement("option");
      opt.value = it.file;
      opt.textContent = it.label;
      sel.appendChild(opt);
    });

    await onSelectChanged();
    setStatus("");
  } catch(err) {
    console.error(err);
    setStatus(err.message || String(err), true);
  }
}

async function onSelectChanged(){
  try {
    const projectId = (document.getElementById("ctxProjectId").value||"").trim();
    const selFile = (document.getElementById("sfSelect").value||"").trim();
    if(!selFile) return;

    const m = selFile.match(/\bSF(\d{2})\b/i);
    const sfVer = m ? m[1] : "01";

    const prefix = (document.getElementById("prefix").value||"").trim();
    let dateShort = "";
    const md = selFile.match(/\b(\d{2}\.\d{2}\.\d{2})\b/);
    if(md) dateShort = md[1];

    document.getElementById("outName").value = buildOutName(prefix, sfVer, dateShort || "00.00.00");

    const type = (document.getElementById("ctxType").value||"").trim().toUpperCase();
    const sfBuf = await fetchSfFile(projectId, selFile);
    const sfWb = new ExcelJS.Workbook();
    await sfWb.xlsx.load(sfBuf);

    let ex;
    if(type === "F") ex = await extractFromSff(sfWb);
    else ex = await extractFromScf(sfWb);

    const items = (ex.items || []).filter(x => x.name && x.qty!==null && x.qty!==0);
    renderPreview(items.map(x => ({ name:x.name, qty: (typeof x.qty==="number" ? Math.round(x.qty*1000)/1000 : x.qty) })));

    const dateFull = normalizeDateFullFromAny(ex.dateFull);
    const dateShort2 = normalizeDateShortFromFull(dateFull) || dateShort;
    document.getElementById("outName").value = buildOutName(prefix, sfVer, dateShort2 || "00.00.00");
  } catch(e) {
    console.error(e);
  }
}

document.addEventListener("DOMContentLoaded", ()=>{
  document.getElementById("refreshBtn").addEventListener("click", refreshList);
  document.getElementById("generateBtn").addEventListener("click", generateRmc);
  document.getElementById("sfSelect").addEventListener("change", onSelectChanged);

  refreshList();
});
</script>

<?php render_footer(); ?>
