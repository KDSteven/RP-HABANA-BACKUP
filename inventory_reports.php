<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.html"); exit; }
include "config/db.php";

$user_id   = $_SESSION["user_id"];
$role      = $_SESSION["role"] ?? "";
$branch_id = $_SESSION["branch_id"] ?? null;


$from = $_GET["from"] ?? date("Y-m-d");
$to   = $_GET["to"]   ?? date("Y-m-d");

$pidFilter = "";
if (!empty($_GET["product"])) {
    $pid = (int)$_GET["product"];
    $pidFilter = "AND m.product_id = $pid";
}

$branchScope = "";
if ($branch_id) {
    $branchScope = "AND m.branch_id = $branch_id";
}

/* =============================================================================
   1. MOVEMENT QUERY (WITH SALES FIELDS INCLUDED)
============================================================================= */
$movementSQL = "
SELECT * FROM (
    /* ================= STOCK IN ================= */
    SELECT 
        i.request_date AS date,
        i.product_id,
        p.product_name,
        i.branch_id,
        b.branch_name AS branch,
        NULL AS from_branch,
        NULL AS to_branch,
        'STOCK IN' AS type,
        i.quantity AS qty,
        i.id AS ref,
        NULL AS sale_id,
        NULL AS shift_id,
        NULL AS total,
        NULL AS payment,
        NULL AS change_given,
        NULL AS processed_by,
        NULL AS sale_status,
        NULL AS discount,
        NULL AS discount_type
    FROM stock_in_requests i
    JOIN products p ON p.product_id=i.product_id
    JOIN branches b ON b.branch_id=i.branch_id
    WHERE i.status='approved'
      AND i.initial = 0
      AND i.request_date >= '$from'
      AND i.request_date < DATE_ADD('$to', INTERVAL 1 DAY)

    UNION ALL

    /* ================= SALES ================= */
    SELECT
        s.sale_date,
        si.product_id,
        p.product_name,
        s.branch_id,
        b.branch_name,
        NULL,
        NULL,
        'SALE',
        si.quantity,
        s.sale_id AS ref,

        s.sale_id,
        s.shift_id,
        s.total,
        s.payment,
        s.change_given,
        s.processed_by,
        s.status AS sale_status,
        s.discount,
        s.discount_type

    FROM sales_items si
    JOIN sales s ON s.sale_id=si.sale_id
    JOIN products p ON p.product_id=si.product_id
    JOIN branches b ON b.branch_id=s.branch_id
    WHERE s.sale_date >= '$from'
      AND s.sale_date < DATE_ADD('$to', INTERVAL 1 DAY)

    UNION ALL

    /* ================= SERVICE USED ================= */
    SELECT
        s.sale_date,
        ss.service_id AS product_id,
        p.product_name,
        s.branch_id,
        b.branch_name,
        NULL,NULL,
        'SERVICE USED',
        1 AS qty,
        ss.id AS ref,

        s.sale_id,
        s.shift_id,
        s.total,
        s.payment,
        s.change_given,
        s.processed_by,
        s.status AS sale_status,
        s.discount,
        s.discount_type

    FROM sales_services ss
    JOIN sales s ON s.sale_id=ss.sale_id
    JOIN products p ON p.product_id=ss.service_id
    JOIN branches b ON b.branch_id=s.branch_id
    WHERE s.sale_date >= '$from'
      AND s.sale_date < DATE_ADD('$to', INTERVAL 1 DAY)

    UNION ALL

    /* ================= TRANSFER OUT ================= */
    SELECT 
        t.decision_date,
        t.product_id,
        p.product_name,
        t.source_branch AS branch_id,
        sb.branch_name,
        sb.branch_name,
        db.branch_name,
        'TRANSFER OUT',
        t.quantity,
        t.request_id,

        NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL

    FROM transfer_requests t
    JOIN products p ON p.product_id=t.product_id
    JOIN branches sb ON sb.branch_id=t.source_branch
    JOIN branches db ON db.branch_id=t.destination_branch
    WHERE t.status='approved'
      AND t.decision_date >= '$from'
      AND t.decision_date < DATE_ADD('$to', INTERVAL 1 DAY)

    UNION ALL

    /* ================= TRANSFER IN ================= */
    SELECT 
        t.decision_date,
        t.product_id,
        p.product_name,
        t.destination_branch AS branch_id,
        db.branch_name,
        sb.branch_name,
        db.branch_name,
        'TRANSFER IN',
        t.quantity,
        t.request_id,

        NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL

    FROM transfer_requests t
    JOIN products p ON p.product_id=t.product_id
    JOIN branches sb ON sb.branch_id=t.source_branch
    JOIN branches db ON db.branch_id=t.destination_branch
    WHERE t.status='approved'
      AND t.decision_date >= '$from'
      AND t.decision_date < DATE_ADD('$to', INTERVAL 1 DAY)

) AS m
WHERE 1=1
$pidFilter
$branchScope
ORDER BY m.date DESC
";


$movements = $conn->query($movementSQL)->fetch_all(MYSQLI_ASSOC);

/* =============================================================================
   2. SUMMARY FIRST PASS
============================================================================= */
$summary = [];
foreach ($movements as $m) {
    $key = $m["product_id"]."-".$m["branch_id"];

    if (!isset($summary[$key])) {
        $summary[$key] = [
            "product_id"=>$m["product_id"],
            "product"=>$m["product_name"],
            "branch_id"=>$m["branch_id"],
            "branch"=>$m["branch"],
            "begin"=>0,
            "stockIn"=>0,
            "sales"=>0,
            "serviceUsed"=>0,
            "transferIn"=>0,
            "transferOut"=>0,
            "ending"=>0
        ];
    }

    switch ($m["type"]) {
        case "STOCK IN":      $summary[$key]["stockIn"] += $m["qty"]; break;
        case "SALE":          $summary[$key]["sales"] += $m["qty"]; break;
        case "SERVICE USED":  $summary[$key]["serviceUsed"] += 1; break;
        case "TRANSFER IN":   $summary[$key]["transferIn"] += $m["qty"]; break;
        case "TRANSFER OUT":  $summary[$key]["transferOut"] += $m["qty"]; break;
    }
}

/* =============================================================================
   3. BEGINNING STOCK ENGINE
============================================================================= */
$beginSQL = "
SELECT product_id, branch_id, movement_type, SUM(qty) AS qty
FROM (

    /* STOCK IN */
    SELECT 
    product_id,
    branch_id,
    SUM(quantity) AS qty,
    'IN' AS movement_type
FROM stock_in_requests
WHERE status='approved'
  AND (
        initial = 1         -- Always count initial stock
        OR DATE(request_date) < '$from'
      )
GROUP BY product_id, branch_id


    UNION ALL

    /* SALES */
    SELECT
        si.product_id,
        s.branch_id,
        SUM(si.quantity),
        'SALE' AS movement_type
    FROM sales_items si
    JOIN sales s ON s.sale_id = si.sale_id
    WHERE DATE(s.sale_date) <= '$from'
    GROUP BY si.product_id, s.branch_id

    UNION ALL

    /* SERVICE */
    SELECT
        ss.service_id AS product_id,
        s.branch_id,
        COUNT(*),
        'SERVICE' AS movement_type
    FROM sales_services ss
    JOIN sales s ON s.sale_id = ss.sale_id
    WHERE DATE(s.sale_date) <= '$from'
    GROUP BY ss.service_id, s.branch_id

    UNION ALL

    /* TRANSFER IN */
    SELECT
        product_id,
        destination_branch AS branch_id,
        SUM(quantity),
        'TIN' AS movement_type
    FROM transfer_requests
    WHERE status='approved'
      AND DATE(decision_date) <= '$from'
    GROUP BY product_id, destination_branch

    UNION ALL

    /* TRANSFER OUT */
    SELECT
        product_id,
        source_branch AS branch_id,
        SUM(quantity),
        'TOUT' AS movement_type
    FROM transfer_requests
    WHERE status='approved'
      AND DATE(decision_date) <= '$from'
    GROUP BY product_id, source_branch

) AS x
GROUP BY product_id, branch_id, movement_type
";


$beginRows = $conn->query($beginSQL)->fetch_all(MYSQLI_ASSOC);
/* =============================================================
   BEGIN-DEBUG PACK (FULL)
   Shows exactly how beginning stock is calculated.
   -------------------------------------------------------------
   Place this block RIGHT AFTER:
   $beginRows = $conn->query($beginSQL)->fetch_all(MYSQLI_ASSOC);
   ============================================================= 

// 1) Console log for browser
echo "<script>console.log('=== BEGIN DEBUG RAW DATA ===');</script>";

foreach ($beginRows as $dbg) {
    $line = json_encode($dbg, JSON_UNESCAPED_UNICODE);
    echo "<script>console.log(" . json_encode($line) . ");</script>";
}

// 2) On-screen debug table
echo "
<br><br>
<div class='card' style='background:#111; border:1px solid #444; padding:20px;'>
<h3 style='color:#0f0;'>BEGIN-DEBUG TABLE</h3>
<table class='table table-dark table-striped table-bordered'>
    <thead>
        <tr>
            <th>Product ID</th>
            <th>Branch ID</th>
            <th>Movement Type</th>
            <th>Qty</th>
        </tr>
    </thead>
    <tbody>
";

foreach ($beginRows as $b) {
    echo "
    <tr>
        <td>{$b['product_id']}</td>
        <td>{$b['branch_id']}</td>
        <td>{$b['movement_type']}</td>
        <td>{$b['qty']}</td>
    </tr>
    ";
}

echo "
    </tbody>
</table>
</div>
";

// 3) PHP raw dump toggle
if (isset($_GET['debug_begin'])) {
    echo "<pre style='color:#0f0; background:#000; padding:15px;'>";
    var_dump($beginRows);
    echo "</pre>";
}
*/
foreach ($beginRows as $b) {
    $key = $b["product_id"]."-".$b["branch_id"];

    if (!isset($summary[$key])) {

        // Fetch names directly from DB
        $p = $conn->query("SELECT product_name FROM products WHERE product_id = {$b['product_id']} LIMIT 1")->fetch_assoc();
        $productName = $p["product_name"] ?? "Unknown";

        $br = $conn->query("SELECT branch_name FROM branches WHERE branch_id = {$b['branch_id']} LIMIT 1")->fetch_assoc();
        $branchName = $br["branch_name"] ?? "Unknown";

        $summary[$key] = [
            "product_id"  => $b["product_id"],
            "product"     => $productName,
            "branch_id"   => $b["branch_id"],
            "branch"      => $branchName,

            "begin"       => 0,
            "stockIn"     => 0,
            "sales"       => 0,
            "serviceUsed" => 0,
            "transferIn"  => 0,
            "transferOut" => 0,
            "ending"      => 0
        ];
    }

    switch ($b["movement_type"]) {
        case "IN":     $summary[$key]["begin"] += $b["qty"]; break;
        case "SALE":   $summary[$key]["begin"] -= $b["qty"]; break;
        case "SERVICE":$summary[$key]["begin"] -= $b["qty"]; break;
        case "TIN":    $summary[$key]["begin"] += $b["qty"]; break;
        case "TOUT":   $summary[$key]["begin"] -= $b["qty"]; break;
    }
}


/* =============================================================================
   4. FINAL ENDING BALANCE
============================================================================= */
// REMOVE rows that have NO MOVEMENT inside the selected date range
foreach ($summary as $k => $s) {
    $hasMovement =
        $s['stockIn'] != 0 ||
        $s['sales'] != 0 ||
        $s['serviceUsed'] != 0 ||
        $s['transferIn'] != 0 ||
        $s['transferOut'] != 0;

    if (!$hasMovement) {
        unset($summary[$k]);  // Hide products with no activity inside the date range
    }
}


// Now compute ENDING (AFTER filtering)
foreach ($summary as $k => $s) {
    $summary[$k]['ending'] =
          $s['begin']
        + $s['stockIn']
        + $s['transferIn']
        - $s['sales']
        - $s['serviceUsed']
        - $s['transferOut'];
}
/* =============================================================================
   5. LOAD PRODUCTS LIST FOR DROPDOWN
============================================================================= */
$allProducts = $conn->query("SELECT product_id, product_name FROM products ORDER BY product_name ASC");

/* =============================================================================
   6. HTML + UI
============================================================================= */

// Notifications (Pending Approvals)
$pending = $conn->query("SELECT COUNT(*) AS pending FROM transfer_requests WHERE status='Pending'")->fetch_assoc()['pending'] ?? 0;

$pendingTransfers = (int)$conn->query("SELECT COUNT(*) AS c FROM transfer_requests WHERE status='pending'")->fetch_assoc()['c'];
$pendingStockIns  = (int)$conn->query("SELECT COUNT(*) AS c FROM stock_in_requests WHERE status='pending'")->fetch_assoc()['c'];

$pendingTotalInventory = $pendingTransfers + $pendingStockIns;

// Fetch current user's full name
$currentName = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($fetchedName);
    if ($stmt->fetch()) {
        $currentName = $fetchedName;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Inventory Reports — MODEL-B</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/R.P.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/inventory_reports.css">
<link rel="stylesheet" href="css/notifications.css">

</head>
<body class="inventory-reports-page">

<!-- Sidebar -->
<div class="sidebar" id="mainSidebar">
  <!-- Toggle button always visible on the rail -->
  <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="false">
    <i class="fas fa-bars" aria-hidden="true"></i>
  </button>

  <!-- Wrap existing sidebar content so we can hide/show it cleanly -->
  <div class="sidebar-content">
    <h2 class="user-heading">
      <span class="role"><?= htmlspecialchars(strtoupper($role), ENT_QUOTES) ?></span>
      <?php if ($currentName !== ''): ?>
        <span class="name">(<?= htmlspecialchars($currentName, ENT_QUOTES) ?>)</span>
      <?php endif; ?>
      <span class="notif-wrapper">
        <i class="fas fa-bell" id="notifBell"></i>
        <span id="notifCount" <?= $pending > 0 ? '' : 'style="display:none;"' ?>><?= (int)$pending ?></span>
      </span>
    </h2>

        <!-- Common -->
    <a href="dashboard.php"><i class="fas fa-tv"></i> Dashboard</a>

    <?php
// put this once before the sidebar (top of file is fine)
$self = strtolower(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$isArchive = substr($self, 0, 7) === 'archive'; // matches archive.php, archive_view.php, etc.
$invOpen   = in_array($self, ['inventory.php','physical_inventory.php'], true);
$toolsOpen = ($self === 'backup_admin.php' || $isArchive);
?>

<!-- Admin Links -->
<?php if ($role === 'admin'): ?>

  <!-- Inventory group (unchanged) -->
<div class="menu-group has-sub">
  <button class="menu-toggle" type="button" aria-expanded="<?= $invOpen ? 'true' : 'false' ?>">
  <span><i class="fas fa-box"></i> Inventory
    <?php if ($pendingTotalInventory > 0): ?>
      <span class="badge-pending"><?= $pendingTotalInventory ?></span>
    <?php endif; ?>
  </span>
    <i class="fas fa-chevron-right caret"></i>
  </button>
  <div class="submenu" <?= $invOpen ? '' : 'hidden' ?>>
    <a href="inventory.php#pending-requests" class="<?= $self === 'inventory.php#pending-requests' ? 'active' : '' ?>">
      <i class="fas fa-list"></i> Inventory List
        <?php if ($pendingTotalInventory > 0): ?>
          <span class="badge-pending"><?= $pendingTotalInventory ?></span>
        <?php endif; ?>
    </a>
    <a href="inventory_reports.php" class="<?= $self === 'inventory_reports.php' ? 'active' : '' ?>">
      <i class="fas fa-chart-line"></i> Inventory Reports
    </a>
    <a href="physical_inventory.php" class="<?= $self === 'physical_inventory.php' ? 'active' : '' ?>">
      <i class="fas fa-warehouse"></i> Physical Inventory
    </a>
        <a href="barcode-print.php<?php 
        $b = (int)($_SESSION['current_branch_id'] ?? 0);
        echo $b ? ('?branch='.$b) : '';?>" class="<?= $self === 'barcode-print.php' ? 'active' : '' ?>">
        <i class="fas fa-barcode"></i> Barcode Labels
    </a>
  </div>
</div>

    <a href="services.php" class="<?= $self === 'services.php' ? 'active' : '' ?>">
      <i class="fa fa-wrench" aria-hidden="true"></i> Services
    </a>

  <!-- Sales (normal link with active state) -->
  <a href="sales.php" class="<?= $self === 'sales.php' ? 'active' : '' ?>">
    <i class="fas fa-receipt"></i> Sales
  </a>


<a href="accounts.php" class="<?= $self === 'accounts.php' ? 'active' : '' ?>">
  <i class="fas fa-users"></i> Accounts & Branches
</a>

  <!-- NEW: Backup & Restore group with Archive inside -->
  <div class="menu-group has-sub">
    <button class="menu-toggle" type="button" aria-expanded="<?= $toolsOpen ? 'true' : 'false' ?>">
      <span><i class="fas fa-screwdriver-wrench me-2"></i> Data Tools</span>
      <i class="fas fa-chevron-right caret"></i>
    </button>
    <div class="submenu" <?= $toolsOpen ? '' : 'hidden' ?>>
      <a href="/config/admin/backup_admin.php" class="<?= $self === 'backup_admin.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-database"></i> Backup & Restore
      </a>
      <a href="archive.php" class="<?= $isArchive ? 'active' : '' ?>">
        <i class="fas fa-archive"></i> Archive
      </a>
    </div>
  </div>

  <a href="logs.php" class="<?= $self === 'logs.php' ? 'active' : '' ?>">
    <i class="fas fa-file-alt"></i> Logs
  </a>

<?php endif; ?>



   <!-- Stockman Links -->
  <?php if ($role === 'stockman'): ?>
    <div class="menu-group has-sub">
      <button class="menu-toggle" type="button" aria-expanded="<?= $invOpen ? 'true' : 'false' ?>">
        <span><i class="fas fa-box"></i> Inventory</span>
        <i class="fas fa-chevron-right caret"></i>
      </button>
      <div class="submenu" <?= $invOpen ? '' : 'hidden' ?>>
        <a href="inventory.php" class="<?= $self === 'inventory.php' ? 'active' : '' ?>">
          <i class="fas fa-list"></i> Inventory List
        </a>
        <a href="physical_inventory.php" class="<?= $self === 'physical_inventory.php' ? 'active' : '' ?>">
          <i class="fas fa-warehouse"></i> Physical Inventory
        </a>
        <!-- Stockman can access Barcode Labels; server forces their branch -->
        <a href="barcode-print.php" class="<?= $self === 'barcode-print.php' ? 'active' : '' ?>">
          <i class="fas fa-barcode"></i> Barcode Labels
        </a>
      </div>
    </div>
  <?php endif; ?>
    <!-- Staff Links -->
    <?php if ($role === 'staff'): ?>
        <a href="pos.php"><i class="fas fa-cash-register"></i> Point of Sale</a>
        <a href="history.php"><i class="fas fa-history"></i> Sales History</a>
    <?php endif; ?>

    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>
  </div>
</div>


<div class="main-content">
<h2>Inventory Reports (MODEL-B)</h2>

<form method="get" class="row g-3 mb-4">
    <div class="col-md-3">
        <label>From</label>
        <input type="date" name="from" class="form-control"
               value="<?= htmlspecialchars($from) ?>">
    </div>

    <div class="col-md-3">
        <label>To</label>
        <input type="date" name="to" class="form-control"
               value="<?= htmlspecialchars($to) ?>">
    </div>

    <div class="col-md-4">
        <label>Product</label>
        <select name="product" class="form-control">
            <option value="">All Products</option>
            <?php while($p=$allProducts->fetch_assoc()): ?>
                <option value="<?= $p['product_id'] ?>"
                    <?= (!empty($_GET['product']) && $_GET['product']==$p['product_id']) 
                        ? "selected" 
                        : "" ?>>
                    <?= htmlspecialchars($p['product_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-md-2">
        <label>&nbsp;</label>
        <button class="btn btn-primary w-100">Apply</button>
    </div>
</form>


<!-- MOVEMENT TABLE -->
<h3>Movement Details</h3>
<table class="table table-light table-striped">
<thead>
<tr>
<th>Date</th><th>Product</th><th>Branch</th>
<th>From</th><th>To</th><th>Type</th><th>Qty</th><th>Ref</th>
</tr>
</thead>
<tbody>
<?php if (!empty($movements)): foreach($movements as $m): ?>

<tr>
<td><?=date("M d, Y", strtotime($m['date']))?></td>
<td><?=$m['product_name']?></td>
<td><?=$m['branch']?></td>
<td><?=$m['from_branch'] ?: "-"?></td>
<td><?=$m['to_branch'] ?: "-"?></td>
<td><?=$m['type']?></td>
<td><?=$m['qty']?></td>
<td><?=$m['ref']?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8" class="text-center text-muted">No movements found</td></tr>
<?php endif; ?>
</tbody>
</table>

<!-- SUMMARY TABLE -->
<h3 class="mt-4">Summary Report</h3>
<table class="table table-light table-striped">
<thead>
<tr>
<th>Product</th><th>Branch</th><th>Begin</th><th>In</th>
<th>Sales</th><th>Service</th><th>T.in</th><th>T.out</th>
<th>Ending</th>
</tr>
<tbody>
<?php if (!empty($summary)): ?>
    <?php foreach ($summary as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['product']) ?></td>
            <td><?= htmlspecialchars($s['branch']) ?></td>
            <td><?= $s['begin'] ?></td>
            <td><?= $s['stockIn'] ?></td>
            <td><?= $s['sales'] ?></td>
            <td><?= $s['serviceUsed'] ?></td>
            <td><?= $s['transferIn'] ?></td>
            <td><?= $s['transferOut'] ?></td>
            <td><b><?= $s['ending'] ?></b></td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="10" class="text-center text-muted">No summary rows</td>
    </tr>
<?php endif; ?>
</tbody>

</table>

</div>
</div>
<script>
    document.querySelectorAll('.menu-group .menu-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const submenu = btn.parentElement.querySelector('.submenu');
        const expanded = btn.getAttribute('aria-expanded') === 'true';

        btn.setAttribute('aria-expanded', !expanded);
        submenu.hidden = expanded;
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="notifications.js"></script>
<script src="sidebar.js"></script>
</body>
</html>
