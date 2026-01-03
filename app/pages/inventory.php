<?php
/************************************************************
 * Inventory View — inventory.php
 * Read-only Level 1 view (PAGE-IMP-10)
 ************************************************************/

if (!defined('APP_ROOT')) {
  define('APP_ROOT', realpath(__DIR__ . '/..'));
}

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/acl.php';
require_login();
require_role(['Admin','Manager','Office','R&D']); // Customer blocked; reuse shared ACL
require_once APP_ROOT . '/includes/layout.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$role = current_role();
$search = trim((string)($_GET['search'] ?? ''));

// Inventory derivation must come from ISSUED/LOCKED MRQ/MRS/RMC only.
// TODO(PAGE-IMP-10): Load issued MRQ/MRS/RMC line items when document tables exist.
// TODO(PAGE-IMP-10): Aggregate issued quantities (exclude Draft/Proposed/Confirmed) to compute on-hand totals.
$inventoryDataAvailable = false;
$inventoryDataNote = 'Inventory data not available yet: issued MRQ/MRS/RMC line tables are missing.';
$inventoryRows = [];

$filteredRows = $inventoryRows;
if ($search !== '') {
  $filteredRows = [];
  foreach ($inventoryRows as $row) {
    $code = strtoupper((string)($row['code'] ?? ''));
    $name = strtoupper((string)($row['name'] ?? ''));
    if (strpos($code, strtoupper($search)) !== false || strpos($name, strtoupper($search)) !== false) {
      $filteredRows[] = $row;
    }
  }
}

render_header('Inventory', $role);
?>

  <div class="card">
    <div class="row" style="align-items:flex-start;">
      <div>
        <h2 style="margin:0;">Inventory</h2>
        <div class="hint">Derived from issued MRQ/MRS/RMC only.</div>
        <div class="hint">Draft/Proposed/Confirmed documents do not affect this view.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <form method="get" class="row" style="align-items:flex-end; gap:12px;">
      <div style="flex:1; min-width:240px;">
        <label for="search">Search</label>
        <input type="text" id="search" name="search" value="<?= h($search) ?>" placeholder="Material code or name">
      </div>
      <div>
        <label style="visibility:hidden;">Submit</label>
        <button class="btn" type="submit">Search</button>
      </div>
    </form>

    <?php if (!$inventoryDataAvailable): ?>
      <div class="hint" style="margin-top:10px;"><?= h($inventoryDataNote) ?></div>
      <div class="hint" style="margin-top:6px;">TODO(PAGE-IMP-10): Requires issued MRQ/MRS/RMC line extraction to compute on-hand inventory.</div>
      <div class="hint" style="margin-top:6px;">TODO(PAGE-IMP-10): Add aggregation of issued quantities by material once source data exists.</div>
    <?php endif; ?>

    <table style="margin-top:10px;">
      <thead>
        <tr>
          <th style="width:180px;">Material Code</th>
          <th>Material Name</th>
          <th style="width:160px;">On-hand Qty</th>
          <th style="width:120px;">Unit</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($filteredRows)): ?>
          <tr>
            <td colspan="4">
              <div class="hint">No inventory data available yet.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($filteredRows as $row): ?>
            <tr>
              <td><?= h((string)($row['code'] ?? '')) ?></td>
              <td><?= h((string)($row['name'] ?? '')) ?></td>
              <td><?= h((string)($row['quantity'] ?? '')) ?></td>
              <td><?= h((string)($row['unit'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<?php render_footer(); ?>
