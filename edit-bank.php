<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Bank account ID is required.";
    header("Location: banks.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch bank account details
$sql = "SELECT * FROM banks WHERE id = ? AND business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Bank account not found or you don't have permission to edit it.";
    header("Location: banks.php");
    exit();
}

$bank = $result->fetch_assoc();
$stmt->close();
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
                        <button type="button" class="btn btn-primary">Back to Bank Accounts</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Bank Account</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-bank.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $bank['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $bank['business_id']; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="account_name">Account Name</label>
                                        <input required type="text" id="account_name" class="form-control"
                                            placeholder="Account Name" name="account_name" value="<?php echo $bank['account_name']; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="bank_name">Bank Name</label>
                                        <input required type="text" id="bank_name" class="form-control"
                                            placeholder="Bank Name" name="bank_name" value="<?php echo $bank['bank_name']; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="address">Bank Address</label>
                                        <textarea required id="address" class="form-control"
                                            placeholder="Bank Address" name="address" rows="3"><?php echo $bank['address']; ?></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="account_no">Account Number</label>
                                        <input required type="text" id="account_no" class="form-control"
                                            placeholder="Account Number" name="account_no" value="<?php echo $bank['account_no']; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="ifsc_code">IFSC Code</label>
                                        <input required type="text" id="ifsc_code" class="form-control"
                                            placeholder="IFSC Code" name="ifsc_code" value="<?php echo $bank['ifsc_code']; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="location_id">Business Location</label>
                                        <select required id="location_id" class="form-control" name="location_id">
                                            <option value="">Choose Business Location</option>
                                            <?php
                                            // Fetch locations from the database
                                            $loc_sql = "SELECT * FROM locations WHERE business_id=$business_id";
                                            $loc_results = $connect->query($loc_sql);
                                            while($loc = $loc_results->fetch_assoc()) {
                                                $selected = ($loc['id'] == $bank['location_id']) ? 'selected' : '';
                                                echo '<option value="' . $loc['id'] . '" ' . $selected . '>' . $loc['location_name'] . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Bank Account">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 