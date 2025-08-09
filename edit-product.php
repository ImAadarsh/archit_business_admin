<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Product ID is required.";
    header("Location: products.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch product details
$sql = "SELECT * FROM products WHERE id = ? AND business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Product not found or you don't have permission to edit it.";
    header("Location: products.php");
    exit();
}

$product = $result->fetch_assoc();
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
                    <a href="products.php">
                        <button type="button" class="btn btn-primary">Back to Products</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Product</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-product.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $product['business_id']; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="name">Product Name</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Product Name" name="name" value="<?php echo $product['name']; ?>">
                                    </div>
                                    <!--<div class="form-group mb-3">-->
                                    <!--    <label for="product_serial_number">Product Serial Number</label>-->
                                    <!--    <input required type="text" id="product_serial_number" class="form-control"-->
                                    <!--        placeholder="Product Serial Number" name="product_serial_number" value="<?php echo $product['product_serial_number']; ?>">-->
                                    <!--</div>-->
                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code</label>
                                        <input required type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code" value="<?php echo $product['hsn_code']; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="price">Price</label>
                                        <input required type="number" step="0.01" id="price" class="form-control"
                                            placeholder="Price" name="price" value="<?php echo $product['price']; ?>">
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
                                                $selected = ($loc['id'] == $product['location_id']) ? 'selected' : '';
                                                echo '<option value="' . $loc['id'] . '" ' . $selected . '>' . $loc['location_name'] . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Product">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 