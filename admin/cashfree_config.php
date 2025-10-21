<?php
// Prevent multiple inclusions
if (defined('CASHFREE_CONFIG_LOADED')) {
    return;
}
define('CASHFREE_CONFIG_LOADED', true);

// Cashfree Configuration for Business
define('CASHFREE_CLIENT_ID', 'TEST108162956a8ed7c31b33fa15f63f59261801');
define('CASHFREE_CLIENT_SECRET', 'cfsk_ma_test_c2c13226a4c8c3a197c0c64dcd71270f_e929b4e6');
define('CASHFREE_API_VERSION', '2023-08-01');
define('CASHFREE_BASE_URL', 'https://test.cashfree.com/pg');

// Cashfree API Helper Functions
if (!function_exists('callCashfreeAPI')) {
function callCashfreeAPI($method, $endpoint, $data = null, $headers = []) {
    $url = CASHFREE_BASE_URL . $endpoint;
    
    $defaultHeaders = [
        'Content-Type: application/json',
        'x-api-version: ' . CASHFREE_API_VERSION,
        'x-client-id: ' . CASHFREE_CLIENT_ID,
        'x-client-secret: ' . CASHFREE_CLIENT_SECRET
    ];
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $curl = curl_init();
    
    switch ($method) {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case "DELETE":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        default:
            if ($data) {
                $url = sprintf("%s?%s", $url, http_build_query($data));
            }
    }
    
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, TRUE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    // Debug logging (remove in production)
    if ($httpCode >= 400) {
        error_log("Cashfree API Error - URL: $url, HTTP Code: $httpCode, Response: $result");
    }
    
    if (curl_error($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        return json_encode(['error' => $error, 'http_code' => $httpCode]);
    }
    
    curl_close($curl);
    return $result;
}
}

// Create Cashfree Subscription
if (!function_exists('createCashfreeSubscription')) {
function createCashfreeSubscription($subscriptionData) {
    $endpoint = '/subscriptions';
    return callCashfreeAPI('POST', $endpoint, $subscriptionData);
}
}

// Get Cashfree Subscription
if (!function_exists('getCashfreeSubscription')) {
function getCashfreeSubscription($subscriptionId) {
    $endpoint = '/subscriptions/' . $subscriptionId;
    return callCashfreeAPI('GET', $endpoint);
}
}

// Manage Cashfree Subscription (pause, resume, cancel, change plan)
if (!function_exists('manageCashfreeSubscription')) {
function manageCashfreeSubscription($subscriptionId, $action, $actionDetails = null) {
    $endpoint = '/subscriptions/' . $subscriptionId . '/manage';
    $data = [
        'subscription_id' => $subscriptionId,
        'action' => $action
    ];
    
    if ($actionDetails) {
        $data['action_details'] = $actionDetails;
    }
    
    return callCashfreeAPI('POST', $endpoint, $data);
}
}

// Get all plans from database
if (!function_exists('getAllPlans')) {
function getAllPlans() {
    global $connect;
    $sql = "SELECT * FROM subscription_plans WHERE is_active = 1 AND is_public = 1 ORDER BY display_order, created_at ASC";
    $result = $connect->query($sql);
    $plans = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $plans[] = $row;
        }
    }
    
    return $plans;
}
}

// Get business subscription
if (!function_exists('getBusinessSubscription')) {
function getBusinessSubscription($businessId) {
    global $connect;
    $sql = "SELECT s.*, sp.name as plan_name, sp.code as plan_code, sp.features_json 
            FROM subscriptions s 
            JOIN subscription_plans sp ON s.plan_id = sp.id 
            WHERE s.business_id = ? 
            ORDER BY s.created_at DESC 
            LIMIT 1";
    
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription = $result->fetch_assoc();
    $stmt->close();
    
    return $subscription;
}
}

// Check if business has active subscription or trial
if (!function_exists('hasActiveSubscription')) {
function hasActiveSubscription($businessId) {
    global $connect;
    
    // Check if business has active subscription
    $sql = "SELECT s.*, sp.trial_days, sp.features_json 
            FROM subscriptions s 
            JOIN subscription_plans sp ON s.plan_id = sp.id 
            WHERE s.business_id = ? 
            AND s.status IN ('trialing', 'active') 
            ORDER BY s.created_at DESC 
            LIMIT 1";
    
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription = $result->fetch_assoc();
    $stmt->close();
    
    if ($subscription) {
        // Check if trial is still valid
        if ($subscription['status'] == 'trialing' && $subscription['trial_ends_at']) {
            $trialEnds = new DateTime($subscription['trial_ends_at']);
            $now = new DateTime();
            
            if ($now > $trialEnds) {
                // Trial expired, check if subscription is active
                return $subscription['status'] == 'active';
            }
        }
        
        return true;
    }
    
    return false;
}
}

// Check if business can access specific feature
if (!function_exists('canAccessFeature')) {
function canAccessFeature($businessId, $feature) {
    $subscription = getBusinessSubscription($businessId);
    
    if (!$subscription) {
        return false;
    }
    
    // Parse features from plan
    $features = json_decode($subscription['features_json'], true);
    
    if (is_array($features)) {
        return in_array($feature, $features);
    }
    
    return false;
}
}

// Get subscription status for business
if (!function_exists('getBusinessSubscriptionStatus')) {
function getBusinessSubscriptionStatus($businessId) {
    global $connect;
    
    $sql = "SELECT s.*, sp.name as plan_name, sp.trial_days, sp.features_json,
                   b.trial_ends_at as business_trial_ends
            FROM subscriptions s 
            JOIN subscription_plans sp ON s.plan_id = sp.id 
            JOIN businessses b ON s.business_id = b.id
            WHERE s.business_id = ? 
            ORDER BY s.created_at DESC 
            LIMIT 1";
    
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription = $result->fetch_assoc();
    $stmt->close();
    
    if (!$subscription) {
        return [
            'status' => 'none',
            'plan_name' => 'No Plan',
            'trial_remaining' => 0,
            'features' => []
        ];
    }
    
    $features = json_decode($subscription['features_json'], true) ?: [];
    
    // Calculate trial remaining days
    $trialRemaining = 0;
    if ($subscription['status'] == 'trialing' && $subscription['trial_ends_at']) {
        $trialEnds = new DateTime($subscription['trial_ends_at']);
        $now = new DateTime();
        $diff = $now->diff($trialEnds);
        $trialRemaining = $diff->days;
    }
    
    return [
        'status' => $subscription['status'],
        'plan_name' => $subscription['plan_name'],
        'trial_remaining' => $trialRemaining,
        'features' => $features,
        'subscription_id' => $subscription['id'],
        'cf_subscription_id' => $subscription['razorpay_subscription_id'] // Using existing field for Cashfree ID
    ];
}
}
?>