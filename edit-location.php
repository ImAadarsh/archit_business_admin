<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Location ID is required.";
    header("Location: locations.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch location details
$sql = "SELECT * FROM locations WHERE id = ? AND business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Location not found or you don't have permission to edit it.";
    header("Location: locations.php");
    exit();
}

$location = $result->fetch_assoc();
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
                    <a href="locations.php">
                        <button type="button" class="btn btn-primary">Back to Locations</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Location</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-location.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $location['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $location['business_id']; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="email">Email</label>
                                        <input required type="email" id="email" class="form-control"
                                            placeholder="Email" name="email" value="<?php echo htmlspecialchars($location['email']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="location_name">Location Name</label>
                                        <input required type="text" id="location_name" class="form-control"
                                            placeholder="Location Name" name="location_name" value="<?php echo htmlspecialchars($location['location_name']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="address">Address</label>
                                        <textarea required id="address" class="form-control" rows="3"
                                            placeholder="Address" name="address"><?php echo htmlspecialchars($location['address']); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="state">State</label>
                                        <select required id="state" class="form-control" name="state">
                                            <option value="">Select State</option>
                                            <option value="Andhra Pradesh" <?php echo ($location['state'] == 'Andhra Pradesh') ? 'selected' : ''; ?>>Andhra Pradesh</option>
                                            <option value="Arunachal Pradesh" <?php echo ($location['state'] == 'Arunachal Pradesh') ? 'selected' : ''; ?>>Arunachal Pradesh</option>
                                            <option value="Assam" <?php echo ($location['state'] == 'Assam') ? 'selected' : ''; ?>>Assam</option>
                                            <option value="Bihar" <?php echo ($location['state'] == 'Bihar') ? 'selected' : ''; ?>>Bihar</option>
                                            <option value="Chhattisgarh" <?php echo ($location['state'] == 'Chhattisgarh') ? 'selected' : ''; ?>>Chhattisgarh</option>
                                            <option value="Goa" <?php echo ($location['state'] == 'Goa') ? 'selected' : ''; ?>>Goa</option>
                                            <option value="Gujarat" <?php echo ($location['state'] == 'Gujarat') ? 'selected' : ''; ?>>Gujarat</option>
                                            <option value="Haryana" <?php echo ($location['state'] == 'Haryana') ? 'selected' : ''; ?>>Haryana</option>
                                            <option value="Himachal Pradesh" <?php echo ($location['state'] == 'Himachal Pradesh') ? 'selected' : ''; ?>>Himachal Pradesh</option>
                                            <option value="Jharkhand" <?php echo ($location['state'] == 'Jharkhand') ? 'selected' : ''; ?>>Jharkhand</option>
                                            <option value="Karnataka" <?php echo ($location['state'] == 'Karnataka') ? 'selected' : ''; ?>>Karnataka</option>
                                            <option value="Kerala" <?php echo ($location['state'] == 'Kerala') ? 'selected' : ''; ?>>Kerala</option>
                                            <option value="Madhya Pradesh" <?php echo ($location['state'] == 'Madhya Pradesh') ? 'selected' : ''; ?>>Madhya Pradesh</option>
                                            <option value="Maharashtra" <?php echo ($location['state'] == 'Maharashtra') ? 'selected' : ''; ?>>Maharashtra</option>
                                            <option value="Manipur" <?php echo ($location['state'] == 'Manipur') ? 'selected' : ''; ?>>Manipur</option>
                                            <option value="Meghalaya" <?php echo ($location['state'] == 'Meghalaya') ? 'selected' : ''; ?>>Meghalaya</option>
                                            <option value="Mizoram" <?php echo ($location['state'] == 'Mizoram') ? 'selected' : ''; ?>>Mizoram</option>
                                            <option value="Nagaland" <?php echo ($location['state'] == 'Nagaland') ? 'selected' : ''; ?>>Nagaland</option>
                                            <option value="Odisha" <?php echo ($location['state'] == 'Odisha') ? 'selected' : ''; ?>>Odisha</option>
                                            <option value="Punjab" <?php echo ($location['state'] == 'Punjab') ? 'selected' : ''; ?>>Punjab</option>
                                            <option value="Rajasthan" <?php echo ($location['state'] == 'Rajasthan') ? 'selected' : ''; ?>>Rajasthan</option>
                                            <option value="Sikkim" <?php echo ($location['state'] == 'Sikkim') ? 'selected' : ''; ?>>Sikkim</option>
                                            <option value="Tamil Nadu" <?php echo ($location['state'] == 'Tamil Nadu') ? 'selected' : ''; ?>>Tamil Nadu</option>
                                            <option value="Telangana" <?php echo ($location['state'] == 'Telangana') ? 'selected' : ''; ?>>Telangana</option>
                                            <option value="Tripura" <?php echo ($location['state'] == 'Tripura') ? 'selected' : ''; ?>>Tripura</option>
                                            <option value="Uttar Pradesh" <?php echo ($location['state'] == 'Uttar Pradesh') ? 'selected' : ''; ?>>Uttar Pradesh</option>
                                            <option value="Uttarakhand" <?php echo ($location['state'] == 'Uttarakhand') ? 'selected' : ''; ?>>Uttarakhand</option>
                                            <option value="West Bengal" <?php echo ($location['state'] == 'West Bengal') ? 'selected' : ''; ?>>West Bengal</option>
                                            <option value="Andaman and Nicobar Islands" <?php echo ($location['state'] == 'Andaman and Nicobar Islands') ? 'selected' : ''; ?>>Andaman and Nicobar Islands</option>
                                            <option value="Chandigarh" <?php echo ($location['state'] == 'Chandigarh') ? 'selected' : ''; ?>>Chandigarh</option>
                                            <option value="Dadra and Nagar Haveli and Daman and Diu" <?php echo ($location['state'] == 'Dadra and Nagar Haveli and Daman and Diu') ? 'selected' : ''; ?>>Dadra and Nagar Haveli and Daman and Diu</option>
                                            <option value="Delhi" <?php echo ($location['state'] == 'Delhi') ? 'selected' : ''; ?>>Delhi</option>
                                            <option value="Jammu and Kashmir" <?php echo ($location['state'] == 'Jammu and Kashmir') ? 'selected' : ''; ?>>Jammu and Kashmir</option>
                                            <option value="Ladakh" <?php echo ($location['state'] == 'Ladakh') ? 'selected' : ''; ?>>Ladakh</option>
                                            <option value="Lakshadweep" <?php echo ($location['state'] == 'Lakshadweep') ? 'selected' : ''; ?>>Lakshadweep</option>
                                            <option value="Puducherry" <?php echo ($location['state'] == 'Puducherry') ? 'selected' : ''; ?>>Puducherry</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="phone">Phone</label>
                                        <input required type="tel" id="phone" class="form-control"
                                            placeholder="Phone" name="phone" value="<?php echo htmlspecialchars($location['phone']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="alternate_phone">Alternate Phone</label>
                                        <input type="tel" id="alternate_phone" class="form-control"
                                            placeholder="Alternate Phone" name="alternate_phone" value="<?php echo htmlspecialchars($location['alternate_phone'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="is_active">Status</label>
                                        <select id="is_active" class="form-control" name="is_active">
                                            <option value="1" <?php echo ($location['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo ($location['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Location">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>
