<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $id = $_POST['id'];
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $name = $_POST['name'];
    $hsn_code = $_POST['hsn_code'] ?? '';
    $gst_percent = $_POST['gst_percent'] ?? 0;
    
    // Current timestamp for updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Verify that the category belongs to the current business
    $check_sql = "SELECT id FROM categories WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Prepare SQL statement for update
        $update_sql = "UPDATE categories SET name = ?, location_id = ?, hsn_code = ?, gst_percent = ?, updated_at = ? WHERE id = ? AND business_id = ?";
        
        // Prepare and bind parameters
        $update_stmt = $connect->prepare($update_sql);
        $update_stmt->bind_param("sissdsi", $name, $location_id, $hsn_code, $gst_percent, $current_timestamp, $id, $business_id);
        
        // Execute the statement
        if ($update_stmt->execute()) {
            // Success
            $_SESSION['success'] = "Product Type updated successfully!";
            header("Location: ../category-view.php");
            exit();
        } else {
            // Error
            $_SESSION['error'] = "Error updating product type: " . $connect->error;
            header("Location: ../edit-category.php?id=" . $id);
            exit();
        }
        
        $update_stmt->close();
    } else {
        // Category not found or doesn't belong to the current business
        $_SESSION['error'] = "Product Type not found or you don't have permission to update it.";
        header("Location: ../category-view.php");
        exit();
    }
    
    $check_stmt->close();
} else {
    // If not POST request, redirect to categories page
    header("Location: ../category-view.php");
    exit();
}
?> 