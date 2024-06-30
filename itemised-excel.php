<?php
// connect to the database
include ("admin/connect.php");
include ("admin/session.php");

// Ensure user is authenticated
if (!isset($_SESSION)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Unauthorized access");
}

// retrieve the data
$query = isset($_GET['query']) ? $_GET['query'] : '';

// Validate and sanitize the query
if (empty($query)) {
    header("HTTP/1.1 400 Bad Request");
    exit("No query provided");
}

// Execute the query
$result = mysqli_query($connect, $query);

if (!$result) {
    error_log("Query failed: " . mysqli_error($connect));
    header("HTTP/1.1 500 Internal Server Error");
    exit("An error occurred while processing your request");
}

// Set headers for Excel download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="purchases_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Output the CSV data
$output = fopen('php://output', 'w');
fputcsv($output, array('Invoice Number', 'Name', 'Invoice Type','Payment Mode', 'Invoice Amount', 'Invoice Date', 'Invoice URL'));

while ($row = mysqli_fetch_assoc($result)) {
    $invoice_date = strtotime($row['invoice_date']);
    fputcsv($output, array(
        (!empty($row['serial_no']) && strtolower($row['serial_no']) !== 'null') ? $row['serial_no'] : 'N/A',
        $row['name'],
        (!empty($row['type']) && strtolower($row['type']) !== 'null') ? ucfirst($row['type']) : 'N/A',
        ucfirst($row['payment_mode']),
        $row['total_amount'],
        $invoice_date ? date('d M Y | H:i', $invoice_date) : 'N/A',
        'https://invoice.architartgallery.in/invoice.html?invoiceid='.$row['id']
    ));
}

fclose($output);
mysqli_close($connect);