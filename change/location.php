<?php
include("../admin/connect.php");
include("../admin/session.php");

// Retrieve id and state parameters from GET request
$newid = $_GET['id'];
$state = $_GET['state'];

// Update the 'is_active' field in the 'locations' table
$sql = "UPDATE locations SET is_active='$state' WHERE id='$newid'";

// Execute the SQL query
if (mysqli_query($connect, $sql)) {
    header('location: ../locations.php'); // Redirect to locations page after successful update
} else {
    echo "Update failed"; // Output error message if update fails
} 
?>
