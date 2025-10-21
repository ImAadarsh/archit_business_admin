<?php
session_start();
include("../../admin/connect.php");
include("../../admin/cashfree_config.php");

// Get subscription details from URL parameters or session
$subscription_id = $_GET['subscription_id'] ?? $_SESSION['checkout_subscription_id'] ?? $_SESSION['subscription_id'] ?? '';
$cf_subscription_id = $_GET['cf_subscription_id'] ?? $_SESSION['cf_subscription_id'] ?? '';
$status = $_GET['status'] ?? 'success';
$error = $_GET['error'] ?? '';

// If we have subscription details, fetch the latest status from Cashfree
if ($cf_subscription_id) {
    $response = getCashfreeSubscription($cf_subscription_id);
    $subscriptionData = json_decode($response, true);
    
    if ($subscriptionData && !isset($subscriptionData['error'])) {
        // Update subscription status in database based on Cashfree response
        $status = $subscriptionData['subscription_status'] ?? 'INITIALIZED';
        
        // Map Cashfree status to our database status
        $dbStatus = 'trialing';
        if ($status === 'ACTIVE') {
            $dbStatus = 'active';
        } elseif ($status === 'CANCELLED' || $status === 'CUSTOMER_CANCELLED') {
            $dbStatus = 'canceled';
        } elseif ($status === 'CUSTOMER_PAUSED' || $status === 'ON_HOLD') {
            $dbStatus = 'paused';
        } elseif ($status === 'COMPLETED') {
            $dbStatus = 'completed';
        }
        
        // Update subscription in database
        $sql = "UPDATE subscriptions SET status = ? WHERE razorpay_subscription_id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $dbStatus, $cf_subscription_id);
        $stmt->execute();
        $stmt->close();
        
        // Update business subscription status
        $sql = "UPDATE businessses SET subscription_status = ? WHERE id = (SELECT business_id FROM subscriptions WHERE razorpay_subscription_id = ?)";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ss", $dbStatus, $cf_subscription_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Clear session data
unset($_SESSION['subscription_id']);
unset($_SESSION['cf_subscription_id']);
unset($_SESSION['checkout_session_id']);
unset($_SESSION['checkout_subscription_id']);

include("../../partials/header.php");
?>

<style>
.success-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.success-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    padding: 40px;
    text-align: center;
    max-width: 500px;
    width: 90%;
}

.success-icon {
    font-size: 4rem;
    color: #28a745;
    margin-bottom: 20px;
}

.status-badge {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 25px;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.9rem;
    margin: 10px 0;
}

.status-active { background: #d4edda; color: #155724; }
.status-trialing { background: #fff3cd; color: #856404; }
.status-pending { background: #cce5ff; color: #004085; }
.status-failed { background: #f8d7da; color: #721c24; }

.action-buttons {
    margin-top: 30px;
}

.btn {
    display: inline-block;
    padding: 12px 30px;
    margin: 10px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: bold;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    color: white;
    text-decoration: none;
}
</style>

<body>
    <?php 
    echo print_r($_POST);
    echo print_r($_GET);
    echo print_r($_SESSION);
    echo print_r($_REQUEST);
    echo print_r($_SERVER);
    echo print_r($_ENV);
    echo print_r($_FILES);
    echo print_r($_COOKIE);
    echo print_r($_SERVER);
    ?>
    <div class="success-container">
        <div class="success-card">
            <?php if ($status === 'success' && $subscriptionData && !isset($subscriptionData['error'])): ?>
                <!-- Success Case -->
                <div class="success-icon">✅</div>
                <h2>Subscription Authorized Successfully!</h2>
                <p>Your subscription has been authorized and is now active.</p>
            <?php elseif ($status === 'failed'): ?>
                <!-- Failed Case -->
                <div class="success-icon" style="color: #dc3545;">❌</div>
                <h2>Authorization Failed</h2>
                <p><?php echo htmlspecialchars($error ?: 'Payment authorization failed. Please try again.'); ?></p>
            <?php elseif ($status === 'cancelled'): ?>
                <!-- Cancelled Case -->
                <div class="success-icon" style="color: #ffc107;">⚠️</div>
                <h2>Authorization Cancelled</h2>
                <p>You cancelled the payment authorization. Your subscription is still pending.</p>
            <?php elseif ($subscriptionData && !isset($subscriptionData['error'])): ?>
                <!-- Success Case (fallback) -->
                <div class="success-icon">✅</div>
                <h2>Subscription Created Successfully!</h2>
                <p>Your subscription has been set up and is ready to use.</p>
                
                <div class="mt-4">
                    <strong>Subscription ID:</strong> <?php echo htmlspecialchars($subscriptionData['subscription_id'] ?? 'N/A'); ?><br>
                    <strong>Status:</strong> 
                    <span class="status-badge status-<?php echo strtolower($dbStatus); ?>">
                        <?php echo ucfirst($dbStatus); ?>
                    </span><br>
                    <?php if (isset($subscriptionData['plan_details'])): ?>
                        <strong>Plan:</strong> <?php echo htmlspecialchars($subscriptionData['plan_details']['plan_name'] ?? 'N/A'); ?><br>
                    <?php endif; ?>
                </div>
                
                <div class="action-buttons">
                    <a href="../../dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                    <a href="../../subscription-management.php" class="btn btn-secondary">Manage Subscription</a>
                </div>
                
            <?php else: ?>
                <!-- Error Case -->
                <div class="success-icon" style="color: #dc3545;">❌</div>
                <h2>Subscription Setup Incomplete</h2>
                <p>There was an issue with your subscription setup. Please try again or contact support.</p>
                
                <div class="action-buttons">
                    <a href="../../subscription.php" class="btn btn-primary">Try Again</a>
                    <a href="../../dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
