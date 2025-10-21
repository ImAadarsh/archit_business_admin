<?php
session_start();
include("admin/connect.php");
include("admin/cashfree_config.php");
include("admin/subscription_check.php");

// Check if user has access
if (isset($_SESSION['business_id'])) {
    $businessId = $_SESSION['business_id'];
    $access = checkSubscriptionAccess($businessId);
    
    if (!$access['access']) {
        $_SESSION['subscription_error'] = $access['reason'];
        $_SESSION['subscription_status'] = $access['status'];
        $_SESSION['subscription_plan'] = $access['plan'];
        header('Location: subscription.php');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}

// Get subscription details
$subscription = getBusinessSubscription($businessId);
$subscriptionStatus = getBusinessSubscriptionStatus($businessId);

// Get payment history
$sql = "SELECT sp.*, s.status as subscription_status, s.trial_ends_at 
        FROM subscription_payments sp 
        JOIN subscriptions s ON sp.subscription_id = s.id 
        WHERE sp.business_id = ? 
        ORDER BY sp.created_at DESC";
$stmt = $connect->prepare($sql);
$stmt->bind_param("i", $businessId);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include("partials/header.php");
?>

<style>
.billing-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.billing-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    text-align: center;
}

.billing-header h1 {
    margin: 0 0 10px 0;
    font-size: 2.5rem;
}

.billing-header p {
    margin: 0;
    font-size: 1.1rem;
    opacity: 0.9;
}

.billing-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    text-align: center;
    border-left: 4px solid #667eea;
}

.stat-card h3 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1.1rem;
}

.stat-card .stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #667eea;
    margin: 10px 0;
}

.stat-card .stat-label {
    color: #666;
    font-size: 0.9rem;
}

.payments-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    overflow: hidden;
}

.payments-header {
    background: #f8f9fa;
    padding: 20px 30px;
    border-bottom: 1px solid #e9ecef;
}

.payments-header h2 {
    margin: 0;
    color: #333;
    font-size: 1.5rem;
}

.payments-table {
    width: 100%;
    border-collapse: collapse;
}

.payments-table th,
.payments-table td {
    padding: 15px 20px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.payments-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.payments-table td {
    font-size: 0.95rem;
}

.payment-id {
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #666;
}

.payment-amount {
    font-weight: bold;
    font-size: 1.1rem;
}

.payment-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-captured {
    background: #d4edda;
    color: #155724;
}

.status-failed {
    background: #f8d7da;
    color: #721c24;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-cancelled {
    background: #e2e3e5;
    color: #383d41;
}

.status-refunded {
    background: #cce5ff;
    color: #004085;
}

.payment-date {
    color: #666;
    font-size: 0.9rem;
}

.payment-method {
    color: #333;
    font-weight: 500;
}

.no-payments {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.no-payments-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.no-payments h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.no-payments p {
    margin: 0;
    font-size: 1.1rem;
}

.action-buttons {
    margin-top: 30px;
    text-align: center;
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

@media (max-width: 768px) {
    .billing-container {
        padding: 10px;
    }
    
    .billing-header h1 {
        font-size: 2rem;
    }
    
    .billing-stats {
        grid-template-columns: 1fr;
    }
    
    .payments-table {
        font-size: 0.85rem;
    }
    
    .payments-table th,
    .payments-table td {
        padding: 10px;
    }
    
    .payments-table th:nth-child(3),
    .payments-table td:nth-child(3) {
        display: none;
    }
}
</style>

<div class="billing-container">
    <div class="billing-header">
        <h1>💳 Billing History</h1>
        <p>View your payment history and invoices</p>
    </div>

    <?php
    // Calculate stats
    $totalPayments = count($payments);
    $successfulPayments = array_filter($payments, function($p) { return $p['status'] === 'captured'; });
    $totalAmount = array_sum(array_column($successfulPayments, 'amount'));
    $lastPayment = $payments[0] ?? null;
    ?>

    <div class="billing-stats">
        <div class="stat-card">
            <h3>Total Payments</h3>
            <div class="stat-value"><?php echo $totalPayments; ?></div>
            <div class="stat-label">All time</div>
        </div>
        
        <div class="stat-card">
            <h3>Successful Payments</h3>
            <div class="stat-value"><?php echo count($successfulPayments); ?></div>
            <div class="stat-label">Completed transactions</div>
        </div>
        
        <div class="stat-card">
            <h3>Total Amount</h3>
            <div class="stat-value">₹<?php echo number_format($totalAmount, 2); ?></div>
            <div class="stat-label">Total paid</div>
        </div>
        
        <div class="stat-card">
            <h3>Last Payment</h3>
            <div class="stat-value"><?php echo $lastPayment ? date('M j', strtotime($lastPayment['created_at'])) : 'N/A'; ?></div>
            <div class="stat-label"><?php echo $lastPayment ? date('Y', strtotime($lastPayment['created_at'])) : ''; ?></div>
        </div>
    </div>

    <div class="payments-section">
        <div class="payments-header">
            <h2>Payment History</h2>
        </div>
        
        <?php if (empty($payments)): ?>
            <div class="no-payments">
                <div class="no-payments-icon">💳</div>
                <h3>No Payment History</h3>
                <p>You haven't made any payments yet. Your payment history will appear here once you start using our services.</p>
            </div>
        <?php else: ?>
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>
                                <div class="payment-id"><?php echo htmlspecialchars($payment['razorpay_payment_id'] ?: 'N/A'); ?></div>
                            </td>
                            <td>
                                <div class="payment-amount">₹<?php echo number_format($payment['amount'], 2); ?></div>
                            </td>
                            <td>
                                <span class="payment-status status-<?php echo $payment['status']; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="payment-date">
                                    <?php echo date('M j, Y', strtotime($payment['created_at'])); ?><br>
                                    <small><?php echo date('g:i A', strtotime($payment['created_at'])); ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="payment-method">
                                    <?php 
                                    if ($payment['payment_method_type']) {
                                        echo htmlspecialchars($payment['payment_method_type']);
                                    } else {
                                        echo 'Subscription Payment';
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="action-buttons">
        <a href="subscription-management.php" class="btn btn-primary">← Back to Subscription</a>
        <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
    </div>
</div>

<?php include("partials/footer.php"); ?>
