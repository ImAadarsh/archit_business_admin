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
fputcsv($output, array('ID', 'Customer Name', 'Type', 'Payment Mode', 'Sale Amount', 'GST Amount', 'Total Amount', 'Invoice Date', 'Invoice URL'));

$temp = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $temp++;
    $temp_gst = (isset($row['total_dgst']) ? floatval($row['total_dgst']) : 0) +
                (isset($row['total_cgst']) ? floatval($row['total_cgst']) : 0) +
                (isset($row['total_igst']) ? floatval($row['total_igst']) : 0);
    
    $total_amount = isset($row['total_amount']) ? floatval($row['total_amount']) : 0;
    $temp_amount_wgst = $total_amount - $temp_gst;
    
    $invoice_date = isset($row['invoice_date']) ? strtotime($row['invoice_date']) : time();
    
    fputcsv($output, array(
        $temp,
        isset($row['name']) ? $row['name'] : 'N/A',
        (!empty($row['type']) && strtolower($row['type']) !== 'null') ? ucfirst($row['type']) : 'N/A',
        isset($row['payment_mode']) ? ucfirst($row['payment_mode']) : 'N/A',
        number_format($temp_amount_wgst, 2),
        number_format($temp_gst, 2),
        number_format($total_amount, 2),
        date('d M Y', $invoice_date),
        'https://invoice.architartgallery.in/invoice.html?invoiceid=' . (isset($row['id']) ? $row['id'] : '')
    ));
}

fclose($output);
mysqli_close($connect);