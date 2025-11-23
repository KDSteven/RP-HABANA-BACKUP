<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}
include 'config/db.php';

// -------------------- USER INFO --------------------
$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? '';
$branch_id = $_SESSION['branch_id'] ?? null;
// If no branch assigned (e.g. admin), include all branches
$branchFilter = $branch_id ? " = '$branch_id'" : "IS NOT NULL";

// Notifications (Pending Approvals)
$pending = $conn->query("SELECT COUNT(*) AS pending FROM transfer_requests WHERE status='Pending'")->fetch_assoc()['pending'];

$pendingTransfers = 0;
if ($role === 'admin') {
    $result = $conn->query("SELECT COUNT(*) AS pending FROM transfer_requests WHERE status='pending'");
    if ($result) {
        $row = $result->fetch_assoc();
        $pendingTransfers = (int)($row['pending'] ?? 0);
    }
}

$pendingStockIns = 0;
if ($role === 'admin') {
    $result = $conn->query("SELECT COUNT(*) AS pending FROM stock_in_requests WHERE status='pending'");
    if ($result) {
        $row = $result->fetch_assoc();
        $pendingStockIns = (int)($row['pending'] ?? 0);
    }
}

$pendingTotalInventory = $pendingTransfers + $pendingStockIns;

$pending = $conn->query("SELECT COUNT(*) AS pending FROM transfer_requests WHERE status='Pending'")->fetch_assoc()['pending'] ?? 0;

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
// --- DATE RANGE ---
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// --- PRODUCT FILTER ---
$productFilterSQL = "";
if (!empty($_GET['product'])) {
    $productId = (int)$_GET['product'];
    $productFilterSQL = "WHERE product_id = $productId";
}

// --- LOAD PRODUCTS & BRANCHES ---
$products = $conn->query("
    SELECT product_id, product_name 
    FROM products 
    $productFilterSQL
    ORDER BY product_name ASC
");

$branchesRes = $conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC");

$branches = [];
while ($b = $branchesRes->fetch_assoc()) {
    $branches[(int)$b['branch_id']] = $b['branch_name'];
}

$summaryData = [];

while ($p = $products->fetch_assoc()) {

    $pid   = (int)$p['product_id'];
    $pname = $p['product_name'];

    // Totals for ALL branches (grand total)
    $totals = [
        'begin'       => 0,
        'stockIn'     => 0,
        'sales'       => 0,
        'serviceUsed' => 0,
        'adjust'      => 0,
        'ending'      => 0
    ];

    $productHasRows = false; // track if any branch had data

    foreach ($branches as $bid => $bname) {

        // If user is limited to a branch, respect that (admins usually have no branch_id)
        if ($branch_id && $bid !== (int)$branch_id) {
            continue;
        }

        // 1. BEGINNING BALANCE = net movements BEFORE $from
        $beginSql = "
            SELECT 
                COALESCE(SUM(stock_in),0)
              + COALESCE(SUM(transfer_in),0)
              - COALESCE(SUM(sales),0)
              - COALESCE(SUM(service_used),0)
              - COALESCE(SUM(transfer_out),0)
              + COALESCE(SUM(adjustments),0) AS beginning
            FROM (
                -- Stock In
                SELECT 
                    SUM(quantity) AS stock_in, 
                    0 AS transfer_in, 
                    0 AS sales,
                    0 AS service_used, 
                    0 AS transfer_out, 
                    0 AS adjustments
                FROM stock_in_requests 
                WHERE product_id = $pid 
                  AND branch_id  = $bid
                  AND status='approved'
                  AND request_date < '$from'

                UNION ALL

                -- Sales (by quantity)
                SELECT 
                    0 AS stock_in,
                    0 AS transfer_in,
                    SUM(si.quantity) AS sales,
                    0 AS service_used,
                    0 AS transfer_out,
                    0 AS adjustments
                FROM sales_items si
                JOIN sales s ON s.sale_id = si.sale_id
                WHERE si.product_id = $pid
                  AND s.branch_id  = $bid
                  AND s.sale_date < '$from'

                UNION ALL

                -- Service Used (COUNT rows, based on your table)
                SELECT 
                    0,0,0,
                    COUNT(*) AS service_used,
                    0,0
                FROM sales_services ss
                JOIN sales s ON s.sale_id = ss.sale_id
                WHERE ss.service_id = $pid
                  AND s.branch_id   = $bid
                  AND s.sale_date  < '$from'

                UNION ALL

                -- Transfer In
                SELECT 
                    0,
                    SUM(quantity) AS transfer_in,
                    0,0,0,0
                FROM transfer_requests
                WHERE product_id         = $pid 
                  AND destination_branch = $bid
                  AND status='approved'
                  AND decision_date < '$from'

                UNION ALL

                -- Transfer Out
                SELECT 
                    0,0,0,0,
                    SUM(quantity) AS transfer_out,
                    0
                FROM transfer_requests
                WHERE product_id      = $pid 
                  AND source_branch   = $bid
                  AND status='approved'
                  AND decision_date  < '$from'

                UNION ALL

                -- Adjustments
                SELECT 
                    0,0,0,0,0,
                    SUM(adjust_qty) AS adjustments
                FROM inventory_adjustments
                WHERE product_id = $pid 
                  AND branch_id  = $bid
                  AND date       < '$from'
            ) AS x
        ";
        $begin = (float)$conn->query($beginSql)->fetch_row()[0];

        // 2. STOCK IN (within range)
        $stockIn = (float)$conn->query("
            SELECT COALESCE(SUM(quantity),0)
            FROM stock_in_requests
            WHERE product_id = $pid
              AND branch_id  = $bid
              AND status     = 'approved'
              AND request_date BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 3. SALES (by quantity, within range)
        $sales = (float)$conn->query("
            SELECT COALESCE(SUM(si.quantity),0)
            FROM sales_items si
            JOIN sales s ON s.sale_id = si.sale_id
            WHERE si.product_id = $pid
              AND s.branch_id   = $bid
              AND s.sale_date  BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 4. SERVICES USED (COUNT rows, within range)
        $serviceUsed = (float)$conn->query("
            SELECT COALESCE(COUNT(*),0)
            FROM sales_services ss
            JOIN sales s ON s.sale_id = ss.sale_id
            WHERE ss.service_id = $pid
              AND s.branch_id   = $bid
              AND s.sale_date  BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 5. TRANSFER IN
        $transferIn = (float)$conn->query("
            SELECT COALESCE(SUM(quantity),0)
            FROM transfer_requests
            WHERE product_id         = $pid
              AND destination_branch = $bid
              AND status='approved'
              AND decision_date BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 6. TRANSFER OUT
        $transferOut = (float)$conn->query("
            SELECT COALESCE(SUM(quantity),0)
            FROM transfer_requests
            WHERE product_id     = $pid
              AND source_branch  = $bid
              AND status='approved'
              AND decision_date BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 7. ADJUSTMENTS
        $adjust = (float)$conn->query("
            SELECT COALESCE(SUM(adjust_qty),0)
            FROM inventory_adjustments
            WHERE product_id = $pid
              AND branch_id  = $bid
              AND date      BETWEEN '$from' AND '$to'
        ")->fetch_row()[0];

        // 8. ENDING BALANCE
        $ending = $begin
                + $stockIn
                + $transferIn
                - $sales
                - $serviceUsed
                - $transferOut
                + $adjust;

        // Skip this branch if **absolutely nothing** happened and no beginning
        if (
            $begin       == 0 &&
            $stockIn     == 0 &&
            $sales       == 0 &&
            $serviceUsed == 0 &&
            $transferIn  == 0 &&
            $transferOut == 0 &&
            $adjust      == 0
        ) {
            continue;
        }

        // We have at least one row for this product
        $productHasRows = true;

        // Store branch row
        $summaryData[] = [
            'product'      => $pname,
            'branch'       => $bname,
            'begin'        => $begin,
            'stockIn'      => $stockIn,
            'sales'        => $sales,
            'serviceUsed'  => $serviceUsed,
            'transferIn'   => $transferIn,
            'transferOut'  => $transferOut,
            'adjust'       => $adjust,
            'ending'       => $ending
        ];

        // UPDATE GRAND TOTALS (EXCLUDE TRANSFERS, they net out company-wide)
        $totals['begin']       += $begin;
        $totals['stockIn']     += $stockIn;
        $totals['sales']       += $sales;
        $totals['serviceUsed'] += $serviceUsed;
        $totals['adjust']      += $adjust;
        $totals['ending']      += $ending;
    }

    // Only append PRODUCT GRAND TOTAL if this product had any rows
    if ($productHasRows) {
        $summaryData[] = [
            'product'      => $pname . " (TOTAL)",
            'branch'       => "All Branches",
            'begin'        => $totals['begin'],
            'stockIn'      => $totals['stockIn'],
            'sales'        => $totals['sales'],
            'serviceUsed'  => $totals['serviceUsed'],
            'transferIn'   => "-",  // not totaled across branches
            'transferOut'  => "-",
            'adjust'       => $totals['adjust'],
            'ending'       => $totals['ending']
        ];
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php $pageTitle =''; ?>
<title><?= htmlspecialchars("RP Habana — $pageTitle") ?><?= strtoupper($role) ?> inventory-reports</title>
<link rel="icon" href="img/R.P.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="css/inventory_reports.css?v=<?= filemtime('css/inventory_reports.css') ?>">
<link rel="stylesheet" href="css/notifications.css">
<link rel="stylesheet" href="css/sidebar.css">
<audio id="notifSound" src="img/notif.mp3" preload="auto"></audio>
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
    <a href="dashboard.php" class="active"><i class="fas fa-tv"></i> Dashboard</a>

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
        <a href="shift_summary.php" class="<?= $self === 'shift_summary.php' ? 'active' : '' ?>">
  <i class="fa-solid fa-clipboard-check"></i> Shift Summary
</a>
    <?php endif; ?>

    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>
 
</div>
<div class="main-content">
    <div class="report-section">
        <!-- DATE Picker -->
    <form method="get" class="d-flex align-items-end gap-3 mb-3">
    <div>
        <label for="from">From:</label>
        <input type="date" id="from" name="from" class="form-control" 
            value="<?= htmlspecialchars($_GET['from'] ?? date('Y-m-01')) ?>">
    </div>
    <div>
    <label for="product">Product:</label>
    <select id="product" name="product" class="form-control">
        <option value="">All Products</option>
        <?php
        $pRes = $conn->query("SELECT product_id, product_name FROM products ORDER BY product_name ASC");
        while ($pp = $pRes->fetch_assoc()):
        ?>
            <option value="<?= $pp['product_id'] ?>" 
                <?= (isset($_GET['product']) && $_GET['product'] == $pp['product_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($pp['product_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>
</div>

    <div>
        <label for="to">To:</label>
        <input type="date" id="to" name="to" class="form-control" 
            value="<?= htmlspecialchars($_GET['to'] ?? date('Y-m-d')) ?>">
    </div>
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-filter"></i> Apply
    </button>
    </form>
    <h2>Inventory Movements</h2>
    <small>(Displays all stock movements by product and action type)</small>

    <table class="table table-dark table-striped table-hover align-middle mt-3">
        <thead class="table-secondary text-dark">
        <tr>
            <th scope="col">Date</th>
            <th scope="col">Product</th>
            <th scope="col">Branch</th>
            <th scope="col">From Branch</th>   
            <th scope="col">To Branch</th>  
            <th scope="col">Movement Type</th>
            <th scope="col">Quantity</th>
            <th scope="col">Reference</th>
        </tr>
        </thead>
        <tbody>
        <?php
        // Filters
$branchFilter = $branch_id ? "AND b.branch_id = $branch_id" : "";
$productFilter = "";
if (!empty($_GET['product'])) {
    $productId = (int)$_GET['product'];
    $productFilter = "AND p.product_id = $productId";
}

// FINAL UNION QUERY (ALL COLUMNS MATCH)
$sql = "

-- STOCK IN
SELECT 
    i.request_date AS date,
    p.product_name,
    b.branch_name AS branch,
    NULL AS from_branch,
    NULL AS to_branch,
    'STOCK IN' AS movement,
    i.quantity AS quantity,
    i.id AS ref
FROM stock_in_requests i
JOIN products p ON p.product_id = i.product_id
JOIN branches b ON b.branch_id = i.branch_id
WHERE i.status='approved'
  AND i.request_date BETWEEN '$from' AND '$to'
  $branchFilter
  $productFilter

UNION ALL

-- SALES
SELECT 
    s.sale_date AS date,
    p.product_name,
    b.branch_name AS branch,
    NULL AS from_branch,
    NULL AS to_branch,
    'SALE' AS movement,
    si.quantity AS quantity,
    s.sale_id AS ref
FROM sales_items si
JOIN sales s ON s.sale_id = si.sale_id
JOIN products p ON p.product_id = si.product_id
JOIN branches b ON b.branch_id = s.branch_id
WHERE s.sale_date BETWEEN '$from' AND '$to'
  $branchFilter
  $productFilter

UNION ALL

-- SERVICE USED
SELECT 
    s.sale_date AS date,
    p.product_name,
    b.branch_name AS branch,
    NULL AS from_branch,
    NULL AS to_branch,
    'SERVICE USED' AS movement,
    1 AS quantity,
    ss.id AS ref
FROM sales_services ss
JOIN sales s ON s.sale_id = ss.sale_id
JOIN products p ON p.product_id = ss.service_id
JOIN branches b ON b.branch_id = s.branch_id
WHERE s.sale_date BETWEEN '$from' AND '$to'
  $branchFilter
  $productFilter

UNION ALL

-- TRANSFER OUT
SELECT 
    t.decision_date AS date,
    p.product_name,
    sb.branch_name AS branch,
    sb.branch_name AS from_branch,
    db.branch_name AS to_branch,
    'TRANSFER OUT' AS movement,
    t.quantity AS quantity,
    t.request_id AS ref
FROM transfer_requests t
JOIN products p ON p.product_id = t.product_id
JOIN branches sb ON sb.branch_id = t.source_branch
JOIN branches db ON db.branch_id = t.destination_branch
WHERE t.status='approved'
  AND t.decision_date BETWEEN '$from' AND '$to'
  $productFilter

UNION ALL

-- TRANSFER IN
SELECT 
    t.decision_date AS date,
    p.product_name,
    db.branch_name AS branch,
    sb.branch_name AS from_branch,
    db.branch_name AS to_branch,
    'TRANSFER IN' AS movement,
    t.quantity AS quantity,
    t.request_id AS ref
FROM transfer_requests t
JOIN products p ON p.product_id = t.product_id
JOIN branches sb ON sb.branch_id = t.source_branch
JOIN branches db ON db.branch_id = t.destination_branch
WHERE t.status='approved'
  AND t.decision_date BETWEEN '$from' AND '$to'
  $productFilter

UNION ALL

-- ADJUSTMENTS
SELECT 
    a.date AS date,
    p.product_name,
    b.branch_name AS branch,
    NULL AS from_branch,
    NULL AS to_branch,
    'ADJUSTMENT' AS movement,
    a.adjust_qty AS quantity,
    a.id AS ref
FROM inventory_adjustments a
JOIN products p ON p.product_id = a.product_id
JOIN branches b ON b.branch_id = a.branch_id
WHERE a.date BETWEEN '$from' AND '$to'
  $branchFilter
  $productFilter

ORDER BY date DESC
";



        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0):
            while ($row = $result->fetch_assoc()):
        ?>
            <tr>
                <td><?= htmlspecialchars(date("M d, Y", strtotime($row['date']))) ?></td>
                <td><?= htmlspecialchars($row['product_name']) ?></td>
                <td><?= htmlspecialchars($row['branch']) ?></td>
                <td><?= htmlspecialchars($row['from_branch'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['to_branch'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['movement']) ?></td>
                <td><?= (int)$row['quantity'] ?></td>
                <td><?= htmlspecialchars($row['ref_no'] ?? $row['invoice_no'] ?? $row['transfer_code'] ?? $row['adjust_code'] ?? '-') ?></td>
             
            </tr>
        <?php
            endwhile;
        else:
        ?>
            <tr><td colspan="7" class="text-center text-muted py-3">No inventory movements found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
<!-- SUMMARY REPORT SECTION -->
<div class="report-section mt-5">
  <h2>1. SUMMARY REPORT TABLE</h2>
  <small>
    (Shows totals per product, per branch, from 
    <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>)
  </small>
<table class="table table-dark table-striped table-hover align-middle mt-3">
  <thead class="table-secondary text-dark">
    <tr>
      <th>Product</th>
      <th>Branch</th>
      <th>Beginning</th>
      <th>Stock In</th>
      <th>Sales</th>
      <th>Service Used</th>
      <th>Transfer In</th>
      <th>Transfer Out</th>
      <th>Adjustments</th>
      <th>Ending</th>
    </tr>
  </thead>
  <?php
// Helper for safe number formatting
function nf($val) {
    return is_numeric($val) ? number_format($val, 0) : $val;
}
?>

<tbody>
<?php if (!empty($summaryData)): ?>
  <?php foreach ($summaryData as $row): ?>
    <tr <?= str_contains($row['product'], '(TOTAL)') ? 'style="font-weight:bold; background:#1f1f1f;"' : '' ?>>

      <td><?= htmlspecialchars($row['product']) ?></td>
      <td><?= htmlspecialchars($row['branch']) ?></td>

      <td><?= nf($row['begin']) ?></td>
      <td><?= nf($row['stockIn']) ?></td>
      <td><?= nf($row['sales']) ?></td>
      <td><?= nf($row['serviceUsed']) ?></td>

      <td><?= $row['transferIn'] === '-' ? '-' : nf($row['transferIn']) ?></td>
<td><?= $row['transferOut'] === '-' ? '-' : nf($row['transferOut']) ?></td>


      <td><?= nf($row['adjust']) ?></td>
      <td><strong><?= nf($row['ending']) ?></strong></td>

    </tr>
  <?php endforeach; ?>
<?php else: ?>
  <tr><td colspan="10" class="text-center text-muted py-3">No data available for this date range.</td></tr>
<?php endif; ?>
</tbody>

</table>

</div>

<script src="sidebar.js"></script>

</body>
</html>