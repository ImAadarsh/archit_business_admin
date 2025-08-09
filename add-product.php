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


                <div class="card shadow mb-4">
                    <a href="products.php">
                        <button type="button" class="btn btn-primary">View Products</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add New Product</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_add-product.php" method="POST">
                                    <div class="form-group mb-3">
                                        <label for="name">Product Name</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Product Name" name="name">
                                    </div>
                                    <!--<div class="form-group mb-3">-->
                                    <!--    <label for="product_serial_number">Product Serial Number</label>-->
                                    <!--    <input required type="text" id="product_serial_number" class="form-control"-->
                                    <!--        placeholder="Product Serial Number" name="product_serial_number">-->
                                    <!--</div>-->
                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code</label>
                                        <input required type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="price">Price</label>
                                        <input required type="number" step="0.01" id="price" class="form-control"
                                            placeholder="Price" name="price">
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
                                        <input type="submit" class="btn btn-primary" value="Add Product">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>

            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>