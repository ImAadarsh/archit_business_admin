<?php
include ("../admin/connect.php");
include ("../admin/session.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = $_POST['name'];
    $category_id = $_POST['category_id'];
    $business_id = $_SESSION['business_id'];
    
    // Verify that the category belongs to the current business
    $check_sql = "SELECT id FROM categories WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $category_id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['error'] = "Invalid Product Type selected.";
        header("Location: ../art-category.php");
        exit();
    }
    
    // Current timestamp for created_at and updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Prepare SQL statement
    $sql = "INSERT INTO product_category (name, category_id, created_at, updated_at) VALUES (?, ?, ?, ?)";
    
    // Prepare and bind parameters
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("siss", $name, $category_id, $current_timestamp, $current_timestamp);
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success
        $_SESSION['success'] = "Product Category added successfully!";
        header("Location: ../art-category-view.php");
        exit();
    } else {
        // Error
        $_SESSION['error'] = "Error adding Product Category: " . $connect->error;
        header("Location: ../art-category.php");
        exit();
    }
    
    // Close statement
    $stmt->close();
    $check_stmt->close();
} else {
    // If not POST request, redirect to Product Category page
    header("Location: ../art-category.php");
    exit();
}
?> 