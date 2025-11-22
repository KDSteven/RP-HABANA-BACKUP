<?php
if (!isset($_SESSION)) session_start();
if (!isset($cartItems)) $cartItems = $_SESSION['cart'] ?? [];
include 'config/db.php';
require_once 'functions.php';

$displaySubtotal = 0.0;

$cartItems = $_SESSION['cart'] ?? [];
$jsCart    = [];

/* ---------- Build rows + compute totals (NO VAT) ---------- */
foreach ($cartItems as &$item) {

    if (($item['type'] ?? '') === 'product') {

        $pid = (int)($item['product_id'] ?? 0);

        // Fetch price from DB
        $stmt = $conn->prepare("SELECT product_name, price, markup_price, expiration_date FROM products WHERE product_id=?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $name  = $row['product_name'] ?? ($item['name'] ?? 'Product');
        $price = finalPrice((float)($row['price'] ?? 0), (float)($row['markup_price'] ?? 0));
        $qty   = (int)($item['qty'] ?? 1);
        $exp   = $row['expiration_date'] ?? ($item['expiration'] ?? '');

        // Update snapshot
        $item['name']       = $name;
        $item['price']      = $price;
        $item['expiration'] = $exp;

        $lineTotal = $price * $qty;
        $displaySubtotal += $lineTotal;

        $jsCart[] = [
            'name'  => $name,
            'price' => $price,
            'qty'   => $qty
        ];

    } else {

        // SERVICE
        $sid   = (int)($item['service_id'] ?? 0);
        $name  = $item['name'] ?? 'Service';
        $price = (float)($item['price'] ?? 0);
        $qty   = 1;

        $item['name']  = $name;
        $item['price'] = $price;

        $lineTotal = $price * $qty;
        $displaySubtotal += $lineTotal;

        $jsCart[] = [
            'name'  => $name,
            'price' => $price,
            'qty'   => 1
        ];
    }
}
unset($item);

$grandTotal = $displaySubtotal;
?>

<div class="cart-box">
  <h3><i class="fas fa-shopping-cart"></i> Current Transaction</h3>

  <?php if (empty($cartItems)): ?>
    <p class="text-muted">Your cart is empty.</p>
  <?php else: ?>
    <table class="table align-middle table-sm">
      <thead>
        <tr>
          <th>Item</th>
          <th class="text-end">Price</th>
          <th class="text-center">Qty</th>
          <th class="text-end">Total</th>
          <th class="text-center">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cartItems as $item): ?>
          <?php
            $isProduct = (($item['type'] ?? '') === 'product');
            $name      = $item['name'] ?? ($isProduct ? 'Product' : 'Service');
            $qty       = (int)($item['qty'] ?? 1);
            $price     = (float)($item['price'] ?? 0);
            $lineTotal = $price * $qty;

            $rowAttrs = '';
            if ($isProduct) {
                $exp = $item['expiration'] ?? '';
                $rowAttrs = 'class="expirable" data-expiration="'.htmlspecialchars($exp, ENT_QUOTES).'"';
            }

            $idAttr   = $isProduct ? (int)$item['product_id'] : (int)$item['service_id'];
            $typeAttr = $isProduct ? 'product' : 'service';
          ?>

          <tr <?= $rowAttrs ?>>
            <td>
              <?= htmlspecialchars($name) ?>
              <?php if ($isProduct): ?>
                <br><small class="text-muted">#<?= (int)$item['product_id'] ?></small>
              <?php else: ?>
                <br><small class="text-muted">SVC #<?= (int)$item['service_id'] ?></small>
              <?php endif; ?>
            </td>

            <td class="text-end">₱<?= number_format($price, 2) ?></td>

            <?php if (!$isProduct): ?>
                <td class="text-center">—</td>
            <?php else: ?>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary btn-decrease"
                            data-type="product" data-id="<?= $idAttr ?>">−</button>
                    <span class="px-2"><?= $qty ?></span>
                    <button class="btn btn-outline-secondary btn-increase"
                            data-type="product" data-id="<?= $idAttr ?>">+</button>
                  </div>
                </td>
            <?php endif; ?>

            <td class="text-end">₱<?= number_format($lineTotal, 2) ?></td>

            <td class="text-center">
              <button class="btn btn-sm btn-outline-danger btn-remove"
                      data-type="<?= $typeAttr ?>" data-id="<?= $idAttr ?>">
                <i class="fas fa-trash"></i>
              </button>
            </td>

          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card totals-box mt-3 p-3">
  <h4 class="final-total">
    TOTAL  
    <span class="grand" data-value="<?= number_format($grandTotal, 2, '.', '') ?>">
      ₱<?= number_format($grandTotal, 2) ?>
    </span>
  </h4>

  <hr>
  <h5>Discount <span id="displayDiscount">₱0.00</span></h5>
  <h5>Payment  <span id="displayPayment">₱0.00</span></h5>
  <h5>Change   <span id="displayChange">₱0.00</span></h5>
</div>

<script>
const cartItems = <?= json_encode($jsCart) ?>;
</script>
