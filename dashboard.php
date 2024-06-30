<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
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
                        <div class="card shadow my-4">
    <div class="card-body">
        <div class="row align-items-center my-12">
            <div class="col-md-12">
                <div class="row align-items-center">
                    <?php
                    $dashboard_items = [
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