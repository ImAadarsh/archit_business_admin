<?php
include '../admin/connect.php';
include '../admin/session.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verify that the product belongs to the current business
    $business_id = $_SESSION['business_id'];
    $check_sql = "SELECT id FROM products WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Product exists and belongs to the current business
        $delete_sql = "DELETE FROM products WHERE id = ?";
        $delete_stmt = $connect->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Product deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting product: " . $connect->error;
        }
        
        $delete_stmt->close();
    } else {
        $_SESSION['error'] = "Product not found or you don't have permission to delete it.";
    }
    
    $check_stmt->close();
} else {
    $_SESSION['error'] = "Invalid request. Product ID is required.";
}

// Redirect back to products page
header("Location: ../products.php");
exit();
?> 