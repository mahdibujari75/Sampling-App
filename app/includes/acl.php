<?php
/************************************************************
 * ACL — Centralized authorization helpers
 * File: public_html/app/includes/acl.php
 ************************************************************/

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/config.php';

function acl_normalize_role(?string $role): string {
  $r = strtoupper(trim((string)$role));
  $map = [
    'ADMIN'   => 'Admin',
    'MANAGER' => 'Manager',
    'OFFICE'  => 'Office',
    'R&D'     => 'R&D',
    'R & D'   => 'R&D',
    'RANDD'   => 'R&D',
    'CUSTOMER'=> 'Customer',
    'CLIENT'  => 'Customer',
    'OBSERVER'=> 'Observer',
  ];
  return $map[$r] ?? 'Unknown';
}

function current_role(): string {
  $u = current_user();
  return acl_normalize_role($u['role'] ?? '');
}

function is_admin(): bool {
  return current_role() === 'Admin';
}

function has_role($roles): bool {
  $current = current_role();
  $list = is_array($roles) ? $roles : [$roles];
  foreach ($list as $r) {
    if ($current === acl_normalize_role((string)$r)) return true;
  }
  return false;
}

function acl_access_denied(): void {
  http_response_code(403);
  echo "Access denied.";
  exit;
}

function require_role($roles): void {
  require_login();
  if (!has_role($roles)) {
    acl_access_denied();
  }
}

function acl_load_json_array(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: '[]', true);
  return is_array($data) ? $data : [];
}

function acl_projects_file(): string {
  return defined('PROJECTS_DB_FILE') ? PROJECTS_DB_FILE : (APP_ROOT . '/../database/projects_files/projects.json');
}

function acl_load_projects(): array {
  static $cache = null;
  if ($cache === null) {
    $cache = acl_load_json_array(acl_projects_file());
  }
  return $cache;
}

function acl_find_project_by_id(int $projectId): ?array {
  if ($projectId <= 0) return null;
  foreach (acl_load_projects() as $p) {
    if ((int)($p['id'] ?? 0) === $projectId) {
      return $p;
    }
  }
  return null;
}

function acl_find_project_by_slug_code(string $customerSlug, string $projectCode, ?string $type = null): ?array {
  $customerSlug = trim($customerSlug);
  $projectCode = trim($projectCode);
  $type = $type !== null ? strtoupper(trim($type)) : null;

  foreach (acl_load_projects() as $p) {
    $slug = (string)($p['customerSlug'] ?? '');
    $code = (string)($p['code'] ?? '');
    $ptype = strtoupper((string)($p['type'] ?? ''));
    if ($slug === $customerSlug && $code === $projectCode) {
      if ($type === null || $type === $ptype) {
        return $p;
      }
    }
  }
  return null;
}

function acl_is_internal_role(string $role): bool {
  return in_array($role, ['Manager','Office','R&D'], true);
}

function acl_user_can_access_project(array $project): bool {
  $role = current_role();
  if ($role === 'Admin') return true;

  if ($role === 'Customer') {
    $u = current_user();
    $username = is_array($u) ? (string)($u['username'] ?? '') : '';
    return $username !== '' && (string)($project['customerUsername'] ?? '') === $username;
  }

  if (acl_is_internal_role($role)) {
    // TODO: integrate internal assignment checks when assignment tables/fields are available.
    return false;
  }

  // Reserved/unknown roles have no special access
  return false;
}

function require_project_scope(int $projectId): array {
  require_login();
  $project = acl_find_project_by_id($projectId);
  if (!$project || !acl_user_can_access_project($project)) {
    acl_access_denied();
  }
  return $project;
}

function require_project_scope_by_slug_code(string $customerSlug, string $projectCode, string $type): array {
  require_login();
  $project = acl_find_project_by_slug_code($customerSlug, $projectCode, $type);
  if (!$project || !acl_user_can_access_project($project)) {
    acl_access_denied();
  }
  return $project;
}

function require_subproject_scope(int $subprojectId): array {
  return require_project_scope($subprojectId);
}

function apply_scope_filter_to_project_list_query(array $projects): array {
  $out = [];
  foreach ($projects as $p) {
    if (acl_user_can_access_project($p)) $out[] = $p;
  }
  return $out;
}
