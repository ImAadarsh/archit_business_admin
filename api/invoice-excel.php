<?php
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
$day = isset($_GET['day']) ? $_GET['day'] : null;
$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$week_start = isset($_GET['week_start']) ? $_GET['week_start'] : null;
$week_end = isset($_GET['week_end']) ? $_GET['week_end'] : null;
$type = isset($_GET['type']) ? $_GET['type'] : null;
$name = isset($_GET['name']) ? $_GET['name'] : null;
$amount_min = isset($_GET['amount_min']) ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) ? floatval($_GET['amount_max']) : null;
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : null;

// Validate business_id
if (!$business_id) {
    echo "Error: Business ID is required";
    exit;
}

// Build the SQL query
$where_clauses = ["inv.business_id = ?", "inv.is_completed = 1"];
$params = [$business_id];
$types = "i";

// Apply filters based on the provided parameters
if ($day) {
    $where_clauses[] = "DATE(inv.invoice_date) = ?";
    $params[] = $day;
    $types .= "s";
}

if ($month) {
    $where_clauses[] = "MONTH(inv.invoice_date) = ?";
    $params[] = $month;
    $types .= "i";
}

if ($week_start && $week_end) {
    $where_clauses[] = "inv.invoice_date BETWEEN ? AND ?";
    $params[] = $week_start;
    $params[] = $week_end;
    $types .= "ss";
}

if ($year) {
    $where_clauses[] = "YEAR(inv.invoice_date) = ?";
    $params[] = $year;
    $types .= "i";
}

if ($type) {
    $where_clauses[] = "inv.type = ?";
    $params[] = $type;
    $types .= "s";
}

if ($name) {
    $where_clauses[] = "inv.name LIKE ?";
    $params[] = "%" . $name . "%";
    $types .= "s";
}

if ($amount_min && $amount_max) {
    $where_clauses[] = "inv.total_amount BETWEEN ? AND ?";
    $params[] = $amount_min;
    $params[] = $amount_max;
    $types .= "dd";
}

if ($invoice_id) {
    $where_clauses[] = "inv.id = ?";
    $params[] = $invoice_id;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_clauses);

$sql = "SELECT inv.serial_no, inv.invoice_date, inv.name, inv.doc_no, inv.total_dgst, inv.total_cgst, inv.total_igst, inv.total_amount
        FROM invoices inv
        WHERE $where_clause";

// Prepare and execute the query
$stmt = $connect->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Fetch the results and write to CSV
$output = fopen('php://output', 'w');
fputcsv($output, array('Invoice Number', 'Date', 'Customer Name', 'GST/Adhaar Number', 'Amount', 'DGST Amount', 'CGST Amount', 'IGST Amount', 'Total Amount'));

while ($row = $result->fetch_assoc()) {
    $temp_gst = (isset($row['total_dgst']) ? floatval($row['total_dgst']) : 0) +
                (isset($row['total_cgst']) ? floatval($row['total_cgst']) : 0) +
                (isset($row['total_igst']) ? floatval($row['total_igst']) : 0);
    
    $total_amount = isset($row['total_amount']) ? floatval($row['total_amount']) : 0;
    $temp_amount_wgst = $total_amount - $temp_gst;
    
    $invoice_date = isset($row['invoice_date']) ? strtotime($row['invoice_date']) : time();
    
    fputcsv($output, array(
        isset($row['serial_no']) ? $row['serial_no'] : 'N/A',
        date('d M Y', $invoice_date),
        isset($row['name']) ? $row['name'] : 'N/A',
        isset($row['doc_no']) ? $row['doc_no'] : 'N/A',
        number_format($temp_amount_wgst, 2),
        isset($row['total_dgst']) ? $row['total_dgst'] : 'N/A',
        isset($row['total_cgst']) ? $row['total_cgst'] : 'N/A',
        isset($row['total_igst']) ? $row['total_igst'] : 'N/A',
        number_format($total_amount, 2),
    ));
}

fclose($output);

// Set headers for file download
header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename="invoices.csv"');
header('Cache-Control: max-age=0');

// Output the CSV content
readfile('php://output');
?>
