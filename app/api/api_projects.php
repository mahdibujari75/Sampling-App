<?php
// public_html/app/api/api_projects.php
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . "/lib_db.php";

header("Content-Type: application/json; charset=utf-8");

$method = $_SERVER["REQUEST_METHOD"];
require_login();
$user = current_user();

if ($method === "GET") {
  $projects = apply_scope_filter_to_project_list_query(acl_load_projects());
  $out = [];

  foreach ($projects as $p) {
    $slug = (string)($p["customerSlug"] ?? ($p["customerUsername"] ?? ""));
    $slug = preg_replace('/[^a-zA-Z0-9\\-_]/', '', $slug);
    $code = (string)($p["code"] ?? "");
    $out[] = [
      "customerSlug" => $slug,
      "projectCode" => $code,
      "projectName" => $p["projectName"] ?? "",
      "updatedAt" => $p["updatedAt"] ?? "",
      "pathKey" => "{$slug}/{$code}"
    ];
  }

  echo json_encode(["ok"=>true, "projects"=>$out]);
  exit;
}

if ($method === "POST") {
  require_role(["Admin"]);

  $payload = json_decode(file_get_contents("php://input"), true);
  if (!is_array($payload)) { http_response_code(400); echo json_encode(["ok"=>false,"error"=>"Invalid JSON"]); exit; }

  $customerName = trim((string)($payload["customerName"] ?? ""));
  $customerSlug = trim((string)($payload["customerSlug"] ?? "")); // optional
  $projectCode  = trim((string)($payload["projectCode"] ?? ""));
  $projectName  = trim((string)($payload["projectName"] ?? ""));

  if ($customerSlug === "") $customerSlug = db_slug($customerName);
  if ($customerSlug === "") { http_response_code(400); echo json_encode(["ok"=>false,"error"=>"customerName required"]); exit; }

  $projectCodeDigits = preg_replace('/\D+/', '', $projectCode);
  if ($projectCodeDigits === "") { http_response_code(400); echo json_encode(["ok"=>false,"error"=>"projectCode required"]); exit; }

  // auto-create full tree C/F/O + json files
  ensure_project_tree($customerSlug, $projectCodeDigits);

  // update project.json with projectName
  $pjPath = project_path($customerSlug, $projectCodeDigits) . "/project.json";
  $pj = db_read_json($pjPath, []);
  $pj["projectName"] = $projectName;
  $pj["customerSlug"] = db_slug($customerSlug);
  $pj["projectCode"] = $projectCodeDigits;
  $pj["updatedAt"] = gmdate("c");
  db_write_json($pjPath, $pj);

  echo json_encode([
    "ok"=>true,
    "customerSlug"=>$customerSlug,
    "projectCode"=>$projectCodeDigits,
    "pathKey"=>"{$customerSlug}/{$projectCodeDigits}"
  ]);
  exit;
}

http_response_code(405);
echo json_encode(["ok"=>false,"error"=>"Method not allowed"]);
