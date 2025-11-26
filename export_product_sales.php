<?php
session_start();
include 'config/db.php';
require_once "vendor/autoload.php";
use Dompdf\Dompdf;

// ---- Check login & role
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}
$role = strtolower($_SESSION['role'] ?? '');
$user_id = $_SESSION['user_id'];
$branch_id = $_GET['branch_id'] ?? ($_SESSION['branch_id'] ?? null);

// ---- Inputs
$reportType = $_GET['report'] ?? 'itemized';
$selectedMonth = $_GET['month'] ?? date('Y-m');
$download = $_GET['download'] ?? 'csv';

// ---- Date range
$startDate = date('Y-m-01 00:00:00', strtotime($selectedMonth . '-01'));
$endDate   = date('Y-m-t 23:59:59', strtotime($selectedMonth . '-01'));

// ---- Build WHERE clause
$conds = ["s.sale_date >= ? AND s.sale_date <= ?"];
$params = [$startDate, $endDate];
$types = "ss";

if ($role === 'admin' && !empty($branch_id) && ctype_digit($branch_id)) {
    $conds[] = "s.branch_id = ?";
    $params[] = (int)$branch_id;
    $types .= "i";
} elseif ($role === 'staff') {
    if ($branch_id) {
        $conds[] = "s.branch_id = ?";
        $params[] = (int)$branch_id;
        $types .= "i";
    } else {
        $conds[] = "1 = 0"; // no access
    }
}

$whereSql = $conds ? "WHERE " . implode(' AND ', $conds) : "";

// ---- Fetch sales
$sql = "SELECT s.sale_id, s.sale_date, s.total + s.vat AS grand_total, b.branch_name
        FROM sales s
        LEFT JOIN branches b ON s.branch_id = b.branch_id
        $whereSql
        ORDER BY s.sale_date DESC";

$stmt = $conn->prepare($sql);
if ($types && $params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// ---- Flatten products + services
$salesData = [];
$totalSales = 0;

while ($row = $result->fetch_assoc()) {
    $saleId = $row['sale_id'];
    $saleDate = $row['sale_date'];
    $branchName = $row['branch_name'];
    $grandTotal = $row['grand_total'];

    // --- Products
    $itemsRes = $conn->query("
        SELECT p.product_name, si.quantity, si.price
        FROM sales_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = $saleId
    ");
    while ($item = $itemsRes->fetch_assoc()) {
        $salesData[] = [
            'sale_id' => $saleId,
            'date' => $saleDate,
            'branch' => $branchName,
            'type' => 'Product',
            'name' => $item['product_name'],
            'qty_fee' => $item['quantity'],
            'price' => $item['price']
        ];
    }

    // --- Services
    $servicesRes = $conn->query("
        SELECT sv.service_name, ss.price
        FROM sales_services ss
        JOIN services sv ON ss.service_id = sv.service_id
        WHERE ss.sale_id = $saleId
    ");
    while ($service = $servicesRes->fetch_assoc()) {
        $salesData[] = [
            'sale_id' => $saleId,
            'date' => $saleDate,
            'branch' => $branchName,
            'type' => 'Service',
            'name' => $service['service_name'],
            'qty_fee' => 1,
            'price' => $service['price']
        ];
    }

    $totalSales += $grandTotal;
}

// ---- Branch Name
$branchName = $branch_id ? ($conn->query("SELECT branch_name FROM branches WHERE branch_id=".intval($branch_id))->fetch_assoc()['branch_name'] ?? 'N/A') : 'All Branches';

// ---- CSV Export
if ($download === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Sales_Report_'.$selectedMonth.'.csv"');
    echo "\xEF\xBB\xBF"; // BOM for UTF-8

    $output = fopen('php://output', 'w');

    // Header info
    fputcsv($output, ["Sales Report"]);
    fputcsv($output, ["Month:", date('F Y', strtotime($selectedMonth . '-01'))]);
    fputcsv($output, ["Branch:", $branchName]);
    fputcsv($output, ["Generated:", date('F j, Y H:i')]);
    fputcsv($output, []); // empty line

    // Columns
    fputcsv($output, ['Sale ID','Date','Branch','Type','Name','Qty / Fee','Price (₱)']);

    foreach($salesData as $row){
        fputcsv($output, [
            $row['sale_id'],
            date('F j, Y | h:i A', strtotime($row['date'])),
            $row['branch'],
            $row['type'],
            $row['name'],
            $row['qty_fee'],
            "₱".number_format($row['price'],2)
        ]);
    }

    // Total row
    fputcsv($output, []);
    fputcsv($output, ['','','','','TOTAL SALES','',"₱".number_format($totalSales,2)]);

    fclose($output);
    exit;
}

// ---- PDF Export
if ($download === 'pdf') {
    $dompdf = new Dompdf();

    $html = '<meta charset="UTF-8">';
    $html .= '<style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
        th { background-color: #f2f2f2; }
        tr.total-row td { font-weight: bold; background-color: #f9f9f9; }
    </style>';

    $html .= '<h1 style="text-align:center;"><strong>RP Habana Report</strong></h1>';
    $html .= '<h2 style="text-align:center;">Sales Report</h2>';
    $html .= '<p style="text-align:center;">Month: '.date('F Y', strtotime($selectedMonth.'-01')).' | Branch: '.$branchName.'</p>';

    $html .= '<table>';
    $html .= '<tr>
                <th>Sale ID</th>
                <th>Date</th>
                <th>Branch</th>
                <th>Type</th>
                <th>Name</th>
                <th>Qty / Fee</th>
                <th>Price (₱)</th>
              </tr>';

    foreach($salesData as $row){
        $html .= '<tr>
                    <td>'.$row['sale_id'].'</td>
                    <td>'.date('F j, Y | h:i A', strtotime($row['date'])).'</td>
                    <td>'.$row['branch'].'</td>
                    <td>'.$row['type'].'</td>
                    <td>'.htmlspecialchars($row['name']).'</td>
                    <td>'.$row['qty_fee'].'</td>
                    <td>₱'.number_format($row['price'],2).'</td>
                  </tr>';
    }

    $html .= '<tr class="total-row">
                <td colspan="6" style="text-align:right;">TOTAL SALES</td>
                <td>₱'.number_format($totalSales,2).'</td>
              </tr>';
    $html .= '</table>';

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("Sales_Report_".date('F_Y', strtotime($selectedMonth.'-01')).".pdf", ["Attachment" => true]);
    exit;
}
?>
