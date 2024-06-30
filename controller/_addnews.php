<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "title" => $_POST['title'],
    "link" => $_POST['link']
    
);
    $make_call = callAPI1('POST', 'addnews', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../addnews.php';
        </script>
        ";
        
    }  

?>