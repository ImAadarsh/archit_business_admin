<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from reviews where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../reviews.php');
}else{
    echo "Not Delete";
} 


?>