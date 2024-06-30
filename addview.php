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
                        <strong class="card-title">Add Profile Views</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/addviews.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">

                                        <input hidden value="<?php echo $_GET['id'] ?>" name="id" >
                                        <input hidden value="<?php  echo $_GET['old'] ?>" name="old" >
                                        <label for="simpleinput">No. Of Views to Add</label>
                                        <input required type="number" min="0" max="1000" id="simpleinput" class="form-control"
                                            placeholder="No. of Views" name="new">
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