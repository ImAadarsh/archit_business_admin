<?php
// Fetch business logo from the database

?>
<aside class="sidebar-left border-right bg-white shadow" id="leftSidebar" data-simplebar>
    <a href="#" class="btn collapseSidebar toggle-btn d-lg-none text-muted ml-2 mt-3" data-toggle="toggle">
        <i class="fe fe-x"><span class="sr-only"></span></i>
    </a>
    <nav class="vertnav navbar navbar-light">
        <!-- nav bar -->
        <div class="w-100 mb-4 d-flex">
            <a class="navbar-brand mx-auto mt-2 flex-fill text-center" href="dashboard.php">
                <img height="50" src="<?php echo $logo; ?>" alt="Business Logo">
            </a>
        </div>
        
        <!-- Main Dashboard -->
        <ul class="navbar-nav flex-fill w-100 mb-2">
            <li class="nav-item">
                <a href="dashboard.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-home fe-16"></i>
                    <span class="ml-3 item-text">Dashboard</span><span class="sr-only"></span>
                </a>
            </li>
        </ul>
        
        <!-- Business Management Section -->
        <p class="text-muted nav-heading mt-4 mb-1">
            <span>Business Management</span>
        </p>
        <ul class="navbar-nav flex-fill w-100 mb-2">
            <li class="nav-item">
                <a href="locations.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-map-pin fe-16"></i>
                    <span class="ml-3 item-text">Locations</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="team.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-users fe-16"></i>
                    <span class="ml-3 item-text">Team</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="create-user.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-user-plus fe-16"></i>
                    <span class="ml-3 item-text">Add Team Member</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-user-check fe-16"></i>
                    <span class="ml-3 item-text">Customers</span>
                </a>
            </li>
        </ul>
        
        <!-- Financial Management Section -->
        <p class="text-muted nav-heading mt-4 mb-1">
            <span>Financial Management</span>
        </p>
        <ul class="navbar-nav flex-fill w-100 mb-2">
            <li class="nav-item">
                <a href="banks.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-credit-card fe-16"></i>
                    <span class="ml-3 item-text">Bank Accounts</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="add-bank.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-plus-circle fe-16"></i>
                    <span class="ml-3 item-text">Add Bank Account</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="expense.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-dollar-sign fe-16"></i>
                    <span class="ml-3 item-text">Expenses</span>
                </a>
            </li>
        </ul>
        
        <!-- Sales & Inventory Section -->
        <p class="text-muted nav-heading mt-4 mb-1">
            <span>Sales & Inventory</span>
        </p>
        <ul class="navbar-nav flex-fill w-100 mb-2">
            <li class="nav-item">
                <a href="products.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-package fe-16"></i>
                    <span class="ml-3 item-text">Products</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="add-product.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-plus-circle fe-16"></i>
                    <span class="ml-3 item-text">Add Product</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="invoices.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-file-text fe-16"></i>
                    <span class="ml-3 item-text">Invoices</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="purchase.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-box fe-16"></i>
                    <span class="ml-3 item-text">Sales Report</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="itemised.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-shopping-cart fe-16"></i>
                    <span class="ml-3 item-text">Itemised Sales</span>
                </a>
            </li>
        </ul>
        
        <!-- Account Section -->
        <p class="text-muted nav-heading mt-4 mb-1">
            <span>Account</span>
        </p>
        <ul class="navbar-nav flex-fill w-100 mb-2">
            <li class="nav-item">
                <a href="admin/logout.php" aria-expanded="false" class="dropdown-toggle nav-link">
                    <i class="fe fe-log-out fe-16"></i>
                    <span class="ml-3 item-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>