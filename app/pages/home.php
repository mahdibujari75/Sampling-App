<?php
/**
 * HOME / DASHBOARD PAGE
 * File: public_html/app/pages/home.php
 */

/************************************************************
 * SECTION 0 — Auth Guard (direct access safe)
 ************************************************************/
if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..')); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_once APP_ROOT . "/includes/acl.php";
require_login();

/************************************************************
 * SECTION 1 — Shared Layout
 ************************************************************/
require_once APP_ROOT . "/includes/layout.php";

/************************************************************
 * SECTION 2 — Page State
 ************************************************************/
$u = current_user();
$username = $u["username"] ?? "User";
$role = current_role();
$isAllowedDashboard = has_role(["Admin","Manager","Office","R&D","Customer"]);
if (!$isAllowedDashboard) {
  acl_access_denied();
}

// TODO(PAGE-IMP-04): Replace placeholders with real data queries once document and production metadata tables are available.
$pendingConfirmations = [];
// Expected query: Proposed documents assigned to the current user as confirmer (apply project/subproject scope checks).

$readyToIssue = [];
// Expected query: Confirmed documents scoped to the current Manager/Admin for issuing.

$productionTasks = [];
// Expected query: Production days missing issued MRQ/MRS or missing RMC outputs.

$assignedSubprojects = [];
// Expected query: Subprojects assigned to the current user with state/stream and next required docs indicator.

$creationRequests = [];
// Expected query: Creation requests submitted by R&D/Customer and pending handling by Manager/Admin.

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

/************************************************************
 * SECTION 3 — Render Header
 ************************************************************/
render_header("Dashboard", "Welcome {$username} ({$role})");
?>

<!-- =======================================================
     SECTION B — My Pending Confirmations
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>My Pending Confirmations</h2>
    <div class="hint">Documents marked Proposed where you are the confirmer.</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:260px;">Document</th>
        <th style="width:200px;">Project/Subproject</th>
        <th style="width:160px;">Status</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($pendingConfirmations)): ?>
        <tr>
          <td colspan="4">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No items.</span>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link to document confirmation view">Open document</button>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($pendingConfirmations as $item): ?>
          <tr>
            <td><?= h((string)($item["document"] ?? "")) ?></td>
            <td><?= h((string)($item["project"] ?? "")) ?></td>
            <td><?= h((string)($item["status"] ?? "Proposed")) ?></td>
            <td>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link to document confirmation view">Open document</button>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- =======================================================
     SECTION C — Ready to Issue
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Ready to Issue</h2>
    <div class="hint">Confirmed documents waiting for Manager/Admin to issue.</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:260px;">Document</th>
        <th style="width:200px;">Project/Subproject</th>
        <th style="width:160px;">Status</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($readyToIssue)): ?>
        <tr>
          <td colspan="4">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No items.</span>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link to issuing screen">Open document for issuing</button>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($readyToIssue as $item): ?>
          <tr>
            <td><?= h((string)($item["document"] ?? "")) ?></td>
            <td><?= h((string)($item["project"] ?? "")) ?></td>
            <td><?= h((string)($item["status"] ?? "Confirmed")) ?></td>
            <td>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link to issuing screen">Open document for issuing</button>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- =======================================================
     SECTION D — Production Tasks
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Production Tasks</h2>
    <div class="hint">Production days missing issued MRQ/MRS or pending RMC.</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:200px;">Production Day</th>
        <th style="width:200px;">Missing</th>
        <th style="width:160px;">Status</th>
        <th style="width:200px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($productionTasks)): ?>
        <tr>
          <td colspan="4">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No items.</span>
              <a class="btn btn-ghost" href="/production" style="text-decoration:none;">Open production</a>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link to MRQ/MRS or RMC task">Go to task</button>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($productionTasks as $task): ?>
          <tr>
            <td><?= h((string)($task["day"] ?? "")) ?></td>
            <td><?= h((string)($task["missing"] ?? "")) ?></td>
            <td><?= h((string)($task["status"] ?? "")) ?></td>
            <td>
              <a class="btn btn-ghost" href="/production" style="text-decoration:none;">Open production</a>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Link directly to MRQ/MRS or RMC">Go to task</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- =======================================================
     SECTION E — Assigned Subprojects
     ======================================================= -->
<div class="card">
  <div class="row">
    <h2>Assigned Subprojects</h2>
    <div class="hint">Scope-aware list with state, stream, and next required docs indicator.</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:220px;">Subproject</th>
        <th style="width:160px;">State</th>
        <th style="width:180px;">Stream</th>
        <th style="width:200px;">Next Required Docs</th>
        <th style="width:140px;">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($assignedSubprojects)): ?>
        <tr>
          <td colspan="5">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No items.</span>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($assignedSubprojects as $sp): ?>
          <tr>
            <td><?= h((string)($sp["name"] ?? "")) ?></td>
            <td><?= h((string)($sp["state"] ?? "")) ?></td>
            <td><?= h((string)($sp["stream"] ?? "")) ?></td>
            <td><?= h((string)($sp["nextDocs"] ?? "")) ?></td>
            <td>
              <a class="btn btn-ghost" href="/projects" style="text-decoration:none;">Open project/subproject</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- =======================================================
     SECTION F — Creation Requests
     ======================================================= -->
<div class="card">
  <div class="row" style="align-items:flex-start;">
    <div>
      <h2>Creation Requests</h2>
      <div class="hint">R&amp;D/Customer submit creation requests; Manager/Admin handle them.</div>
    </div>
    <div>
      <?php if (has_role(["R&D","Customer"])): ?>
        <button class="btn" type="button" disabled title="TODO(PAGE-IMP-04): Wire to creation request form">Create subproject creation request</button>
      <?php else: ?>
        <button class="btn btn-ghost" type="button" disabled title="Available to R&amp;D and Customer roles">Create subproject creation request</button>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:220px;">Request</th>
        <th style="width:200px;">Submitted By</th>
        <th style="width:160px;">Status</th>
        <th style="width:200px;">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($creationRequests)): ?>
        <tr>
          <td colspan="4">
            <div class="row" style="align-items:center; gap:10px;">
              <span class="hint">No items.</span>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Open creation request record">Open request</button>
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($creationRequests as $req): ?>
          <tr>
            <td><?= h((string)($req["title"] ?? "")) ?></td>
            <td><?= h((string)($req["submittedBy"] ?? "")) ?></td>
            <td><?= h((string)($req["status"] ?? "")) ?></td>
            <td>
              <button class="btn btn-ghost" type="button" disabled title="TODO(PAGE-IMP-04): Open creation request record">Open request</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
/************************************************************
 * SECTION 4 — Render Footer
 ************************************************************/
render_footer();
