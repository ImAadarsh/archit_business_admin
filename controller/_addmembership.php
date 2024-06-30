<?php
include ("../admin/connect.php");
include ("../admin/session.php");


$data_array =  array(
    "membership" => $_GET['membership'],
    "id" => $_GET['id'],
    "token" => $_SESSION['usertoken'],
);
    $make_call = callAPI1('POST', 'addmembership', $data_array,null);
    $response = json_decode($make_call, true);
    if ($response['message']) {
        echo "<script>alert('".$response['message']."')
        window.location.href='../users.php';
        </script>
        ";
    }

// header('location: categories.php');
?>