<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "name" => $_POST['name'],
    "description" => $_POST['description'],
);
    $make_call = callAPI1('POST', 'addfaq', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../faq-view.php';
        </script>
        ";
        
    }  

?>