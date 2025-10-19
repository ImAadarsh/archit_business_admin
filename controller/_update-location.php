<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $id = $_POST['id'];
    $business_id = $_POST['business_id'];
    $email = $_POST['email'];
    $location_name = $_POST['location_name'];
    $address = $_POST['address'];
    $phone = $_POST['phone'];
    $alternate_phone = $_POST['alternate_phone'] ?? '';
    $is_active = $_POST['is_active'];
    
    // Current timestamp for updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Verify that the location belongs to the current business
    $check_sql = "SELECT id FROM locations WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Prepare SQL statement for update
        $update_sql = "UPDATE locations SET email = ?, location_name = ?, address = ?, phone = ?, alternate_phone = ?, is_active = ?, updated_at = ? WHERE id = ? AND business_id = ?";
        
        // Prepare and bind parameters
        $update_stmt = $connect->prepare($update_sql);
        $update_stmt->bind_param("sssssissi", $email, $location_name, $address, $phone, $alternate_phone, $is_active, $current_timestamp, $id, $business_id);
        
        // Execute the statement
        if ($update_stmt->execute()) {
            // Success
            $_SESSION['success'] = "Location updated successfully!";
            header("Location: ../locations.php");
            exit();
        } else {
            // Error
            $_SESSION['error'] = "Error updating location: " . $connect->error;
            header("Location: ../edit-location.php?id=" . $id);
            exit();
        }
        
        $update_stmt->close();
    } else {
        // Location not found or doesn't belong to the current business
        $_SESSION['error'] = "Location not found or you don't have permission to update it.";
        header("Location: ../locations.php");
        exit();
    }
    
    $check_stmt->close();
} else {
    // If not POST request, redirect to locations page
    header("Location: ../locations.php");
    exit();
}
?>
