<?php
include ("../admin/connect.php");
include ("../admin/session.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $business_id = $_POST['business_id'];
    $email = $_POST['email'];
    $location_name = $_POST['location_name'];
    $address = $_POST['address'];
    $state = $_POST['state'];
    $phone = $_POST['phone'];
    $alternate_phone = $_POST['alternate_phone'] ?? '';
    
    // Current timestamp for created_at and updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Prepare SQL statement
    $sql = "INSERT INTO locations (business_id, email, location_name, address, state, phone, alternate_phone, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)";
    
    // Prepare and bind parameters
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("issssssss", $business_id, $email, $location_name, $address, $state, $phone, $alternate_phone, $current_timestamp, $current_timestamp);
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success
        $_SESSION['success'] = "Location added successfully!";
        header("Location: ../locations.php");
        exit();
    } else {
        // Error
        $_SESSION['error'] = "Error adding location: " . $connect->error;
        header("Location: ../create-location.php");
        exit();
    }
    
    // Close statement
    $stmt->close();
} else {
    // If not POST request, redirect to create location page
    header("Location: ../create-location.php");
    exit();
}
?>