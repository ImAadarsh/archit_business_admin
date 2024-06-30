<?php
// Establish database connection
$host = "82.180.142.204";
$user = "u954141192_archit";
$password = "Endeavour@2023";
$dbname = "u954141192_archit";

// Create connection
$connect = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($connect->connect_error) {
    die("Connection failed: " . $connect->connect_error);
}

// Check if business_id is set in the POST request
if (isset($_POST['business_id'])) {
    // Retrieve the selected business ID from the POST request
    $selected_business_id = $_POST['business_id'];

    // Prepare SQL query to fetch locations associated with the selected business ID
    $sql = "SELECT * FROM locations WHERE business_id = $selected_business_id";
    
    // Execute the query
    $results = $connect->query($sql);
    
    // Check if there are any results
    if ($results->num_rows > 0) {
        // Output HTML options for locations
        while ($final = $results->fetch_assoc()) {
            echo '<option value="' . $final['id'] . '">' . $final['location_name'] . '</option>';
        }
    } else {
        // If no locations found, display default option
        echo '<option>No locations found</option>';
    }
} else {
    // If business_id is not set in the POST request, display default option
    echo '<option>Choose Location</option>';
}

// Close database connection
$connect->close();
?>
