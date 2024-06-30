<?php
include ("../admin/connect.php");
include ("../admin/session.php");
echo "aa";

// Get input from URL
    echo "bb";
    $id = $_POST['id'];
    $temp = $_POST['new'];
    print_r($_POST);

    // Update w_status in workshops table
    $sql = "UPDATE users SET profile_views=profile_views+$temp WHERE id = '$id'";
    
    if (mysqli_query($connect, $sql)) {
        // echo $sql;
        header("Location: ".$_SERVER['HTTP_REFERER']);
        // echo "Record updated successfully";
    } else {
        // Log the error or display a generic message
        echo "Error updating record. Please try again later.";
        error_log("MySQL Error: " . mysqli_error($connect));
    }
    
    // Close database connection
    mysqli_close($connect);
    
    // Return back to the previous page
    exit();

?>
