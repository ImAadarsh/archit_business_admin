<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from locations where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../locations.php');
}else{
    echo "Not Delete";
} 


?>