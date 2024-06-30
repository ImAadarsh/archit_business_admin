<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from businessses where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../business.php');
}else{
    echo "Not Delete";
} 


?>