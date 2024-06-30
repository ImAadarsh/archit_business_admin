<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "business_name" => $_POST['business_name'],
    "email" => $_POST['email'],
    "owner_name" => $_POST['owner_name'],
    "gst" => $_POST['gst'],
    "phone" => $_POST['phone'],
    "alternate_phone" => $_POST['alternate_phone'],
    'logo'=> curl_file_create( $_FILES['logo']['tmp_name'], $_FILES['logo']['type'], $_FILES['logo']['name']),
);
    $make_call = callAPI1('POST', 'insertBusiness', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../business.php';
        </script>
        ";
        
    }  

?>