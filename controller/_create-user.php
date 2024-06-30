<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "name" => $_POST['name'],
    "email" => $_POST['email'],
    "phone" => $_POST['phone'],
    "password" => $_POST['password'],
    "role" =>$_POST['role'],
    "business_id" =>$_POST['business_id'],
    "location_id" =>$_POST['location_id'],
);
    $make_call = callAPI1('POST', 'register', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../team.php';
        </script>
        ";
        
    }  

?>