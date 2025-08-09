<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $id = $_POST['id'];
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $name = $_POST['name'];
    $product_serial_number = $_POST['product_serial_number'];
    $hsn_code = $_POST['hsn_code'];
    $price = $_POST['price'];
    
    // Current timestamp for updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Verify that the product belongs to the current business
    $check_sql = "SELECT id FROM products WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Product exists and belongs to the current business
        // Prepare SQL statement for update
        $update_sql = "UPDATE products SET 
                        location_id = ?, 
                        name = ?, 
                        product_serial_number = ?, 
                        hsn_code = ?, 
                        price = ?, 
                        updated_at = ? 
                      WHERE id = ? AND business_id = ?";
        
        // Prepare and bind parameters
        $update_stmt = $connect->prepare($update_sql);
        $update_stmt->bind_param("isssdsii", $location_id, $name, $product_serial_number, $hsn_code, $price, $current_timestamp, $id, $business_id);
        
        // Execute the statement
        if ($update_stmt->execute()) {
            // Success
            $_SESSION['success'] = "Product updated successfully!";
            header("Location: ../products.php");
            exit();
        } else {
            // Error
            $_SESSION['error'] = "Error updating product: " . $connect->error;
            header("Location: ../edit-product.php?id=" . $id);
            exit();
        }
        
        $update_stmt->close();
    } else {
        // Product not found or doesn't belong to the current business
        $_SESSION['error'] = "Product not found or you don't have permission to update it.";
        header("Location: ../products.php");
        exit();
    }
    
    $check_stmt->close();
} else {
    // If not POST request, redirect to products page
    header("Location: ../products.php");
    exit();
}
?> 