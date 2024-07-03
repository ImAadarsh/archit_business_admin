<?php

use LDAP\Result;
error_reporting(0);
ini_set('display_errors', 0);
session_start();
include("admin/connect.php");

if (isset($_POST['login'])) {
    $data_array =  array(
    "user_type" => 'admin',
    "phone" => $_POST['phone'],
    "password" => $_POST['password'],
    "role" => "admin"
);
    $make_call = callAPI('POST', 'login', json_encode($data_array),NULL);
    $response = json_decode($make_call, true);
    // echo $response;
    if($response['message']){
        echo '<script>alert("'.$response['message'].'")</script>';
    }  
if ($response['user']['role']=='admin') {
    $_SESSION['email'] =  $response['user']['email'];
    $_SESSION['name'] = $response['user']['name'];
    $_SESSION['phone'] = $response['user']['phone'];
    $_SESSION['userid'] = $response['user']['id'];
    $_SESSION['business_id'] = $response['user']['business_id'];
    $_SESSION['role'] = 'admin';
    header('location: dashboard.php');
}else{
    if($response['message']){
        echo '<script>alert("Invaild Role.")</script>';
    }  
}
}

?>
<?php include("partials/header.php"); ?>

<body class="light ">
    <div class="wrapper vh-100">
        <div class="row align-items-center h-100">
            <form class="col-lg-3 col-md-4 col-10 mx-auto text-center" action="index.php" method="POST">
                <a class="navbar-brand mx-auto mt-2 flex-fill text-center" href="index.php">
                    <img  height="110" src="assets/images/archit.svg" alt="SVG image">
                    </img>

                </a>
                <br>
                <h1 class="h6 mb-3">Sign in</h1>
                <div class="form-group">
                    <label for="inputPhone" class="sr-only">Select Business</label>
                    <select name="phone" type="tel" id="inputEmail" class="form-control form-control-lg"
                        placeholder="Phone Number" required autofocus="">
                        <?php
                                        // echo $sql;
                                        $sql = "SELECT * FROM businessses";
                                        $results = $connect->query($sql);
                                        while($final=$results->fetch_assoc()){?>
                                        <option value="<?php echo $final['phone'] ?>"><?php echo $final['business_name'] ?></option>
                                        <?php } ?>

                    </select>
                </div>
                <div class="form-group">
                    <label for="inputPassword" class="sr-only">Passcode</label>
                    <input type="tel" id="inputPassword" class="form-control form-control-lg"
                        placeholder="Password" name="password" required>
                </div>
                <div class="checkbox mb-3">
                    <label>
                        <input type="checkbox" value="remember-me"> Stay logged in </label>
                </div>
                <button class="btn btn-lg btn-primary btn-block" name="login" type="submit">Login</button>
                <!-- <a href="forgotpassword.php">
                    <p class="mt-5 mb-3 text-muted">Forgot Password</p>
                </a>
                <br> -->
                <!--<p class="mt-5 mb-3 text-muted">Endeavour Digital © 2023</p>-->
            </form>
        </div>
    </div>
    <script src="js/jquery.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/moment.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/simplebar.min.js"></script>
    <script src='js/daterangepicker.js'></script>
    <script src='js/jquery.stickOnScroll.js'></script>
    <script src="js/tinycolor-min.js"></script>
    <script src="js/config.js"></script>
    <script src="js/apps.js"></script>
</body>

</html>
</body>

</html>