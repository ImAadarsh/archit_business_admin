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
                                <strong class="card-title">Bank Accounts</strong>
                                <a href="add-bank.php" class="btn btn-primary float-right">Add New Bank Account</a>
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
                                                <th>Account Name</th>
                                                <th>Bank Name</th>
                                                <th>Account Number</th>
                                                <th>IFSC Code</th>
                                                <th>Location</th>
                                                <th>Address</th>
                                                <th>Created At</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $business_id = $_SESSION['business_id'];
                                            $sql = "SELECT b.*, l.location_name 
                                                    FROM banks b 
                                                    JOIN locations l ON b.location_id = l.id 
                                                    WHERE b.business_id = $business_id 
                                                    ORDER BY b.id DESC";
                                            $result = $connect->query($sql);
                                            
                                            if ($result->num_rows > 0) {
                                                while($row = $result->fetch_assoc()) {
                                                    echo "<tr>";
                                                    echo "<td>" . $row['id'] . "</td>";
                                                    echo "<td>" . $row['account_name'] . "</td>";
                                                    echo "<td>" . $row['bank_name'] . "</td>";
                                                    echo "<td>" . $row['account_no'] . "</td>";
                                                    echo "<td>" . $row['ifsc_code'] . "</td>";
                                                    echo "<td>" . $row['location_name'] . "</td>";
                                                    echo "<td>" . $row['address'] . "</td>";
                                                    echo "<td>" . date('d M Y, h:i A', strtotime($row['created_at'])) . "</td>";
                                                    echo "<td>
                                                            <a href='edit-bank.php?id=" . $row['id'] . "' class='btn btn-sm btn-info'>Edit</a>
                                                            <a href='controller/_delete-bank.php?id=" . $row['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure you want to delete this bank account?\")'>Delete</a>
                                                          </td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='9' class='text-center'>No bank accounts found</td></tr>";
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