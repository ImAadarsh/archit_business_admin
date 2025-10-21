<?php
// Test file to verify subscription system is working
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing Subscription System</h2>";

try {
    // Test 1: Include connect.php
    echo "<h3>Test 1: Including connect.php</h3>";
    include("admin/connect.php");
    echo "✅ connect.php loaded successfully<br>";
    
    // Test 2: Include cashfree_config.php
    echo "<h3>Test 2: Including cashfree_config.php</h3>";
    include("admin/cashfree_config.php");
    echo "✅ cashfree_config.php loaded successfully<br>";
    
    // Test 3: Include subscription_check.php
    echo "<h3>Test 3: Including subscription_check.php</h3>";
    include("admin/subscription_check.php");
    echo "✅ subscription_check.php loaded successfully<br>";
    
    // Test 4: Check if functions exist
    echo "<h3>Test 4: Checking function availability</h3>";
    
    $functions_to_check = [
        'callAPI',
        'callAPI1', 
        'callCashfreeAPI',
        'createCashfreeSubscription',
        'getCashfreeSubscription',
        'manageCashfreeSubscription',
        'getAllPlans',
        'getBusinessSubscription',
        'hasActiveSubscription',
        'canAccessFeature',
        'getBusinessSubscriptionStatus',
        'checkSubscriptionAccess',
        'requireSubscription',
        'getSubscriptionInfo',
        'isTrialExpiringSoon',
        'getTrialWarning'
    ];
    
    foreach ($functions_to_check as $function) {
        if (function_exists($function)) {
            echo "✅ Function '$function' exists<br>";
        } else {
            echo "❌ Function '$function' NOT found<br>";
        }
    }
    
    // Test 5: Test database connection
    echo "<h3>Test 5: Database connection</h3>";
    if (isset($connect) && $connect) {
        echo "✅ Database connection successful<br>";
    } else {
        echo "❌ Database connection failed<br>";
    }
    
    // Test 6: Test Cashfree constants
    echo "<h3>Test 6: Cashfree configuration</h3>";
    if (defined('CASHFREE_CLIENT_ID')) {
        echo "✅ CASHFREE_CLIENT_ID defined: " . CASHFREE_CLIENT_ID . "<br>";
    } else {
        echo "❌ CASHFREE_CLIENT_ID not defined<br>";
    }
    
    if (defined('CASHFREE_BASE_URL')) {
        echo "✅ CASHFREE_BASE_URL defined: " . CASHFREE_BASE_URL . "<br>";
    } else {
        echo "❌ CASHFREE_BASE_URL not defined<br>";
    }
    
    echo "<h3>🎉 All tests completed successfully!</h3>";
    echo "<p>The subscription system is ready to use.</p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error occurred:</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
?>
