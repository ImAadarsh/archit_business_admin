<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $id = $_POST['id'];
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $account_name = $_POST['account_name'];
    $bank_name = $_POST['bank_name'];
    $address = $_POST['address'];
    $account_no = $_POST['account_no'];
    $ifsc_code = $_POST['ifsc_code'];
    
    // Current timestamp for updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Verify that the bank account belongs to the current business
    $check_sql = "SELECT id FROM banks WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Bank account exists and belongs to the current business
        // Prepare SQL statement for update
        $update_sql = "UPDATE banks SET 
                        location_id = ?, 
                        account_name = ?, 
                        bank_name = ?, 
                        address = ?, 
                        account_no = ?, 
                        ifsc_code = ?, 
                        updated_at = ? 
                      WHERE id = ? AND business_id = ?";
        
        // Prepare and bind parameters
        $update_stmt = $connect->prepare($update_sql);
        $update_stmt->bind_param("issssssii", $location_id, $account_name, $bank_name, $address, $account_no, $ifsc_code, $current_timestamp, $id, $business_id);
        
        // Execute the statement
        if ($update_stmt->execute()) {
            // Success
            $_SESSION['success'] = "Bank account updated successfully!";
            header("Location: ../banks.php");
            exit();
        } else {
            // Error
            $_SESSION['error'] = "Error updating bank account: " . $connect->error;
            header("Location: ../edit-bank.php?id=" . $id);
            exit();
        }
        
        $update_stmt->close();
    } else {
        // Bank account not found or doesn't belong to the current business
        $_SESSION['error'] = "Bank account not found or you don't have permission to update it.";
        header("Location: ../banks.php");
        exit();
    }
    
    $check_stmt->close();
} else {
    // If not POST request, redirect to banks page
    header("Location: ../banks.php");
    exit();
}
?> 