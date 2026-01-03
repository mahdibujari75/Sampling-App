<?php

/************************************************************
 * LAYOUT (Theme + Header + Footer)
 * File: public_html/app/includes/layout.php
 *
 * This file merges:
 *   - theme.php  (theme_css)
 *   - header.php (render_header)
 *   - footer.php (render_footer)
 *
 * Recommended usage in pages:
 *   require_once APP_ROOT . "/includes/layout.php";
 *   render_header("Title", "Subtitle");
 *   ...
 *   render_footer();
 ************************************************************/

declare(strict_types=1);

require_once __DIR__ . '/acl.php';


// includes/theme.php
// Central theme: colors, CSS, shared UI utilities.

function theme_css(string $pageTitle = ""): void {
?>
<style>
  :root{
    --bg:#f3f7f3;
    --card:rgba(255,255,255,0.86);
    --line:#e5e7eb;
    --text:#0f172a;
    --muted:#64748b;

    --matcha:#7aa874;
    --matcha-dark:#5f8f59;

    --danger:#b91c1c;
    --ok:#166534;

    --shadow:0 12px 30px rgba(15,23,42,0.06);
    --radius:16px;
  }

  *{ box-sizing:border-box; }
  body{
    margin:0;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial;
    color:var(--text);
    background:
      radial-gradient(1200px 700px at 20% 10%, rgba(122,168,116,0.18), transparent 60%),
      radial-gradient(900px 600px at 80% 30%, rgba(122,168,116,0.12), transparent 55%),
      var(--bg);
  }

  .wrap{ max-width:1100px; margin:0 auto; padding:22px; }

  .topbar{
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    padding:14px 16px; border:1px solid var(--line); border-radius:var(--radius);
    background:rgba(255,255,255,0.75); backdrop-filter: blur(8px);
    box-shadow: var(--shadow);
  }

  .brand{ display:flex; flex-direction:column; }
  .brand h1{ margin:0; font-size:18px; letter-spacing:0.2px; }
  .brand small{ color:var(--muted); }

  .nav a{
    text-decoration:none; color:var(--text);
    padding:8px 12px; border-radius:999px; border:1px solid var(--line);
    background:rgba(255,255,255,0.7);
    display:inline-block; margin-left:8px;
  }
  .nav a:hover{ border-color: rgba(122,168,116,0.7); }

  .nav{ display:flex; flex-direction:column; align-items:flex-end; gap:6px; }
  .nav-links{ display:flex; align-items:center; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
  .nav-chip{
    text-decoration:none; color:var(--muted);
    padding:8px 12px; border-radius:999px; border:1px solid var(--line);
    background:rgba(255,255,255,0.5);
    display:inline-block;
    cursor:default;
  }

  .nav-badges{ display:flex; align-items:center; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
  .nav-badge{
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 10px; border-radius:999px; border:1px solid var(--line);
    background:rgba(255,255,255,0.85); font-size:12px; color:var(--text);
  }
  .nav-badge-count{
    background:var(--matcha); color:#fff; border-radius:10px; padding:2px 8px;
    font-weight:700; min-width:24px; text-align:center; display:inline-block;
  }

  .card{
    margin-top:14px;
    padding:16px;
    border:1px solid var(--line);
    border-radius:var(--radius);
    background:var(--card);
    backdrop-filter: blur(8px);
    box-shadow: var(--shadow);
  }

  .card h2{ margin:0 0 10px; font-size:15px; }

  .row{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }

  .hint{ color:var(--muted); font-size:12px; margin-top:6px; }

  .flash{ margin-top:10px; font-size:13px; }
  .flash.ok{ color:var(--ok); }
  .flash.err{ color:var(--danger); }

  label{ font-size:12px; color:var(--muted); display:block; margin-bottom:4px; }

  input,select,textarea{
    width:100%;
    border:1px solid var(--line);
    border-radius:12px;
    padding:10px 11px;
    font-size:14px;
    background:#fff;
    outline:none;
  }
  input:focus,select:focus,textarea:focus{
    border-color:rgba(122,168,116,0.9);
    box-shadow:0 0 0 3px rgba(122,168,116,0.16);
  }

  .btn{
    border:none; border-radius:999px; padding:10px 14px;
    background:var(--matcha); color:#fff; cursor:pointer; font-weight:600;
  }
  .btn:hover{ background:var(--matcha-dark); }

  .btn-ghost{
    background:#fff; color:var(--text); border:1px solid var(--line);
  }
  .btn-ghost:hover{ border-color:rgba(122,168,116,0.7); }

  .btn-danger{ background:var(--danger); }
  .btn-danger:hover{ filter:brightness(0.92); }

  table{ width:100%; border-collapse:collapse; margin-top:10px; }
  th,td{ padding:10px; border-bottom:1px solid var(--line); font-size:14px; text-align:left; vertical-align:top; }
  th{ font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; }

  .grid{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
  @media (max-width:900px){ .grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:520px){ .grid{ grid-template-columns:1fr; } }

  .pill{
    display:inline-block; padding:5px 10px; border-radius:999px;
    border:1px solid var(--line); background:#fff; font-size:12px;
  }

</style>
<?php
}



/************************************************************
 * HEADER (Shared Layout)
 * File: public_html/app/includes/header.php
 ************************************************************/

/************************************************************
 * SECTION 1 — Load Theme
 ************************************************************/

/************************************************************
 * SECTION 2 — Render Header
 ************************************************************/

function render_header(string $title, string $subtitle = ""): void {
  $subtitle = $subtitle ?: "Portal";

  $role = function_exists('current_role') ? current_role() : '';
  $isAdmin = function_exists('is_admin') ? is_admin() : ($role === 'Admin');
  $canSeeDashboard = function_exists('has_role') ? has_role(['Admin','Manager','Office','R&D','Customer']) : $isAdmin;
  $canSeeDocuments = function_exists('has_role') ? has_role(['Admin','Manager','Office','R&D','Customer']) : $isAdmin;
  $canSeeProduction = function_exists('has_role') ? has_role(['Admin','Manager','Office','R&D','Customer']) : $isAdmin;

  $hasInventoryPage = file_exists(APP_ROOT . '/pages/inventory.php');
  $canSeeInventory = function_exists('has_role') ? has_role(['Admin','Manager','Office','R&D']) : $isAdmin;

  $hasAdministrationPage = file_exists(APP_ROOT . '/pages/administration.php');
  $canSeeAdministration = $hasAdministrationPage && ($isAdmin || has_role('Manager'));

  $badgeCounts = [
    'Waiting for my confirmation' => 0,
    'Ready to issue' => 0,
    'Production tasks' => 0,
  ];
  // TODO(PAGE-IMP-03): implement count query once doc/production metadata tables exist

  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, "UTF-8") ?></title>
    <?php theme_css($title); ?>
  </head>
  <body>
    <div class="wrap">
      <div class="topbar">
        <div class="brand">
          <h1><?= htmlspecialchars($title, ENT_QUOTES, "UTF-8") ?></h1>
          <small><?= htmlspecialchars($subtitle, ENT_QUOTES, "UTF-8") ?></small>
        </div>

        <div class="nav">
          <div class="nav-links">
            <?php if ($canSeeDashboard): ?>
              <a href="/">Dashboard</a>
            <?php endif; ?>

            <?php if ($canSeeDocuments): ?>
              <a href="/projects">Documents Register</a>
            <?php endif; ?>

            <?php if ($canSeeProduction): ?>
              <a href="/production">Production</a>
            <?php endif; ?>

            <?php if ($canSeeInventory): ?>
              <?php if ($hasInventoryPage): ?>
                <a href="/inventory">Inventory</a>
              <?php else: ?>
                <span class="nav-chip">Inventory</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($canSeeAdministration): ?>
              <a href="/administration">Administration</a>
            <?php endif; ?>

            <a href="/logout">Logout</a>
            <span class="nav-chip"><?= htmlspecialchars($role ?: 'Unknown', ENT_QUOTES, "UTF-8") ?></span>
          </div>

          <div class="nav-badges">
            <?php foreach ($badgeCounts as $label => $count): ?>
              <span class="nav-badge">
                <span><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></span>
                <span class="nav-badge-count"><?= (int)$count ?></span>
              </span>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
  <?php
}
function render_footer(): void {
  ?>
    </div>
  </body>
  </html>
  <?php
}
