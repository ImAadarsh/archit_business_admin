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


                <div class="card shadow mb-4">
                    <a href="banks.php">
                        <button type="button" class="btn btn-primary">View Bank Accounts</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add New Bank Account</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_add-bank.php" method="POST">
                                    <div class="form-group mb-3">
                                        <label for="account_name">Account Name</label>
                                        <input required type="text" id="account_name" class="form-control"
                                            placeholder="Account Name" name="account_name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="bank_name">Bank Name</label>
                                        <input required type="text" id="bank_name" class="form-control"
                                            placeholder="Bank Name" name="bank_name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="address">Bank Address</label>
                                        <textarea required id="address" class="form-control"
                                            placeholder="Bank Address" name="address" rows="3"></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="account_no">Account Number</label>
                                        <input required type="text" id="account_no" class="form-control"
                                            placeholder="Account Number" name="account_no">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="ifsc_code">IFSC Code</label>
                                        <input required type="text" id="ifsc_code" class="form-control"
                                            placeholder="IFSC Code" name="ifsc_code">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="location_id">Business Location</label>
                                        <select required id="location_id" class="form-control" name="location_id">
                                            <option value="">Choose Business Location</option>
                                            <?php
                                            $b_id = $_SESSION['business_id'];
                                            // Fetch locations from the database
                                            $sql = "SELECT * FROM locations WHERE business_id=$b_id";
                                            $results = $connect->query($sql);
                                            while($final = $results->fetch_assoc()) {
                                                echo '<option value="' . $final['id'] . '">' . $final['location_name'] . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <input hidden value="<?php echo $b_id; ?>" name="business_id">
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Add Bank Account">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>

            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>