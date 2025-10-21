<?php
// Example of how to protect any page with subscription access control

// Include access control at the very top
include("admin/access_control.php");

// Include header
include("partials/header.php");
?>

<!-- Your page content here -->
<div class="container">
    <h1>Protected Page</h1>
    <p>This page is only accessible to users with active subscriptions or approved trials.</p>
    
    <!-- Display subscription info -->
    <div class="alert alert-info">
        <strong>Subscription Status:</strong> <?php echo $_SESSION['subscription_status']; ?><br>
        <strong>Plan:</strong> <?php echo $_SESSION['subscription_plan']; ?><br>
        <?php if ($_SESSION['trial_remaining'] > 0): ?>
            <strong>Trial Remaining:</strong> <?php echo $_SESSION['trial_remaining']; ?> days
        <?php endif; ?>
    </div>
    
    <!-- Display trial warning if applicable -->
    <?php if (isset($_SESSION['trial_warning'])): ?>
        <div class="alert alert-<?php echo $_SESSION['trial_warning']['type']; ?>">
            <?php echo $_SESSION['trial_warning']['message']; ?>
        </div>
    <?php endif; ?>
    
    <!-- Your page content continues here -->
    <p>This page is now protected and only accessible to users with proper subscription access.</p>
</div>

<?php include("partials/footer.php"); ?>
