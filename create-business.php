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
                    <a href="business.php">
                        <button type="button" class="btn btn-primary">View Business</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">New Business Create</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_create-business.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Email</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Email" name="email">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Business Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Business Name" name="business_name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Owner Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Owner Name" name="owner_name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Mobile" name="phone">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">GST Number</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="GST Number" name="gst">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Logo</label>
                                        <input required type="file" id="simpleinput" class="form-control"
                                            placeholder="GST Number" name="logo">
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