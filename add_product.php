<?php
session_start();
include 'config/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ---------- Helpers ---------- */
function logAction($conn, $action, $details, $user_id = null, $branch_id = null) {
    if (!$user_id && isset($_SESSION['user_id']))   $user_id = (int)$_SESSION['user_id'];
    if (!$branch_id && isset($_SESSION['branch_id'])) $branch_id = (int)$_SESSION['branch_id'];
    $stmt = $conn->prepare("INSERT INTO logs (user_id, action, details, timestamp, branch_id) VALUES (?, ?, ?, NOW(), ?)");
    $stmt->bind_param("issi", $user_id, $action, $details, $branch_id);
    $stmt->execute();
    $stmt->close();
}

function ean13CheckDigit(string $base12): int {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $d = (int)$base12[$i];
        $sum += ($i % 2 === 0) ? $d : $d * 3;
    }
    return (10 - ($sum % 10)) % 10;
}
function makeEan13FromId(int $productId): string {
    $base12 = '200' . str_pad((string)$productId, 9, '0', STR_PAD_LEFT);
    return $base12 . ean13CheckDigit($base12);
}

/* ---------- Guard ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"Invalid request"]);
    exit;
}

/* ---------- Collect POST ---------- */
$barcode       = trim($_POST['barcode'] ?? '');
$productName   = trim($_POST['product_name'] ?? '');
$categoryId    = (int)($_POST['category_id'] ?? 0);
$price         = (float)($_POST['price'] ?? 0);
$markupPrice   = (float)($_POST['markup_price'] ?? 0);
$retailPrice   = $price + ($price * $markupPrice / 100);
$ceilingPoint  = (int)($_POST['ceiling_point'] ?? 0);
$criticalPoint = (int)($_POST['critical_point'] ?? 0);
$stocks        = (int)($_POST['stocks'] ?? 0);
$branchId      = (int)($_POST['branch_id'] ?? 0);
$brandName     = trim($_POST['brand_name'] ?? '');
$expiration    = $_POST['expiration_date'] ?? null;

/* ---------- Basic validations ---------- */
if ($productName === '' || $categoryId <= 0 || $branchId <= 0) {
    echo json_encode(["status"=>"error","message"=>"Required fields missing."]);
    exit;
}
if ($price < 0 || $markupPrice < 0 || $retailPrice < 0 || $ceilingPoint < 0 || $criticalPoint < 0 || $stocks < 0) {
    echo json_encode(["status"=>"error","message"=>"Numeric values cannot be negative."]);
    exit;
}
if ($criticalPoint > $ceilingPoint) {
    echo json_encode(["status"=>"error","message"=>"Critical Point cannot be higher than Ceiling Point."]);
    exit;
}
if ($stocks > $ceilingPoint) {
    echo json_encode(["status"=>"error","message"=>"Initial stock exceeds the Ceiling Point."]);
    exit;
}

/* ---------- Resolve category name ---------- */
$stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
$stmt->bind_param("i", $categoryId);
$stmt->execute();
$categoryRow  = $stmt->get_result()->fetch_assoc();
$categoryName = $categoryRow['category_name'] ?? '';
$stmt->close();

/* ---------- Tire rule ---------- */
if (stripos($categoryName, 'tire') !== false && empty($expiration)) {
    $dt = new DateTime();
    $dt->modify('+5 years');
    $expiration = $dt->format('Y-m-d');
}

$expiryRequired = !empty($expiration) ? 1 : 0;

/* ---------- Validate expiration ---------- */
if (!empty($expiration)) {
    $dt = DateTime::createFromFormat('Y-m-d', $expiration);
    if (!($dt && $dt->format('Y-m-d') === $expiration)) {
        echo json_encode(["status"=>"error","message"=>"Invalid expiration date"]);
        exit;
    }
}

/* ---------- Barcode uniqueness ---------- */
if ($barcode !== '') {
    $check = $conn->prepare("SELECT product_id FROM products WHERE barcode = ?");
    $check->bind_param("s", $barcode);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        echo json_encode(["status"=>"error","message"=>"Barcode already exists."]);
        exit;
    }
    $check->close();
}

/* ---------- Begin transaction ---------- */
$conn->begin_transaction();

try {
    $barcodeParam    = ($barcode !== '') ? $barcode : null;
    $expirationParam = (!empty($expiration)) ? $expiration : null;

    $stmt = $conn->prepare("
        INSERT INTO products 
        (barcode, product_name, category, price, markup_price, retail_price,
        ceiling_point, critical_point, expiration_date, expiry_required, brand_name,
        initial_stock)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "sssdddiisisi",
        $barcodeParam,
        $productName,
        $categoryName,
        $price,
        $markupPrice,
        $retailPrice,
        $ceilingPoint,
        $criticalPoint,
        $expirationParam,
        $expiryRequired,
        $brandName,
        $stocks
    );

    $stmt->execute();
    $productId = $conn->insert_id;
    $stmt->close();

    if ($barcode === '') {
        $auto = makeEan13FromId($productId);
        $u = $conn->prepare("UPDATE products SET barcode=? WHERE product_id=?");
        $u->bind_param("si", $auto, $productId);
        $u->execute();
        $u->close();
    }

    $stmt2 = $conn->prepare("INSERT INTO inventory (product_id, branch_id, stock) VALUES (?, ?, ?)");
    $stmt2->bind_param("iii", $productId, $branchId, $stocks);
    $stmt2->execute();
    $stmt2->close();

    if ($stocks > 0 && $expiryRequired === 1 && !empty($expirationParam)) {
        $lot = $conn->prepare("
            INSERT INTO inventory_lots (product_id, branch_id, expiry_date, qty)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        $lot->bind_param("iisi", $productId, $branchId, $expirationParam, $stocks);
        $lot->execute();
        $lot->close();
    }

    logAction($conn, "Add Product", "Added product '$productName' (ID: $productId)");
    $conn->commit();

    echo json_encode(["status"=>"success","message"=>"Product added successfully"]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status"=>"error","message"=>"Database error: ".$e->getMessage()]);
    exit;
}
