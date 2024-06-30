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
                    <a href="manageuser.php">
                        <button type="button" class="btn btn-primary">View Admins</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">New Super Admin</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_create-admin.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Email</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Email" name="email">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Name" name="name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Mobile" name="phone">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Password</label>
                                        <input required type="password" id="simpleinput" class="form-control"
                                            placeholder="User Password" name="password">
                                    </div>

                                    <div class="form-group mb-3">
                                        <label for="custom-select">User Type</label>
                                        <select required name="user_type" class="custom-select" id="custom-select">
                                            <option selected value="superadmin">Super Admin
                                            </option>
                                        </select>
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