<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_POST['user_id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $location_id = $_POST['location_id'];
    $business_id = $_POST['business_id'];
    $passcode = $_POST['passcode'];

    $sql = "UPDATE users SET name=?, email=?, phone=?, role=?, location_id=?, business_id=?, passcode=? WHERE id=?";
    $stmt = $connect->prepare($sql);
    
    // Corrected bind_param() call
    $stmt->bind_param("sssssiis", $name, $email, $phone, $role, $location_id, $business_id, $passcode, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "User updated successfully";
    } else {
        $_SESSION['error'] = "Error updating user: " . $stmt->error;
    }

    $stmt->close();
    $connect->close();

    header("Location: ../team.php");
    exit();
}
?>