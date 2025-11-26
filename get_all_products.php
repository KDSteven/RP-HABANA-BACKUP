<?php
// get_all_products.php
header('Content-Type: application/json');
include 'config/db.php';

// Fetch all active products that exist in inventory
$sql = "
    SELECT DISTINCT 
        p.product_id,
        p.product_name
    FROM products p
    JOIN inventory i ON p.product_id = i.product_id
    WHERE p.archived = 0
    ORDER BY p.product_name ASC
";

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'product_id'   => (int)$row['product_id'],
        'product_name' => $row['product_name']
    ];
}

echo json_encode($data);
?>
