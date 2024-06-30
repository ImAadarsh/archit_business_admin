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
    "cpd" => $_POST['cpd'],
    "is_premium" => $_POST['is_premium'],
    "type" => $_POST['type'],
    "skills" => $_POST['skills'],
    "video_link" => $_POST['video_link'],
    "trainer_description" => $_POST['trainer_description'],
    'image'=> curl_file_create( $_FILES['image']['tmp_name'], $_FILES['image']['type'], $_FILES['image']['name']),
    'trainer_image'=> curl_file_create( $_FILES['trainer_image']['tmp_name'], $_FILES['trainer_image']['type'], $_FILES['trainer_image']['name']),
    'video_banner'=> curl_file_create( $_FILES['video_banner']['tmp_name'], $_FILES['video_banner']['type'], $_FILES['video_banner']['name']),
);
    $make_call = callAPI1('POST', 'addworkshop', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../workshop-view.php';
        </script>
        ";
        
    }  

?>