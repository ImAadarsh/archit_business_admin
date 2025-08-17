<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id'])) {
    $art_category_id = (int)$_GET['id'];
    $business_id = $_SESSION['business_id'];
    
    // Verify that the art category belongs to the current business
    $check_sql = "SELECT pc.id FROM product_category pc 
                   LEFT JOIN categories c ON pc.category_id = c.id 
                   WHERE pc.id = ? AND c.business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $art_category_id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Delete the art category
        $delete_sql = "DELETE FROM product_category WHERE id = ?";
        $delete_stmt = $connect->prepare($delete_sql);
        $delete_stmt->bind_param("i", $art_category_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Art Category deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting art category: " . $connect->error;
        }
        
        $delete_stmt->close();
    } else {
        $_SESSION['error'] = "Art Category not found or you don't have permission to delete it.";
    }
    
    $check_stmt->close();
} else {
    $_SESSION['error'] = "Invalid request.";
}

header("Location: ../art-category-view.php");
exit();
?> 