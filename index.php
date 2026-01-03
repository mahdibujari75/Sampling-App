<?php
/************************************************************
 * FRONT CONTROLLER / ROUTER (Robust)
 * File: public_html/index.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Define APP_ROOT
 ************************************************************/
define('APP_ROOT', __DIR__ . '/app');

/************************************************************
 * SECTION 2 — Load Auth (must define current_user/require_login)
 ************************************************************/
require_once APP_ROOT . '/includes/auth.php';

/************************************************************
 * SECTION 3 — Route Normalization
 * Handles:
 * - /           -> home
 * - /index.php  -> home
 * - /users.php  -> users
 * - /projects/  -> projects
 * - case-insensitive
 ************************************************************/
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = trim($path, '/');

// If someone hits "/index.php" treat it as home
if ($path === '' || $path === 'index.php') {
  $route = 'home';
} else {
  // remove ".php" if present
  if (str_ends_with($path, '.php')) {
    $path = substr($path, 0, -4);
  }

  // normalize case (users, projects, pma)
  $route = strtolower($path);
}

/************************************************************
 * SECTION 4 — Route Map
 ************************************************************/
$routes = [
  'home'     => APP_ROOT . '/pages/home.php',
  'users'    => APP_ROOT . '/pages/users.php',
  'projects' => APP_ROOT . '/pages/projects.php',
  'project-definition' => APP_ROOT . '/pages/project-definition.php',
  'pi'  => APP_ROOT . '/pages/pi.php',
  'scf' => APP_ROOT . '/pages/scf.php',
  'sff' => APP_ROOT . '/pages/sff.php',
  'rmc' => APP_ROOT . '/pages/rmc.php',
  'production' => APP_ROOT . '/pages/production.php',
  'production_plan' => APP_ROOT . '/pages/production_plan.php',
  'production_log' => APP_ROOT . '/pages/production_log.php',
  'administration' => APP_ROOT . '/pages/administration.php',
  'get_template' => APP_ROOT . '/pages/get_template.php',
  'inventory' => APP_ROOT . '/pages/inventory.php',

    
  // PMA later: 'pma' => APP_ROOT . '/pages/PMA.php',
];

/************************************************************
 * SECTION 5 — Dispatch
 ************************************************************/
if (!isset($routes[$route])) {
  http_response_code(404);
  echo "Page not found. route=" . htmlspecialchars($route) . " path=" . htmlspecialchars($path);
  exit;
}

$publicRoutes = ['login', 'login.php', 'logout', 'logout.php'];
if (!in_array($route, $publicRoutes, true)) {
  // protect everything here (login/logout remain separate real files)
  require_login();
}

require $routes[$route];
