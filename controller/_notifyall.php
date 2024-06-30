<?php
include ("../admin/connect.php");
include ("../admin/session.php");
ini_set('max_execution_time', 3600); //3 minutes
$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "id" => $_GET['id']
);
    $make_call = callAPI1('POST', 'workshopallnotify', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../workshop-view.php';
        </script>
        ";
        
    }  

?>