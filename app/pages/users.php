<?php
/************************************************************
 * USERS PAGE — Clean, minimal, aligned (no DB paths shown)
 * File: public_html/app/pages/users.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Auth Guard
 ************************************************************/
require_once APP_ROOT . "/includes/auth.php";
require_admin(); // Admin-only

/************************************************************
 * SECTION 2 — Shared Layout
 ************************************************************/
require_once APP_ROOT . "/includes/layout.php";

/************************************************************
 * SECTION 3 — Helpers
 ************************************************************/
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

function load_users(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: "[]", true);
  return is_array($data) ? $data : [];
}

function save_users_atomic(string $file, array $data): void {
  $dir = dirname($file);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $tmp = $file . ".tmp";
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  rename($tmp, $file);
}

function next_user_id(array $users): int {
  $max = 0;
  foreach ($users as $u) {
    $id = (int)($u["id"] ?? 0);
    if ($id > $max) $max = $id;
  }
  return $max + 1;
}

function normalize_role(string $r): string {
  $r = trim($r);
  $allowed = ["Admin","Observer","Client"];
  return in_array($r, $allowed, true) ? $r : "Observer";
}

function username_exists(array $users, string $username, ?int $excludeId = null): bool {
  foreach ($users as $u) {
    if ((string)($u["username"] ?? "") === $username) {
      if ($excludeId !== null && (int)($u["id"] ?? 0) === $excludeId) continue;
      return true;
    }
  }
  return false;
}

function parse_allowed_projects(string $csv): array {
  $csv = trim($csv);
  if ($csv === "") return [];
  $parts = array_map("trim", explode(",", $csv));
  $parts = array_values(array_filter($parts, fn($x) => $x !== ""));
  // Remove duplicates
  $uniq = [];
  foreach ($parts as $p) $uniq[$p] = true;
  return array_keys($uniq);
}

function allowed_projects_to_csv($arr): string {
  if (!is_array($arr)) return "";
  return implode(", ", array_map("strval", $arr));
}

/************************************************************
 * SECTION 4 — CSRF
 ************************************************************/
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION["csrf_users"])) {
  $_SESSION["csrf_users"] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION["csrf_users"];

function require_csrf(string $expected): void {
  $posted = (string)($_POST["csrf"] ?? "");
  if (!$posted || !hash_equals($expected, $posted)) {
    http_response_code(400);
    echo "Bad request (CSRF).";
    exit;
  }
}

/************************************************************
 * SECTION 5 — Load Users DB
 ************************************************************/
$USERS_FILE = USERS_DB_FILE;
$users = load_users($USERS_FILE);

/************************************************************
 * SECTION 6 — Handle Actions
 ************************************************************/
$flash_ok = "";
$flash_err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  require_csrf($CSRF);
  $action = trim((string)($_POST["action"] ?? ""));

  /**********************
   * ACTION: Add User
   **********************/
  if ($action === "add") {
    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");
    $role     = normalize_role((string)($_POST["role"] ?? "Observer"));

    // Optional client fields:
    $customerSlug = trim((string)($_POST["customerSlug"] ?? ""));
    $allowedCSV   = trim((string)($_POST["allowedProjects"] ?? ""));
    $allowedArr   = parse_allowed_projects($allowedCSV);

    if ($username === "" || $password === "") {
      $flash_err = "Username and password are required.";
    } elseif (username_exists($users, $username)) {
      $flash_err = "Username already exists.";
    } else {
      $users[] = [
        "id" => next_user_id($users),
        "username" => $username,
        "password" => $password, // NOTE: Plain text per your current approach
        "role" => $role,
        // Only meaningful for Client, but kept for all to avoid breaking schema
        "customerSlug" => $customerSlug,
        "allowedProjects" => $allowedArr,
      ];
      save_users_atomic($USERS_FILE, $users);
      $users = load_users($USERS_FILE);
      $flash_ok = "User created.";
    }
  }

  /**********************
   * ACTION: Update User
   **********************/
  if ($action === "update") {
    $id = (int)($_POST["id"] ?? 0);
    $username = trim((string)($_POST["username"] ?? ""));
    $role     = normalize_role((string)($_POST["role"] ?? "Observer"));

    // Password is optional on update
    $password = (string)($_POST["password"] ?? "");

    $customerSlug = trim((string)($_POST["customerSlug"] ?? ""));
    $allowedCSV   = trim((string)($_POST["allowedProjects"] ?? ""));
    $allowedArr   = parse_allowed_projects($allowedCSV);

    if ($id <= 0) {
      $flash_err = "Invalid user ID.";
    } elseif ($username === "") {
      $flash_err = "Username is required.";
    } elseif (username_exists($users, $username, $id)) {
      $flash_err = "Username already exists.";
    } else {
      $found = false;
      foreach ($users as &$u) {
        if ((int)($u["id"] ?? 0) === $id) {
          $u["username"] = $username;
          $u["role"] = $role;

          if ($password !== "") {
            $u["password"] = $password;
          }

          $u["customerSlug"] = $customerSlug;
          $u["allowedProjects"] = $allowedArr;

          $found = true;
          break;
        }
      }
      unset($u);

      if (!$found) {
        $flash_err = "User not found.";
      } else {
        save_users_atomic($USERS_FILE, $users);
        $users = load_users($USERS_FILE);
        $flash_ok = "User updated.";
      }
    }
  }

  /**********************
   * ACTION: Delete User
   **********************/
  if ($action === "delete") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
      $flash_err = "Invalid user ID.";
    } else {
      $new = [];
      $found = false;

      foreach ($users as $u) {
        if ((int)($u["id"] ?? 0) === $id) {
          $found = true;
          continue;
        }
        $new[] = $u;
      }

      if (!$found) {
        $flash_err = "User not found.";
      } else {
        $users = $new;
        save_users_atomic($USERS_FILE, $users);
        $users = load_users($USERS_FILE);
        $flash_ok = "User deleted.";
      }
    }
  }
}

/************************************************************
 * SECTION 7 — Render
 ************************************************************/
render_header("Users", "Admin");

if ($flash_ok)  echo '<div class="flash ok">'.h($flash_ok).'</div>';
if ($flash_err) echo '<div class="flash err">'.h($flash_err).'</div>';
?>

<style>
/* Local minimal alignment helpers (does not change global theme) */
.u-row{ display:flex; gap:12px; flex-wrap:wrap; align-items:stretch; }
.u-col{ flex:1 1 320px; min-width:320px; }
.u-grid{ display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:12px; }
@media (max-width: 900px){ .u-grid{ grid-template-columns: 1fr; } }
.u-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
.u-actions form{ margin:0; }
.u-note{ font-size:13px; color: rgba(0,0,0,.55); }
.u-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>

<!-- =======================================================
     SECTION A — Create User
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Create User</h2>
    <div class="hint">Add Admin / Observer / Client</div>
  </div>

  <form method="post" style="margin-top:10px;">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
    <input type="hidden" name="action" value="add">

    <div class="u-grid">
      <div>
        <label>Username</label>
        <input name="username" required placeholder="Borna">
      </div>

      <div>
        <label>Password</label>
        <input name="password" required placeholder="••••••" type="text">
        <div class="u-note">Current system uses plain text passwords (can be upgraded later).</div>
      </div>

      <div>
        <label>Role</label>
        <select name="role">
          <option value="Client">Client</option>
          <option value="Observer" selected>Observer</option>
          <option value="Admin">Admin</option>
        </select>
      </div>

      <div>
        <label>Customer Slug (optional)</label>
        <input name="customerSlug" placeholder="borna">
        <div class="u-note">Used for project folders if you want a specific slug.</div>
      </div>

      <div style="grid-column: span 2;">
        <label>Allowed Projects (optional)</label>
        <input name="allowedProjects" placeholder="302-C, 305-F">
        <div class="u-note">Comma-separated project keys (optional; your project filtering can use this later).</div>
      </div>
    </div>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Create</button>
    </div>
  </form>
</div>

<!-- =======================================================
     SECTION B — Users Table
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Users</h2>
    <div class="hint"><?= count($users) ?> user(s)</div>
  </div>

  <table style="margin-top:10px;">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Username</th>
        <th style="width:120px;">Role</th>
        <th style="width:170px;">Customer Slug</th>
        <th>Allowed Projects</th>
        <th style="width:220px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $id = (int)($u["id"] ?? 0);
          $username = (string)($u["username"] ?? "");
          $role = (string)($u["role"] ?? "Observer");
          $customerSlug = (string)($u["customerSlug"] ?? "");
          $allowedCSV = allowed_projects_to_csv($u["allowedProjects"] ?? []);
        ?>
        <tr>
          <td><?= h((string)$id) ?></td>
          <td><?= h($username) ?></td>
          <td><span class="pill"><?= h($role) ?></span></td>
          <td class="u-mono"><?= h($customerSlug) ?></td>
          <td><?= h($allowedCSV) ?></td>
          <td>
            <div class="u-actions">
              <button class="btn btn-ghost" type="button" onclick="toggleEdit(<?= (int)$id ?>)">Edit</button>

              <form method="post" onsubmit="return confirm('Delete this user?');">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h((string)$id) ?>">
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>

        <!-- =======================================================
             SECTION C — Inline Edit Panel (Hidden by default)
             ======================================================= -->
        <tr id="edit-<?= (int)$id ?>" style="display:none;">
          <td colspan="6">
            <div class="card" style="margin:0;">
              <div class="row">
                <h2 style="font-size:16px; margin:0;">Edit User</h2>
                <div class="hint">Leave password empty to keep current password</div>
              </div>

              <form method="post" style="margin-top:10px;">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= h((string)$id) ?>">

                <div class="u-grid">
                  <div>
                    <label>Username</label>
                    <input name="username" required value="<?= h($username) ?>">
                  </div>

                  <div>
                    <label>New Password (optional)</label>
                    <input name="password" placeholder="(leave empty)" type="text">
                  </div>

                  <div>
                    <label>Role</label>
                    <select name="role">
                      <option value="Client" <?= $role==="Client"?"selected":"" ?>>Client</option>
                      <option value="Observer" <?= $role==="Observer"?"selected":"" ?>>Observer</option>
                      <option value="Admin" <?= $role==="Admin"?"selected":"" ?>>Admin</option>
                    </select>
                  </div>

                  <div>
                    <label>Customer Slug (optional)</label>
                    <input name="customerSlug" value="<?= h($customerSlug) ?>">
                  </div>

                  <div style="grid-column: span 2;">
                    <label>Allowed Projects (optional)</label>
                    <input name="allowedProjects" value="<?= h($allowedCSV) ?>">
                  </div>
                </div>

                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                  <button class="btn" type="submit">Save</button>
                  <button class="btn btn-ghost" type="button" onclick="toggleEdit(<?= (int)$id ?>)">Close</button>
                </div>
              </form>
            </div>
          </td>
        </tr>

      <?php endforeach; ?>

      <?php if (count($users) === 0): ?>
        <tr><td colspan="6" class="hint">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
/************************************************************
 * SECTION D — UI Behavior (Edit panel toggle)
 ************************************************************/
function toggleEdit(id){
  const row = document.getElementById("edit-" + id);
  if (!row) return;
  row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
}
</script>

<?php render_footer(); ?>
