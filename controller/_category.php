<?php
include ("../admin/connect.php");
include ("../admin/session.php");


$data_array =  array(
    "name" => $_POST['name'],
    "token" => $_SESSION['usertoken'],
);
    $make_call = callAPI1('POST', 'addcategory', $data_array,null);
    $response = json_decode($make_call, true);
    if ($response['message']) {
        echo "<script>alert('".$response['message']."')
        window.location.href='../category-view.php';
        </script>
        ";
    }

// header('location: categories.php');
?>