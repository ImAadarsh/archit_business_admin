<?php
// Generate Excel file
require 'vendor/autoload.php'; // Make sure you have PhpSpreadsheet installed

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// Include database connection
include '../admin/connect.php';

// Function to validate date format
function validateDate($date, $format = 'Y-m-d')
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Get parameters from request
$business_id = isset($_GET['business_id']) ? intval($_GET['business_id']) : null;
$day = isset($_GET['day']) ? intval($_GET['day']) : null;
$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$week_start = isset($_GET['week_start']) ? $_GET['week_start'] : null;
$week_end = isset($_GET['week_end']) ? $_GET['week_end'] : null;
$purchase_at = isset($_GET['purchase_at']) ? floatval($_GET['purchase_at']) : 100;
$amount_min = isset($_GET['amount_min']) ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) ? floatval($_GET['amount_max']) : null;

// Validate business_id
if (!$business_id) {
    echo "Error: Business ID is required";
    exit;
}

// Build the SQL query
$where_clauses = ["inv.business_id = ?", "inv.is_completed = 1", "inv.type = 'normal'"];
$params = [$business_id];
$types = "i";

// ... [rest of the query building code remains the same] ...

$where_clause = implode(" AND ", $where_clauses);

$sql = "SELECT p.name AS product_name, i.price_of_one, SUM(i.quantity) AS total_quantity, SUM(i.price_of_all) AS total_sales
        FROM items i
        JOIN products p ON i.product_id = p.id
        JOIN invoices inv ON i.invoice_id = inv.id
        WHERE $where_clause
        GROUP BY i.product_id, i.price_of_one
        ORDER BY i.price_of_one";

// Prepare and execute the query
$stmt = $connect->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Fetch the results
$data = [];
$totalSales = 0;
while ($row = $result->fetch_assoc()) {
    $adjustedPrice = $row['price_of_one'] * ($purchase_at / 100);
    $adjustedTotal = $adjustedPrice * $row['total_quantity'];
    $totalSales += $adjustedTotal;
    $data[] = [
        "Product Name" => $row['product_name'],
        "Price per Item" => number_format($adjustedPrice, 2),
        "Total Quantity" => $row['total_quantity'],
        "Total" => number_format($adjustedTotal, 2)
    ];
}



$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Add headers
$headers = array_keys($data[0]);
$column = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($column . '1', $header);
    $column++;
}

// Add data
$row = 2;
foreach ($data as $item) {
    $column = 'A';
    foreach ($item as $value) {
        $sheet->setCellValue($column . $row, $value);
        $column++;
    }
    $row++;
}

// Add total sales
$sheet->setCellValue('A' . ($row + 1), 'Total Sales');
$sheet->setCellValue('D' . ($row + 1), number_format($totalSales, 2));

// Create Excel file
$writer = new Xlsx($spreadsheet);

// Set headers for file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="itemized_sales.xlsx"');
header('Cache-Control: max-age=0');

// Save Excel file to output
$writer->save('php://output');

// JavaScript to show message (this will be ignored by the browser during file download)
echo "<script>alert('Excel file has been downloaded successfully!');</script>";