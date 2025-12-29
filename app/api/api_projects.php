<?php
// public_html/api_projects.php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/lib_db.php";

header("Content-Type: application/json; charset=utf-8");

$method = $_SERVER["REQUEST_METHOD"];
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(["ok"=>false,"error"=>"Not logged in"]); exit; }

function is_admin($u){ return ($u["role"] ?? "") === "Admin"; }
function is_observer($u){ return ($u["role"] ?? "") === "Observer"; }

if ($method === "GET") {
  // List all projects (Admin/Observer) or filtered later (Client)
  $root = db_projects_root();
  $out = [];

  if (is_dir($root)) {
    foreach (scandir($root) as $cust) {
      if ($cust === "." || $cust === "..") continue;
      $custPath = $root . "/" . $cust;
      if (!is_dir($custPath)) continue;

      foreach (scandir($custPath) as $code) {
        if ($code === "." || $code === "..") continue;
        $projPath = $custPath . "/" . $code;
        if (!is_dir($projPath)) continue;

        $pj = $projPath . "/project.json";
        $meta = db_read_json($pj, null);
        if (!$meta) continue;

        // For now: only Admin/Observer exist; so return all
        $out[] = [
          "customerSlug" => $cust,
          "projectCode" => $code,
          "projectName" => $meta["projectName"] ?? "",
          "updatedAt" => $meta["updatedAt"] ?? "",
          "pathKey" => "{$cust}/{$code}"
        ];
      }
    }
  }

  echo json_encode(["ok"=>true, "projects"=>$out]);
  exit;
}

if ($method === "POST") {
  if (!is_admin($user)) { http_response_code(403); echo json_encode(["ok"=>false,"error"=>"Admin only"]); exit; }

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
