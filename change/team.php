<?php
include("../admin/connect.php");
include("../admin/session.php");

if (isset($_GET['id']) && isset($_GET['status'])) {
    $id = $_GET['id'];
    $status = $_GET['status'];

    $sql = "UPDATE users SET is_active = ? WHERE id = ?";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("ii", $status, $id);

    if ($stmt->execute()) {
        header("Location: ../team.php"); // Redirect back to the users page
        exit();
    } else {
        echo "Error updating record: " . $connect->error;
    }

    $stmt->close();
}

$connect->close();
?>