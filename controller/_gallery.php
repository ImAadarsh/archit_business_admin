<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "event_name" => $_POST['event_name'],
    'image'=> curl_file_create( $_FILES['image']['tmp_name'], $_FILES['image']['type'], $_FILES['image']['name'])
);
    $make_call = callAPI1('POST', 'addgallery', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../gallery-view.php';
        </script>
        ";
        
    }  

?>