<?php error_reporting(E_ALL);
ini_set('display_errors', 1);
?>

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if(empty($_SESSION['email']) &&  $_SESSION['role'] != 'admin'){
    header('location: index.php');
}

// Include subscription check for business users
if (isset($_SESSION['business_id']) && $_SESSION['role'] == 'admin') {
    include("subscription_check.php");
    
    // Get subscription info for the current business
    $subscriptionInfo = getSubscriptionInfo($_SESSION['business_id']);
    $_SESSION['subscription_status'] = $subscriptionInfo['status'];
    $_SESSION['subscription_plan'] = $subscriptionInfo['plan_name'];
    $_SESSION['trial_remaining'] = $subscriptionInfo['trial_remaining'];
}