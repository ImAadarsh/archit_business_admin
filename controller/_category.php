<?php
include ("../admin/connect.php");
include ("../admin/session.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $name = $_POST['name'];
    $hsn_code = $_POST['hsn_code'] ?? '';
    
    // Current timestamp for created_at and updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Prepare SQL statement
    $sql = "INSERT INTO categories (business_id, location_id, name, hsn_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)";
    
    // Prepare and bind parameters
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("iissss", $business_id, $location_id, $name, $hsn_code, $current_timestamp, $current_timestamp);
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success
        $_SESSION['success'] = "Product Type added successfully!";
        header("Location: ../category-view.php");
        exit();
    } else {
        // Error
        $_SESSION['error'] = "Error adding product type: " . $connect->error;
        header("Location: ../category.php");
        exit();
    }
    
    // Close statement
    $stmt->close();
} else {
    // If not POST request, redirect to category page
    header("Location: ../category.php");
    exit();
}
?>