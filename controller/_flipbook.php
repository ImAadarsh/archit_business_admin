<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "title" => $_POST['name'],
    "url" => $_POST['description'],
);
    $make_call = callAPI1('POST', 'updateFlipbook', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../flipbook-view.php';
        </script>
        ";
        
    }  

?>