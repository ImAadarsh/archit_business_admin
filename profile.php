<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Get business information
$business_id = $_SESSION['business_id'];
$user_id = isset($_SESSION['userid']) ? $_SESSION['userid'] : null;

// Fetch business data
$sql = "SELECT b.*, l.location_name as primary_location_name 
        FROM businessses b 
        LEFT JOIN locations l ON b.primary_location_id = l.id 
        WHERE b.id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("i", $business_id);
$stmt->execute();
$result = $stmt->get_result();
$business_data = $result->fetch_assoc();
$stmt->close();

// Fetch user data
$user_data = null;
if ($user_id) {
    $sql_user = "SELECT * FROM users WHERE id = ?";
    $stmt_user = $connect->prepare($sql_user);
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user_data = $result_user->fetch_assoc();
    $stmt_user->close();
}

// If user_data is still null, try to get it from session email
if (!$user_data && isset($_SESSION['email'])) {
    $sql_user = "SELECT * FROM users WHERE email = ? AND business_id = ?";
    $stmt_user = $connect->prepare($sql_user);
    $stmt_user->bind_param("si", $_SESSION['email'], $business_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user_data = $result_user->fetch_assoc();
    $stmt_user->close();
    
    // Update session with user_id if found
    if ($user_data) {
        $_SESSION['userid'] = $user_data['id'];
        $user_id = $user_data['id'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_business'])) {
    $business_name = $_POST['business_name'];
    $gst = $_POST['gst'];
    $owner_name = $_POST['owner_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $alternate_phone = $_POST['alternate_phone'];
    $primary_location_id = $_POST['primary_location_id'];
    
    // Handle logo upload via API
    $logo_path = $business_data['logo'];
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        // Validate file size (max 5MB)
        $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
        if ($_FILES['logo']['size'] > $max_file_size) {
            $error_message = "File size too large. Maximum allowed size is 5MB.";
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $file_type = $_FILES['logo']['type'];
            
            if (!in_array($file_type, $allowed_types)) {
                $error_message = "Invalid file type. Only JPG, PNG, GIF, WEBP, and SVG files are allowed.";
            } else {
                // Upload file via API
                $file_tmp = $_FILES['logo']['tmp_name'];
                $file_name = $_FILES['logo']['name'];
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = 'business_' . $business_id . '_' . time() . '.' . $file_extension;
                
                // Create CURLFile for upload
                $cfile = new CURLFile($file_tmp, $file_type, $new_filename);
                
                // Prepare API data
                $upload_data = array(
                    'file' => $cfile,
                    'folder' => 'business_logos',
                    'business_id' => $business_id
                );
                
                // Call API to upload file
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.invoicemate.in/public/api/upload-business-logo');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $upload_data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code == 200 || $http_code == 201) {
                    $result = json_decode($response, true);
                    if (isset($result['path'])) {
                        $logo_path = $result['path'];
                        $success_message = "Logo uploaded successfully!";
                    } else {
                        $error_message = "Failed to get logo path from API response.";
                    }
                } else {
                    $error_message = "Failed to upload logo to server. Please try again.";
                }
            }
        }
    }
    
    $update_sql = "UPDATE businessses SET 
                   business_name = ?, 
                   gst = ?, 
                   owner_name = ?, 
                   email = ?, 
                   phone = ?, 
                   alternate_phone = ?, 
                   primary_location_id = ?,
                   logo = ?
                   WHERE id = ?";
    $update_stmt = $connect->prepare($update_sql);
    $update_stmt->bind_param("ssssssssi", 
        $business_name, 
        $gst, 
        $owner_name, 
        $email, 
        $phone, 
        $alternate_phone, 
        $primary_location_id,
        $logo_path,
        $business_id
    );
    
    if (!isset($error_message)) {
        if ($update_stmt->execute()) {
            if (!isset($success_message)) {
                $success_message = "Business profile updated successfully!";
            }
            // Refresh business data
            $stmt = $connect->prepare("SELECT b.*, l.location_name as primary_location_name 
                                        FROM businessses b 
                                        LEFT JOIN locations l ON b.primary_location_id = l.id 
                                        WHERE b.id = ?");
            $stmt->bind_param("i", $business_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $business_data = $result->fetch_assoc();
            $stmt->close();
        } else {
            $error_message = "Error updating business profile: " . $update_stmt->error;
        }
        $update_stmt->close();
    }
}
?>
<body class="vertical light">
<div class="wrapper">
<?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>

<main role="main" class="main-content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12">
                <h2 class="page-title mb-4">Business Profile</h2>
                
                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fe fe-check-circle"></i> <?php echo $success_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fe fe-alert-triangle"></i> <?php echo $error_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Profile Navigation Cards -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card shadow-sm border-primary">
                            <div class="card-body text-center">
                                <i class="fe fe-briefcase fe-32 text-primary mb-2"></i>
                                <h5 class="card-title">Business Information</h5>
                                <p class="text-muted">Edit your business details, GST, contact info, and logo</p>
                                <button type="button" class="btn btn-primary" onclick="document.getElementById('business-section').scrollIntoView({behavior: 'smooth'})">
                                    <i class="fe fe-edit"></i> Edit Business
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-info">
                            <div class="card-body text-center">
                                <i class="fe fe-user fe-32 text-info mb-2"></i>
                                <h5 class="card-title">User Profile</h5>
                                <p class="text-muted">Edit your personal user account details and password</p>
                                <?php if ($user_id): ?>
                                <a href="team-edit.php?id=<?php echo $user_id; ?>" class="btn btn-info">
                                    <i class="fe fe-user-check"></i> Edit User Profile
                                </a>
                                <?php else: ?>
                                <button class="btn btn-secondary" disabled>User ID not found</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Business Information Section -->
                <div class="card shadow mb-4" id="business-section">
                    <div class="card-header">
                        <strong class="card-title">
                            <i class="fe fe-briefcase"></i> Business Information
                        </strong>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_business" value="1">
                            
                            <div class="row">
                                <!-- Business Name -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="business_name">Business Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="business_name" name="business_name" 
                                               value="<?php echo htmlspecialchars($business_data['business_name']); ?>" required>
                                    </div>
                                </div>

                                <!-- GST Number -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="gst">GST Number</label>
                                        <input type="text" class="form-control" id="gst" name="gst" 
                                               value="<?php echo htmlspecialchars($business_data['gst']); ?>" 
                                               placeholder="Enter GST Number">
                                    </div>
                                </div>

                                <!-- Owner Name -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="owner_name">Owner Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="owner_name" name="owner_name" 
                                               value="<?php echo htmlspecialchars($business_data['owner_name']); ?>" required>
                                    </div>
                                </div>

                                <!-- Email -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="email">Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($business_data['email']); ?>" required>
                                    </div>
                                </div>

                                <!-- Phone -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="phone">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($business_data['phone']); ?>" required>
                                    </div>
                                </div>

                                <!-- Alternate Phone -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="alternate_phone">Alternate Phone</label>
                                        <input type="text" class="form-control" id="alternate_phone" name="alternate_phone" 
                                               value="<?php echo htmlspecialchars($business_data['alternate_phone']); ?>" 
                                               placeholder="Enter alternate phone number">
                                    </div>
                                </div>

                                <!-- Primary Location -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="primary_location_id">Primary Location</label>
                                        <select class="form-control" id="primary_location_id" name="primary_location_id">
                                            <option value="">Select Primary Location</option>
                                            <?php
                                            $location_sql = "SELECT * FROM locations WHERE business_id = ?";
                                            $location_stmt = $connect->prepare($location_sql);
                                            $location_stmt->bind_param("i", $business_id);
                                            $location_stmt->execute();
                                            $location_result = $location_stmt->get_result();
                                            while ($location = $location_result->fetch_assoc()) {
                                                $selected = ($location['id'] == $business_data['primary_location_id']) ? 'selected' : '';
                                                echo '<option value="' . $location['id'] . '" ' . $selected . '>' . 
                                                     htmlspecialchars($location['location_name']) . '</option>';
                                            }
                                            $location_stmt->close();
                                            ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            <a href="create-location.php">Create new location</a>
                                        </small>
                                    </div>
                                </div>

                                <!-- Current Logo -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label>Current Logo</label>
                                        <div class="mb-2">
                                            <?php if (!empty($business_data['logo'])): ?>
                                                <img src="<?php echo $uri . $business_data['logo']; ?>" 
                                                     alt="Business Logo" 
                                                     class="img-thumbnail" 
                                                     style="max-width: 200px; max-height: 100px;">
                                            <?php else: ?>
                                                <p class="text-muted">No logo uploaded</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Upload New Logo -->
                                <div class="col-md-12">
                                    <div class="form-group mb-3">
                                        <label for="logo">Upload New Logo</label>
                                        <input type="file" class="form-control-file" id="logo" name="logo" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml">
                                        <small class="form-text text-muted">Upload a new logo to replace the current one (JPG, PNG, GIF, WEBP, SVG - Max 5MB)</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fe fe-save"></i> Update Business Profile
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary ml-2">
                                    <i class="fe fe-x"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- User Information Display -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <strong class="card-title">
                            <i class="fe fe-user"></i> Your User Account
                        </strong>
                    </div>
                    <div class="card-body">
                        <?php if ($user_data): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($user_data['name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($user_data['email']); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($user_data['phone']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Role:</strong> 
                                    <span class="badge badge-<?php echo $user_data['role'] == 'admin' ? 'success' : 'primary'; ?>">
                                        <?php echo ucfirst($user_data['role']); ?>
                                    </span>
                                </p>
                                <p><strong>Business:</strong> <?php echo htmlspecialchars($business_data['business_name']); ?></p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="team-edit.php?id=<?php echo $user_id; ?>" class="btn btn-info">
                                <i class="fe fe-edit-2"></i> Edit Your User Profile
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fe fe-alert-triangle"></i> Unable to load user information. Please contact support if this persists.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<?php include "admin/footer.php"; ?>
</div>

<script>
// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    $('.alert').fadeOut('slow');
}, 5000);
</script>

</body>
</html>

