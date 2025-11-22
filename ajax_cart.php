<?php
session_start();
include 'config/db.php';
include 'functions.php';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

/* ========= Helpers ========= */
function finalPrice($price, $markup) {
    $p = (float)$price;
    $m = (float)$markup;
    return $p + ($p * ($m / 100));
}

function findCartIndex(string $type, $id): int {
    foreach ($_SESSION['cart'] as $idx => $item) {
        if (($item['type'] ?? '') !== $type) continue;
        $key = ($type === 'product') ? 'product_id' : 'service_id';
        if (($item[$key] ?? null) == $id) return $idx;
    }
    return -1;
}

function computeTotals(): array {
    $subtotal = 0.0;

    foreach ($_SESSION['cart'] as $item) {
        $qty   = max(0, (int)($item['qty'] ?? 0));
        $price = (float)($item['price'] ?? 0);
        $subtotal += ($price * $qty);
    }

    return [
        'raw' => [
            'subtotal' => $subtotal,
            'grand'    => $subtotal  // grand total is now same as subtotal
        ],
        'display' => [
            'subtotal' => number_format($subtotal, 2),
            'grand'    => number_format($subtotal, 2),
        ],
    ];
}

/* ========= Read Input ========= */
$input = json_decode(file_get_contents('php://input'), true);
$post  = array_merge($_POST, is_array($input) ? $input : []);
$action = $post['action'] ?? '';

$response = [
    'success' => false,
    'message' => '',
    'cart_html' => '',
    'totals' => []
];

/* ========= ACTION HANDLERS ========= */

switch ($action) {

    /* ============================
       ADD PRODUCT
    ============================ */
    case 'add_product': {

        $pid = (int)($post['product_id'] ?? 0);
        $qty = max(1, (int)($post['qty'] ?? 1));

        if ($pid <= 0 || empty($_SESSION['branch_id'])) {
            $response['message'] = "Invalid product or branch.";
            break;
        }

        $stmt = $conn->prepare("
            SELECT p.product_name, p.price, p.markup_price,
                   IFNULL(i.stock,0) AS stock,
                   p.expiration_date, p.category
            FROM products p
            JOIN inventory i ON p.product_id = i.product_id
            WHERE p.product_id = ? AND i.branch_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $pid, $_SESSION['branch_id']);
        $stmt->execute();
        $prod = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$prod) {
            $response['message'] = "Product not found.";
            break;
        }

        $stock = (int)$prod['stock'];

        if ($stock <= 0) {
            $response['message'] = "Product out of stock.";
            break;
        }

        $idx = findCartIndex('product', $pid);

        if ($idx >= 0) {
            $currentQty = (int)$_SESSION['cart'][$idx]['qty'];
            $newQty = min($currentQty + $qty, $stock);
            $_SESSION['cart'][$idx]['qty'] = $newQty;
            $_SESSION['cart'][$idx]['stock'] = $stock;
        } else {
            $_SESSION['cart'][] = [
                'type'         => 'product',
                'product_id'   => $pid,
                'product_name' => $prod['product_name'],
                'qty'          => min($qty, $stock),
                'price'        => finalPrice($prod['price'], $prod['markup_price']),
                'stock'        => $stock,
                'expiration'   => $prod['expiration_date'],
                'category'     => $prod['category'],
            ];
        }

        $response['success'] = true;
        break;
    }

    /* ============================
   ADD SERVICE + MATERIALS
============================ */
case 'add_service': {

    $sid = (int)($post['service_id'] ?? 0);
    if ($sid <= 0) {
        $response['message'] = "Invalid service.";
        break;
    }

    // Prevent duplication (only 1 service allowed)
    foreach ($_SESSION['cart'] as $item) {
        if ($item['type'] === 'service' && (int)$item['service_id'] === $sid) {
            $response['success'] = false;
            $response['message'] = "This service is already in the cart. Only 1 allowed.";
            break 2;
        }
    }

    // Load service info
    $stmt = $conn->prepare("SELECT service_name, price FROM services WHERE service_id=? LIMIT 1");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$srv) {
        $response['message'] = "Service not found.";
        break;
    }

    // ADD SERVICE TO CART
    $_SESSION['cart'][] = [
        'type'       => 'service',
        'service_id' => $sid,
        'name'       => $srv['service_name'],
        'qty'        => 1,
        'price'      => (float)$srv['price']
    ];
    

    /* ===========================================
       LOAD MATERIALS FOR THIS SERVICE
    ============================================ */

    $q = $conn->prepare("
        SELECT sm.product_id, sm.qty_needed, 
               p.product_name, p.price, p.markup_price, 
               i.stock, p.expiration_date, p.category
        FROM service_materials sm
        JOIN products p ON p.product_id = sm.product_id
        JOIN inventory i ON i.product_id = p.product_id
        WHERE sm.service_id = ? AND i.branch_id = ?
    ");
    $q->bind_param("ii", $sid, $_SESSION['branch_id']);
    $q->execute();
    $materials = $q->get_result();
    $q->close();

    while ($m = $materials->fetch_assoc()) {

        $pid        = (int)$m['product_id'];
        $qty_needed = (int)$m['qty_needed'];
        $stock      = (int)$m['stock'];

        if ($stock <= 0) continue; // skip if no stock

        // Calculate selling price
        $price = finalPrice($m['price'], $m['markup_price']);

        // Check if material already exists in cart
        $idx = findCartIndex('product', $pid);

        if ($idx >= 0) {
            // add qty_needed
            $_SESSION['cart'][$idx]['qty'] += $qty_needed;
        } else {
            // add new product line
            $_SESSION['cart'][] = [
                'type'         => 'product',
                'product_id'   => $pid,
                'product_name' => $m['product_name'],
                'qty'          => $qty_needed,
                'price'        => $price,
                'stock'        => $stock,
                'expiration'   => $m['expiration_date'],
                'category'     => $m['category'],
            ];
        }
    }

    $response['success'] = true;
    break;
}

    /* ============================
       UPDATE QTY
    ============================ */
    case 'update_qty': {

        $type  = $post['item_type'] ?? '';
        $id    = $post['item_id'] ?? '';
        $delta = (int)($post['qty'] ?? 0);

        if (!$type || $id === '') {
            $response['message'] = "Invalid item.";
            break;
        }

        if ($type === 'service') {
            $idx = findCartIndex('service', $id);
            if ($idx >= 0) $_SESSION['cart'][$idx]['qty'] = 1;

            ob_start();
            include 'pos_cart_partial.php';
            $response['cart_html'] = ob_get_clean();
            $response['totals'] = computeTotals();
            $response['success'] = true;
            $response['message'] = "Service quantity cannot be changed.";
            echo json_encode($response);
            exit;
        }

        $idx = findCartIndex($type, $id);
        if ($idx < 0) {
            $response['message'] = "Item not found.";
            break;
        }

        $curQty = (int)$_SESSION['cart'][$idx]['qty'];
        $reqQty = $curQty + $delta;

        $stmt = $conn->prepare("SELECT IFNULL(stock,0) AS stock FROM inventory WHERE branch_id=? AND product_id=?");
        $stmt->bind_param("ii", $_SESSION['branch_id'], $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $liveStock = (int)$row['stock'];
        $newQty = max(0, min($reqQty, $liveStock));

        if ($newQty <= 0) {
            array_splice($_SESSION['cart'], $idx, 1);
        } else {
            $_SESSION['cart'][$idx]['qty'] = $newQty;
            $_SESSION['cart'][$idx]['stock'] = $liveStock;
        }

        $response['success'] = true;
        break;
    }

    /* ============================
       REMOVE ITEM
    ============================ */
    case 'remove_item': {
        $type = $post['item_type'] ?? '';
        $id   = $post['item_id'] ?? '';
        $idx  = findCartIndex($type, $id);

        if ($idx >= 0) {
            array_splice($_SESSION['cart'], $idx, 1);
            $response['success'] = true;
        } else {
            $response['message'] = "Item not found.";
        }
        break;
    }

    /* ============================
       CANCEL ORDER
    ============================ */
    case 'cancel_order': {
        $_SESSION['cart'] = [];
        $response['success'] = true;

        ob_start();
        include 'pos_cart_partial.php';
        $response['cart_html'] = ob_get_clean();
        $response['totals'] = computeTotals();
        echo json_encode($response);
        exit;
    }

    default:
        $response['message'] = "Invalid action.";
}

/* ========= Build Cart HTML ========= */
ob_start();
include 'pos_cart_partial.php';
$response['cart_html'] = ob_get_clean();

/* ========= Totals ========= */
$response['totals'] = computeTotals();

/* ========= Output JSON ========= */
header('Content-Type: application/json');
echo json_encode($response);
exit;
