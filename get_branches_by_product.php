<?php
// get_branches_by_product.php
header('Content-Type: application/json');
include 'config/db.php';

$product_id = intval($_GET['product_id'] ?? 0);

if ($product_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT 
        b.branch_id,
        b.branch_name,
        i.stock
    FROM inventory i
    JOIN branches b ON b.branch_id = i.branch_id
    WHERE i.product_id = ?
      AND i.archived = 0
      AND i.stock > 0
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
    $out[] = $row;
}

echo json_encode($out);
?>
