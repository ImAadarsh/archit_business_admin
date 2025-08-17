<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $id = $_POST['id'];
    $business_id = $_POST['business_id'];
    $name = $_POST['name'];
    $category_id = $_POST['category_id'];
    
    // Current timestamp for updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Verify that the category belongs to the current business
    $check_sql = "SELECT id FROM categories WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $category_id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['error'] = "Invalid Product Type selected.";
        header("Location: ../edit-art-category.php?id=" . $id);
        exit();
    }
    
    // Verify that the art category exists and belongs to a valid category
    $verify_sql = "SELECT pc.id FROM product_category pc 
                   LEFT JOIN categories c ON pc.category_id = c.id 
                   WHERE pc.id = ? AND c.business_id = ?";
    $verify_stmt = $connect->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $id, $business_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        // Prepare SQL statement for update
        $update_sql = "UPDATE product_category SET name = ?, category_id = ?, updated_at = ? WHERE id = ?";
        
        // Prepare and bind parameters
        $update_stmt = $connect->prepare($update_sql);
        $update_stmt->bind_param("sisi", $name, $category_id, $current_timestamp, $id);
        
        // Execute the statement
        if ($update_stmt->execute()) {
            // Success
            $_SESSION['success'] = "Art Category updated successfully!";
            header("Location: ../art-category-view.php");
            exit();
        } else {
            // Error
            $_SESSION['error'] = "Error updating art category: " . $connect->error;
            header("Location: ../edit-art-category.php?id=" . $id);
            exit();
        }
        
        $update_stmt->close();
    } else {
        // Art category not found or doesn't belong to the current business
        $_SESSION['error'] = "Art Category not found or you don't have permission to update it.";
        header("Location: ../art-category-view.php");
        exit();
    }
    
    $check_stmt->close();
    $verify_stmt->close();
} else {
    // If not POST request, redirect to art categories page
    header("Location: ../art-category-view.php");
    exit();
}
?> 