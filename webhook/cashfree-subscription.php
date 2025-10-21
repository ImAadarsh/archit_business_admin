<?php
// Cashfree Subscription Webhook Handler
include("../admin/connect.php");
include("../admin/cashfree_config.php");

// Get the raw POST data
$input = file_get_contents('php://input');
$webhookData = json_decode($input, true);

// Log webhook for debugging
error_log("Cashfree Webhook Received: " . $input);

if ($webhookData) {
    $eventType = $webhookData['type'] ?? '';
    $subscriptionData = $webhookData['data'] ?? [];
    
    switch ($eventType) {
        case 'subscription.authorized':
            handleSubscriptionAuthorized($subscriptionData);
            break;
            
        case 'subscription.activated':
            handleSubscriptionActivated($subscriptionData);
            break;
            
        case 'subscription.charged':
            handleSubscriptionCharged($subscriptionData);
            break;
            
        case 'subscription.paused':
            handleSubscriptionPaused($subscriptionData);
            break;
            
        case 'subscription.resumed':
            handleSubscriptionResumed($subscriptionData);
            break;
            
        case 'subscription.cancelled':
            handleSubscriptionCancelled($subscriptionData);
            break;
            
        case 'subscription.completed':
            handleSubscriptionCompleted($subscriptionData);
            break;
            
        default:
            error_log("Unknown webhook event: " . $eventType);
    }
}

function handleSubscriptionAuthorized($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        // Update subscription status to active
        $sql = "UPDATE subscriptions SET status = 'active' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        // Update business status
        $sql = "UPDATE businessses b 
                JOIN subscriptions s ON b.id = s.business_id 
                SET b.subscription_status = 'active' 
                WHERE s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription authorized: " . $subscriptionId);
    }
}

function handleSubscriptionActivated($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        // Update subscription status to active
        $sql = "UPDATE subscriptions SET status = 'active' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription activated: " . $subscriptionId);
    }
}

function handleSubscriptionCharged($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $currency = $data['currency'] ?? 'INR';
    $paymentId = $data['payment_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        // Get subscription details
        $sql = "SELECT s.*, sp.name as plan_name FROM subscriptions s 
                JOIN subscription_plans sp ON s.plan_id = sp.id 
                WHERE s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();
        
        if ($subscription) {
            // Record payment
            $sql = "INSERT INTO subscription_payments (
                subscription_id, business_id, amount, currency, status,
                razorpay_payment_id, paid_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'captured', ?, NOW(), NOW(), NOW())";
            
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("iisss", 
                $subscription['id'], 
                $subscription['business_id'], 
                $amount, 
                $currency, 
                $paymentId
            );
            $stmt->execute();
            $stmt->close();
            
            // Update paid count
            $sql = "UPDATE subscriptions SET paid_count = paid_count + 1 WHERE id = ?";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("i", $subscription['id']);
            $stmt->execute();
            $stmt->close();
            
            error_log("Payment recorded for subscription: " . $subscriptionId);
        }
    }
}

function handleSubscriptionPaused($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        $sql = "UPDATE subscriptions SET status = 'paused' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription paused: " . $subscriptionId);
    }
}

function handleSubscriptionResumed($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        $sql = "UPDATE subscriptions SET status = 'active' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription resumed: " . $subscriptionId);
    }
}

function handleSubscriptionCancelled($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        $sql = "UPDATE subscriptions SET status = 'canceled' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        // Update business status
        $sql = "UPDATE businessses b 
                JOIN subscriptions s ON b.id = s.business_id 
                SET b.subscription_status = 'canceled' 
                WHERE s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription cancelled: " . $subscriptionId);
    }
}

function handleSubscriptionCompleted($data) {
    global $connect;
    
    $subscriptionId = $data['subscription_id'] ?? '';
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    
    if ($subscriptionId && $cfSubscriptionId) {
        $sql = "UPDATE subscriptions SET status = 'completed' WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("s", $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription completed: " . $subscriptionId);
    }
}

// Return success response
http_response_code(200);
echo json_encode(['status' => 'success']);
?>
