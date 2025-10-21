<?php
// Universal Access Control for All Pages
// This file should be included at the top of every page that requires subscription access

if (!defined('ACCESS_CONTROL_LOADED')) {
    define('ACCESS_CONTROL_LOADED', true);
    
    // Start session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Include required files
    include("connect.php");
    include("subscription_check.php");
    
    // Get business ID from session
    $businessId = $_SESSION['business_id'] ?? null;
    
    if (!$businessId) {
        // Redirect to login if no business ID
        header('Location: ../index.php');
        exit;
    }
    
    // Check subscription access
    $access = checkSubscriptionAccess($businessId);
    
    if (!$access['access']) {
        // Store access denial info in session
        $_SESSION['subscription_error'] = $access['reason'];
        $_SESSION['subscription_status'] = $access['status'];
        $_SESSION['subscription_plan'] = $access['plan'];
        
        // Redirect to subscription page
        header('Location: ../subscription.php');
        exit;
    }
    
    // Store subscription info in session for easy access
    $_SESSION['subscription_status'] = $access['status'];
    $_SESSION['subscription_plan'] = $access['plan'];
    $_SESSION['trial_remaining'] = $access['trial_remaining'] ?? 0;
    
    // Get trial warning if applicable
    $trialWarning = getTrialWarning($businessId);
    if ($trialWarning) {
        $_SESSION['trial_warning'] = $trialWarning;
    }
}
?>
