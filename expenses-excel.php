<?php
// connect to the database
include ("admin/connect.php");
include ("admin/session.php");

// Ensure user is authenticated
if (!isset($_SESSION)) {
    die("Unauthorized access");
}

if (isset($_GET['export_all'])) {
    // Export all data
    $query = $_GET['query'];
    
    // Validate and sanitize the query
    if (!$query) {
        die("No query provided");
    }
    
    // Execute the query
    $result = mysqli_query($connect, $query);
} elseif (isset($_POST['export_selected'])) {
    // Export selected rows
    $selected_ids = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : '';
    $selected_ids = array_map('intval', explode(',', $selected_ids));
    
    if (empty($selected_ids)) {
        die("No rows selected");
    }
    
    // Prepare the query with selected IDs
    $ids_string = implode(',', $selected_ids);
    $query = "SELECT e.*, u.name as user_name, l.location_name 
              FROM expenses e
              LEFT JOIN users u ON e.user_id = u.id
              LEFT JOIN locations l ON e.location_id = l.id
              WHERE e.id IN ($ids_string) AND e.business_id = '{$_SESSION['business_id']}'";
    
    // Execute the query
    $result = mysqli_query($connect, $query);
} else {
    die("Invalid export option");
}

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