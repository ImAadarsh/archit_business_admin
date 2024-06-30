<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

$user_data = null;
if (isset($_GET['id'])) {
    $user_id = $_GET['id'];
    $sql = "SELECT * FROM users WHERE id = ?";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
}
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
                    <a href="team.php">
                        <button type="button" class="btn btn-primary">View Team</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit User </strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                            <form role="form" action="edit/team.php" method="POST" enctype="multipart/form-data">
                            <?php if ($user_data): ?>
                                <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group mb-3">
                                        <label for="simpleinput"> Name</label>
                                        <input required type="text" id="simpleinput" class="form-control" placeholder=" Name" name="name" 
                                            value="<?php echo $user_data ? htmlspecialchars($user_data['name']) : ''; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Email</label>
                                        <input required type="email" id="simpleinput" class="form-control" placeholder="Email" name="email" 
                                            value="<?php echo $user_data ? htmlspecialchars($user_data['email']) : ''; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Mobile</label>
                                        <input required type="text" id="simpleinput" class="form-control" placeholder="Mobile" name="phone" 
                                            value="<?php echo $user_data ? htmlspecialchars($user_data['phone']) : ''; ?>">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Password</label>
                                        <input type="text" id="simpleinput" class="form-control" value="<?php echo $user_data ? htmlspecialchars($user_data['passcode']) : ''; ?>" placeholder="Leave blank to keep current password" name="passcode">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="custom-select">User Type</label>
                                        <select required name="role" class="custom-select" id="custom-select">
                                            <option>Choose the User Type</option>
                                            <option value="admin" <?php echo ($user_data && $user_data['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                            <option value="sales" <?php echo ($user_data && $user_data['role'] == 'sales') ? 'selected' : ''; ?>>Salesman</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="business_id">Associate Business</label>
                                        <select required type="text" id="location_id" class="form-control" name="location_id">
                                            <option>Choose Business Location</option>
                                            <?php
                                            $b_id = $_SESSION['business_id'];
                                            $sql = "SELECT * FROM locations where business_id=$b_id";
                                            $results = $connect->query($sql);
                                            while($final = $results->fetch_assoc()) {
                                                $selected = ($user_data && $user_data['location_id'] == $final['id']) ? 'selected' : '';
                                                echo '<option value="' . $final['id'] . '" ' . $selected . '>' . $final['location_name'] . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <input hidden value="<?php echo $b_id; ?>" name="business_id">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" id="example-palaceholder" class="btn btn-primary" value="<?php echo $user_data ? 'Update' : 'Submit'; ?>">
                                    </div>
                            </div> <!-- /.col -->
                            </form>
                        </div>
                    </div>
                </div>





            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>