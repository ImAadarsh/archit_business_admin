<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['id'];

$sql="DELETE from payments where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../pending-payments.php');
}else{
    echo "Not Delete";
} 


?>