<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Product Type ID is required.";
    header("Location: category-view.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch category details
$sql = "SELECT * FROM categories WHERE id = ? AND business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Product Type not found or you don't have permission to edit it.";
    header("Location: category-view.php");
    exit();
}

$category = $result->fetch_assoc();
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
                    <a href="category-view.php">
                        <button type="button" class="btn btn-primary">Back to Product Types</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Product Type</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-category.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $category['business_id']; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="name">Product Type Name</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Product Type Name" name="name" value="<?php echo htmlspecialchars($category['name']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="location_id">Business Location *</label>
                                        <select required id="location_id" class="form-control" name="location_id">
                                            <option value="">Choose Business Location</option>
                                            <?php
                                            $b_id = $_SESSION['business_id'];
                                            $sql = "SELECT * FROM locations WHERE business_id=$b_id ORDER BY location_name";
                                            $results = $connect->query($sql);
                                            while($location = $results->fetch_assoc()) {
                                                $selected = ($location['id'] == $category['location_id']) ? 'selected' : '';
                                                echo '<option value="' . $location['id'] . '" ' . $selected . '>' . htmlspecialchars($location['location_name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code</label>
                                        <input type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code" value="<?php echo htmlspecialchars($category['hsn_code'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="gst_percent">GST Percentage</label>
                                        <input type="number" id="gst_percent" class="form-control"
                                            placeholder="GST Percentage" name="gst_percent" step="0.01" min="0" max="100" 
                                            value="<?php echo htmlspecialchars($category['gst_percent'] ?? '0'); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Product Type">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 