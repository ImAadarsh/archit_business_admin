<?php
session_start();
include("admin/connect.php");
include("admin/cashfree_config.php");
include("admin/session.php");

// Get all available plans
$plans = getAllPlans();

// Get current business subscription
$businessId = $_SESSION['business_id'];
$currentSubscription = getBusinessSubscriptionStatus($businessId);

// Handle subscription creation
if (isset($_POST['subscribe'])) {
    $planId = $_POST['plan_id'];
    $planCode = $_POST['plan_code'];
    
    // Get plan details
    $sql = "SELECT * FROM subscription_plans WHERE id = ?";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_assoc();
    $stmt->close();
    
    if ($plan) {
        // Get business details
        $sql = "SELECT * FROM businessses WHERE id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("i", $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        $business = $result->fetch_assoc();
        $stmt->close();
        
        // Create subscription data for Cashfree
        $subscriptionData = [
            'subscription_id' => 'SUB_' . $businessId . '_' . time(),
            'customer_details' => [
                'customer_name' => $business['owner_name'],
                'customer_email' => $business['email'],
                'customer_phone' => $business['phone'],
                'customer_bank_account_number' => $_POST['bank_account'] ?? '',
                'customer_bank_ifsc' => $_POST['bank_ifsc'] ?? '',
                'customer_bank_code' => substr($_POST['bank_ifsc'] ?? '', 0, 4),
                'customer_bank_account_type' => $_POST['account_type'] ?? 'SAVINGS'
            ],
            'plan_details' => [
                'plan_name' => $plan['code'],
                'plan_type' => 'PERIODIC',
                'plan_currency' => $plan['currency'] ?? 'INR',
                'plan_amount' => $plan['amount'] / 100, // Convert from paise to rupees
                'plan_max_amount' => $plan['amount'] / 100,
                'plan_max_cycles' => null,
                'plan_intervals' => $plan['interval_count'],
                'plan_interval_type' => strtoupper($plan['interval_unit']),
                'plan_note' => $plan['description']
            ],
            'authorization_details' => [
                'authorization_amount' => 1, // 1 rupee authorization
                'authorization_amount_refund' => true,
                'payment_methods' => ['enach', 'pnach', 'upi', 'card']
            ],
            'subscription_meta' => [
                'return_url' => 'https://dashboard.invoicemate.in/business/subscription/success.php',
                'notification_channel' => ['EMAIL', 'SMS']
            ],
            'subscription_expiry_time' => date('Y-m-d\TH:i:s\Z', strtotime('+1 year')),
            'subscription_first_charge_time' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
            'subscription_tags' => [
                'business_id' => (string)$businessId,
                'plan_code' => (string)$plan['code']
            ]
        ];
        
        // Create subscription in Cashfree
        $response = createCashfreeSubscription($subscriptionData);
        $result = json_decode($response, true);
        
        // Debug: Print the response (remove this in production)
        if (isset($result['error']) || isset($result['code'])) {
            echo "<div style='background: #f8d7da; padding: 15px; margin: 20px 0; border: 1px solid #f5c6cb; border-radius: 5px;'>";
            echo "<h4>Cashfree API Error</h4>";
            echo "<strong>Error:</strong> " . ($result['message'] ?? 'Unknown error') . "<br>";
            echo "<strong>Code:</strong> " . ($result['code'] ?? 'N/A') . "<br>";
            echo "</div>";
        }
        
        if ($result && !isset($result['error']) && isset($result['cf_subscription_id'])) {
            // Store subscription in database
            $cfSubscriptionId = $result['cf_subscription_id'];
            $subscriptionId = $result['subscription_id'] ?? $subscriptionData['subscription_id'];
            
            // Store in session for success page
            $_SESSION['subscription_id'] = $subscriptionId;
            $_SESSION['cf_subscription_id'] = $cfSubscriptionId;
            
            // Calculate trial end date
            $trialEndsAt = null;
            if ($plan['trial_days'] > 0) {
                $trialEndsAt = date('Y-m-d H:i:s', strtotime('+' . $plan['trial_days'] . ' days'));
            }
            
            $sql = "INSERT INTO subscriptions (
                business_id, plan_id, quantity, status, 
                trial_ends_at, total_count, paid_count,
                razorpay_subscription_id, created_at, updated_at
            ) VALUES (?, ?, 1, 'trialing', ?, 12, 0, ?, NOW(), NOW())";
            
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("iiss", $businessId, $planId, $trialEndsAt, $cfSubscriptionId);
            
            if ($stmt->execute()) {
                // Update business subscription status
                $sql = "UPDATE businessses SET subscription_status = 'trialing' WHERE id = ?";
                $stmt = $connect->prepare($sql);
                $stmt->bind_param("i", $businessId);
                $stmt->execute();
                $stmt->close();
                
                // Redirect to Cashfree checkout page
                echo "<script>
                    alert('Subscription created successfully! Redirecting to payment authorization...');
                    window.location.href='subscription/checkout.php?session_id=" . $result['subscription_session_id'] . "&subscription_id=" . $subscriptionId . "';
                </script>";
            } else {
                echo "<script>alert('Failed to create subscription. Please try again.');</script>";
            }
        } else {
            $errorMsg = isset($result['message']) ? $result['message'] : 'Failed to create subscription';
            echo "<script>alert('Error: " . addslashes($errorMsg) . "');</script>";
        }
    }
}

include("partials/header.php");
?>

<style>
.plan-card {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.plan-card:hover {
    border-color: #007bff;
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,123,255,0.15);
}

.plan-card.featured {
    border-color: #28a745;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.plan-card.featured .card-header {
    background: rgba(255,255,255,0.1);
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.price {
    font-size: 2.5rem;
    font-weight: bold;
    color: #007bff;
}

.plan-card.featured .price {
    color: white;
}

.features-list {
    list-style: none;
    padding: 0;
}

.features-list li {
    padding: 8px 0;
    border-bottom: 1px solid #f8f9fa;
}

.features-list li:before {
    content: "✓";
    color: #28a745;
    font-weight: bold;
    margin-right: 10px;
}

.subscription-status {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 30px;
}

.status-badge {
    display: inline-block;
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: bold;
    text-transform: uppercase;
}

.status-active { background: #d4edda; color: #155724; }
.status-trialing { background: #fff3cd; color: #856404; }
.status-none { background: #f8d7da; color: #721c24; }
</style>

<body class="vertical  light  ">
    <div class="wrapper">
        
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>
        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Subscription Plans</h4>
                            </div>
                            <div class="card-body">
                                
                                <!-- Current Subscription Status -->
                                <div class="subscription-status">
                                    <h5>Current Status</h5>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Plan:</strong> <?php echo htmlspecialchars($currentSubscription['plan_name']); ?></p>
                                            <p><strong>Status:</strong> 
                                                <span class="status-badge status-<?php echo $currentSubscription['status']; ?>">
                                                    <?php echo ucfirst($currentSubscription['status']); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <?php if ($currentSubscription['trial_remaining'] > 0): ?>
                                                <p><strong>Trial Remaining:</strong> <?php echo $currentSubscription['trial_remaining']; ?> days</p>
                                            <?php endif; ?>
                                            <?php if (!empty($currentSubscription['features'])): ?>
                                                <p><strong>Features:</strong> <?php echo implode(', ', $currentSubscription['features']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Available Plans -->
                                <h5 class="mb-4">Choose Your Plan</h5>
                                <div class="row">
                                    <?php foreach ($plans as $index => $plan): ?>
                                        <div class="col-lg-4 col-md-6 mb-4">
                                            <div class="plan-card <?php echo $index == 1 ? 'featured' : ''; ?>">
                                                <div class="card-header text-center">
                                                    <h4><?php echo htmlspecialchars($plan['name']); ?></h4>
                                                    <div class="price">₹<?php echo number_format($plan['amount'] / 100, 0); ?></div>
                                                    <small style="color: black !important;" class="text-muted">per <?php echo $plan['interval_unit']; ?></small>
                                                </div>
                                                <div class="card-body">
                                                    <?php if ($plan['description']): ?>
                                                        <p class="text-muted"><?php echo htmlspecialchars($plan['description']); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($plan['features_json']): ?>
                                                        <?php $features = json_decode($plan['features_json'], true); ?>
                                                        <?php if (is_array($features) && !empty($features)): ?>
                                                            <ul class="features-list">
                                                                <?php foreach ($features as $feature): ?>
                                                                    <li><?php echo htmlspecialchars($feature); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($plan['trial_days'] > 0): ?>
                                                        <div class="alert alert-info">
                                                            <strong><?php echo $plan['trial_days']; ?> days free trial</strong>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" class="mt-3">
                                                        <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                        <input type="hidden" name="plan_code" value="<?php echo $plan['code']; ?>">
                                                        
                                                        <?php if ($currentSubscription['status'] == 'none' || $currentSubscription['status'] == 'canceled'): ?>
                                                            <button type="submit" name="subscribe" class="btn btn-primary btn-block">
                                                                Subscribe Now
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-secondary btn-block" disabled>
                                                                Current Plan
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Bank Details Form (shown when subscribing) -->
                                <?php if (isset($_POST['subscribe']) && !isset($result)): ?>
                                    <div class="card mt-4">
                                        <div class="card-header">
                                            <h5>Bank Account Details for Mandate</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <input type="hidden" name="plan_id" value="<?php echo $_POST['plan_id']; ?>">
                                                <input type="hidden" name="plan_code" value="<?php echo $_POST['plan_code']; ?>">
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Bank Account Number</label>
                                                            <input type="text" name="bank_account" class="form-control" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>IFSC Code</label>
                                                            <input type="text" name="bank_ifsc" class="form-control" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Account Type</label>
                                                            <select name="account_type" class="form-control" required>
                                                                <option value="SAVINGS">Savings</option>
                                                                <option value="CURRENT">Current</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <button type="submit" name="subscribe" class="btn btn-success">
                                                    Create Mandate & Subscribe
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include("admin/footer.php"); ?>
    </div>
</body>
</html>
