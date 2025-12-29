<?php
/************************************************************
 * AUTH — Session + Access Guards
 * File: public_html/app/includes/auth.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Session Start
 ************************************************************/
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
  
require_once __DIR__ . '/config.php';

}

/************************************************************
 * SECTION 2 — Current User Helper
 ************************************************************/
function current_user(): ?array {
  return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

/************************************************************
 * SECTION 3 — Require Login
 ************************************************************/
 
 require_once __DIR__ . '/config.php';

function require_login(): void {
  if (!current_user()) {
    // If your login page is still /login.php:
    header("Location: /login.php");
    exit;
  }
}

/************************************************************
 * SECTION 4 — Require Admin
 ************************************************************/
function require_admin(): void {
  require_login();
  $u = current_user();
  if (($u['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}
