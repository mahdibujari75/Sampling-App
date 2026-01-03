<?php
/************************************************************
 * AUTH — Session + Access Guards
 * File: public_html/app/includes/auth.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Base Guard
 ************************************************************/
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/config.php';

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
