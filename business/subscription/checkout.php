<?php
session_start();
include("../admin/connect.php");
include("../admin/cashfree_config.php");

$sessionId = $_GET['session_id'] ?? '';
$subscriptionId = $_GET['subscription_id'] ?? '';

if (empty($sessionId)) {
    echo "<script>alert('Invalid session. Please try again.'); window.location.href='../subscription.php';</script>";
    exit;
}

// Store session data for success page
$_SESSION['checkout_session_id'] = $sessionId;
$_SESSION['checkout_subscription_id'] = $subscriptionId;

include("../partials/header.php");
?>

<style>
.checkout-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
}

.checkout-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    padding: 40px;
    text-align: center;
    max-width: 600px;
    width: 100%;
}

.loading-spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007bff;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
    margin: 20px auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.checkout-info {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin: 20px 0;
    text-align: left;
}

.info-item {
    display: flex;
    justify-content: space-between;
    margin: 10px 0;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: bold;
    color: #495057;
}

.info-value {
    color: #6c757d;
}

.btn {
    display: inline-block;
    padding: 12px 30px;
    margin: 10px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: bold;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
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

.status-badge {
    display: inline-block;
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: bold;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-initialized {
    background: #cce5ff;
    color: #004085;
}
</style>

<body>
    <div class="checkout-container">
        <div class="checkout-card">
            <h2>Complete Your Subscription Authorization</h2>
            <p>Please complete the payment authorization to activate your subscription.</p>
            
            <div class="checkout-info">
                <div class="info-item">
                    <span class="info-label">Subscription ID:</span>
                    <span class="info-value"><?php echo htmlspecialchars($subscriptionId); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Session ID:</span>
                    <span class="info-value"><?php echo htmlspecialchars(substr($sessionId, 0, 20)) . '...'; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="status-badge status-initialized">Initialized</span>
                </div>
            </div>
            
            <div id="loading-section">
                <div class="loading-spinner"></div>
                <p>Preparing payment authorization...</p>
            </div>
            
            <div id="checkout-section" style="display: none;">
                <p>Click the button below to complete your payment authorization:</p>
                <button id="authorize-btn" class="btn btn-primary">
                    Complete Authorization
                </button>
            </div>
            
            <div id="error-section" style="display: none;">
                <p style="color: #dc3545;">There was an error loading the payment authorization. Please try again.</p>
                <a href="../subscription.php" class="btn btn-secondary">Try Again</a>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="../dashboard.php" class="btn btn-secondary">Cancel & Return to Dashboard</a>
            </div>
        </div>
    </div>

    <!-- Cashfree SDK -->
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    
    <script>
        const sessionId = '<?php echo $sessionId; ?>';
        const subscriptionId = '<?php echo $subscriptionId; ?>';
        
        // Initialize Cashfree
        const cashfree = Cashfree({ 
            mode: "sandbox" // Change to "production" for live environment
        });
        
        // Show checkout section after a short delay
        setTimeout(() => {
            document.getElementById('loading-section').style.display = 'none';
            document.getElementById('checkout-section').style.display = 'block';
        }, 2000);
        
        // Handle authorization button click
        document.getElementById('authorize-btn').addEventListener('click', function() {
            this.disabled = true;
            this.textContent = 'Processing...';
            
            try {
                // Configure checkout options
                let checkoutOptions = {
                    subsSessionId: sessionId,
                    onSuccess: function(data) {
                        console.log('Authorization successful:', data);
                        // Redirect to success page
                        window.location.href = 'success.php?status=success&subscription_id=' + subscriptionId;
                    },
                    onFailure: function(data) {
                        console.log('Authorization failed:', data);
                        // Redirect to success page with error
                        window.location.href = 'success.php?status=failed&subscription_id=' + subscriptionId + '&error=' + encodeURIComponent(data.message || 'Authorization failed');
                    },
                    onClose: function() {
                        console.log('Authorization cancelled');
                        // Redirect to success page with cancelled status
                        window.location.href = 'success.php?status=cancelled&subscription_id=' + subscriptionId;
                    }
                };
                
                // Trigger Cashfree checkout
                cashfree.subscriptionsCheckout(checkoutOptions);
                
            } catch (error) {
                console.error('Error initializing checkout:', error);
                document.getElementById('checkout-section').style.display = 'none';
                document.getElementById('error-section').style.display = 'block';
            }
        });
        
        // Handle page load errors
        window.addEventListener('load', function() {
            // Check if Cashfree SDK loaded properly
            if (typeof Cashfree === 'undefined') {
                console.error('Cashfree SDK failed to load');
                document.getElementById('loading-section').style.display = 'none';
                document.getElementById('error-section').style.display = 'block';
            }
        });
    </script>
</body>
</html>
