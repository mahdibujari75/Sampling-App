<?php
if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/..")); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_login();
require_once APP_ROOT . "/includes/layout.php";

$u = current_user();
$role = (string)($u["role"] ?? "Observer");
$isAdmin = ($role === "Admin");

if (!$isAdmin) {
  http_response_code(403);
  echo "Access denied.";
  exit;
}

render_header("Administration", "Admin");
?>

  <div class="grid" style="grid-template-columns: repeat(2, minmax(240px, 1fr)); gap:12px;">
    <a class="card" href="/project-definition" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px;">Project Definition</div>
      <div style="color:var(--muted); font-size:13px;">Create and manage project/subproject templates and definitions.</div>
    </a>

    <a class="card" href="/users" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px;">Users</div>
      <div style="color:var(--muted); font-size:13px;">Manage accounts, roles, and access controls.</div>
    </a>

  </div>
</div>

<?php render_footer(); ?>
