<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $name = $_POST['name'];
    $product_serial_number = 0;
    $hsn_code = $_POST['hsn_code'];
    $price = $_POST['price'];
    
    // Current timestamp for created_at and updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Prepare SQL statement
    $sql = "INSERT INTO products (business_id, location_id, name, is_temp, hsn_code, price, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    // Prepare and bind parameters
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("iisssdss", $business_id, $location_id, $name, $product_serial_number, $hsn_code, $price, $current_timestamp, $current_timestamp);
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success
        $_SESSION['success'] = "Product added successfully!";
        header("Location: ../products.php");
        exit();
    } else {
        // Error
        $_SESSION['error'] = "Error adding product: " . $connect->error;
        header("Location: ../add-product.php");
        exit();
    }
    
    // Close statement
    $stmt->close();
} else {
    // If not POST request, redirect to add-product page
    header("Location: ../add-product.php");
    exit();
}
?> 