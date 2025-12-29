<?php
/************************************************************
 * CONFIG — Single source of truth (paths)
 * File: public_html/app/includes/config.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Ensure APP_ROOT Exists (router or direct pages)
 ************************************************************/
if (!defined('APP_ROOT')) {
  // /public_html/app/includes -> /public_html/app
  define('APP_ROOT', realpath(__DIR__ . '/..'));
}

/************************************************************
 * SECTION 2 — Users DB Path (Canonical)
 ************************************************************/
if (!defined('USERS_DB_FILE')) {
  define('USERS_DB_FILE', APP_ROOT . '/../database/users_files/users.json');
}

/************************************************************
 * SECTION — Projects DB Path (Canonical)
 ************************************************************/
if (!defined('PROJECTS_DB_FILE')) {
  define('PROJECTS_DB_FILE', APP_ROOT . '/../database/projects_files/projects.json');
}

if (!defined('PROJECTS_ROOT_DIR')) {
  define('PROJECTS_ROOT_DIR', APP_ROOT . '/../database/projects');
}
