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
                    <a href="users.php">
                        <button type="button" class="btn btn-primary">View Users</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">New User Create</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_create-user.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput"> Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder=" Name" name="name">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Email</label>
                                        <input required type="email" id="simpleinput" class="form-control"
                                            placeholder="Email" name="email">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Mobile" name="phone">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Password</label>
                                        <input required type="password" id="simpleinput" class="form-control"
                                            placeholder="Password" name="password">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="custom-select">User Type</label>
                                        <select required name="role" class="custom-select" id="custom-select">
                                            <option>Choose the User Type</option>
                                            <option  value="admin">Admin
                                            </option>
                                            <option  value="sales">Salesman
                                            </option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
    <label for="business_id">Associate Business</label>
    
    <select required type="text" id="location_id" class="form-control" name="location_id">
        <option>Choose Business Location</option>
        <?php
        $b_id = $_SESSION['business_id'];
// Fetch businesses from the database
$sql = "SELECT * FROM locations where business_id=$b_id";
$results = $connect->query($sql);
while($final = $results->fetch_assoc()) {
    echo '<option value="' . $final['id'] . '">' . $final['location_name'] . '</option>';
}
?>
    </select>
    <input hidden value="<?php echo $b_id; ?>" name="business_id">
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