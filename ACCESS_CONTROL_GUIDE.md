# Access Control Implementation Guide

## Overview
This guide explains how to implement subscription-based access control across all pages in your application.

## Key Features
- ✅ **All pages accessible** with any plan OR approved trial
- ✅ **Free trial only activates** when mandate is approved (not just initiated)
- ✅ **Automatic redirect** to subscription page if no access
- ✅ **Trial warnings** for expiring trials
- ✅ **Easy implementation** with single include

## Implementation

### 1. Basic Page Protection
Add this to the **very top** of any page that requires subscription access:

```php
<?php
// Include access control at the very top
include("admin/access_control.php");

// Your page content here
include("partials/header.php");
?>
```

### 2. Access Control Logic

#### **Trial Activation Rules:**
- **Trial starts** only when `authorization_status = 'ACTIVE'`
- **Trial duration** is set when mandate is approved
- **Access granted** only for approved trials or active subscriptions

#### **Access Levels:**
1. **Active Subscription** - Full access to all features
2. **Approved Trial** - Full access during trial period
3. **Pending Authorization** - No access (redirected to subscription page)
4. **No Subscription** - No access (redirected to subscription page)

### 3. Database Status Flow

```
User subscribes → Status: 'pending' → No access
↓
Mandate approved → Status: 'trialing' + authorization_status: 'ACTIVE' → Full access
↓
Trial expires → Status: 'expired' → No access (redirect to subscription)
↓
User pays → Status: 'active' → Full access
```

### 4. Session Variables Available

After including `access_control.php`, these session variables are available:

```php
$_SESSION['subscription_status']    // 'active', 'trialing', 'pending', 'expired'
$_SESSION['subscription_plan']       // Plan name
$_SESSION['trial_remaining']         // Days remaining in trial
$_SESSION['trial_warning']          // Warning message if trial expiring
```

### 5. Example Implementation

#### **Dashboard Page:**
```php
<?php
include("admin/access_control.php");
include("partials/header.php");
?>

<h1>Dashboard</h1>
<p>Welcome! Your subscription status: <?php echo $_SESSION['subscription_status']; ?></p>

<?php if (isset($_SESSION['trial_warning'])): ?>
    <div class="alert alert-warning">
        <?php echo $_SESSION['trial_warning']['message']; ?>
    </div>
<?php endif; ?>
```

#### **Protected Feature Page:**
```php
<?php
include("admin/access_control.php");
include("partials/header.php");
?>

<h1>Advanced Feature</h1>
<p>This feature is only available to subscribed users.</p>
```

### 6. Files to Update

Add `include("admin/access_control.php");` to the top of these files:

- `dashboard.php`
- `invoices.php`
- `products.php`
- `expense.php`
- `purchase.php`
- `itemised.php`
- `users.php`
- `banks.php`
- `locations.php`
- Any other business pages

### 7. Trial Warning Display

Add this to your main layout or dashboard:

```php
<?php if (isset($_SESSION['trial_warning'])): ?>
    <div class="alert alert-<?php echo $_SESSION['trial_warning']['type']; ?>">
        <strong>Trial Notice:</strong> <?php echo $_SESSION['trial_warning']['message']; ?>
        <a href="subscription.php" class="btn btn-primary btn-sm">Subscribe Now</a>
    </div>
<?php endif; ?>
```

### 8. Testing Access Control

#### **Test Scenarios:**
1. **No subscription** → Should redirect to subscription page
2. **Pending authorization** → Should redirect to subscription page
3. **Approved trial** → Should allow access
4. **Active subscription** → Should allow access
5. **Expired trial** → Should redirect to subscription page

#### **Debug Information:**
Add this to any page to see access control status:

```php
<?php
echo "<pre>";
echo "Business ID: " . ($_SESSION['business_id'] ?? 'NULL') . "\n";
echo "Subscription Status: " . ($_SESSION['subscription_status'] ?? 'NULL') . "\n";
echo "Plan: " . ($_SESSION['subscription_plan'] ?? 'NULL') . "\n";
echo "Trial Remaining: " . ($_SESSION['trial_remaining'] ?? 'NULL') . "\n";
echo "</pre>";
?>
```

## Security Notes

- ✅ **Access control is enforced** at the server level
- ✅ **No client-side bypassing** possible
- ✅ **Session-based** authentication
- ✅ **Automatic redirects** for unauthorized access
- ✅ **Trial status validation** ensures only approved trials get access

## Troubleshooting

### Common Issues:

1. **"Redirect loop"** - Check that subscription.php doesn't include access_control.php
2. **"Session not found"** - Ensure session_start() is called before access_control.php
3. **"Business ID null"** - Check that user is properly logged in
4. **"Trial not working"** - Verify authorization_status is 'ACTIVE' in database

### Debug Steps:

1. Check session variables
2. Verify database subscription status
3. Check authorization_status in subscriptions table
4. Verify trial_ends_at date is set correctly
