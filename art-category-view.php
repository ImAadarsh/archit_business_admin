<?php
// Include access control at the very top
include 'admin/access_control.php';
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
                                <strong class="card-title">Art Categories</strong>
                                <a href="art-category.php" class="btn btn-primary float-right">Add New Art Category</a>
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
                                                <th>Product Type</th>
                                                <th>Created At</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $business_id = $_SESSION['business_id'];
                                            $sql = "SELECT pc.*, c.name as category_name 
                                                   FROM product_category pc 
                                                   LEFT JOIN categories c ON pc.category_id = c.id 
                                                   WHERE c.business_id = $business_id 
                                                   ORDER BY pc.created_at DESC";
                                            $result = $connect->query($sql);
                                            
                                            if ($result && $result->num_rows > 0) {
                                                while($row = $result->fetch_assoc()) {
                                                    echo "<tr>";
                                                    echo "<td>" . $row['id'] . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['category_name'] ?? 'N/A') . "</td>";
                                                    echo "<td>" . date('d M Y, h:i A', strtotime($row['created_at'])) . "</td>";
                                                    echo "<td>
                                                            <a href='edit-art-category.php?id=" . $row['id'] . "' class='btn btn-sm btn-info'>Edit</a>
                                                            <a href='controller/_delete-art-category.php?id=" . $row['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure you want to delete this art category?\")'>Delete</a>
                                                          </td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='5' class='text-center'>No art categories found</td></tr>";
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