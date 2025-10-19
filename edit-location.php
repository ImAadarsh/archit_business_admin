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
