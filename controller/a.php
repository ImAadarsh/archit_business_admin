<?php
include ("../admin/connect.php");
include ("../admin/session.php");

// Get input from URL
$id = $_GET['id'];
$w_status = $_GET['w_stsatus'];

// Update w_status in workshops table
$sql = "UPDATE users SET payment=1 WHERE id = '$id'";
if (mysqli_query($connect, $sql)) {
    // echo $sql;
    header("Location: ".$_SERVER['HTTP_REFERER']);
    // echo "Record updated successfully";
} else {
    echo "Error updating record: " . mysqli_error($conn);
}

// Close database connection
mysqli_close($conn);

// Return back to the previous page

exit();
?>