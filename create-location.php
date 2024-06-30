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
                    <a href="locations.php">
                        <button type="button" class="btn btn-primary">View Locations</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">New Location Create</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_create-location.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Email</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Email" name="email">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Location Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Location Name" name="location_name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Address</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Address" name="address">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Mobile" name="phone">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Alternate Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Alternate Mobile" name="alternate_phone">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Associate BE</label>
                                        <select required type="text" id="simpleinput" class="form-control"
                                            placeholder="Alternate Mobile" name="business_id">
                                            <option>Choose Business for Location</option>
                                            <?php
                                        // echo $sql;
                                        $sql = "SELECT * FROM businessses";
                                        $results = $connect->query($sql);
                                        while($final=$results->fetch_assoc()){?>
                                        <option value="<?php echo $final['id'] ?>"><?php echo $final['business_name'] ?></option>

                                        <?php } ?>
                                            
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