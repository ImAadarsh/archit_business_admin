<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "title" => $_POST['title'],
    "description" => $_POST['description'],
    "star" => $_POST['star'],
    "name" => $_POST['name'],
    "designation" => $_POST['designation'],
    'user_image'=> curl_file_create( $_FILES['thumbnail']['tmp_name'], $_FILES['thumbnail']['type'], $_FILES['thumbnail']['name'])
);
    $make_call = callAPI1('POST', 'addReview', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../reviews.php';
        </script>
        ";
        
    }  

?>