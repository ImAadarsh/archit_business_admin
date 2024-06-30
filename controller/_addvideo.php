<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "title" => $_POST['title'],
    "link" => $_POST['link'],
    'thumbnail'=> curl_file_create( $_FILES['thumbnail']['tmp_name'], $_FILES['thumbnail']['type'], $_FILES['thumbnail']['name'])
);
    $make_call = callAPI1('POST', 'addreview', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../addreview.php';
        </script>
        ";
        
    }  

?>