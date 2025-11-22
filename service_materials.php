<?php
session_start();
require 'config/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ---------------------------
   A) GET PRODUCTS BY BRANCH
---------------------------- */
if ($action === 'load_products') {
    $branch_id = intval($_GET['branch_id']);

    $q = $conn->prepare("
        SELECT p.product_id, p.product_name
        FROM products p
        JOIN inventory i ON i.product_id = p.product_id
        WHERE i.branch_id = ? AND i.archived = 0 AND p.archived = 0
        ORDER BY p.product_name ASC
    ");
    $q->bind_param("i", $branch_id);
    $q->execute();
    $res = $q->get_result();

    echo json_encode($res->fetch_all(MYSQLI_ASSOC));
    exit;
}

/* ---------------------------
   B) LIST MATERIALS OF SERVICE
---------------------------- */
if ($action === 'list') {
    $sid = intval($_GET['service_id']);

    $q = $conn->prepare("
        SELECT sm.id, sm.qty_needed, p.product_name
        FROM service_materials sm
        JOIN products p ON p.product_id = sm.product_id
        WHERE sm.service_id = ?
    ");
    $q->bind_param("i", $sid);
    $q->execute();
    $res = $q->get_result();

    echo json_encode($res->fetch_all(MYSQLI_ASSOC));
    exit;
}

/* ---------------------------
   C) ADD MATERIAL
---------------------------- */
if ($action === 'add') {
    $sid = intval($_POST['service_id']);
    $pid = intval($_POST['product_id']);
    $qty = intval($_POST['qty']);

    $q = $conn->prepare("
        INSERT INTO service_materials (service_id, product_id, qty_needed)
        VALUES (?, ?, ?)
    ");
    $q->bind_param("iii", $sid, $pid, $qty);
    $q->execute();

    echo "OK";
    exit;
}

/* ---------------------------
   D) DELETE MATERIAL
---------------------------- */
if ($action === 'delete') {
    $id = intval($_GET['id']);
    $conn->query("DELETE FROM service_materials WHERE id = $id");
    echo "OK";
    exit;
}
?>
