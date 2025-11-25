<?php
session_start();
include 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$range     = $_GET['range']     ?? 'daily';    // daily | weekly | monthly
$month     = $_GET['month']     ?? date('Y-m');
$branch_id = $_GET['branch_id'] ?? '';

date_default_timezone_set("Asia/Manila");

// Build date ranges for the selected month
$startMonth = date("Y-m-01 00:00:00", strtotime($month));
$endMonth   = date("Y-m-t 23:59:59", strtotime($month));

/* ------------------------
   GROUPING BASED ON RANGE
   ------------------------*/
switch ($range) {

    case "daily":
        $groupField = "DATE(s.sale_date)";
        $label = "Daily Sales Report";
        break;

    case "weekly":
        $groupField = "CONCAT('Week ', WEEK(s.sale_date, 1))";
        $label = "Weekly Sales Report";
        break;

    case "monthly":
        $groupField = "CONCAT(MONTHNAME(s.sale_date), ' ', YEAR(s.sale_date))";
        $label = "Monthly Sales Report";
        break;

    default:
        $groupField = "DATE(s.sale_date)";
        $label = "Daily Sales Report";
}

/* ------------------------
   MAIN PRODUCT SALES QUERY
   ------------------------*/

$sql = "
    SELECT 
        $groupField AS period,
        p.product_name,
        SUM(si.quantity) AS total_qty,
        SUM(si.quantity * si.price) AS total_amount
    FROM sales_items si
    JOIN products p ON si.product_id = p.product_id
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE s.sale_date BETWEEN ? AND ?
";

$params = [$startMonth, $endMonth];
$types  = "ss";

// Filter by branch
if (!empty($branch_id) && ctype_digit($branch_id)) {
    $sql .= " AND s.branch_id = ?";
    $params[] = (int)$branch_id;
    $types   .= "i";
}

$sql .= "
    GROUP BY period, p.product_id
    ORDER BY period ASC, p.product_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Prepare JSON Data
$response = [];
$response['report_label'] = $label;
$response['data'] = [];

$grandTotal = 0;

while ($row = $result->fetch_assoc()) {
    $response['data'][] = [
        'period'        => $row['period'],
        'product_name'  => $row['product_name'],
        'quantity_sold' => (int)$row['total_qty'],
        'total_amount'  => number_format((float)$row['total_amount'], 2),
    ];
    $grandTotal += $row['total_amount'];
}

// Add Grand Total
$response['grand_total'] = number_format($grandTotal, 2);

// Output as JSON
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>
