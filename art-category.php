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
                    <a href="art-category-view.php">
                        <button type="button" class="btn btn-primary">View Art Categories</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add Art Category</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_art-category.php" method="POST">
                                    <div class="form-group mb-3">
                                        <label for="name">Art Category Name</label>
                                        <input type="text" id="name" class="form-control"
                                            placeholder="Art Category Name" name="name" required>
                                    </div>

                                    <div class="form-group mb-3">
                                        <label for="category_id">Product Type</label>
                                        <select id="category_id" class="form-control" name="category_id" required>
                                            <option value="">Choose Product Type</option>
                                            <?php
                                            $business_id = $_SESSION['business_id'];
                                            $cat_sql = "SELECT id, name FROM categories WHERE business_id = $business_id ORDER BY name";
                                            $cat_results = $connect->query($cat_sql);
                                            while($cat = $cat_results->fetch_assoc()) {
                                                echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Submit">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>

            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 