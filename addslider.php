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
                        <strong class="card-title">Add Slider</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_addslider.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Blog Link</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Blog Link of Website" name="link">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Title</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Title" name="title">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Thumbnail</label>
                                        <input required type="file" id="simpleinput" class="form-control"
                                            placeholder="Title" name="thumbnail">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Position</label>
                                        <input required type="number" min="0" max="5" id="simpleinput" class="form-control"
                                            placeholder="1,2,..5" name="position">
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" id="example-palaceholder" class="btn btn-primary"
                                            value="Submit">
                                    </div>
                            </div> <!-- /.col -->
                            </form>
                        </div>
                    </div>
                    

            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>