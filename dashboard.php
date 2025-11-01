
<?php
// Include access control at the very top
include 'admin/access_control.php';
include 'admin/header.php';

// Get subscription info for display
$businessId = $_SESSION['business_id'];
$subscriptionInfo = getBusinessSubscriptionStatus($businessId);

// Show trial warning if applicable
$trialWarning = getTrialWarning($businessId);
?>

<body class="vertical  light  ">
    <div class="wrapper">
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-2">
                            <div class="col">
                                <h2 class="h5 page-title">Welcome ! <?php echo $_SESSION['name'] ?></h2>
                            </div>
                            <div class="col-auto">

                            </div>
                        </div>
                        
                        <!-- Trial Warning -->
                        <?php if ($trialWarning): ?>
                            <div class="alert alert-<?php echo $trialWarning['type']; ?> alert-dismissible fade show">
                                <h5><i class="fas fa-exclamation-triangle"></i> Trial Alert</h5>
                                <p><?php echo $trialWarning['message']; ?></p>
                                <a href="subscription.php" class="btn btn-primary">Subscribe Now</a>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Subscription Status Card -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="card-title mb-1">
                                            <i class="fe fe-credit-card"></i> 
                                            Subscription Status
                                        </h5>
                                        <p class="text-muted mb-0">
                                            Plan: <strong><?php echo htmlspecialchars($subscriptionInfo['plan_name']); ?></strong> | 
                                            Status: <span class="badge badge-<?php echo $subscriptionInfo['status'] == 'active' ? 'success' : ($subscriptionInfo['status'] == 'trialing' ? 'warning' : 'secondary'); ?>">
                                                <?php echo ucfirst($subscriptionInfo['status']); ?>
                                            </span>
                                        </p>
                                        <?php if ($subscriptionInfo['trial_remaining'] > 0): ?>
                                            <small class="text-warning">
                                                <i class="fe fe-clock"></i> 
                                                Trial expires in <?php echo $subscriptionInfo['trial_remaining']; ?> days
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <a href="subscription-management.php" class="btn btn-outline-primary">
                                            <i class="fe fe-settings"></i> Manage
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions Card -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="fe fe-zap"></i> Quick Actions
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <a href="subscription.php" class="btn btn-primary btn-block">
                                            <i class="fe fe-credit-card"></i> View Plans & Pricing
                                        </a>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <a href="subscription-management.php" class="btn btn-outline-primary btn-block">
                                            <i class="fe fe-settings"></i> Manage Subscription
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card shadow my-4">
    <div class="card-body">
        <div class="row align-items-center my-12">
            <div class="col-md-12">
                <div class="row align-items-center">
                    <?php
                    $dashboard_items = [
                        ["profile.php", "fe-briefcase", "Business Profile"],
                        ["locations.php", "fe-home", "My Locations"],
                        ["team.php", "fe-star", "My Team"],
                        ["users.php", "fe-users", "My Customers"],
                        ["create-user.php", "fe-user-plus", "Add Team"],
                        ["expense.php", "fe-dollar-sign", "Expenses"],
                        ["purchase.php", "fe-shopping-cart", "Invoices"],
                        ["itemised.php", "fe-list", "Itemised Sale"]
                    ];

                    foreach ($dashboard_items as $item) {
                        echo '<div class="col-md-3 col-sm-6 mb-4">';
                        echo '<div class="p-4 border rounded">';
                        echo '<p class="small text-uppercase text-muted mb-2">' . $item[2] . '</p>';
                        echo '<a href="' . $item[0] . '" class="h3 mb-0 text-decoration-none">';
                        echo '<i class="fe ' . $item[1] . ' mr-2"></i>';
                        echo $item[2];
                        echo '</a>';
                        echo '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

                        <?php include "admin/footer.php"; ?>