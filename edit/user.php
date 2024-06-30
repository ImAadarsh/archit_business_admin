<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $customer_type = $_POST['customer_type'];
    $invoice_type = $_POST['invoice_type'];
    $mobile_number = $_POST['mobile_number'];
    $state = $_POST['state'];
    $doc_no = $_POST['doc_no'];
    $business_id = $_SESSION['business_id']; // Get business_id from session

    $sql = "UPDATE invoices SET 
                name = ?,
                customer_type = ?,
                type = ?,
                mobile_number = ?,
                doc_no = ?
            WHERE id = ? AND business_id = ?";

    $stmt = $connect->prepare($sql);
    $stmt->bind_param("sssssii", $name, $customer_type, $invoice_type, $mobile_number, $doc_no, $id, $business_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Invoice updated successfully";
    } else {
        $_SESSION['error'] = "Error updating invoice: " . $stmt->error;
    }

    $stmt->close();

    // Update the state in the address table
    $sql = "UPDATE addres SET state = ? WHERE id = (SELECT billing_address_id FROM invoices WHERE id = ?)";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("si", $state, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: ../users.php");
    exit();
} else {
    header("Location: ../users.php");
    exit();
}