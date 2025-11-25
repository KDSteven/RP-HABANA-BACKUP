<?php
session_start();
include 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo "Unauthorized";
    exit;
}

// Get export parameters
$exportPeriod = $_GET['export_period'] ?? 'monthly';
$exportStartDate = $_GET['export_start_date'] ?? '';
$exportEndDate = $_GET['export_end_date'] ?? '';
$branchId = $_GET['branch_id'] ?? '';

// Compute date range
if ($exportPeriod === 'yearly') {
    $year = $_GET['export_year'] ?? date('Y');
    $startDate = $year . '-01-01 00:00:00';
    $endDate = $year . '-12-31 23:59:59';
} elseif (!empty($exportStartDate) && !empty($exportEndDate)) {
    $startDate = date('Y-m-d 00:00:00', strtotime($exportStartDate));
    $endDate = date('Y-m-d 23:59:59', strtotime($exportEndDate));
} else {
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = date('Y-m-01 00:00:00', strtotime($month . '-01'));
    $endDate = date('Y-m-t 23:59:59', strtotime($month . '-01'));
}

// Group field
$groupField = ($exportPeriod === 'monthly') ? 'DATE(s.sale_date)' :
              (($exportPeriod === 'weekly') ? "CONCAT('Week ', WEEK(s.sale_date, 1), ' - ', YEAR(s.sale_date))" :
              (($exportPeriod === 'daily') ? "CONCAT(MONTHNAME(s.sale_date), ' ', YEAR(s.sale_date))" :
              "YEAR(s.sale_date)"));

$sql = "
    SELECT 
        $groupField AS period,
        p.product_name,
        SUM(si.quantity) AS total_qty,
        ROUND(SUM(si.quantity * si.price), 2) AS total_amount
    FROM sales_items si
    JOIN products p ON si.product_id = p.product_id
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE s.sale_date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];
$types = "ss";

if (!empty($branchId) && ctype_digit($branchId)) {
    $sql .= " AND s.branch_id = ?";
    $params[] = (int)$branchId;
    $types .= "i";
}

$sql .= " GROUP BY $groupField, p.product_id ORDER BY MIN(s.sale_date) ASC, p.product_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// CSV output
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="product_sales_' . $exportPeriod . '_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$output = fopen('php://output', 'w');
fputcsv($output, ['Period', 'Product Name', 'Quantity Sold', 'Total Amount (₱)']);

$grandTotal = 0.0;
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['period'],
        $row['product_name'],
        (int)$row['total_qty'],
        number_format((float)$row['total_amount'], 2)
    ]);
    $grandTotal += (float)$row['total_amount'];
}

fputcsv($output, ['', '', 'Grand Total', number_format($grandTotal, 2)]);
fclose($output);
exit;
?>
