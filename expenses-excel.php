<?php
// connect to the database
include ("admin/connect.php");
include ("admin/session.php");

// Ensure user is authenticated
if (!isset($_SESSION)) {
    die("Unauthorized access");
}

// retrieve the data
$query = $_GET['query'];

// Validate and sanitize the query
if (!$query) {
    die("No query provided");
}

// Execute the query
$result = mysqli_query($connect, $query);

if (!$result) {
    die("Query failed: " . mysqli_error($connect));
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Output the Excel data
echo "ID\tName\tAmount\tType\tLocation\tCreated By\tCreated At\n";

while ($row = mysqli_fetch_assoc($result)) {
    echo $row['id'] . "\t";
    echo $row['name'] . "\t";
    echo $row['amount'] . "\t";
    echo ($row['type'] == 0 ? 'Monthly' : 'Adhoc') . "\t";
    echo $row['location_name'] . "\t";
    echo $row['user_name'] . "\t";
    echo $row['created_at'] . "\n";
}

mysqli_close($connect);

// No need for JavaScript redirect, the file download will happen automatically