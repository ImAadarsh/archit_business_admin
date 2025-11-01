<?php

use LDAP\Result;
error_reporting(0);
ini_set('display_errors', 0);
session_start();
include("admin/connect.php");

if (isset($_POST['login'])) {
    
    $data_array =  array(
    "user_type" => 'admin',
    "phone" => $_POST['phone'],
    "password" => $_POST['password'],
    "role" => "admin"
);
    
    $make_call = callAPI('POST', 'login', json_encode($data_array),NULL);
    $response = json_decode($make_call, true);
    
    if($response['message']){
        echo '<script>alert("'.$response['message'].'")</script>';
    }  
if ($response['user']['role']=='admin' || $response['user']['role']=='superadmin') {
    $_SESSION['email'] =  $response['user']['email'];
    $_SESSION['name'] = $response['user']['name'];
    $_SESSION['phone'] = $response['user']['phone'];
    $_SESSION['userid'] = $response['user']['id'];
    $_SESSION['business_id'] = $response['user']['business_id'];
    $_SESSION['logo'] = $response['user']['logo'];
    $_SESSION['role'] = 'admin';
    header('location: dashboard.php');
}else{
    if($response['message']){
        echo '<script>alert("Invalid Role.")</script>';
    }  
}
}

?>
<?php include("partials/header.php"); ?>


<style>
    .login-container {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        height: 100vh;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
    }
    
    .login-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="60" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="90" cy="40" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
        opacity: 0.3;
    }
    
    .login-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        position: relative;
        z-index: 2;
        margin: 0;
    }
    
    .logo-container {
        text-align: center;
        margin-bottom: 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .logo-container img {
        transition: transform 0.3s ease;
    }
    
    .logo-container:hover img {
        transform: scale(1.05);
    }
    
    .login-title {
        color: #2d3748;
        font-weight: 700;
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        text-align: center;
    }
    
    .login-subtitle {
        color: #718096;
        font-size: 0.9rem;
        margin-bottom: 2rem;
        text-align: center;
    }
    
    .form-floating {
        position: relative;
        margin-bottom: 2rem;
    }
    
    .form-floating .form-control {
        height: 60px;
        border-radius: 12px;
        border: 2px solid #e2e8f0;
        background: #f8fafc;
        transition: all 0.3s ease;
        font-size: 1.1rem;
        padding-top: 1.625rem;
        padding-bottom: 0.625rem;
        color: #2d3748;
    }
    
    .form-floating .form-control:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        background: white;
        color: #2d3748;
    }
    
    .form-floating label {
        color: #718096;
        font-weight: 500;
        font-size: 1rem;
        z-index: 1;
        padding: 1rem 0.75rem;
    }
    
    .form-floating .form-control:focus ~ label,
    .form-floating .form-control:not(:placeholder-shown) ~ label {
        color: #667eea;
        transform: scale(0.85) translateY(-0.5rem) translateX(0.15rem);
    }
    
    /* Ensure select dropdown has proper styling */
    .form-floating select.form-control {
        color: #2d3748;
        background-color: #f8fafc;
    }
    
    .form-floating select.form-control:focus {
        color: #2d3748;
        background-color: white;
    }
    
    .form-floating select.form-control option {
        color: #2d3748;
        background-color: white;
    }
    
    .form-check {
        margin: 1.5rem 0;
    }
    
    .form-check-input {
        border-radius: 6px;
        border: 2px solid #e2e8f0;
    }
    
    .form-check-input:checked {
        background-color: #667eea;
        border-color: #667eea;
    }
    
    .form-check-label {
        color: #4a5568;
        font-weight: 500;
    }
    
    .btn-login {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: 12px;
        height: 3.5rem;
        font-weight: 600;
        font-size: 1.1rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }
    
    .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
    }
    
    .btn-login:active {
        transform: translateY(0);
    }
    
    .features-preview {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 15px;
        padding: 2rem;
        margin: 0;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        height: fit-content;
    }
    
    .feature-item {
        display: flex;
        align-items: center;
        margin-bottom: 1rem;
        color: white;
    }
    
    .feature-icon {
        width: 20px;
        height: 20px;
        margin-right: 10px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }
    
    .stats-container {
        display: flex;
        justify-content: space-around;
        margin-top: 2rem;
        text-align: center;
    }
    
    .stat-item {
        color: white;
    }
    
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        display: block;
    }
    
    .stat-label {
        font-size: 0.8rem;
        opacity: 0.8;
    }
    
    @media (max-width: 768px) {
        .login-card {
            margin: 1rem;
            border-radius: 15px;
            padding: 1.5rem !important;
        }
        
        .features-preview {
            display: none;
        }
        
        .login-title {
            font-size: 1.2rem;
            margin-bottom: 0.3rem;
        }
        
        .login-subtitle {
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
        }
        
        .logo-container {
            margin-bottom: 1.5rem;
        }
        
        .logo-container img {
            height: 60px !important;
        }
        
        .btn-login {
            font-size: 0.9rem;
            height: 2.5rem;
            padding: 0.5rem;
        }
        
        .form-floating .form-control {
            height: 60px;
            font-size: 0.9rem;
            padding-top: 1rem;
            padding-bottom: 0.5rem;
            color: #2d3748;
        }
        
        .form-floating label {
            font-size: 0.85rem;
            padding: 0.8rem 0.6rem;
        }
        
        .form-floating {
            margin-bottom: 1rem;
        }
        
        .form-check {
            margin: 1rem 0;
        }
        
        .form-check-label {
            font-size: 0.8rem;
        }
        
        .text-center small {
            font-size: 0.7rem;
        }
    }
    
    @media (max-width: 480px) {
        .login-card {
            margin: 0.5rem;
            padding: 1rem !important;
        }
        
        .login-title {
            font-size: 1rem;
        }
        
        .login-subtitle {
            font-size: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .logo-container {
            margin-bottom: 1rem;
        }
        
        .logo-container img {
            height: 50px !important;
        }
        
        .form-floating .form-control {
            height: 60px;
            font-size: 0.85rem;
        }
        
        .form-floating label {
            font-size: 0.8rem;
            padding: 0.7rem 0.5rem;
        }
        
        .btn-login {
            font-size: 0.85rem;
            height: 2.2rem;
        }
        
        .form-check-label {
            font-size: 0.75rem;
        }
        
        .text-center small {
            font-size: 0.65rem;
        }
    }
    
    @media (min-width: 992px) {
        .row {
            gap: 1.5rem;
        }
        
        .col-lg-6 {
            flex: 0 0 auto;
            width: 50%;
        }
        
        .col-lg-4 {
            flex: 0 0 auto;
            width: 40%;
        }
    }
</style>

<body class="light">
    <div class="login-container">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <!-- Left Column with stacked cards -->
                <div class="col-lg-6 col-md-6 col-11">
                    <!-- Features Preview for Desktop -->
                    <div class="features-preview d-none d-lg-block mb-4">
                        <h5 class="text-white mb-3">Everything you need to run your business</h5>
                        <div class="feature-item">
                            <div class="feature-icon">📊</div>
                            <span>Smart Invoicing & Billing</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📦</div>
                            <span>Inventory Management</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">🚛</div>
                            <span>E-way Bill Generation</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">🔗</div>
                            <span>B2B API Integration</span>
                        </div>
                        
                        <div class="stats-container">
                            <div class="stat-item">
                                <span class="stat-number">10K+</span>
                                <span class="stat-label">Happy Customers</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number">50M+</span>
                                <span class="stat-label">Invoices Generated</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number">99%</span>
                                <span class="stat-label">Uptime</span>
                            </div>
                        </div>
                        
                        
                    </div>
                    
                    <!-- Why Choose InvoiceMate Card -->
                    <div class="features-preview d-none d-lg-block">
                        <h5 class="text-white mb-1">Why Choose InvoiceMate?</h5>
                        
                        <div class="feature-item">
                            <div class="feature-icon">🔒</div>
                            <span>Secure & Reliable</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📱</div>
                            <span>Mobile Friendly</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">🎯</div>
                            <span>Easy to Use</span>
                        </div>
                       
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 col-11">
                    <div class="login-card p-4 p-md-5">
                        <div class="logo-container">
                            <a href="index.php" class="text-decoration-none">
                                <img height="80" src="assets/images/invoice_mate.svg" alt="InvoiceMate Logo" class="mb-3">
                            </a>
                            <h1 class="login-title">Welcome Back</h1>
                            <p class="login-subtitle">Sign in to your <strong>InvoiceMate</strong> dashboard</p>
                        </div>
                        
                        <form action="index.php" method="POST">
                            <div class="form-floating">
                                <input type="tel" id="phone" name="phone" class="form-control" placeholder="" required autofocus>
                                <label for="phone">Phone Number</label>
                            </div>
                            
                            <div class="form-floating">
                                <input type="password" id="password" name="password" class="form-control" placeholder="" required>
                                <label for="password">Password</label>
                            </div>
                            
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="remember-me" id="rememberMe">
                                <label class="form-check-label" for="rememberMe">
                                    Keep me signed in
                                </label>
                            </div>
                            
                            <button class="btn btn-login w-100" name="login" type="submit">
                                <i class="feather feather-log-in me-2"></i>
                                Sign In
                            </button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                Secure business dashboard • Powered by InvoiceMate
                            </small>
                            <br>
                            <small class="text-muted">
                                Developed by <a href="https://endeavourdigital.in/" target="_blank" class="text-decoration-none" style="color: #667eea;">Endeavour Digital</a>
                            </small>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    <script src="js/jquery.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/moment.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/simplebar.min.js"></script>
    <script src='js/daterangepicker.js'></script>
    <script src='js/jquery.stickOnScroll.js'></script>
    <script src="js/tinycolor-min.js"></script>
    <script src="js/config.js"></script>
    <script src="js/apps.js"></script>
    
    <script>
        // Enhanced login form interactions
        $(document).ready(function() {
            // Form submit handler
            $('form').on('submit', function(e) {
                const phone = $('input[name="phone"]').val();
                const password = $('input[name="password"]').val();
                
                // Form validation
                if (!phone) {
                    alert('Please enter your phone number');
                    e.preventDefault();
                    return false;
                }
                
                // Phone format validation (basic)
                const phoneRegex = /^[0-9+\-\s()]{10,}$/;
                if (!phoneRegex.test(phone)) {
                    alert('Please enter a valid phone number');
                    e.preventDefault();
                    return false;
                }
                
                if (!password) {
                    alert('Please enter your password');
                    e.preventDefault();
                    return false;
                }
                
                // Change button text
                const submitBtn = $('button[name="login"]');
                submitBtn.html('<i class="feather feather-loader me-2"></i>Signing In...');
                
                return true;
            });
            
            // Add focus effects to form controls
            $('.form-control').on('focus', function() {
                $(this).closest('.form-floating').addClass('focused');
            }).on('blur', function() {
                if (!$(this).val()) {
                    $(this).closest('.form-floating').removeClass('focused');
                }
            });
            
            // Initialize floating labels
            $('.form-floating .form-control').each(function() {
                if ($(this).val()) {
                    $(this).closest('.form-floating').addClass('focused');
                }
            });
            
            // Add smooth animations
            $('.login-card').hide().fadeIn(800);
            $('.features-preview').hide().delay(1000).fadeIn(600);
            
            // Add hover effects to feature items
            $('.feature-item').hover(
                function() {
                    $(this).css('transform', 'translateX(5px)');
                },
                function() {
                    $(this).css('transform', 'translateX(0)');
                }
            );
            
            // Add typing animation to stats
            $('.stat-number').each(function() {
                const $this = $(this);
                const text = $this.text();
                $this.text('');
                $this.prop('Counter', 0).animate({
                    Counter: text.replace(/[^\d]/g, '')
                }, {
                    duration: 2000,
                    easing: 'swing',
                    step: function(now) {
                        if (text.includes('K+')) {
                            $this.text(Math.ceil(now) + 'K+');
                        } else if (text.includes('M+')) {
                            $this.text(Math.ceil(now) + 'M+');
                        } else if (text.includes('%')) {
                            $this.text(Math.ceil(now) + '%');
                        } else {
                            $this.text(Math.ceil(now));
                        }
                    }
                });
            });
        });
        
            // Add keyboard navigation
            $(document).keydown(function(e) {
                if (e.key === 'Enter' && !$('button[name="login"]').prop('disabled')) {
                    $('form').submit();
                }
            });
            
    </script>
</body>

</html>