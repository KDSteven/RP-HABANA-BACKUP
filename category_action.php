<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/functions.php';  // for logAction()
header('Content-Type: application/json');

/**
 * Read body as JSON if possible, otherwise fall back to $_POST.
 */
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);

if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    $data = $decoded;
} else {
    $data = $_POST;
}

/**
 * Small debug helper: see what we actually received.
 * Check your PHP error log if needed.
 */
error_log('categories_action.php data: ' . print_r($data, true));

$action = $data['action'] ?? '';

/**
 * If no explicit action but category_name is present from a POST form,
 * treat it as "create".
 */
if ($action === '' && isset($data['category_name']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = 'create';
}

function respond($ok, $message) {
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

/**
 * Helper: normalize has_expiration to 0/1.
 * - If the field is present and non-empty (1, "1", true, "on") => 1
 * - Missing or empty / "0" / 0 / false => 0
 */
function parse_has_expiration(array $data, string $key = 'has_expiration'): int {
    return !empty($data[$key]) ? 1 : 0;
}

$inTx = false;

try {

    /* =======================================================
       CREATE NEW CATEGORY
    ======================================================= */
    if ($action === 'create') {
        $name = trim($data['category_name'] ?? '');
        $hasExpiration = parse_has_expiration($data);  // 👈 simple & robust

        if ($name === '') {
            respond(false, 'Category name is required.');
        }

        // Prevent duplicates (case-insensitive)
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM categories WHERE LOWER(category_name) = LOWER(?)"
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->bind_result($exists);
        $stmt->fetch();
        $stmt->close();

        if ($exists > 0) {
            respond(false, 'Category already exists.');
        }

        // Insert including has_expiration flag
        $stmt = $conn->prepare("
            INSERT INTO categories (category_name, active, has_expiration)
            VALUES (?, 1, ?)
        ");
        $stmt->bind_param('si', $name, $hasExpiration);
        $stmt->execute();
        $stmt->close();

        logAction(
            $conn,
            "Create Category",
            "Created category: {$name} (has_expiration={$hasExpiration})"
        );

        respond(true, 'Category created successfully.');
    }

    /* =======================================================
       DEACTIVATE CATEGORY (Soft archive — only if unused)
    ======================================================= */
    if ($action === 'deactivate') {
        $id = (int)($data['category_id'] ?? 0);
        if ($id === 0) respond(false, 'Invalid category ID.');

        $stmt = $conn->prepare("
            SELECT TRIM(category_name)
            FROM categories
            WHERE category_id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($catName);
        $stmt->fetch();
        $stmt->close();

        if (!$catName) respond(false, 'Category not found.');

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM products
            WHERE TRIM(category) = TRIM(?)
              AND (archived = 0 OR archived IS NULL)
        ");
        $stmt->bind_param('s', $catName);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();

        if ($cnt > 0) {
            respond(false, "Cannot archive: category is used by {$cnt} active product(s).");
        }

        $stmt = $conn->prepare("UPDATE categories SET active = 0 WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        logAction($conn, "Archive Category", "Archived category: {$catName}");

        respond(true, 'Category archived.');
    }

    /* =======================================================
       HARD DELETE CATEGORY (ONLY IF UNUSED)
    ======================================================= */
    if ($action === 'restrict') {
        $id = (int)($data['category_id'] ?? 0);
        if ($id === 0) respond(false, 'Invalid category ID.');

        $stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($catName);
        $stmt->fetch();
        $stmt->close();

        if (!$catName) respond(false, 'Category not found.');

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM products
            WHERE TRIM(category) = TRIM(?)
              AND archived = 0
        ");
        $stmt->bind_param('s', $catName);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();

        if ($cnt > 0) {
            respond(false, "Cannot delete: category is used by {$cnt} product(s).");
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        logAction($conn, "Delete Category", "Deleted unused category: {$catName}");

        respond(true, 'Category deleted permanently.');
    }

    /* =======================================================
       REASSIGN CATEGORY (merge A → B)
    ======================================================= */
    if ($action === 'reassign') {
        $id = (int)($data['category_id'] ?? 0);
        $to = (int)($data['reassign_to'] ?? 0);

        if ($id === 0 || $to === 0 || $id === $to) {
            respond(false, 'Invalid reassignment.');
        }

        $stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($oldName);
        $stmt->fetch();
        $stmt->close();

        $stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $to);
        $stmt->execute();
        $stmt->bind_result($newName);
        $stmt->fetch();
        $stmt->close();

        if (!$oldName || !$newName) {
            respond(false, 'Category not found.');
        }

        $conn->begin_transaction();
        $inTx = true;

        $stmt = $conn->prepare("UPDATE products SET category = ? WHERE TRIM(category) = TRIM(?)");
        $stmt->bind_param('ss', $newName, $oldName);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $inTx = false;

        logAction(
            $conn,
            "Reassign Category",
            "Reassigned category: {$oldName} → {$newName}"
        );

        respond(true, "Category reassigned from '{$oldName}' to '{$newName}'.");
    }

    /* =======================================================
       UPDATE CATEGORY EXPIRY ONLY
    ======================================================= */
    if ($action === 'update_expiry') {
        $id = (int)($data['category_id'] ?? 0);
        if ($id === 0) {
            respond(false, 'Invalid category ID.');
        }

        $newHasExpiration = parse_has_expiration($data);

        $stmt = $conn->prepare(
            "SELECT category_name, has_expiration
             FROM categories
             WHERE category_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($catName, $curHasExp);
        $stmt->fetch();
        $stmt->close();

        if (!$catName) {
            respond(false, 'Category not found.');
        }

        if ((int)$curHasExp === $newHasExpiration) {
            respond(true, 'No change needed (expiry already set that way).');
        }

        $stmt = $conn->prepare(
            "UPDATE categories SET has_expiration = ? WHERE category_id = ?"
        );
        $stmt->bind_param('ii', $newHasExpiration, $id);
        $stmt->execute();
        $stmt->close();

        // ★ SYNC ALL PRODUCTS TO CATEGORY EXPIRY
        $syncStmt = $conn->prepare("
            UPDATE products
            SET expiry_required = ?
            WHERE category = (
                SELECT category_name FROM categories WHERE category_id = ?
            )
        ");
        $syncStmt->bind_param('ii', $newHasExpiration, $id);
        $syncStmt->execute();
        $syncStmt->close();

        logAction(
            $conn,
            "Update Category Expiry",
            "Changed has_expiration for category '{$catName}' from {$curHasExp} to {$newHasExpiration}"
        );

        respond(true, 'Category expiry flag updated.');
    }

    /* =======================================================
       UNKNOWN ACTION
    ======================================================= */
    respond(false, 'Unknown action: ' . $action);

} catch (Throwable $e) {
    if ($inTx) {
        $conn->rollback();
    }
    respond(false, $e->getMessage());
}
