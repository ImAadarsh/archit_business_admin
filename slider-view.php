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

                <?php
          $sql="Select * from mains";
          $results=$connect->query($sql);
          while($final=$results->fetch_assoc()){ ?>
                <div class="col-md-12 mb-4">

                    <div class="card profile shadow">
                        <div class="card-body my-4">
                            <div class="row align-items-center">
                                <div class="col-md-3 text-center mb-5">
                                    <a href="#!" class="avatar avatar-xl">
                                        <img src="<?php echo $uri.$final['thumbnail']; ?>" alt="<?php echo $final['id']; ?>"
                                            class="avatar-img rounded">
                                    </a>
                                </div>
                                <div class="col">
                                    <div class="row align-items-center">
                                        <div class="col-md-7">
                                            <h4 class="mb-1"><?php echo $final['title']; ?></h4>
                                            <h5 class="mb-1">Position<?php echo $final['position']; ?></h5>
                                            <a href="<?php echo $final['link']; ?>">Blog Link</a>

                                        </div>
                                        <div class="col">
                                        </div>
                                    </div>

                                </div>
                                <div class="row align-items-center">

                                    <div class="col mb-2">

                                        <a href="remove/_slider.php?del_id=<?php echo $final['id']; ?>">
                                            <button type="button" class="btn btn-danger">Delete</button>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- / .row- -->
                    </div> <!-- / .card-body - -->
                </div> <!-- / .card- -->


                <?php } ?>


            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>