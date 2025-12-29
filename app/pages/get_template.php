<?php
// app/pages/get_template.php
// Streams protected Excel templates to authenticated users.

if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}

require_once APP_ROOT . '/includes/auth.php';

// Use the same auth guard as the rest of the app.
// NOTE: index.php already calls require_login() for routed pages, but we keep it
// here so this file is safe even if called directly.
require_login();

// Only allow specific templates (whitelist)
$type = $_GET['type'] ?? '';
if ($type !== 'scf') {
  http_response_code(400);
  echo 'Bad request';
  exit;
}

// Template file location (NOT a public URL; this script streams it)
$templatePath = APP_ROOT . '/templates/scf_master.xlsx';

if (!is_file($templatePath)) {
  http_response_code(404);
  echo 'Template not found';
  exit;
}

// Disable caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: inline; filename="scf_master.xlsx"');
header('Content-Length: ' . filesize($templatePath));

readfile($templatePath);
exit;
