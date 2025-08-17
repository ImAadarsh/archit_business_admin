<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id'])) {
    $category_id = (int)$_GET['id'];
    $business_id = $_SESSION['business_id'];
    
    // Verify that the category belongs to the current business
    $check_sql = "SELECT id FROM categories WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $category_id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Delete the category
        $delete_sql = "DELETE FROM categories WHERE id = ? AND business_id = ?";
        $delete_stmt = $connect->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $category_id, $business_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Product Type deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting product type: " . $connect->error;
        }
        
        $delete_stmt->close();
    } else {
        $_SESSION['error'] = "Product Type not found or you don't have permission to delete it.";
    }
    
    $check_stmt->close();
} else {
    $_SESSION['error'] = "Invalid request.";
}

header("Location: ../category-view.php");
exit();
?> 