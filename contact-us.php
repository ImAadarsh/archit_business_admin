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
          $sql="Select * from contacts";
          $results=$connect->query($sql);
          while($final=$results->fetch_assoc()){ ?>

                <div class="col-md-12 mb-4">
                    <div class="card profile shadow">
                        <div class="card-body my-4">
                            <div class="row align-items-center">

                                <div class="col">

                                    <div class="row align-items-center">
                                        <div class="col-md-7">Name:
                                            <h4 class="mb-1"><?php echo $final['name']; ?></h4>
                                            <p class="small mb-3"><span class="badge badge-info">Email:
                                                    <?php echo $final['email']; ?></span>
                                            </p>
                                        </div>
                                        <div class="col">
                                        </div>
                                    </div>
                                    <div class="row mb-4">

                                        <div class="col-md-7">
                                            Form Message:
                                            <b>
                                                <p class="text-dark-darker"> <?php echo $final['message']; ?> </p>
                                            </b>
                                        </div>
                                        <div class="col">

                                        </div>
                                    </div>
                                    <div class="row align-items-center">
                                        <div class="col-md-7 mb-2">
                                            <span class="small text-dark-darker mb-0">Raised On
                                                <?php echo $final['created_at']; ?></span>
                                        </div>
                                        <div class="col mb-2">

                                            <a href="remove/_contact-us.php?del_id=<?php echo $final['id']; ?>">
                                                <button type="button" class="btn btn-danger">Delete</button>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    Details:
                                    <p class="small mb-0 text-dark-darker">Name: <?php echo $final['name']; ?>
                                    </p>

                                    <p class="small mb-0 text-dark-darker">Email: <?php echo $final['email']; ?>
                                    </p>

                                  

                                    <p class="small mb-0 text-dark-darker">Mobile: <?php echo $final['mobile']; ?>
                                    </p>
                                    <br>

                                </div>
                            </div> <!-- / .row- -->
                        </div> <!-- / .card-body - -->
                    </div> <!-- / .card- -->
                </div>

                <?php } ?>


            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>