<?php
session_start();
require 'config/db.php';

// Authz: only admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.html');
    exit;
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'];
$branch_id = $_SESSION['branch_id'] ?? null;

/* -------------------------
   Pending Inventory Badges
-------------------------- */
$pendingTransfers = 0;
$pendingStockIns = 0;

if ($role === 'admin') {
    $pendingTransfers = (int)($conn->query("SELECT COUNT(*) AS c FROM transfer_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
    $pendingStockIns  = (int)($conn->query("SELECT COUNT(*) AS c FROM stock_in_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
}

$pendingTotalInventory = $pendingTransfers + $pendingStockIns;

/* -------------------------
   Branch dropdown
-------------------------- */
$branches = $conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name");

/* -------------------------
   Filters
-------------------------- */
$where  = [];
$params = [];
$types  = '';

if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
    if ($_GET['branch_id'] === 'NULL') {
        $where[] = "l.branch_id IS NULL";
    } elseif (is_numeric($_GET['branch_id'])) {
        $where[] = "l.branch_id = ?";
        $params[] = (int)$_GET['branch_id'];
        $types   .= "i";
    }
}

$from = $_GET['from_date'] ?? '';
$to   = $_GET['to_date']   ?? '';

if ($from !== '') {
    $where[] = "l.timestamp >= ?";
    $params[] = $from . " 00:00:00";
    $types .= "s";
}
if ($to !== '') {
    $where[] = "l.timestamp < ?";
    $params[] = date("Y-m-d", strtotime($to . " +1 day")) . " 00:00:00";
    $types .= "s";
}

if (!empty($_GET['role'])) {
    $where[]  = "u.role = ?";
    $params[] = $_GET['role'];
    $types   .= "s";
}

$activity = $_GET['activity'] ?? '';
if ($activity !== '') {
    switch ($activity) {
        case 'login':
            $where[] = "LOWER(l.action) LIKE '%login%'";
            break;

        case 'stockin':
            $where[] = "(LOWER(l.action) REGEXP 'stock[ -]?in|restock|receive'
                      OR LOWER(l.details) REGEXP 'stock[ -]?in|restock|receive')";
            break;

        case 'transfer':
            $where[] = "(LOWER(l.action) LIKE '%transfer%' OR LOWER(l.details) LIKE '%transfer%')";
            break;

        case 'create':
            $where[] = "(LOWER(l.action) LIKE '%create%' OR LOWER(l.action) LIKE '%add%')";
            break;

        case 'update':
            $where[] = "(LOWER(l.action) LIKE '%update%' OR LOWER(l.action) LIKE '%edit%')";
            break;

        case 'delete':
            $where[] = "(LOWER(l.action) LIKE '%delete%' OR LOWER(l.action) LIKE '%archive%')";
            break;
    }
}

/* -------------------------
   Build Query
-------------------------- */
$sql = "SELECT 
            l.*, 
            COALESCE(b.branch_name, 'System') AS branch_name,
            u.username, u.role
        FROM logs l
        LEFT JOIN users u ON l.user_id = u.id
        LEFT JOIN branches b ON l.branch_id = b.branch_id";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$sql .= " ORDER BY l.timestamp DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* -------------------------
   Sidebar State Utilities
-------------------------- */
$self      = strtolower(basename($_SERVER["PHP_SELF"]));
$isArchive = str_starts_with($self, "archive");
$invOpen   = in_array($self, ['inventory.php', 'physical_inventory.php']);
$toolsOpen = ($self === 'backup_admin.php' || $isArchive);

/* Current User Name */
$currentName = "";
if (isset($_SESSION['user_id'])) {
    $q = $conn->prepare("SELECT name FROM users WHERE id=? LIMIT 1");
    $q->bind_param("i", $_SESSION['user_id']);
    $q->execute();
    $q->bind_result($nm);
    if ($q->fetch()) $currentName = $nm;
    $q->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RP Habana — Logs</title>
<link rel="icon" href="img/R.P.png">

<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/logs.css">
<link rel="stylesheet" href="css/notifications.css">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<style>
  #sidebarToggle {
    position: absolute;
    top: 12px;
    right: -10px;
    border-radius: 50%;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    z-index: 50; /* NOT 9999 */
}
.sidebar {
    position: fixed;
    z-index: 100;
}

</style>
<body class="logs-page">

<!-- =======================
       SIDEBAR + TOGGLE  
========================== -->
<div id="mainSidebar" class="sidebar expanded">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
    </button>
  <div class="sidebar-content">
    <h2 class="user-heading">
        <span class="role"><?= strtoupper($role) ?></span>
        <?php if ($currentName): ?>
            <span class="name">(<?= htmlspecialchars($currentName) ?>)</span>
        <?php endif; ?>
        <span class="notif-wrapper">
            <i class="fas fa-bell"></i>
            <span id="notifCount" <?= $pendingTotalInventory ? '' : 'style="display:none;"' ?>>
                <?= $pendingTotalInventory ?>
            </span>
        </span>
    </h2>

    <a href="dashboard.php"><i class="fas fa-tv"></i> Dashboard</a>

    <?php if ($role === 'admin'): ?>
    <div class="menu-group has-sub">
        <button class="menu-toggle" type="button" aria-expanded="<?= $invOpen?'true':'false' ?>">
            <span><i class="fas fa-box"></i> Inventory</span>
            <i class="fas fa-chevron-right caret"></i>
        </button>
        <div class="submenu" <?= $invOpen?'':'hidden' ?>>
            <a href="inventory.php" class="<?= $self==='inventory.php'?'active':'' ?>"><i class="fas fa-list"></i> Inventory List</a>
            <a href="inventory_reports.php"><i class="fas fa-chart-line"></i> Inventory Reports</a>
            <a href="physical_inventory.php" class="<?= $self==='physical_inventory.php'?'active':'' ?>"><i class="fas fa-warehouse"></i> Physical Inventory</a>
            <a href="barcode-print.php" class="<?= $self==='barcode-print.php'?'active':'' ?>"><i class="fas fa-barcode"></i> Barcode Labels</a>
        </div>
    </div>

    <a href="services.php" class="<?= $self==='services.php'?'active':'' ?>"><i class="fas fa-wrench"></i> Services</a>
    <a href="sales.php" class="<?= $self==='sales.php'?'active':'' ?>"><i class="fas fa-receipt"></i> Sales</a>
    <a href="accounts.php" class="<?= $self==='accounts.php'?'active':'' ?>"><i class="fas fa-users"></i> Accounts & Branches</a>

    <div class="menu-group has-sub">
        <button class="menu-toggle" type="button" aria-expanded="<?= $toolsOpen?'true':'false' ?>">
            <span><i class="fas fa-screwdriver-wrench"></i> Data Tools</span>
            <i class="fas fa-chevron-right caret"></i>
        </button>
        <div class="submenu" <?= $toolsOpen?'':'hidden' ?>>
            <a href="/config/admin/backup_admin.php"><i class="fas fa-database"></i> Backup & Restore</a>
            <a href="archive.php"><i class="fas fa-archive"></i> Archive</a>
        </div>
    </div>

    <a href="logs.php" class="active"><i class="fas fa-file-alt"></i> Logs</a>
    <?php endif; ?>

    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<div id="sidebarBackdrop"></div>

<!-- =======================
       CONTENT
========================== -->
<div class="content">
    <div class="header-bar">
        <h1><i class="fas fa-file-alt me-2"></i> Activity Logs</h1>

        <form method="get" class="filters-bar">
            <select name="branch_id" class="form-select">
                <option value="">All Branches</option>
                <?php while ($b = $branches->fetch_assoc()): ?>
                    <option value="<?= $b['branch_id'] ?>" <?= ($_GET['branch_id'] ?? '')==$b['branch_id']?'selected':'' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                <?php endwhile; ?>
                <option value="NULL" <?= ($_GET['branch_id'] ?? '')==='NULL'?'selected':'' ?>>System / No Branch</option>
            </select>

            <input type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>" class="form-control">
            <input type="date" name="to_date"   value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>" class="form-control">

            <select name="role" class="form-select">
                <option value="">All Roles</option>
                <option value="admin"    <?= ($_GET['role'] ?? '')==='admin'?'selected':'' ?>>Admin</option>
                <option value="staff"    <?= ($_GET['role'] ?? '')==='staff'?'selected':'' ?>>Staff</option>
                <option value="stockman" <?= ($_GET['role'] ?? '')==='stockman'?'selected':'' ?>>Stockman</option>
            </select>

            <select name="activity" class="form-select">
                <option value="">All Activities</option>
                <option value="login"    <?= ($_GET['activity'] ?? '')==='login'?'selected':'' ?>>Login</option>
                <option value="stockin"  <?= ($_GET['activity'] ?? '')==='stockin'?'selected':'' ?>>Add Stocks</option>
                <option value="transfer" <?= ($_GET['activity'] ?? '')==='transfer'?'selected':'' ?>>Stock Transfer</option>
                <option value="create"   <?= ($_GET['activity'] ?? '')==='create'?'selected':'' ?>>Create</option>
                <option value="update"   <?= ($_GET['activity'] ?? '')==='update'?'selected':'' ?>>Update</option>
                <option value="archive"  <?= ($_GET['activity'] ?? '')==='archive'?'selected':'' ?>>Archive</option>
                <option value="restore"  <?= ($_GET['activity'] ?? '')==='restore'?'selected':'' ?>>Restore</option>
                <option value="delete"   <?= ($_GET['activity'] ?? '')==='delete'?'selected':'' ?>>Delete</option>
            </select>

            <button class="btn btn-primary"><i class="fas fa-filter me-1"></i> Filter</button>
        </form>
    </div>

    <!-- LOG LIST -->
    <div class="logs-feed">
        <?php while ($log = $result->fetch_assoc()): ?>
            <?php
                $uname = $log['username'] ?: "System";
                $initial = strtoupper($uname[0]);
                $tsISO  = date("c", strtotime($log["timestamp"]));
            ?>
            <div class="log-card accent-other">
                <div class="avatar-ring">
                    <span><?= $initial ?></span>
                </div>

                <div class="log-main">
                    <div class="log-top">
                        <div class="who">
                            <strong><?= htmlspecialchars($uname) ?></strong>
                            <span class="sep">•</span>
                            <span class="branch"><?= htmlspecialchars($log['branch_name']) ?></span>
                        </div>
                        <span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span>
                    </div>

                    <small class="timeline-time" data-timestamp="<?= $tsISO ?>"></small>

                    <div class="log-details">
                        <?= nl2br(htmlspecialchars($log['details'])) ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

</div> <!-- END CONTENT -->


<script src="sidebar.js"></script>
<script src="notifications.js"></script>

<script>
// Relative time updater
function timeAgo(ts){
    let diff = (new Date() - new Date(ts))/1000;
    if(diff < 60) return Math.floor(diff)+' sec ago';
    if(diff < 3600) return Math.floor(diff/60)+' min ago';
    if(diff < 86400) return Math.floor(diff/3600)+' hr ago';
    return Math.floor(diff/86400)+' days ago';
}

function updateTimes(){
    document.querySelectorAll('.timeline-time').forEach(el=>{
        el.textContent = timeAgo(el.dataset.timestamp);
    });
}
updateTimes();
setInterval(updateTimes,60000);
</script>
<script>
// Local submenu logic JUST for logs.php
document.addEventListener('DOMContentLoaded', function () {
    // Find all submenu groups in the sidebar
    document.querySelectorAll('#mainSidebar .menu-group.has-sub').forEach(function (group, idx) {
        const btn = group.querySelector('.menu-toggle');
        const submenu = group.querySelector('.submenu');
        if (!btn || !submenu) return;

        // Restore saved state (optional, like other pages)
        const key = 'logs-submenu-' + idx;
        const saved = localStorage.getItem(key);
        if (saved === 'open') {
            btn.setAttribute('aria-expanded', 'true');
            submenu.hidden = false;
        } else if (saved === 'closed') {
            btn.setAttribute('aria-expanded', 'false');
            submenu.hidden = true;
        }

        // Click handler
        btn.addEventListener('click', function (e) {
            e.preventDefault();          // don’t submit anything / scroll
            const isOpen = btn.getAttribute('aria-expanded') === 'true';
            const nowOpen = !isOpen;

            btn.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            submenu.hidden = !nowOpen;

            // remember state
            localStorage.setItem(key, nowOpen ? 'open' : 'closed');
        });
    });
});
</script>

</body>
</html>
