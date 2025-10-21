<?php
session_start();
include("admin/connect.php");
include("admin/cashfree_config.php");
include("admin/session.php");

$businessId = $_SESSION['business_id'];
$subscriptionInfo = getBusinessSubscriptionStatus($businessId);

// Handle subscription management actions
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $subscriptionId = $_POST['subscription_id'] ?? null;
    
    if ($subscriptionId) {
        switch ($action) {
            case 'pause':
                $response = manageCashfreeSubscription($subscriptionId, 'PAUSE');
                break;
            case 'resume':
                $response = manageCashfreeSubscription($subscriptionId, 'ACTIVATE');
                break;
            case 'cancel':
                $response = manageCashfreeSubscription($subscriptionId, 'CANCEL');
                break;
        }
        
        $result = json_decode($response, true);
        
        if ($result && !isset($result['error'])) {
            // Update database status
            $newStatus = $action == 'cancel' ? 'canceled' : ($action == 'pause' ? 'paused' : 'active');
            $sql = "UPDATE subscriptions SET status = ? WHERE business_id = ?";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("si", $newStatus, $businessId);
            $stmt->execute();
            $stmt->close();
            
            // Update business status
            $sql = "UPDATE businessses SET subscription_status = ? WHERE id = ?";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("si", $newStatus, $businessId);
            $stmt->execute();
            $stmt->close();
            
            echo "<script>alert('Subscription " . $action . "d successfully'); window.location.href='subscription-management.php';</script>";
        } else {
            $errorMsg = isset($result['message']) ? $result['message'] : 'Action failed';
            echo "<script>alert('Error: " . addslashes($errorMsg) . "');</script>";
        }
    }
}

include("partials/header.php");
?>

<style>
.subscription-card {
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

.subscription-card:hover {
    transform: translateY(-5px);
}

.status-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 25px;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.8rem;
}

.status-active { background: #d4edda; color: #155724; }
.status-trialing { background: #fff3cd; color: #856404; }
.status-paused { background: #f8d7da; color: #721c24; }
.status-canceled { background: #e2e3e5; color: #6c757d; }

.action-btn {
    margin: 5px;
    border-radius: 20px;
    padding: 8px 20px;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.8rem;
}

.trial-warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.feature-list {
    list-style: none;
    padding: 0;
}

.feature-list li {
    padding: 10px 0;
    border-bottom: 1px solid #f8f9fa;
    display: flex;
    align-items: center;
}

.feature-list li:before {
    content: "✓";
    color: #28a745;
    font-weight: bold;
    margin-right: 15px;
    font-size: 1.2rem;
}
</style>

<body class="vertical  light  ">
    <div class="wrapper">
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                
                <!-- Trial Warning -->
                <?php 
                $trialWarning = getTrialWarning($businessId);
                if ($trialWarning): 
                ?>
                    <div class="alert alert-<?php echo $trialWarning['type']; ?> alert-dismissible fade show">
                        <h5><i class="fas fa-exclamation-triangle"></i> Trial Alert</h5>
                        <p><?php echo $trialWarning['message']; ?></p>
                        <a href="subscription.php" class="btn btn-primary">Subscribe Now</a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card subscription-card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">
                                    <i class="fas fa-credit-card"></i> Subscription Management
                                </h4>
                            </div>
                            <div class="card-body">
                                
                                <!-- Current Subscription Details -->
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5>Current Plan: <?php echo htmlspecialchars($subscriptionInfo['plan_name']); ?></h5>
                                        <p class="text-muted">Status: 
                                            <span class="status-badge status-<?php echo $subscriptionInfo['status']; ?>">
                                                <?php echo ucfirst($subscriptionInfo['status']); ?>
                                            </span>
                                        </p>
                                        
                                        <?php if ($subscriptionInfo['trial_remaining'] > 0): ?>
                                            <p class="text-warning">
                                                <i class="fas fa-clock"></i> 
                                                Trial expires in <?php echo $subscriptionInfo['trial_remaining']; ?> days
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($subscriptionInfo['features'])): ?>
                                            <h6>Plan Features:</h6>
                                            <ul class="feature-list">
                                                <?php foreach ($subscriptionInfo['features'] as $feature): ?>
                                                    <li><?php echo htmlspecialchars($feature); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-4 text-end">
                                        <?php if ($subscriptionInfo['status'] == 'active'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="subscription_id" value="<?php echo $subscriptionInfo['subscription_id']; ?>">
                                                <button type="submit" name="action" value="pause" class="btn btn-warning action-btn">
                                                    <i class="fas fa-pause"></i> Pause
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="subscription_id" value="<?php echo $subscriptionInfo['subscription_id']; ?>">
                                                <button type="submit" name="action" value="cancel" class="btn btn-danger action-btn" 
                                                        onclick="return confirm('Are you sure you want to cancel your subscription?')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </form>
                                        <?php elseif ($subscriptionInfo['status'] == 'paused'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="subscription_id" value="<?php echo $subscriptionInfo['subscription_id']; ?>">
                                                <button type="submit" name="action" value="resume" class="btn btn-success action-btn">
                                                    <i class="fas fa-play"></i> Resume
                                                </button>
                                            </form>
                                        <?php elseif ($subscriptionInfo['status'] == 'none' || $subscriptionInfo['status'] == 'canceled'): ?>
                                            <a href="subscription.php" class="btn btn-primary action-btn">
                                                <i class="fas fa-plus"></i> Subscribe Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Subscription History -->
                                <hr>
                                <h5>Subscription History</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Plan</th>
                                                <th>Status</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $sql = "SELECT s.*, sp.name as plan_name 
                                                    FROM subscriptions s 
                                                    JOIN subscription_plans sp ON s.plan_id = sp.id 
                                                    WHERE s.business_id = ? 
                                                    ORDER BY s.created_at DESC";
                                            $stmt = $connect->prepare($sql);
                                            $stmt->bind_param("i", $businessId);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            
                                            while ($row = $result->fetch_assoc()):
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['plan_name']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $row['status']; ?>">
                                                            <?php echo ucfirst($row['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        if ($row['trial_ends_at']) {
                                                            echo date('M d, Y', strtotime($row['trial_ends_at']));
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['status'] == 'active'): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="subscription_id" value="<?php echo $row['id']; ?>">
                                                                <button type="submit" name="action" value="pause" class="btn btn-sm btn-warning">
                                                                    Pause
                                                                </button>
                                                            </form>
                                                        <?php elseif ($row['status'] == 'paused'): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="subscription_id" value="<?php echo $row['id']; ?>">
                                                                <button type="submit" name="action" value="resume" class="btn btn-sm btn-success">
                                                                    Resume
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Quick Actions -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="card">
                                            <div class="card-body text-center">
                                                <i class="fas fa-credit-card fa-3x text-primary mb-3"></i>
                                                <h5>Change Plan</h5>
                                                <p class="text-muted">Upgrade or downgrade your subscription</p>
                                                <a href="subscription.php" class="btn btn-outline-primary">View Plans</a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-invoice fa-3x text-success mb-3"></i>
                                                <h5>Billing History</h5>
                                                <p class="text-muted">View your payment history and invoices</p>
                                                <a href="invoices.php" class="btn btn-outline-success">View Invoices</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

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
