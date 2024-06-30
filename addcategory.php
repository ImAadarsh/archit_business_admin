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
                    <a href="dashboard.php">
                        <button type="button" class="btn btn-primary">Dashboard</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add Category</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_addcategory.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Category Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Category Name" name="name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Category Image</label>
                                        <input required type="file" id="simpleinput" class="form-control"
                                            placeholder="Certificate Id" name="image">
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" id="example-palaceholder" class="btn btn-primary"
                                            value="Submit">
                                    </div>
                            </div> <!-- /.col -->
                            </form>
                        </div>
                   
                   <!-- end section -->
                </div>





            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>