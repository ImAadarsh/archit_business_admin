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
                    <a href="category-view.php">
                        <button type="button" class="btn btn-primary">View Product Types</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add Product Type</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_category.php" method="POST">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Product Type Name</label>
                                        <input type="text" id="simpleinput" class="form-control"
                                            placeholder="Product Type Name" name="name" required>
                                        <input type="hidden" name="business_id" value="<?php echo $_SESSION['business_id']; ?>">
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
                                                echo '<option value="' . $location['id'] . '">' . htmlspecialchars($location['location_name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code</label>
                                        <input type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code">
                                    </div>

                                    <div class="form-group mb-3">
                                        <label for="gst_percent">GST Percentage</label>
                                        <input type="number" id="gst_percent" class="form-control"
                                            placeholder="GST Percentage" name="gst_percent" step="0.01" min="0" max="100">
                                    </div>

                                    <div class="form-group mb-3">

                                        <input type="submit" id="example-palaceholder" class="btn btn-primary"
                                            value="Submit">
                                    </div>
                            </div> <!-- /.col -->
                            </form>
                        </div>
                    </div>
                </div>




            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>