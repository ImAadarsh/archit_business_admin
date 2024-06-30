<?php
include("../admin/connect.php");
include("../admin/session.php");
$newid=$_GET['del_id'];

$sql="DELETE from partners where id='$newid'";
if(mysqli_query($connect,$sql)){
    header('location: ../partner-view.php');
}else{
    echo "Not Delete";
} 


?>