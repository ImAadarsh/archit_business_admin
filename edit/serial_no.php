<?php
include '../admin/connect.php';
include '../admin/session.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $invoice_id = $_POST['invoice_id'];
    $serial_no = $_POST['serial_no'];
    $business_id = $_SESSION['business_id'];

    // Validate input
    if (empty($invoice_id) || empty($serial_no)) {
        echo json_encode(['success' => false, 'message' => 'Invoice ID and Serial Number are required']);
        exit;
    }

    // Check if the invoice belongs to the current business
    $check_sql = "SELECT id FROM invoices WHERE id = ? AND business_id = ?";
    $check_stmt = $connect->prepare($check_sql);
    $check_stmt->bind_param("ii", $invoice_id, $business_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found or unauthorized']);
        exit;
    }

    // Update the serial number
    $update_sql = "UPDATE invoices SET serial_no = ? WHERE id = ? AND business_id = ?";
    $update_stmt = $connect->prepare($update_sql);
    $update_stmt->bind_param("sii", $serial_no, $invoice_id, $business_id);

    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Serial number updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating serial number: ' . $update_stmt->error]);
    }

    $update_stmt->close();
    $check_stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?> 