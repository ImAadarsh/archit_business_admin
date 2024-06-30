<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from users where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../users.php');
}else{
    echo "Not Delete";
} 


?>