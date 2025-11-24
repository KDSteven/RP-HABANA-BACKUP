<?php
session_start();
require 'config/db.php';

/* --- Auth --- */
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$role         = $_SESSION['role'] ?? '';
$userBranchId = $_SESSION['branch_id'] ?? null;

if (!in_array($role, ['admin','stockman'], true)) {
    http_response_code(403);
    die('Access denied.');
}

/* --- Branch filter --- */
$branch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;

if ($role === 'stockman') {
    if (!$userBranchId) {
        http_response_code(403);
        die('Access denied: no branch assigned.');
    }
    $branch = (int)$userBranchId;
}

/* --- Stockman label --- */
$stockmanBranchLabel = '';
if ($role === 'stockman' && $userBranchId) {
    $stmt = $conn->prepare("
        SELECT CONCAT(branch_name,
            CASE WHEN branch_location IS NULL OR branch_location = '' 
                 THEN '' ELSE CONCAT(' – ', branch_location) END
        ) AS label
        FROM branches WHERE branch_id=? LIMIT 1
    ");
    $stmt->bind_param('i', $userBranchId);
    $stmt->execute();
    $stmt->bind_result($stockmanBranchLabel);
    $stmt->fetch();
    $stmt->close();

    if (!$stockmanBranchLabel) $stockmanBranchLabel = 'Branch #'.$userBranchId;
}

/* --- Admin: branch list --- */
$branches = [];
if ($role === 'admin') {
    $res = $conn->query("
        SELECT branch_id, branch_name, branch_location 
        FROM branches ORDER BY branch_name ASC
    ");
    while ($row = $res->fetch_assoc()) $branches[] = $row;
}

/* --- Pending counts for sidebar bell --- */
$pendingTransfers = 0;
$pendingStockIns  = 0;
$pendingResets    = 0;

if ($role === 'admin') {
    $pendingTransfers = (int)($conn->query("SELECT COUNT(*) c FROM transfer_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
    $pendingStockIns  = (int)($conn->query("SELECT COUNT(*) c FROM stock_in_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
}
$pending = $pendingTransfers + $pendingStockIns + $pendingResets;

/* --- Load products --- */
$sql = "
    SELECT DISTINCT p.product_name AS name, p.barcode AS barcode, p.category
    FROM products p
    JOIN inventory i ON i.product_id = p.product_id
    WHERE p.archived = 0
      AND i.archived = 0
      AND p.barcode IS NOT NULL AND p.barcode <> ''
";

$params = [];
$types  = '';

if ($branch > 0) {
    $sql .= " AND i.branch_id = ?";
    $params[] = $branch;
    $types   .= 'i';
}

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$stmt->close();

/* --- Group by category --- */
$groups = [];
foreach ($rows as $r) {
    $cat = $r['category'] ?: 'Uncategorized';
    $groups[$cat][] = ['name'=>$r['name'], 'barcode'=>$r['barcode']];
}

function h($x){ return htmlspecialchars($x, ENT_QUOTES, 'UTF-8'); }

/* --- Current user name --- */
$currentName = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($nm);
    if ($stmt->fetch()) $currentName = $nm;
    $stmt->close();
}

/* --- Sidebar open state --- */
$self = strtolower(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$invOpen   = in_array($self, ['inventory.php','physical_inventory.php','barcode-print.php']);
$isArchive = substr($self,0,7) === 'archive';
$toolsOpen = $self === 'backup_admin.php' || $isArchive;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>RP Habana — Barcode Labels</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="img/R.P.png">
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/notifications.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<style>
/* KEEP your original barcode-print styling except layout overrides */
:root{
    --bg:#f3f6fb;
    --card:#fff;
    --pill-bg:#edf2f7;
    --pill-fg:#0f172a;
    --pill-muted:#6b7280;
    --pill-active-bg:#ffd9a6;
    --pill-active-fg:#7a4b00;
    --section-bg:#ffeecd;
    --section-accent:#A72F2F;
    --grid-gap:16px;
    --label-w:45mm;
    --label-h:30mm;
}
body{
    background:var(--bg);
    color:#0f172a;
}
.content {
    padding:20px; /* MATCH DASHBOARD */
}

/* Your header */
.page-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
}
.page-title{
    display:flex;
    align-items:center;
    gap:10px;
}
.page-title h1{
    margin:0; font-size:24px; font-weight:800;
}

/* Pills */
.branch-pills{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:16px;
}
.branch-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:8px 14px;
    background:var(--pill-bg);
    color:var(--pill-fg);
    text-decoration:none;
    border-radius:999px;
    border:1px solid #e5e7eb;
    font-weight:700;
}
.branch-pill.active{
    background:var(--pill-active-bg);
    color:var(--pill-active-fg);
}

/* Card */
.main-card{
    background:var(--card);
    border:1px solid #e9eef5;
    border-radius:18px;
    padding:18px;
    box-shadow:0 10px 20px -12px rgba(15,23,42,0.12);
}
.card-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
}
.card-head h2{ margin:0; font-size:22px; font-weight:800; }

/* Section bar */
.section-bar{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:800;
    background:var(--section-bg);
    border-left:6px solid var(--section-accent);
    padding:10px 12px;
    margin:14px 4px;
}

/* Labels grid */
.labels-grid{
    display:grid;
    gap:var(--grid-gap);
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
}
.label-card{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:14px;
    padding:16px;
    height:160px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
}
.label-card svg{ height:56px; width:100%; max-width:230px; }

/* PRINT ONLY */
@media print{
    #mainSidebar, .page-head, .branch-pills {display:none!important;}
    .content{padding:0;margin:0;}
    .main-card{border:0;box-shadow:none;padding:0;}

    .labels-grid{
        grid-template-columns:repeat(5,var(--label-w));
        gap:6mm;
    }
    svg[id^="bc"]{
        width:35mm!important;
        height:25mm!important;
    }
}
</style>
</head>

<body>
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="false">
    <i class="fas fa-bars" aria-hidden="true"></i>
  </button>

<!-- Sidebar -->
<div class="sidebar expanded" id="mainSidebar">

  <div class="sidebar-content">
    
        <h2 class="user-heading">
            <span class="role"><?= strtoupper($role) ?></span>
            <?php if ($currentName): ?>
                <span class="name">(<?= h($currentName) ?>)</span>
            <?php endif; ?>
            <span class="notif-wrapper">
                <i class="fas fa-bell" id="notifBell"></i>
                <span id="notifCount" <?= $pending? '' : 'style="display:none;"' ?>>
                    <?= $pending ?>
                </span>
            </span>
        </h2>

        <a href="dashboard.php"><i class="fas fa-tv"></i> Dashboard</a>

        <?php
        $self = strtolower(basename($_SERVER['PHP_SELF']));
        $isArchive = substr($self,0,7)==='archive';
        $invOpen   = in_array($self,['inventory.php','physical_inventory.php','barcode-print.php']);
        $toolsOpen = $self==='backup_admin.php' || $isArchive;
        ?>

        <?php if ($role==='admin'): ?>
        <div class="menu-group has-sub">
            <button class="menu-toggle" type="button" aria-expanded="<?= $invOpen?'true':'false' ?>">
                <span><i class="fas fa-box"></i> Inventory</span>
                <i class="fas fa-chevron-right caret"></i>
            </button>
            

            <div class="submenu" <?= $invOpen?'':'hidden' ?>>
                <a href="inventory.php"><i class="fas fa-list"></i> Inventory List</a>
                <a href="inventory_reports.php"><i class="fas fa-chart-line"></i> Inventory Reports</a>
                <a href="physical_inventory.php"><i class="fas fa-warehouse"></i> Physical Inventory</a>
                <a href="barcode-print.php" class="active"><i class="fas fa-barcode"></i> Barcode Labels</a>
            </div>
        </div>

        <a href="services.php"><i class="fa fa-wrench"></i> Services</a>
        <a href="sales.php"><i class="fas fa-receipt"></i> Sales</a>
        <a href="accounts.php"><i class="fas fa-users"></i> Accounts & Branches</a>

        <div class="menu-group has-sub">
            <button class="menu-toggle" type="button" aria-expanded="<?= $toolsOpen?'true':'false' ?>">
                <span><i class="fas fa-screwdriver-wrench"></i> Data Tools</span>
                <i class="fas fa-chevron-right caret"></i>
            </button>
            <div class="submenu" <?= $toolsOpen?'':'hidden' ?>>
                <a href="/config/admin/backup_admin.php"><i class="fa-solid fa-database"></i> Backup & Restore</a>
                <a href="archive.php"><i class="fas fa-archive"></i> Archive</a>
            </div>
        </div>

        <a href="logs.php"><i class="fas fa-file-alt"></i> Logs</a>
        <?php endif; ?>


        <?php if ($role==='stockman'): ?>
        <div class="menu-group has-sub">
            <button class="menu-toggle" type="button" aria-expanded="<?= $invOpen?'true':'false' ?>">
                <span><i class="fas fa-box"></i> Inventory</span>
                <i class="fas fa-chevron-right caret"></i>
            </button>
            <div class="submenu" <?= $invOpen?'':'hidden' ?>>
                <a href="inventory.php"><i class="fas fa-list"></i> Inventory List</a>
                <a href="physical_inventory.php"><i class="fas fa-warehouse"></i> Physical Inventory</a>
                <a href="barcode-print.php" class="active"><i class="fas fa-barcode"></i> Barcode Labels</a>
            </div>
        </div>
        <?php endif; ?>

        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>
<div id="sidebarBackdrop"></div>

<!-- CONTENT (DASHBOARD-COMPATIBLE) -->
<div class="content">

      <div class="page-head">
        <div class="page-title">
            <i class="fa-solid fa-barcode fa-lg text-dark"></i>
            <h1>Barcode Labels</h1>

            <?php if ($role==='stockman'): ?>
            <span class="badge bg-warning text-dark"><?= h($stockmanBranchLabel) ?></span>
            <?php endif; ?>
        </div>

        <div class="page-actions">
            <?php if ($role==='admin' && $branch>0): ?>
                <a href="barcode-print.php" class="btn btn-outline-secondary">
                    <i class="fa-regular fa-circle-xmark me-1"></i> Clear
                </a>
            <?php endif; ?>

            <button onclick="window.print()" class="btn btn-primary">
                <i class="fa-solid fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <?php if ($role==='admin'): ?>
    <div class="branch-pills">
        <a href="barcode-print.php" class="branch-pill <?= $branch===0?'active':'' ?>">All Branches</a>

        <?php foreach ($branches as $b): ?>
            <a href="barcode-print.php?branch=<?= $b['branch_id'] ?>"
               class="branch-pill <?= ($branch==$b['branch_id'])?'active':'' ?>">
               <?= h($b['branch_name']) ?>
               <?php if ($b['branch_location']): ?>
                    <small><?= h($b['branch_location']) ?></small>
               <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="main-card">
        <div class="card-head">
            <i class="fa-solid fa-layer-group text-warning"></i>
            <h2>Categories</h2>
            <span class="ms-auto text-muted fw-semibold">Total Labels: <?= count($rows) ?></span>
        </div>

        <?php if (empty($groups)): ?>
            <div class="alert alert-warning">No products with barcodes found.</div>
        <?php else: ?>

            <?php foreach ($groups as $cat => $items): ?>
            <section class="category-print">
                <div class="section-bar">
                    <i class="fa-solid fa-folder-open"></i> <?= h($cat) ?>
                </div>

                <div class="labels-grid mb-4">
                <?php foreach ($items as $i => $p): ?>
                    <div class="label-card">
                        <svg id="bc<?= md5($cat.$i.$p['barcode']) ?>"></svg>
                        <div class="label-name"><?= h($p['name']) ?></div>
                        <div class="label-code"><?= h($p['barcode']) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div> <!-- END CONTENT -->

<!-- Add just above your JsBarcode/custom scripts -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.querySelectorAll('svg[id^="bc"]').forEach(svg => {
    const code = svg.parentElement.querySelector('.label-code').textContent.trim();
    const fmt = /^\d{13}$/.test(code) ? 'ean13' : 'code128';
    JsBarcode(svg, code, { format: fmt, width: 3, height: 50, displayValue: false });
});
</script>

<script>
// persist submenu state
(function(){
  const groups = document.querySelectorAll('.menu-group.has-sub');
  groups.forEach((g, idx) => {
    const btn = g.querySelector('.menu-toggle');
    const panel = g.querySelector('.submenu');
    if (!btn || !panel) return;

    const key = 'sidebar-sub-' + idx;
    const saved = localStorage.getItem(key);

    if (saved === 'open') {
      btn.setAttribute('aria-expanded', 'true');
      panel.hidden = false;
    }

    btn.addEventListener('click', () => {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!expanded));
      panel.hidden = expanded;
      localStorage.setItem(key, expanded ? 'closed' : 'open');
    });
  });
})();

</script>

<script src="notifications.js"></script>
<script src="sidebar.js"></script>
</body>
</html>

