<?php
// Subscription Access Control
include("connect.php");
include("cashfree_config.php");

// Check if user has active subscription or approved trial
if (!function_exists('checkSubscriptionAccess')) {
function checkSubscriptionAccess($businessId, $requiredFeature = null) {
    $subscriptionStatus = getBusinessSubscriptionStatus($businessId);
    
    // Allow access if user has active subscription or approved trial
    if ($subscriptionStatus['status'] == 'active' || 
        ($subscriptionStatus['status'] == 'trialing' && isTrialApproved($businessId))) {
        
        // If specific feature is required, check if plan includes it
        if ($requiredFeature && !empty($subscriptionStatus['features'])) {
            if (!in_array($requiredFeature, $subscriptionStatus['features'])) {
                return [
                    'access' => false,
                    'reason' => 'This feature is not included in your current plan',
                    'status' => $subscriptionStatus['status'],
                    'plan' => $subscriptionStatus['plan_name']
                ];
            }
        }
        
        return [
            'access' => true,
            'status' => $subscriptionStatus['status'],
            'plan' => $subscriptionStatus['plan_name'],
            'trial_remaining' => $subscriptionStatus['trial_remaining']
        ];
    }
    
    // No active subscription or trial
    return [
        'access' => false,
        'reason' => 'You need an active subscription or approved trial to access this feature',
        'status' => $subscriptionStatus['status'],
        'plan' => $subscriptionStatus['plan_name']
    ];
}
}

// Redirect to subscription page if no access
if (!function_exists('requireSubscription')) {
function requireSubscription($businessId, $requiredFeature = null) {
    $access = checkSubscriptionAccess($businessId, $requiredFeature);
    
    if (!$access['access']) {
        $_SESSION['subscription_error'] = $access['reason'];
        $_SESSION['subscription_status'] = $access['status'];
        $_SESSION['subscription_plan'] = $access['plan'];
        header('Location: subscription.php');
        exit;
    }
    
    return $access;
}
}

// Get subscription info for display
if (!function_exists('getSubscriptionInfo')) {
function getSubscriptionInfo($businessId) {
    return getBusinessSubscriptionStatus($businessId);
}
}

// Check if trial is expiring soon (within 3 days)
if (!function_exists('isTrialExpiringSoon')) {
function isTrialExpiringSoon($businessId) {
    $subscription = getBusinessSubscriptionStatus($businessId);
    
    if ($subscription['status'] == 'trialing' && $subscription['trial_remaining'] <= 3) {
        return true;
    }
    
    return false;
}
}

// Check if trial is approved (mandate approved)
if (!function_exists('isTrialApproved')) {
function isTrialApproved($businessId) {
    global $connect;
    
    $sql = "SELECT authorization_status, authorization_time 
            FROM subscriptions 
            WHERE business_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1";
    
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription = $result->fetch_assoc();
    $stmt->close();
    
    if ($subscription) {
        // Trial is approved if authorization status is active or pending (for BANK_APPROVAL_PENDING)
        return $subscription['authorization_status'] === 'active' || $subscription['authorization_status'] === 'pending';
    }
    
    return false;
}
}

// Get trial warning message
if (!function_exists('getTrialWarning')) {
function getTrialWarning($businessId) {
    $subscription = getBusinessSubscriptionStatus($businessId);
    
    // Only show trial warnings if trial is approved
    if ($subscription['status'] == 'trialing' && isTrialApproved($businessId)) {
        $days = $subscription['trial_remaining'];
        
        if ($days <= 0) {
            return [
                'type' => 'danger',
                'message' => 'Your trial has expired! Please subscribe to continue using the service.',
                'days' => 0
            ];
        } elseif ($days <= 3) {
            return [
                'type' => 'warning',
                'message' => "Your trial expires in {$days} day(s). Please subscribe to avoid service interruption.",
                'days' => $days
            ];
        }
    }
    
    return null;
}
}
?>