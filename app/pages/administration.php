<?php
if (!defined("APP_ROOT")) {
  define("APP_ROOT", realpath(__DIR__ . "/..")); // /app
}
require_once APP_ROOT . "/includes/auth.php";
require_once APP_ROOT . "/includes/acl.php";
require_login();
require_once APP_ROOT . "/includes/layout.php";

$role = current_role();
$isAdmin = has_role('Admin');
$isManager = has_role('Manager');

if (!$isAdmin && !$isManager) {
  acl_access_denied();
}

$templateRegistry = [
  [
    "type" => "scf",
    "label" => "SCF",
    "templateVersion" => "Unknown (TODO)", // TODO(PAGE-IMP-09): detect template version once metadata is available.
    "namedRanges" => "Unknown (TODO)", // TODO(PAGE-IMP-09): list required named ranges when the contract is defined.
  ],
];

render_header("Administration", "Administration");
?>

  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
    <div class="card">
      <div class="title" style="margin-bottom:6px;">Users &amp; Roles</div>
      <div style="color:var(--muted); font-size:13px;">Manage accounts, roles, and access controls.</div>
      <?php if ($isAdmin): ?>
        <div style="margin-top:12px;">
          <a class="btn" href="/users">Open Users &amp; Roles</a>
        </div>
      <?php else: ?>
        <div class="hint" style="margin-top:12px;">Read-only for Manager. TODO(PAGE-IMP-09): implement scoped management UI once available.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title" style="margin-bottom:6px;">Assignments</div>
      <div style="color:var(--muted); font-size:13px;">Project / subproject assignments.</div>
      <?php if ($isManager && !$isAdmin): ?>
        <div class="hint" style="margin-top:12px;">Read-only for Manager. TODO(PAGE-IMP-09): render scoped assignment management once assignment storage is available.</div>
      <?php else: ?>
        <div class="hint" style="margin-top:12px;">TODO(PAGE-IMP-09): render assignment management once assignment storage and scoped handling are available.</div>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
      <div class="card">
        <div class="title" style="margin-bottom:6px;">PP Fee Table</div>
        <div style="color:var(--muted); font-size:13px;">Configure PP fees (Admin only).</div>
        <div class="hint" style="margin-top:12px;">TODO(PAGE-IMP-09): Implement PP fee table storage in Tools phase.</div>
      </div>
    <?php endif; ?>

    <div class="card" style="grid-column: 1 / -1;">
      <div class="title" style="margin-bottom:6px;">Template Pack Status</div>
      <div style="color:var(--muted); font-size:13px;">Per document type: template version and required named ranges.</div>
      <table>
        <thead>
          <tr>
            <th style="width:180px;">Doc Type</th>
            <th style="width:200px;">Template Version</th>
            <th>Required Named Ranges</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($templateRegistry as $tpl): ?>
            <tr>
              <td><?= htmlspecialchars($tpl["label"], ENT_QUOTES, "UTF-8") ?></td>
              <td><?= htmlspecialchars($tpl["templateVersion"], ENT_QUOTES, "UTF-8") ?></td>
              <td><?= htmlspecialchars($tpl["namedRanges"], ENT_QUOTES, "UTF-8") ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="hint" style="margin-top:8px;">Templates are served from the existing whitelist via /get_template?type=&lt;DocType&gt;. TODO(PAGE-IMP-09): add named-range contract checks once defined.</div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
