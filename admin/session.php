<?php
session_start();
if(empty($_SESSION['email']) &&  $_SESSION['role'] != 'admin'){
    header('location: index.php');
}