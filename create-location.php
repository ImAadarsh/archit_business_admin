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
                                        <label for="state">State</label>
                                        <select required id="state" class="form-control" name="state">
                                            <option value="">Select State</option>
                                            <option value="Andhra Pradesh">Andhra Pradesh</option>
                                            <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                                            <option value="Assam">Assam</option>
                                            <option value="Bihar">Bihar</option>
                                            <option value="Chhattisgarh">Chhattisgarh</option>
                                            <option value="Goa">Goa</option>
                                            <option value="Gujarat">Gujarat</option>
                                            <option value="Haryana">Haryana</option>
                                            <option value="Himachal Pradesh">Himachal Pradesh</option>
                                            <option value="Jharkhand">Jharkhand</option>
                                            <option value="Karnataka">Karnataka</option>
                                            <option value="Kerala">Kerala</option>
                                            <option value="Madhya Pradesh">Madhya Pradesh</option>
                                            <option value="Maharashtra">Maharashtra</option>
                                            <option value="Manipur">Manipur</option>
                                            <option value="Meghalaya">Meghalaya</option>
                                            <option value="Mizoram">Mizoram</option>
                                            <option value="Nagaland">Nagaland</option>
                                            <option value="Odisha">Odisha</option>
                                            <option value="Punjab">Punjab</option>
                                            <option value="Rajasthan">Rajasthan</option>
                                            <option value="Sikkim">Sikkim</option>
                                            <option value="Tamil Nadu">Tamil Nadu</option>
                                            <option value="Telangana">Telangana</option>
                                            <option value="Tripura">Tripura</option>
                                            <option value="Uttar Pradesh">Uttar Pradesh</option>
                                            <option value="Uttarakhand">Uttarakhand</option>
                                            <option value="West Bengal">West Bengal</option>
                                            <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
                                            <option value="Chandigarh">Chandigarh</option>
                                            <option value="Dadra and Nagar Haveli and Daman and Diu">Dadra and Nagar Haveli and Daman and Diu</option>
                                            <option value="Delhi">Delhi</option>
                                            <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                                            <option value="Ladakh">Ladakh</option>
                                            <option value="Lakshadweep">Lakshadweep</option>
                                            <option value="Puducherry">Puducherry</option>
                                        </select>
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
                                    <input type="hidden" name="business_id" value="<?php echo $_SESSION['business_id']; ?>">
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