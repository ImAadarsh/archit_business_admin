<?php
include ("../admin/connect.php");
include ("../admin/session.php");

$data_array =  array(
    "token" => $_SESSION['usertoken'],
    "title" => $_POST['title'],
    'banner_image'=> curl_file_create( $_FILES['banner_image']['tmp_name'], $_FILES['banner_image']['type'], $_FILES['banner_image']['name']),
    "meta_title" => $_POST['meta_title'],
    "meta_description" => $_POST['meta_description'],
    "keywords" => $_POST['keywords'],
    "description" => $_POST['description'],
    "content" => $_POST['content'],
    "is_featured"=> $_POST['is_featured'],
    "category_id" => $_POST['category_id'],
    "created_by" => $_POST['created_by']
);
    $make_call = callAPI1('POST', 'addblog', $data_array,null);
    $response = json_decode($make_call, true);
    if($response['message']){
        echo "<script>alert('".$response['message']."')
        window.location.href='../addfeedbacks.php';
        </script>
        ";
        
    }  

?>