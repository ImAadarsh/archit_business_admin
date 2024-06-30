<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "location_name" => $_POST['location_name'],
    "email" => $_POST['email'],
    "business_id" => $_POST['business_id'],
    "address" => $_POST['address'],
    "phone" => $_POST['phone'],
    "alternate_phone" => $_POST['alternate_phone'],
);
    $make_call = callAPI1('POST', 'insertLocation', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../locations.php';
        </script>
        ";
        
    }  

?>