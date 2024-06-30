<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "name" => $_POST['name'],
    "trainer_name" => $_POST['trainer_name'],
    "link" => $_POST['link'],
    "rlink" => $_POST['rlink'],
    "info" => $_POST['info'],
    "duration" => $_POST['duration'],
    "start_date" => $_POST['start_date'],
    "price" => $_POST['price'],
    "price_2" => $_POST['price_2'],
    "description" => $_POST['description'],
    "category_id" => $_POST['category_id'],
    "type" => $_POST['type'],
    "cpd" => $_POST['cpd'],
    "is_premium" => $_POST['is_premium'],
    "skills" => $_POST['skills'],
    "video_link" => $_POST['video_link'],
    "trainer_description" => $_POST['trainer_description'],
    "id" => $_POST['id'],
);
    $make_call = callAPI1('POST', 'updateworkshop', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../edit-workshop.php?id=".$_POST['id']."';
        </script>
        ";
        
    }  

?>