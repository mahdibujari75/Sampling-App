<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_guard.php'; // must start session + provide require_login()
require_once __DIR__ . '/../includes/acl.php';

require_login(); // blocks if not logged in

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function safe_slug(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', '-', $s);
  $s = preg_replace('/[^a-zA-Z0-9\-_]/', '', $s);
  $s = strtolower($s);
  return $s ?: 'customer';
}

function safe_code(string $s, int $minLen = 1): string {
  $s = trim($s);
  $s = preg_replace('/[^0-9]/', '', $s);
  if (strlen($s) < $minLen) return '';
  return $s;
}

function safe_type(string $t): string {
  $t = strtoupper(trim($t));
  return in_array($t, ['C','F','O'], true) ? $t : '';
}

function safe_module(string $m): string {
  $m = strtoupper(trim($m));
  return in_array($m, ['PI','SCF','SFF','LOGS'], true) ? $m : '';
}

function safe_date_short(string $s): string {
  // expected like "04.09.29" (YY.MM.DD) - allow digits and dots only
  $s = trim($s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  // minimal validation: 8 chars like 00.00.00
  return (preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $s)) ? $s : '';
}

// -------- Input (multipart/form-data) --------
$customerSlug = isset($_POST['customerSlug']) ? safe_slug((string)$_POST['customerSlug']) : '';
$projectCode  = isset($_POST['projectCode']) ? safe_code((string)$_POST['projectCode'], 3) : '';
$subType      = isset($_POST['subType']) ? safe_type((string)$_POST['subType']) : '';
$module       = isset($_POST['module']) ? safe_module((string)$_POST['module']) : '';
$dateShort    = isset($_POST['dateShort']) ? safe_date_short((string)$_POST['dateShort']) : '';

if ($customerSlug === '' || $projectCode === '' || $subType === '' || $module === '' || $dateShort === '') {
  json_out(['ok' => false, 'error' => 'Missing/invalid fields.'], 400);
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  json_out(['ok' => false, 'error' => 'No file uploaded.'], 400);
}

$fileTmp  = $_FILES['file']['tmp_name'];
$fileSize = (int)($_FILES['file']['size'] ?? 0);
if ($fileSize <= 0) json_out(['ok' => false, 'error' => 'Empty upload.'], 400);

// Scope enforcement (project ownership)
$project = require_project_scope_by_slug_code($customerSlug, $projectCode, $subType);

// -------- Paths --------
$baseDir = __DIR__ . '/database/projects';
$targetDir = $baseDir . '/' . $customerSlug . '/' . $projectCode . '/' . $subType . '/' . $module;

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
  json_out(['ok' => false, 'error' => 'Cannot create target directory.'], 500);
}

// -------- Counter (monotonic, no daily reset) --------
$counterFile = $targetDir . '/_counter.json';
$counter = 0;

if (is_file($counterFile)) {
  $raw = file_get_contents($counterFile);
  $j = json_decode($raw ?: 'null', true);
  if (is_array($j) && isset($j['last']) && is_numeric($j['last'])) $counter = (int)$j['last'];
}
$counter++;
file_put_contents($counterFile, json_encode(['last' => $counter], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

// -------- Filename per your rule --------
// example: "302-C PI 02 04.09.29.xlsx"
$nn = str_pad((string)$counter, 2, '0', STR_PAD_LEFT);
$filename = "{$projectCode}-{$subType} {$module} {$nn} {$dateShort}.xlsx";

$dest = $targetDir . '/' . $filename;
if (!move_uploaded_file($fileTmp, $dest)) {
  json_out(['ok' => false, 'error' => 'Failed to save uploaded file.'], 500);
}

// Build a web path (relative)
$webPath = "/database/projects/{$customerSlug}/{$projectCode}/{$subType}/{$module}/" . rawurlencode($filename);

json_out([
  'ok' => true,
  'savedAs' => $filename,
  'counter' => $counter,
  'webPath' => $webPath
]);
