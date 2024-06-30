<?php
include 'admin/connect.php';
include 'admin/session.php';

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <link rel="icon" href="favicon.ico">
    <title>Archit Art Gallery | Admin Dashboard</title>
    <!-- Simple bar CSS -->
    <link rel="stylesheet" href="css/simplebar.css">
    <!-- Fonts CSS -->
    <link
        href="https://fonts.googleapis.com/css2?family=Overpass:ital,wght@0,100;0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,600;1,700;1,800;1,900&display=swap"
        rel="stylesheet">
    <!-- Icons CSS -->
    <link rel="stylesheet" href="css/feather.css">
    <!-- Date Range Picker CSS -->
    <link rel="stylesheet" href="/richtexteditor/rte_theme_default.css"/>
<script type="text/javascript" src="/richtexteditor/rte.js"></script>
<script type="text/javascript" src='/richtexteditor/plugins/all_plugins.js'></script>
    <link rel="stylesheet" href="css/daterangepicker.css">
    <!-- App CSS -->
    <link rel="stylesheet" href="css/app-light.css" id="lightTheme">
    <link rel="stylesheet" href="css/app-dark.css" id="darkTheme" disabled>
    


    <link rel="stylesheet" type="text/css" href=https://snehsobatimatrimony.in/assets/css/bootstrap.min.css">

<!-- Icon Font - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/fontawesome.css">

<!-- Animation - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/animate.css">

<!-- Cursor - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/cursor.css">

<!-- Carousel - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/slick.css">
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/slick-theme.css">

<!-- Video & Image Popup - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/magnific-popup.css">

<!-- Vanilla Calendar - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/vanilla-calendar.min.css">

<!-- Custom - CSS Include -->
<link rel="stylesheet" type="text/css" href="https://snehsobatimatrimony.in/assets/css/style.css">
</head>
<body class="vertical  light  ">
    <div class="wrapper">
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>



        <main role="main" class="main-content">
            <div class="container-fluid">

        <!-- Contact Section - Start
        ================================================== -->
        <section class="contact_section ">
          <div class="container">
          <section class="register_section ">
          <div class="container">
            <div class="row justify-content-center">
            <?php
                                        // echo $sql;
                                        $id = $_GET['id'];
                                        $sql = "SELECT * FROM users where id=$id";
                                        $results = $connect->query($sql);
                                        $final=$results->fetch_assoc();
                                        ?>
                <h4 class="register_heading text-center"> User Details</h4>
               
                <form action="controllers/registration.php" method="post" enctype="multipart/form-data">
                  <div class="register_form signup_login_form">
                  <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Name</label>
                      <input required type="name" value="<?php echo $final['name'] ?>" name="name" placeholder="Your Full Name">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Gender</label>
                      <select name="sex" placeholder="Email">
                        <option value="male"><?php echo $final['sex'] ?></option>
                       
                      </select>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Looking For</label>
                      <select name="looking_for" placeholder="Email">
                        <option value="male"><?php echo $final['looking_for'] ?></option>
                    
                      </select>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Date of Birth</label>
                      <input disabled required value="<?php echo $final['dob'] ?>" type="date" name="dob" placeholder="Your Full Name">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Religion</label>
                      <input disabled required type="text" value="<?php echo $final['religion'] ?>" name="religion" placeholder="Your Religion">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Education</label>
                      <input required type="text" name="education" value="<?php echo $final['education'] ?>" placeholder="Your Education">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Occupation</label>
                      <input required type="text" value="<?php echo $final['occupation'] ?>" name="occupation" placeholder="Your Occupation">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Email</label>
                      <input required type="email" name="email" value="<?php echo $final['email'] ?>" placeholder="Email">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Phone</label>
                      <input required type="text" name="phone" value="<?php echo $final['phone'] ?>" placeholder="Your Contact Number">
                    </div>
                    <h3 class="mb-4">Mental Health Information:</h3>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Do you have a diagnosed mental health condition?</label>
                      <select name="mental_health">
                        <option value="Depression"><?php echo $final['mental_health'] ?></option>
                       
                      </select>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Briefly describe your mental health journey and any treatment or support you
have received</label>
                      <textarea  name="health_describe" placeholder="Please Describe"><?php echo $final['health_describe'] ?></textarea>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Profile Picture: (Upload a clear, recent photo of yourself)</label>
                      ----- <a target="_blank" href="<?php echo $uri.$final['profile'] ?>">View Profile</a>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">City</label>
                      <input required value="<?php echo $final['city'] ?>" type="text" name="city" placeholder="Your City">
                    </div>
                    <div class="form_item">
    <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_state" class="input required_title">Select your state:</label>
    <select name="state">
        <option value="Andhra Pradesh"><?php echo $final['state'] ?></option>
       
    </select>
</div>
<div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Country</label>
                      <input value="<?php echo $final['country'] ?>" required type="text" name="country" placeholder="Your Country">
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">About Me: </label>
                      <textarea  name="about" placeholder="Please Describe"><?php echo $final['about'] ?></textarea>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">What qualities are you looking for in a partner?</label>
                      <select name="looking_qualities">
                        <option value="Understanding and Empathetic"><?php echo $final['looking_qualities'] ?></option>
                      </select>
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Desired Age Range in a Partner</label>
                      <input value="<?php echo $final['partner_age'] ?>" required type="number" min="18" placeholder="Age" max="100" name="partner_age" >
                    </div>
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Preferred Location for a Partner</label>
                      <input required type="text" placeholder="Location" value="<?php echo $final['partner_location'] ?>" name="partner_location" >
                    </div>
                    
                    <div class="form_item">
                      <label style="font-size: 16.5px; padding-bottom: 7px; padding-left: 5px;" for="input required_name" class="input required_title">Select Doctor Certificate</label>
                     ------ <a target="_blank" href="<?php echo $uri.$final['certificate'] ?>">View Certificate</a>
                    </div>

                              
                  </div>
                </form>
              </div>
            </div>
          </div>
        </section>

            <?php include "admin/footer.php"; ?>