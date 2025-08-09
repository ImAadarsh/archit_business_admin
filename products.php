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
                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title">Products</strong>
                                <a href="add-product.php" class="btn btn-primary float-right">Add New Product</a>
                            </div>
                            <div class="card-body">
                                <?php if(isset($_SESSION['success'])): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <?php 
                                    echo $_SESSION['success']; 
                                    unset($_SESSION['success']);
                                    ?>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <?php endif; ?>

                                <?php if(isset($_SESSION['error'])): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <?php 
                                    echo $_SESSION['error']; 
                                    unset($_SESSION['error']);
                                    ?>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Name</th>
                                                <!--<th>Product Serial Number</th>-->
                                                <th>HSN Code</th>
                                                <th>Price</th>
                                                <th>Location</th>
                                                <th>Created At</th>
                                                <th>Updated At</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $business_id = $_SESSION['business_id'];
                                            $sql = "SELECT p.*, l.location_name 
                                                    FROM products p 
                                                    JOIN locations l ON p.location_id = l.id 
                                                    WHERE p.business_id = $business_id 
                                                    ORDER BY p.id DESC";
                                            $result = $connect->query($sql);
                                            
                                            if ($result->num_rows > 0) {
                                                while($row = $result->fetch_assoc()) {
                                                    echo "<tr>";
                                                    echo "<td>" . $row['id'] . "</td>";
                                                    echo "<td>" . $row['name'] . "</td>";
                                                    // echo "<td>" . $row['product_serial_number'] . "</td>";
                                                    echo "<td>" . $row['hsn_code'] . "</td>";
                                                    echo "<td>" . $row['price'] . "</td>";
                                                    echo "<td>" . $row['location_name'] . "</td>";
                                                    echo "<td>" . date('d M Y, h:i A', strtotime($row['created_at'])) . "</td>";
                                                    echo "<td>" . date('d M Y, h:i A', strtotime($row['updated_at'])) . "</td>";
                                                    echo "<td>
                                                            <a href='edit-product.php?id=" . $row['id'] . "' class='btn btn-sm btn-info'>Edit</a>
                                                            <a href='controller/_delete-product.php?id=" . $row['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure you want to delete this product?\")'>Delete</a>
                                                          </td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='9' class='text-center'>No products found</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 