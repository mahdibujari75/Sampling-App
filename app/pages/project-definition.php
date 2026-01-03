<?php
/************************************************************
 * PROJECT DEFINITION (Admin Only)
 * - Define new projects
 * - Link each project to a Client username
 * - Auto-create folders under /database/projects/<customerSlug>/<code-type>/
 * File: public_html/app/pages/project-definition.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Auth Guard
 ************************************************************/
if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_admin();

/************************************************************
 * SECTION 2 — Shared Layout
 ************************************************************/
require_once APP_ROOT . "/includes/layout.php";

/************************************************************
 * SECTION 3 — Configuration (DB Paths)
 ************************************************************/
$USERS_FILE    = USERS_DB_FILE;
$PROJECTS_FILE = PROJECTS_DB_FILE;
$PROJECTS_DIR  = PROJECTS_ROOT_DIR;

/************************************************************
 * SECTION 4 — Helpers
 ************************************************************/
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function slugify(string $s): string {
  $s = trim($s);
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim($s, '-');
  return $s !== '' ? $s : 'customer';
}

function normalize_type(string $t): string {
  $t = strtoupper(trim($t));
  return in_array($t, ['F','C','O'], true) ? $t : 'C';
}

function load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: "[]", true);
  return is_array($data) ? $data : [];
}

function save_json_array_atomic(string $file, array $data): void {
  ensure_dir(dirname($file));
  $tmp = $file . ".tmp";
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  rename($tmp, $file);
}

function next_id(array $items): int {
  $max = 0;
  foreach ($items as $it) {
    $id = (int)($it["id"] ?? 0);
    if ($id > $max) $max = $id;
  }
  return $max + 1;
}

/************************************************************
 * SECTION 5 — CSRF
 ************************************************************/
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION["csrf_project_def"])) {
  $_SESSION["csrf_project_def"] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION["csrf_project_def"];

function require_csrf(string $expected): void {
  $posted = (string)($_POST["csrf"] ?? "");
  if (!$posted || !hash_equals($expected, $posted)) {
    http_response_code(400);
    echo "Bad request (CSRF).";
    exit;
  }
}

/************************************************************
 * SECTION 6 — Load Clients (from users.json)
 * Only role == "Client" appears in dropdown.
 ************************************************************/
$users = load_json_array($USERS_FILE);

$clients = [];
foreach ($users as $u) {
  if (($u["role"] ?? "") === "Client") {
    $uname = trim((string)($u["username"] ?? ""));
    if ($uname !== "") $clients[] = $uname;
  }
}
sort($clients);

/************************************************************
 * SECTION 7 — Folder Builder
 * /database/projects/<customerSlug>/<302-C>/(PI/SCF/SFF/Logs/Attachments...)
 ************************************************************/
function build_project_dirs(string $projectsRoot, string $customerSlug, string $code, string $type): array {
  $code = trim($code);
  $type = normalize_type($type);

  $projectKey = "{$code}-{$type}";
  $base = rtrim($projectsRoot, "/") . "/" . $customerSlug . "/" . $projectKey;

  $folders = [
    $base,
    $base . "/PI",
    $base . "/SCF",
    $base . "/SFF",
    $base . "/Logs",
    $base . "/Attachments",
    $base . "/Attachments/Incoming",
    $base . "/Attachments/Outgoing",
  ];

  foreach ($folders as $dir) ensure_dir($dir);

  return [$projectKey, $base];
}

/************************************************************
 * SECTION 8 — Load Existing Projects
 ************************************************************/
$projects = load_json_array($PROJECTS_FILE);

/************************************************************
 * SECTION 9 — Handle Actions
 ************************************************************/
$flash_ok = "";
$flash_err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  require_csrf($CSRF);

  $action = trim((string)($_POST["action"] ?? ""));

  /**********************
   * ACTION: Create Project
   **********************/
  if ($action === "create") {
    $projectName = trim((string)($_POST["projectName"] ?? ""));
    $code        = trim((string)($_POST["code"] ?? ""));
    $type        = normalize_type((string)($_POST["type"] ?? "C"));
    $customerU   = trim((string)($_POST["customerUsername"] ?? ""));

    if ($projectName === "" || $code === "" || $customerU === "") {
      $flash_err = "Project Name, Code, and Customer are required.";
    } else {
      // Validate customer exists in clients
      if (!in_array($customerU, $clients, true)) {
        $flash_err = "Selected customer is not a valid Client user.";
      } else {
        $customerSlug = slugify($customerU);
        ensure_dir($PROJECTS_DIR);

        [$projectKey, $baseDir] = build_project_dirs($PROJECTS_DIR, $customerSlug, $code, $type);

        // Prevent duplicates: same customerUsername + projectKey
        foreach ($projects as $p) {
          if (
            (string)($p["customerUsername"] ?? "") === $customerU &&
            (string)($p["projectKey"] ?? "") === $projectKey
          ) {
            $flash_err = "This customer already has the same Code+Type project (duplicate).";
            break;
          }
        }

        if ($flash_err === "") {
          $projects[] = [
            "id" => next_id($projects),

            // Customer link
            "customerUsername" => $customerU,
            "customerSlug" => $customerSlug,

            // Project definition
            "projectName" => $projectName,
            "code" => $code,
            "type" => $type,
            "projectKey" => $projectKey,

            // State tracking (admin can change later)
            "status" => "Active",
            "step" => 0,

            // Storage
            "baseDir" => $baseDir,

            // Meta
            "createdAt" => date("Y-m-d H:i:s"),
            "updatedAt" => date("Y-m-d H:i:s"),
          ];

          save_json_array_atomic($PROJECTS_FILE, $projects);
          $projects = load_json_array($PROJECTS_FILE);
          $flash_ok = "Project created and folders generated.";
        }
      }
    }
  }

  /**********************
   * ACTION: Remove Project from list (keeps folders)
   **********************/
  if ($action === "delete") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
      $flash_err = "Invalid project ID.";
    } else {
      $new = [];
      $found = false;
      foreach ($projects as $p) {
        if ((int)($p["id"] ?? 0) === $id) {
          $found = true;
          continue;
        }
        $new[] = $p;
      }
      if (!$found) {
        $flash_err = "Project not found.";
      } else {
        $projects = $new;
        save_json_array_atomic($PROJECTS_FILE, $projects);
        $projects = load_json_array($PROJECTS_FILE);
        $flash_ok = "Project removed from list (folders kept).";
      }
    }
  }
}

/************************************************************
 * SECTION 10 — Render
 ************************************************************/
$me = current_user();
render_header("Project Definition", "Admin — " . ($me["username"] ?? "Admin"));

if ($flash_ok) echo '<div class="flash ok">'.h($flash_ok).'</div>';
if ($flash_err) echo '<div class="flash err">'.h($flash_err).'</div>';
?>

<!-- =======================================================
     SECTION A — Create New Project
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Create new project</h2>
    <div class="hint">Folders auto-created under: <?= h($PROJECTS_DIR) ?></div>
  </div>

  <?php if (count($clients) === 0): ?>
    <div class="flash err" style="margin-top:10px;">
      No Client users found in users.json. Create Client users first, then return here.
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:10px;">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
    <input type="hidden" name="action" value="create">

    <div class="grid">
      <div>
        <label>Project Name</label>
        <input name="projectName" placeholder="Mozzarella" required>
        <div class="hint">This is the project title (different from customer name).</div>
      </div>

      <div>
        <label>Customer (Client Username)</label>
        <select name="customerUsername" required>
          <option value="" selected disabled>Select a customer...</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= h($c) ?>"><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Projects will be linked to the selected Client username.</div>
      </div>

      <div>
        <label>Project Code</label>
        <input name="code" placeholder="302" required>
        <div class="hint">Used for folder: &lt;302-C&gt;</div>
      </div>

      <div>
        <label>Subproject Type</label>
        <select name="type">
          <option value="F">F</option>
          <option value="C" selected>C</option>
          <option value="O">O</option>
        </select>
        <div class="hint">F / C / O</div>
      </div>

      <div style="display:flex;align-items:flex-end;">
        <button class="btn" type="submit" <?= count($clients)===0 ? "disabled" : "" ?>>Create project</button>
      </div>
    </div>
  </form>
</div>

<!-- =======================================================
     SECTION B — Existing Projects
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Defined projects</h2>
    <div class="hint">DB: <?= h($PROJECTS_FILE) ?> | Count: <?= count($projects) ?></div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Project Name</th>
        <th style="width:160px;">Customer</th>
        <th style="width:90px;">Code</th>
        <th style="width:80px;">Type</th>
        <th style="width:120px;">Key</th>
        <th>Folder</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($projects as $p): ?>
        <tr>
          <td><?= h((string)($p["id"] ?? "")) ?></td>
          <td><?= h((string)($p["projectName"] ?? "")) ?></td>
          <td><?= h((string)($p["customerUsername"] ?? "")) ?></td>
          <td><?= h((string)($p["code"] ?? "")) ?></td>
          <td><span class="pill"><?= h((string)($p["type"] ?? "")) ?></span></td>
          <td><?= h((string)($p["projectKey"] ?? "")) ?></td>
          <td class="hint"><?= h((string)($p["baseDir"] ?? "")) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Remove this project from list? (folders remain)');">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)($p["id"] ?? 0)) ?>">
              <button class="btn btn-danger" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (count($projects) === 0): ?>
        <tr><td colspan="8" class="hint">No projects defined yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
