<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.html"); exit; }
include "config/db.php";
require_once "vendor/autoload.php";
use Dompdf\Dompdf;
// Load all product names
$productNames = [];
$res = $conn->query("SELECT product_id, product_name FROM products");
while ($r = $res->fetch_assoc()) {
    $productNames[$r["product_id"]] = $r["product_name"];
}

// Load all branch names
$branchNames = [];
$res = $conn->query("SELECT branch_id, branch_name FROM branches");
while ($r = $res->fetch_assoc()) {
    $branchNames[$r["branch_id"]] = $r["branch_name"];
}

$user_id   = $_SESSION["user_id"];
$role      = $_SESSION["role"] ?? "";
$branch_id = $_SESSION["branch_id"] ?? null;


$from = $_GET["from"] ?? date("Y-m-d");
$to   = $_GET["to"]   ?? date("Y-m-d");

// FIX: include the whole last day (important for DATETIME fields)
$toEnd = $to . " 23:59:59";

$pidFilter = "";
if (!empty($_GET["product"])) {
    $pid = (int)$_GET["product"];
    $pidFilter = "AND m.product_id = $pid";
}

// BRANCH FILTER
$branchScope = "";
if ($role === 'admin') {
    if (!empty($_GET['branch'])) {
        $branchScope = "AND m.branch_id = " . intval($_GET["branch"]);
    }
} else {
    // staff/stockman restricted
    if ($branch_id) {
        $branchScope = "AND m.branch_id = $branch_id";
    }
}

/* =============================================================================
   1. MOVEMENT QUERY (WITH SALES FIELDS INCLUDED)
============================================================================= */
$movementSQL = "
SELECT * FROM (

    /* ===================== STOCK IN ===================== */
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
        i.id AS ref

    FROM stock_in_requests i
    JOIN products p ON p.product_id = i.product_id
    JOIN branches b ON b.branch_id = i.branch_id
    WHERE i.status='approved'
      AND i.request_date BETWEEN '$from' AND '$toEnd'


    UNION ALL


    /* ==========================================================
       SALES — EXCLUDE ITEMS THAT ARE USED IN A SERVICE
       ========================================================== */
    SELECT
        s.sale_date AS date,
        si.product_id,
        p.product_name,
        s.branch_id,
        b.branch_name,
        NULL AS from_branch,
        NULL AS to_branch,
        'SALE' AS type,
        SUM(si.quantity) AS qty,
        s.sale_id AS ref

    FROM sales_items si
    JOIN sales s ON s.sale_id = si.sale_id
    JOIN products p ON p.product_id = si.product_id
    JOIN branches b ON b.branch_id = s.branch_id
    LEFT JOIN (
        SELECT ss.sale_id, sm.product_id
        FROM sales_services ss
        JOIN service_materials sm ON sm.service_id = ss.service_id
    ) srv ON srv.sale_id = s.sale_id AND srv.product_id = si.product_id

    WHERE srv.product_id IS NULL
      AND s.sale_date BETWEEN '$from' AND '$toEnd'

    GROUP BY s.sale_id, si.product_id


    UNION ALL


    /* ==========================================================
       SERVICE USED — MATERIALS PER SALE
       ========================================================== */
    SELECT
        s.sale_date AS date,
        sm.product_id,
        p.product_name,
        s.branch_id,
        b.branch_name,
        NULL AS from_branch,
        NULL AS to_branch,
        'SERVICE USED' AS type,
        SUM(sm.qty_needed) AS qty,
        s.sale_id AS ref

    FROM sales_services ss
    JOIN sales s ON s.sale_id = ss.sale_id
    JOIN service_materials sm ON sm.service_id = ss.service_id
    JOIN products p ON p.product_id = sm.product_id
    JOIN branches b ON b.branch_id = s.branch_id

    WHERE s.sale_date BETWEEN '$from' AND '$toEnd'
    GROUP BY s.sale_id, sm.product_id


    UNION ALL


    /* ===================== TRANSFERS ===================== */
    SELECT 
        t.decision_date AS date,
        t.product_id,
        p.product_name,
        t.source_branch AS branch_id,
        sb.branch_name,
        sb.branch_name AS from_branch,
        db.branch_name AS to_branch,
        'TRANSFER OUT' AS type,
        t.quantity AS qty,
        t.request_id AS ref

    FROM transfer_requests t
    JOIN products p ON p.product_id = t.product_id
    JOIN branches sb ON sb.branch_id = t.source_branch
    JOIN branches db ON db.branch_id = t.destination_branch
    WHERE t.status='approved'
      AND t.decision_date BETWEEN '$from' AND '$toEnd'

    UNION ALL

    SELECT 
        t.decision_date AS date,
        t.product_id,
        p.product_name,
        t.destination_branch AS branch_id,
        db.branch_name,
        sb.branch_name AS from_branch,
        db.branch_name AS to_branch,
        'TRANSFER IN' AS type,
        t.quantity AS qty,
        t.request_id AS ref

    FROM transfer_requests t
    JOIN products p ON p.product_id = t.product_id
    JOIN branches sb ON sb.branch_id = t.source_branch
    JOIN branches db ON db.branch_id = t.destination_branch
    WHERE t.status='approved'
      AND t.decision_date BETWEEN '$from' AND '$toEnd'

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
    $key = $m["product_id"]."-".$m["branch_id"]. "-" . $m["date"];

    if (!isset($summary[$key])) {
        $summary[$key] = [
            "date" => $m["date"], 
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
SELECT 
    product_id,
    branch_id,
    SUM(begin_qty) AS qty
FROM (

    /* ========== BASE INITIAL STOCK ========== */
    SELECT 
        p.product_id,
        i.branch_id,
        p.initial_stock AS begin_qty
    FROM products p
    JOIN inventory i ON i.product_id = p.product_id


    UNION ALL

    /* ========== STOCK-IN BEFORE DATE RANGE ========== */
    SELECT
        product_id,
        branch_id,
        SUM(quantity) AS begin_qty
    FROM stock_in_requests
    WHERE status='approved'
      AND request_date < '$from'
    GROUP BY product_id, branch_id


    UNION ALL

    /* ========== SALES BEFORE DATE RANGE (subtract) ========== */
    SELECT
        si.product_id,
        s.branch_id,
        -SUM(si.quantity) AS begin_qty
    FROM sales_items si
    JOIN sales s ON s.sale_id = si.sale_id
    WHERE s.sale_date < '$from'
    GROUP BY si.product_id, s.branch_id


    UNION ALL

    /* ========== SERVICE USED BEFORE DATE RANGE (subtract) ========== */
    SELECT
        sm.product_id,
        s.branch_id,
        -SUM(sm.qty_needed) AS begin_qty
    FROM sales_services ss
    JOIN sales s ON s.sale_id = ss.sale_id
    JOIN service_materials sm ON sm.service_id = ss.service_id
    WHERE s.sale_date < '$from'
    GROUP BY sm.product_id, s.branch_id


    UNION ALL

    /* ========== TRANSFER IN BEFORE DATE RANGE ========== */
    SELECT
        product_id,
        destination_branch AS branch_id,
        SUM(quantity) AS begin_qty
    FROM transfer_requests
    WHERE status='approved'
      AND decision_date < '$from'
    GROUP BY product_id, destination_branch


    UNION ALL

    /* ========== TRANSFER OUT BEFORE DATE RANGE (subtract) ========== */
    SELECT
        product_id,
        source_branch AS branch_id,
        -SUM(quantity) AS begin_qty
    FROM transfer_requests
    WHERE status='approved'
      AND decision_date < '$from'
    GROUP BY product_id, source_branch

) AS x
GROUP BY product_id, branch_id
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
    $key = $b["product_id"] . "-" . $b["branch_id"];

    if (!isset($summary[$key])) {
        if (!isset($productNames[$b['product_id']])) continue;
        if (!isset($branchNames[$b['branch_id']])) continue;

        $summary[$key] = [
            "product_id"  => $b["product_id"],
            "product"     => $productNames[$b["product_id"]],
            "branch_id"   => $b["branch_id"],
            "branch"      => $branchNames[$b["branch_id"]],
            "begin"       => 0,
            "stockIn"     => 0,
            "sales"       => 0,
            "serviceUsed" => 0,
            "transferIn"  => 0,
            "transferOut" => 0,
            "ending"      => 0
        ];
    }

    // NEW: qty already has + or - sign applied in SQL
    $summary[$key]["begin"] += $b["qty"];
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
$allProducts = $conn->query("
    SELECT product_id, product_name
    FROM products
    WHERE archived = 0
    ORDER BY product_name ASC
");

/* =============================================================================
   6. HTML + UI
============================================================================= */
if (isset($_GET["download"]) && $_GET["download"] === "excel") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=summary_report.csv");
    header("Cache-Control: max-age=0");

    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Report header rows with spacing
    fputcsv($output, ["Summary Report"]);
    fputcsv($output, ["Date:", date("F d, Y h:i A")]);
    fputcsv($output, []); // empty line for spacing

    // Column headers
    fputcsv($output, [
        'Date','Product','Branch','Beginning','Stock In','Sales','Service','Transfer In','Transfer Out','Ending'
    ]);

    // Data rows
    foreach ($summary as $row) {
        fputcsv($output, [
            "'".$row['date'],
            $row['product'],
            $row['branch'],
            $row['begin'],
            $row['stockIn'],
            $row['sales'],
            $row['serviceUsed'],
            $row['transferIn'],
            $row['transferOut'],
            $row['ending']
        ]);
    }

    fclose($output);
    exit;
}


if (isset($_GET["download"]) && $_GET["download"] === "pdf") {

    require_once "vendor/autoload.php";
    $dompdf = new Dompdf();

    ob_start();
?>
<style>
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
th, td {
    border: 1px solid #333;
    padding: 4px;
    text-align: center;
}
h2, .header {
    text-align: center;
    margin-bottom: 10px;
}
</style>
<div class="header">
   <strong>RP Habana Reports</strong><br>
    <small>Summary Report</small><br>
    <small><?= date("F d, Y h:i A") ?></small>
</div>


<table>
    <thead>
        <tr>
            <th>DATE</th>
            <th>Product</th>
            <th>Branch</th>
            <th>Beginning</th>
            <th>In</th>
            <th>Sales</th>
            <th>Service</th>
            <th>Transfer In</th>
            <th>Transfer Out</th>
            <th>Ending</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($summary as $s): ?>
        <tr>
                <td><?= $s["date"] ?></td>
            <td><?= $s["product"] ?></td>
            <td><?= $s["branch"] ?></td>
            <td><?= $s["begin"] ?></td>
            <td><?= $s["stockIn"] ?></td>
            <td><?= $s["sales"] ?></td>
            <td><?= $s["serviceUsed"] ?></td>
            <td><?= $s["transferIn"] ?></td>
            <td><?= $s["transferOut"] ?></td>
            <td><?= $s["ending"] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
    $html = ob_get_clean();
    $dompdf->loadHtml($html);
    $dompdf->setPaper("A4", "landscape");
    $dompdf->render();
    $dompdf->stream("summary_report.pdf", ["Attachment" => true]);
    exit;
}

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
<title>Inventory Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/R.P.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/inventory_reports.css">
<link rel="stylesheet" href="css/notifications.css">

</head>
<body class="inventory-page">
<!-- Toggle button (ALWAYS outside sidebar) -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="false">
  <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar expanded" id="mainSidebar">

  <div class="sidebar-content">
    
    <h2 class="user-heading">
      <span class="role"><?= htmlspecialchars(strtoupper($role), ENT_QUOTES) ?></span>

      <?php if ($currentName !== ''): ?>
      <span class="name">(<?= htmlspecialchars($currentName, ENT_QUOTES) ?>)</span>
      <?php endif; ?>

      <span class="notif-wrapper">
        <i class="fas fa-bell" id="notifBell"></i>
        <span id="notifCount" <?= $pending > 0 ? '' : 'style="display:none;"' ?>>
          <?= (int)$pending ?>
        </span>
      </span>
    </h2>

    <!-- Common -->
    <a href="dashboard.php" class="<?= $self === 'dashboard.php' ? 'active' : '' ?>">
      <i class="fas fa-tv"></i> Dashboard
    </a>

    <?php
      $self = strtolower(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
      $isArchive = substr($self,0,7)==='archive';
      $invOpen   = in_array($self,['inventory.php','physical_inventory.php'],true);
      $toolsOpen = ($self==='backup_admin.php' || $isArchive);
    ?>

    <!-- ADMIN -->
    <?php if ($role === 'admin'): ?>

    <!-- Inventory -->
    <div class="menu-group has-sub">
      <button class="menu-toggle" aria-expanded="<?= $invOpen ? 'true' : 'false' ?>">
        <span>
          <i class="fas fa-box"></i> Inventory
          <?php if ($pendingTotalInventory > 0): ?>
          <span class="badge-pending"><?= $pendingTotalInventory ?></span>
          <?php endif; ?>
        </span>
        <i class="fas fa-chevron-right caret"></i>
      </button>

      <div class="submenu" <?= $invOpen ? '' : 'hidden' ?>>
        <a href="inventory.php" class="<?= $self==='inventory.php'?'active':'' ?>">
          <i class="fas fa-list"></i> Inventory List
        </a>

        <a href="inventory_reports.php" class="<?= $self==='inventory_reports.php'?'active':'' ?>">
          <i class="fas fa-chart-line"></i> Inventory Reports
        </a>

        <a href="physical_inventory.php" class="<?= $self==='physical_inventory.php'?'active':'' ?>">
          <i class="fas fa-warehouse"></i> Physical Inventory
        </a>

        <a href="barcode-print.php<?php $b=(int)($_SESSION['current_branch_id']??0); echo $b?'?branch='.$b:'';?>"
           class="<?= $self==='barcode-print.php'?'active':'' ?>">
          <i class="fas fa-barcode"></i> Barcode Labels
        </a>
      </div>
    </div>

    <a href="services.php" class="<?= $self==='services.php'?'active':'' ?>">
      <i class="fa fa-wrench"></i> Services
    </a>

    <a href="sales.php" class="<?= $self==='sales.php'?'active':'' ?>">
      <i class="fas fa-receipt"></i> Sales
    </a>

    <a href="accounts.php" class="<?= $self==='accounts.php'?'active':'' ?>">
      <i class="fas fa-users"></i> Accounts & Branches
    </a>

    <!-- Data Tools -->
    <div class="menu-group has-sub">
      <button class="menu-toggle" aria-expanded="<?= $toolsOpen?'true':'false' ?>">
        <span><i class="fas fa-screwdriver-wrench"></i> Data Tools</span>
        <i class="fas fa-chevron-right caret"></i>
      </button>

      <div class="submenu" <?= $toolsOpen ? '' : 'hidden' ?>>
        <a href="/config/admin/backup_admin.php"
           class="<?= $self==='backup_admin.php'?'active':'' ?>">
          <i class="fa-solid fa-database"></i> Backup & Restore
        </a>

        <a href="archive.php" class="<?= $isArchive?'active':'' ?>">
          <i class="fas fa-archive"></i> Archive
        </a>
      </div>
    </div>

    <a href="logs.php" class="<?= $self==='logs.php'?'active':'' ?>">
      <i class="fas fa-file-alt"></i> Logs
    </a>

    <?php endif; ?>


    <!-- STOCKMAN -->
    <?php if ($role === 'stockman'): ?>
    <div class="menu-group has-sub">
      <button class="menu-toggle" aria-expanded="<?= $invOpen?'true':'false' ?>">
        <span><i class="fas fa-box"></i> Inventory</span>
        <i class="fas fa-chevron-right caret"></i>
      </button>

      <div class="submenu" <?= $invOpen?'':'hidden' ?>>
        <a href="inventory.php" class="<?= $self==='inventory.php'?'active':'' ?>">
          <i class="fas fa-list"></i> Inventory List
        </a>

        <a href="physical_inventory.php" class="<?= $self==='physical_inventory.php'?'active':'' ?>">
          <i class="fas fa-warehouse"></i> Physical Inventory
        </a>

        <a href="barcode-print.php" class="<?= $self==='barcode-print.php'?'active':'' ?>">
          <i class="fas fa-barcode"></i> Barcode Labels
        </a>
      </div>
    </div>
    <?php endif; ?>


    <!-- STAFF -->
    <?php if ($role === 'staff'): ?>
    <a href="pos.php"><i class="fas fa-cash-register"></i> Point of Sale</a>
    <a href="history.php"><i class="fas fa-history"></i> Sales History</a>
    <a href="shift_summary.php" class="<?= $self==='shift_summary.php'?'active':'' ?>">
      <i class="fa-solid fa-clipboard-check"></i> Shift Summary
    </a>
    <?php endif; ?>

    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>

  </div><!-- /sidebar-content -->

</div><!-- /sidebar -->


<div class="main-content content">
  <div class="container">
<h2>Inventory Reports</h2>
<form method="get" class="row g-2 align-items-end mb-4">

    <!-- From Date -->
    <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
    </div>

    <!-- To Date -->
    <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
    </div>

    <!-- Branch Filter (Admin Only) -->
    <?php if ($role === 'admin'): ?>
    <div class="col-md-2">
        <label class="form-label">Branch</label>
        <select name="branch" class="form-control">
            <option value="">All Branches</option>
            <?php foreach ($branchNames as $bid => $bname): ?>
                <option value="<?= $bid ?>" <?= (isset($_GET["branch"]) && $_GET["branch"] == $bid) ? "selected" : "" ?>>
                    <?= htmlspecialchars($bname) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <!-- Product Filter -->
    <div class="col-md-3">
        <label class="form-label">Product</label>
        <select name="product" class="form-control">
            <option value="">All Products</option>
            <?php while($p=$allProducts->fetch_assoc()): ?>
                <option value="<?= $p['product_id'] ?>" <?= (!empty($_GET['product']) && $_GET['product']==$p['product_id']) ? "selected" : "" ?>>
                    <?= htmlspecialchars($p['product_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <!-- Apply Button -->
    <div class="col-md-1">
        <button class="btn btn-primary w-100">Apply</button>
    </div>

    <!-- Export Buttons -->
    <div class="col-md-2 d-flex gap-2">
        <a href="inventory_reports.php?download=excel&<?= http_build_query($_GET) ?>" class="btn btn-success flex-grow-1">
            <i class="fa fa-file-excel me-1"></i> Excel
        </a>
        <a href="inventory_reports.php?download=pdf&<?= http_build_query($_GET) ?>" class="btn btn-danger flex-grow-1">
            <i class="fa fa-file-pdf me-1"></i> PDF
        </a>
    </div>

</form>

<!-- MOVEMENT TABLE -->
<h3>Movement Details</h3>
  <div class="table-scroll">
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
  </div>

<!-- SUMMARY TABLE -->
<h3 class="mt-4">Summary Report</h3>
  <div class="table-scroll">
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
  <!-- filter branch -->
<script>
function autoRange() {
    let r = document.querySelector('[name=range]').value;
    let f = document.querySelector('[name=from]');
    let t = document.querySelector('[name=to]');

    let now = new Date();
    let first, last;

    if (r === 'daily') {
        first = last = now;
    } 
    else if (r === 'weekly') {
        let day = now.getDay();
        first = new Date(now); first.setDate(now.getDate() - day);
        last = new Date(first); last.setDate(first.getDate() + 6);
    }
    else if (r === 'monthly') {
        first = new Date(now.getFullYear(), now.getMonth(), 1);
        last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    } 
    else {
        return;
    }

    f.value = first.toISOString().substring(0, 10);
    t.value = last.toISOString().substring(0, 10);
}
</script>

<script>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="notifications.js"></script>
<script src="sidebar.js"></script>
</body>
</html>
