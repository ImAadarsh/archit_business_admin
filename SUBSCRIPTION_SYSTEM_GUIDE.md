# Cashfree Subscription System Integration Guide

## 🎯 **Overview**

This system integrates Cashfree subscription mandates into your business platform, allowing businesses to subscribe to plans with free trials and access control based on their subscription status.

## 📋 **Database Requirements**

Your existing database already has the required tables:
- ✅ `subscription_plans` - Plans with Cashfree integration
- ✅ `subscriptions` - Business subscriptions 
- ✅ `businessses` - Business information
- ✅ `subscription_payments` - Payment tracking

## 🚀 **Features Implemented**

### **1. Subscription Management**
- **Plan Selection**: Businesses can view and select from available plans
- **Free Trial**: Each plan includes configurable trial periods
- **Mandate Creation**: Automatic Cashfree mandate creation with bank details
- **Access Control**: Feature access based on subscription status

### **2. Trial Management**
- **Trial Tracking**: Automatic trial period calculation
- **Trial Warnings**: Alerts when trial is expiring
- **Trial Access**: Full access during trial period
- **Post-Trial**: Subscription required after trial expires

### **3. Cashfree Integration**
- **Mandate Creation**: Bank account mandate setup
- **Payment Processing**: Automatic recurring payments
- **Webhook Handling**: Real-time subscription status updates
- **Subscription Management**: Pause, resume, cancel subscriptions

## 📁 **Files Created/Modified**

### **New Files:**
1. `admin/cashfree_config.php` - Cashfree API configuration and helper functions
2. `admin/subscription_check.php` - Access control and subscription validation
3. `subscription.php` - Plan selection and subscription creation page
4. `subscription-management.php` - Subscription management dashboard
5. `webhook/cashfree-subscription.php` - Webhook handler for Cashfree events

### **Modified Files:**
1. `admin/session.php` - Added subscription status tracking
2. `admin/aside.php` - Added subscription navigation links
3. `dashboard.php` - Added subscription status display and trial warnings

## 🔧 **Setup Instructions**

### **1. Configure Cashfree Credentials**
Update `admin/cashfree_config.php` with your Cashfree credentials:
```php
define('CASHFREE_CLIENT_ID', 'YOUR_CLIENT_ID');
define('CASHFREE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
```

### **2. Set Up Webhook URL**
Configure webhook URL in Cashfree dashboard:
```
https://your-domain.com/business/webhook/cashfree-subscription.php
```

### **3. Update Return URLs**
Update return URLs in `subscription.php`:
```php
'return_url' => 'https://your-domain.com/subscription/success'
```

## 🎮 **How to Use**

### **For Businesses:**

1. **View Plans**: Navigate to "Plans & Pricing" from the sidebar
2. **Subscribe**: Select a plan and provide bank details for mandate
3. **Trial Period**: Use all features during the trial period
4. **Manage Subscription**: Use "Manage Subscription" to pause/resume/cancel

### **For Developers:**

#### **Check Subscription Access:**
```php
// Require active subscription
requireSubscription($businessId);

// Check specific feature access
requireSubscription($businessId, 'advanced_reporting');
```

#### **Get Subscription Info:**
```php
$subscriptionInfo = getBusinessSubscriptionStatus($businessId);
echo $subscriptionInfo['status']; // active, trialing, none
echo $subscriptionInfo['plan_name'];
echo $subscriptionInfo['trial_remaining'];
```

#### **Check Feature Access:**
```php
if (canAccessFeature($businessId, 'advanced_reporting')) {
    // Show advanced reporting
}
```

## 🔄 **Subscription Flow**

### **1. Plan Selection**
- Business views available plans
- Selects plan with trial period
- Provides bank account details

### **2. Mandate Creation**
- System creates Cashfree subscription
- Bank mandate is set up
- Trial period begins immediately

### **3. Trial Period**
- Full access to all features
- Trial countdown displayed
- Warnings when trial expires

### **4. Post-Trial**
- Subscription becomes active
- Automatic payments begin
- Access continues based on plan

## 🛡️ **Access Control**

### **Subscription Statuses:**
- `none` - No subscription
- `trialing` - In trial period
- `active` - Active paid subscription
- `paused` - Subscription paused
- `canceled` - Subscription canceled

### **Feature Access:**
- **Trial Users**: Full access during trial
- **Active Subscribers**: Access based on plan features
- **Expired Users**: Redirected to subscription page

## 📊 **Webhook Events Handled**

1. `subscription.authorized` - Mandate authorized
2. `subscription.activated` - Subscription activated
3. `subscription.charged` - Payment processed
4. `subscription.paused` - Subscription paused
5. `subscription.resumed` - Subscription resumed
6. `subscription.cancelled` - Subscription canceled

## 🎨 **UI Components**

### **Subscription Status Display:**
- Current plan and status
- Trial remaining days
- Quick management actions

### **Trial Warnings:**
- Alert when trial expires soon
- Call-to-action to subscribe
- Dismissible notifications

### **Plan Selection:**
- Feature comparison
- Pricing display
- Trial period highlighting

## 🔧 **Customization**

### **Add New Features:**
1. Update plan features in database
2. Add feature checks in pages
3. Update access control logic

### **Modify Trial Periods:**
1. Update `trial_days` in `subscription_plans` table
2. System automatically calculates trial end dates

### **Add New Plans:**
1. Create plan in superadmin panel
2. Plan automatically appears in business subscription page

## 🚨 **Important Notes**

1. **Trial Access**: Users get full access during trial period
2. **Post-Trial**: Subscription required for continued access
3. **Webhook Security**: Implement webhook signature verification
4. **Error Handling**: All API calls include proper error handling
5. **Database Updates**: Webhooks automatically update subscription status

## 📞 **Support**

For issues with:
- **Cashfree API**: Check Cashfree documentation
- **Database**: Verify table structure matches requirements
- **Webhooks**: Check webhook URL configuration
- **Access Control**: Verify subscription status in database

## 🔄 **Next Steps**

1. **Test the system** with trial subscriptions
2. **Configure webhooks** in Cashfree dashboard
3. **Add feature checks** to protected pages
4. **Customize plans** based on your requirements
5. **Monitor webhook events** for proper functionality

The subscription system is now fully integrated and ready for use! 🎉
