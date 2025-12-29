<?php
/************************************************************
 * Production — production.php
 * Hub page for production tools (Plan + Log)
 ************************************************************/

if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}

require_once APP_ROOT . '/includes/auth.php';
require_login();
require_once APP_ROOT . '/includes/layout.php';

$me = current_user();
$role = (string)($me['role'] ?? 'Observer');
$isAdmin = ($role === 'Admin');
$isObserver = ($role === 'Observer');
$isStaff = ($isAdmin || $isObserver);

if (!$isStaff) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}

render_header('Production', $role);
?>

  <div class="grid" style="grid-template-columns: repeat(2, minmax(260px, 1fr)); gap:12px;">
    <a class="card" href="/production_plan" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px; ">Production Plans</div>
      <div style="color:var(--muted); font-size:13px;">
        Create, load, and manage daily production plans and generate DP-RMC outputs.
      </div>
    </a>

    <a class="card" href="/production_log" style="text-decoration:none; color:inherit; display:block;">
      <div class="title" style="margin-bottom:6px; ">Production Log</div>
      <div style="color:var(--muted); font-size:13px;">
        Record execution results and material consumption for completed production days.
      </div>
    </a>
  </div>

</div>

<?php render_footer(); ?>
