<?php
/************************************************************
 * HOME PAGE
 * File: public_html/app/pages/home.php
 ************************************************************/

/************************************************************
 * SECTION 0 — Auth Guard (direct access safe)
 ************************************************************/
if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_login();

/************************************************************
 * SECTION 1 — Shared Layout
 ************************************************************/
require_once APP_ROOT . "/includes/layout.php";

/************************************************************
 * SECTION 2 — Page State
 ************************************************************/
$u = current_user();
$username = $u["username"] ?? "User";
$role = $u["role"] ?? "User";
$isAdmin = ($role === "Admin");

/************************************************************
 * SECTION 3 — Render Header
 ************************************************************/
render_header("Home", "Welcome {$username} ({$role})");
?>

<!-- =======================================================
     SECTION B — Status
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Status</h2>
    <div class="hint">Session info</div>
  </div>

  <div class="hint" style="margin-top:10px; line-height:1.7;">
    Username: <strong><?= htmlspecialchars($username, ENT_QUOTES, "UTF-8") ?></strong><br>
    Role: <strong><?= htmlspecialchars($role, ENT_QUOTES, "UTF-8") ?></strong>
  </div>
</div>

<?php
/************************************************************
 * SECTION 4 — Render Footer
 ************************************************************/
render_footer();
