<?php
/************************************************************
 * AUTH GUARD — Shared login enforcement
 * File: public_html/app/includes/auth_guard.php
 ************************************************************/

// Ensure APP_ROOT is always available (works for direct access too)
if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..'));
}

// Safe session start (no warnings if already started)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/config.php';

// Returns the logged-in user array or null if not authenticated
if (!function_exists('current_user')) {
  function current_user(): ?array {
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
  }
}

// Redirects to login.php if no authenticated user is present
if (!function_exists('require_login')) {
  function require_login(): void {
    if (!current_user()) {
      header("Location: /login.php");
      exit;
    }
  }
}
