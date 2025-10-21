<?php
// Cashfree Subscription Webhook Handler
include("../../admin/connect.php");
include("../../admin/cashfree_config.php");

// Get the raw POST data
$input = file_get_contents('php://input');
$webhookData = json_decode($input, true);

// Log webhook for debugging
error_log("Cashfree Subscription Webhook Received: " . $input);

if ($webhookData) {
    $eventType = $webhookData['type'] ?? '';
    $data = $webhookData['data'] ?? [];
    
    switch ($eventType) {
        case 'SUBSCRIPTION_STATUS_CHANGED':
            handleSubscriptionStatusChanged($data);
            break;
            
        case 'SUBSCRIPTION_AUTH_STATUS':
            handleSubscriptionAuthStatus($data);
            break;
            
        case 'SUBSCRIPTION_PAYMENT_SUCCESS':
            handleSubscriptionPaymentSuccess($data);
            break;
            
        case 'SUBSCRIPTION_PAYMENT_FAILED':
            handleSubscriptionPaymentFailed($data);
            break;
            
        case 'SUBSCRIPTION_PAYMENT_CANCELLED':
            handleSubscriptionPaymentCancelled($data);
            break;
            
        case 'SUBSCRIPTION_REFUND_STATUS':
            handleSubscriptionRefundStatus($data);
            break;
            
        case 'SUBSCRIPTION_CARD_EXPIRY_REMINDER':
            handleCardExpiryReminder($data);
            break;
            
        default:
            error_log("Unknown webhook event: " . $eventType);
    }
}

function handleSubscriptionStatusChanged($data) {
    global $connect;
    
    $subscriptionDetails = $data['subscription_details'] ?? [];
    $cfSubscriptionId = $subscriptionDetails['cf_subscription_id'] ?? '';
    $subscriptionId = $subscriptionDetails['subscription_id'] ?? '';
    $status = $subscriptionDetails['subscription_status'] ?? '';
    $authDetails = $data['authorization_details'] ?? [];
    $authStatus = $authDetails['authorization_status'] ?? '';
    
    if ($cfSubscriptionId && $status) {
        // Map Cashfree status to database status (same logic as success.php)
        $dbStatus = 'trialing';
        if ($status === 'ACTIVE') {
            $dbStatus = 'active';
        } elseif ($status === 'CANCELLED' || $status === 'CUSTOMER_CANCELLED') {
            $dbStatus = 'canceled';
        } elseif ($status === 'CUSTOMER_PAUSED' || $status === 'ON_HOLD') {
            $dbStatus = 'paused';
        } elseif ($status === 'COMPLETED') {
            $dbStatus = 'completed';
        } elseif ($status === 'EXPIRED') {
            $dbStatus = 'canceled';
        }
        
        // Map authorization status (same logic as success.php)
        $dbAuthStatus = 'active';
        if ($authStatus === 'ACTIVE') {
            $dbAuthStatus = 'active';
        } elseif ($authStatus === 'BANK_APPROVAL_PENDING') {
            $dbAuthStatus = 'active'; // Treat as active for trial purposes
        } elseif ($authStatus === 'PENDING') {
            $dbAuthStatus = 'pending';
        } elseif ($authStatus === 'FAILED') {
            $dbAuthStatus = 'failed';
        }
        
        // Update subscription status with authorization details
        $sql = "UPDATE subscriptions SET 
                status = ?, 
                cashfree_subscription_id = ?,
                authorization_status = ?,
                authorization_reference = ?,
                authorization_time = NOW()
                WHERE cashfree_subscription_id = ? OR razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $authReference = $authDetails['authorization_reference'] ?? '';
        $stmt->bind_param("ssssss", $dbStatus, $cfSubscriptionId, $dbAuthStatus, $authReference, $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        // Update business subscription status
        $sql = "UPDATE businessses b 
                JOIN subscriptions s ON b.id = s.business_id 
                SET b.subscription_status = ? 
                WHERE s.cashfree_subscription_id = ? OR s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("sss", $dbStatus, $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Subscription status updated: $cfSubscriptionId -> $dbStatus (auth: $dbAuthStatus)");
    }
}

function handleSubscriptionAuthStatus($data) {
    global $connect;
    
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    $authDetails = $data['authorization_details'] ?? [];
    $authStatus = $authDetails['authorization_status'] ?? '';
    $paymentId = $data['payment_id'] ?? '';
    $amount = $data['payment_amount'] ?? 0;
    $currency = $data['payment_currency'] ?? 'INR';
    $authReference = $authDetails['authorization_reference'] ?? '';
    
    if ($cfSubscriptionId && $authStatus) {
        // Get subscription details (check both cashfree and razorpay IDs)
        $sql = "SELECT s.*, sp.name as plan_name FROM subscriptions s 
                JOIN subscription_plans sp ON s.plan_id = sp.id 
                WHERE s.cashfree_subscription_id = ? OR s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();
        
        if ($subscription) {
            // Map authorization status (same logic as success.php)
            $dbAuthStatus = 'active';
            if ($authStatus === 'ACTIVE') {
                $dbAuthStatus = 'active';
            } elseif ($authStatus === 'BANK_APPROVAL_PENDING') {
                $dbAuthStatus = 'active'; // Treat as active for trial purposes
            } elseif ($authStatus === 'PENDING') {
                $dbAuthStatus = 'pending';
            } elseif ($authStatus === 'FAILED') {
                $dbAuthStatus = 'failed';
            }
            
            // Update subscription status based on auth status (same logic as success.php)
            $newStatus = 'trialing'; // Start with trial for both ACTIVE and BANK_APPROVAL_PENDING
            if ($authStatus === 'ACTIVE' || $authStatus === 'BANK_APPROVAL_PENDING') {
                $newStatus = 'trialing';
                // Set trial end date (e.g., 7 days from now)
                $trialEndDate = date('Y-m-d H:i:s', strtotime('+7 days'));
                
                $sql = "UPDATE subscriptions SET 
                        status = ?, 
                        cashfree_subscription_id = ?,
                        authorization_status = ?,
                        authorization_reference = ?,
                        authorization_time = NOW(),
                        trial_ends_at = ?
                        WHERE id = ?";
                $stmt = $connect->prepare($sql);
                $stmt->bind_param("sssssi", $newStatus, $cfSubscriptionId, $dbAuthStatus, $authReference, $trialEndDate, $subscription['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                // Keep as pending for other statuses
                $newStatus = 'pending';
                $sql = "UPDATE subscriptions SET 
                        status = ?, 
                        cashfree_subscription_id = ?,
                        authorization_status = ?,
                        authorization_reference = ?,
                        authorization_time = NOW()
                        WHERE id = ?";
                $stmt = $connect->prepare($sql);
                $stmt->bind_param("ssssi", $newStatus, $cfSubscriptionId, $dbAuthStatus, $authReference, $subscription['id']);
                $stmt->execute();
                $stmt->close();
            }
            
            // Update business subscription status
            $sql = "UPDATE businessses SET subscription_status = ? WHERE id = ?";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("si", $newStatus, $subscription['business_id']);
            $stmt->execute();
            $stmt->close();
            
            // Record authorization payment if successful
            if (($authStatus === 'ACTIVE' || $authStatus === 'BANK_APPROVAL_PENDING') && $paymentId && $amount > 0) {
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
            }
            
            error_log("Subscription auth status updated: $cfSubscriptionId -> $newStatus (auth: $dbAuthStatus)");
        }
    }
}

function handleSubscriptionPaymentSuccess($data) {
    global $connect;
    
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    $paymentId = $data['payment_id'] ?? '';
    $amount = $data['payment_amount'] ?? 0;
    $currency = $data['payment_currency'] ?? 'INR';
    
    if ($cfSubscriptionId && $paymentId) {
        // Get subscription details (check both cashfree and razorpay IDs)
        $sql = "SELECT s.*, sp.name as plan_name FROM subscriptions s 
                JOIN subscription_plans sp ON s.plan_id = sp.id 
                WHERE s.cashfree_subscription_id = ? OR s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();
        
        if ($subscription) {
            // Record successful payment
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
            
            error_log("Payment recorded for subscription: $cfSubscriptionId, Amount: $amount $currency");
        }
    }
}

function handleSubscriptionPaymentFailed($data) {
    global $connect;
    
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    $paymentId = $data['payment_id'] ?? '';
    $amount = $data['payment_amount'] ?? 0;
    $currency = $data['payment_currency'] ?? 'INR';
    $failureReason = $data['failure_details']['failure_reason'] ?? '';
    
    if ($cfSubscriptionId && $paymentId) {
        // Get subscription details (check both cashfree and razorpay IDs)
        $sql = "SELECT s.*, sp.name as plan_name FROM subscriptions s 
                JOIN subscription_plans sp ON s.plan_id = sp.id 
                WHERE s.cashfree_subscription_id = ? OR s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();
        
        if ($subscription) {
            // Record failed payment
            $sql = "INSERT INTO subscription_payments (
                subscription_id, business_id, amount, currency, status,
                razorpay_payment_id, error_description, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'failed', ?, ?, NOW(), NOW())";
            
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("iissss", 
                $subscription['id'], 
                $subscription['business_id'], 
                $amount, 
                $currency, 
                $paymentId,
                $failureReason
            );
            $stmt->execute();
            $stmt->close();
            
            error_log("Failed payment recorded for subscription: $cfSubscriptionId, Reason: $failureReason");
        }
    }
}

function handleSubscriptionPaymentCancelled($data) {
    global $connect;
    
    $cfSubscriptionId = $data['cf_subscription_id'] ?? '';
    $paymentId = $data['payment_id'] ?? '';
    
    if ($cfSubscriptionId && $paymentId) {
        // Get subscription details (check both cashfree and razorpay IDs)
        $sql = "SELECT s.* FROM subscriptions s WHERE s.cashfree_subscription_id = ? OR s.razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $cfSubscriptionId, $cfSubscriptionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();
        
        if ($subscription) {
            // Record cancelled payment
            $sql = "INSERT INTO subscription_payments (
                subscription_id, business_id, amount, currency, status,
                razorpay_payment_id, created_at, updated_at
            ) VALUES (?, ?, 0, 'INR', 'cancelled', ?, NOW(), NOW())";
            
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("iss", 
                $subscription['id'], 
                $subscription['business_id'], 
                $paymentId
            );
            $stmt->execute();
            $stmt->close();
            
            error_log("Cancelled payment recorded for subscription: $cfSubscriptionId");
        }
    }
}

function handleSubscriptionRefundStatus($data) {
    global $connect;
    
    $paymentId = $data['payment_id'] ?? '';
    $refundId = $data['refund_id'] ?? '';
    $refundAmount = $data['refund_amount'] ?? 0;
    $refundStatus = $data['refund_status'] ?? '';
    
    if ($paymentId && $refundId) {
        // Update payment record with refund information
        $sql = "UPDATE subscription_payments 
                SET status = CASE 
                    WHEN ? = 'SUCCESS' THEN 'refunded'
                    WHEN ? = 'FAILED' THEN 'refund_failed'
                    ELSE status 
                END,
                meta_json = JSON_SET(COALESCE(meta_json, '{}'), '$.refund_id', ?, '$.refund_amount', ?, '$.refund_status', ?)
                WHERE razorpay_payment_id = ?";
        
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("sssds", $refundStatus, $refundStatus, $refundId, $refundAmount, $refundStatus, $paymentId);
        $stmt->execute();
        $stmt->close();
        
        error_log("Refund status updated for payment: $paymentId, Status: $refundStatus, Amount: $refundAmount");
    }
}

function handleCardExpiryReminder($data) {
    // Handle card expiry reminder
    $subscriptionData = $data['subscription_status_webhook'] ?? [];
    $cardExpiryDate = $data['card_expiry_date'] ?? '';
    
    if ($cardExpiryDate) {
        error_log("Card expiry reminder for subscription: " . ($subscriptionData['subscription_details']['cf_subscription_id'] ?? 'N/A') . ", Expiry: $cardExpiryDate");
        
        // You can add logic here to send email notifications to customers
        // about their card expiring soon
    }
}

// Return success response
http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
?>
