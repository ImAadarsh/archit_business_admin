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
fputcsv($output, array('Invoice Number', 'Date', 'Customer Name', 'GST/Adhaar Number', 'State', 'HSN Code', 'GST Rate', 'Amount', 'DGST Amount', 'CGST Amount', 'IGST Amount', 'Total Amount'));

$temp = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $temp++;
    
    // Calculate row-level amounts (per GST rate)
    $row_gst_amount = isset($row['gst_group_tax']) ? floatval($row['gst_group_tax']) : 0;
    $row_sale_amount = isset($row['gst_group_amount']) ? floatval($row['gst_group_amount']) : 0;
    $row_total = $row_sale_amount + $row_gst_amount;
    
    // Calculate individual GST components (proportional to this GST rate group)
    $dgst_amount = isset($row['total_dgst']) ? floatval($row['total_dgst']) : 0;
    $cgst_amount = isset($row['total_cgst']) ? floatval($row['total_cgst']) : 0;
    $igst_amount = isset($row['total_igst']) ? floatval($row['total_igst']) : 0;
    $total_invoice_gst = $dgst_amount + $cgst_amount + $igst_amount;
    
    // Proportionally distribute GST components for this GST rate group
    if ($total_invoice_gst > 0) {
        $proportion = $row_gst_amount / $total_invoice_gst;
        $dgst_for_row = $dgst_amount * $proportion;
        $cgst_for_row = $cgst_amount * $proportion;
        $igst_for_row = $igst_amount * $proportion;
    } else {
        $dgst_for_row = 0;
        $cgst_for_row = 0;
        $igst_for_row = 0;
    }
    
    $invoice_date = isset($row['invoice_date']) ? strtotime($row['invoice_date']) : time();
    
    // Convert decimal GST rate to percentage (0.05 -> 5, 0.18 -> 18)
    $gst_rate_display = 'N/A';
    if (isset($row['gst_rate']) && $row['gst_rate'] !== null) {
        $gst_val = $row['gst_rate'];
        if ($gst_val < 1) {
            $gst_val = $gst_val * 100;
        }
        $gst_rate_display = round($gst_val) . '%';
    }
    
    fputcsv($output, array(
        isset($row['serial_no']) ? $row['serial_no'] : 'N/A',
        date('d M Y', $invoice_date),
        isset($row['name']) ? $row['name'] : 'N/A',
        isset($row['doc_no']) ? $row['doc_no'] : 'N/A',
        isset($row['customer_state']) ? $row['customer_state'] : 'N/A',
        isset($row['hsn_code']) ? $row['hsn_code'] : 'N/A',
        $gst_rate_display,
        number_format($row_sale_amount, 2),
        number_format($dgst_for_row, 2),
        number_format($cgst_for_row, 2),
        number_format($igst_for_row, 2),
        number_format($row_total, 2),
    ));
}

fclose($output);
mysqli_close($connect);