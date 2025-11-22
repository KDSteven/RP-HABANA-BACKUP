<?php
include 'config/db.php';

$branch = $_GET['branch'] ?? '';
$month  = $_GET['month'] ?? date('Y-m');

// FORCE branch filter (staff)
$branch_sql = "";
if ($branch !== "" && ctype_digit($branch)) {
    $branch_sql = " AND s.branch_id = " . intval($branch);
}

// PRODUCT REVENUE
$q1 = $conn->query("
    SELECT DATE(s.sale_date) AS dt,
           SUM(si.price * si.quantity) AS total
    FROM sales s
    JOIN sales_items si ON si.sale_id = s.sale_id
    WHERE DATE_FORMAT(s.sale_date, '%Y-%m') = '$month'
    $branch_sql
    GROUP BY DATE(s.sale_date)
    ORDER BY dt ASC
");

$products = [];
while ($r = $q1->fetch_assoc()) {
    $products[] = [
        'date'  => $r['dt'],
        'total' => (float)$r['total']
    ];
}

// SERVICE REVENUE
$q2 = $conn->query("
    SELECT DATE(s.sale_date) AS dt,
           SUM(ss.price) AS total
    FROM sales s
    JOIN sales_services ss ON ss.sale_id = s.sale_id
    WHERE DATE_FORMAT(s.sale_date, '%Y-%m') = '$month'
    $branch_sql
    GROUP BY DATE(s.sale_date)
    ORDER BY dt ASC
");

$services = [];
while ($r = $q2->fetch_assoc()) {
    $services[] = [
        'date'  => $r['dt'],
        'total' => (float)$r['total']
    ];
}

echo json_encode([
    'products' => $products,
    'services' => $services
]);
