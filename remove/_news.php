<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from emails where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../news.php');
}else{
    echo "Not Delete";
} 


?>