<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $business_id = $_POST['business_id'];
    $location_id = $_POST['location_id'];
    $account_name = $_POST['account_name'];
    $bank_name = $_POST['bank_name'];
    $address = $_POST['address'];
    $account_no = $_POST['account_no'];
    $ifsc_code = $_POST['ifsc_code'];
    
    // Current timestamp for created_at and updated_at
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Prepare SQL statement
    $sql = "INSERT INTO banks (business_id, location_id, account_name, bank_name, address, account_no, ifsc_code, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    // Prepare and bind parameters
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("iisssssss", $business_id, $location_id, $account_name, $bank_name, $address, $account_no, $ifsc_code, $current_timestamp, $current_timestamp);
    
    // Execute the statement
    if ($stmt->execute()) {
        // Success
        $_SESSION['success'] = "Bank account added successfully!";
        header("Location: ../banks.php");
        exit();
    } else {
        // Error
        $_SESSION['error'] = "Error adding bank account: " . $connect->error;
        header("Location: ../add-bank.php");
        exit();
    }
    
    // Close statement
    $stmt->close();
} else {
    // If not POST request, redirect to add-bank page
    header("Location: ../add-bank.php");
    exit();
}
?> 