<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "id" => $_GET['id'],
    "wid" => $_GET['wid'],
    
);
    $make_call = callAPI1('POST', 'notifyuser', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../pending-payments.php';
        </script>
        ";
        
    }  

?>