<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
$day = isset($_GET['day']) ? $_GET['day'] : null;
$month = isset($_GET['month']) ? $_GET['month'] : null;
$year = isset($_GET['year']) ? $_GET['year'] : null;
$week_start = isset($_GET['week_start']) ? $_GET['week_start'] : null;
$week_end = isset($_GET['week_end']) ? $_GET['week_end'] : null;
$type = isset($_GET['type']) ? intval($_GET['type']) : null;
$name = isset($_GET['name']) ? $_GET['name'] : null;
$amount_min = isset($_GET['amount_min']) ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) ? floatval($_GET['amount_max']) : null;

// Validate month input
if ($month !== null && ($month < 1 || $month > 12)) {
    echo "Invalid month. Please enter a number between 1 and 12.";
    exit;
}

// Validate year input
if ($year !== null && !is_numeric($year)) {
    echo "Invalid year. Please enter a valid year.";
    exit;
}

// Build the SQL query
$where_clauses = ["1=1"];
$params = [];
$types = "";

// Filter by day
if ($day && validateDate($day)) {
    $where_clauses[] = "DATE(e.created_at) = ?";
    $params[] = $day;
    $types .= "s";
}

// Filter by month
if ($month) {
    $where_clauses[] = "MONTH(e.created_at) = ?";
    $params[] = $month;
    $types .= "i";
}

// Filter by year
if ($year) {
    $where_clauses[] = "YEAR(e.created_at) = ?";
    $params[] = $year;
    $types .= "i";
}

// Filter by week
if ($week_start && $week_end && validateDate($week_start) && validateDate($week_end)) {
    $where_clauses[] = "DATE(e.created_at) BETWEEN ? AND ?";
    $params[] = $week_start;
    $params[] = $week_end;
    $types .= "ss";
}

// Filter by expense type
if ($type !== null) {
    $where_clauses[] = "e.type = ?";
    $params[] = $type;
    $types .= "i";
}

// Filter by name
if ($name) {
    $where_clauses[] = "e.name LIKE ?";
    $params[] = "%" . $name . "%";
    $types .= "s";
}

// Filter by amount range
if ($amount_min !== null && $amount_max !== null) {
    $where_clauses[] = "e.amount BETWEEN ? AND ?";
    $params[] = $amount_min;
    $params[] = $amount_max;
    $types .= "dd";
}

$where_clause = implode(" AND ", $where_clauses);

$sql = "SELECT e.id, e.name, e.amount, e.type, l.location_name, u.name as user_name, e.created_at
        FROM expenses e
        JOIN locations l ON e.location_id = l.id
        JOIN users u ON e.user_id = u.id
        WHERE $where_clause";
echo "SQL Query: " . $sql . "<br>";
echo "Parameters: " . print_r($params, true) . "<br>";
// Prepare and execute the query
$stmt = $connect->prepare($sql);

// Only bind parameters if there are any
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Fetch the results
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        "ID" => $row['id'],
        "Name" => $row['name'],
        "Amount" => number_format($row['amount'], 2),
        "Type" => ($row['type'] == 0 ? 'Monthly' : 'Adhoc'),
        "Location" => $row['location_name'],
        "Created By" => $row['user_name'],
        "Created At" => $row['created_at']
    ];
}

// Check if $data is empty
if (empty($data)) {
    echo "No results found for the given criteria.";
    exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Rest of your code...

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

// Create Excel file
$writer = new Xlsx($spreadsheet);

// Set headers for file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="expenses_report.xlsx"');
header('Cache-Control: max-age=0');

// Save Excel file to output
$writer->save('php://output');

// JavaScript to show message (this will be ignored by the browser during file download)
echo "<script>alert('Excel file has been downloaded successfully!');</script>";
?>
