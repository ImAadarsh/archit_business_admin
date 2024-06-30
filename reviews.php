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
                <!-- <div class="row justify-content-center"> -->

                <!-- / .row -->
                <div class="row">
                    <!-- Recent orders -->
                    <div class="col-md-12">
                        <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12">
                                        
                                       
                        <div class="card shadow eq-card">
                            <div class="card-header">
                                <strong class="card-title"> Added Reviews </strong>
                                <a class="float-right small text-muted" href="#!"></a>
                            </div>
                            <div class="card-body">
                                <table class="table datatables" id="dataTable-1">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>User Name</th>
                                            <th>Title</th>
                                            <th>Description</th>
                                            <th>Ratings</th>
                                            <th>View User</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                         <?php
                                        // echo $sql;
                                        $sql = "SELECT * FROM reviews";
                                        $results = $connect->query($sql);
                                        while($final=$results->fetch_assoc()){?>
                                        <tr>
                                            <td><?php echo $final['id']?></td>
                                            <th scope="col"><?php echo $final['name']?></th>
                                            <td> <?php echo $final['title']?>
                                            </td>
                                            <td> <?php echo $final['description']?>
                                            </td>
                                            <td> <?php echo $final['star']?>/5
                                            </td>
                                           
                                            <td> <a href="<?php echo $uri.$final['user_image']?>">View Image</a> 
                                            </td>
                                            <td><button class="btn btn-sm dropdown-toggle more-horizontal" type="button"
                                                    data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                    <span class="text-muted sr-only">Action</span>
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a class="dropdown-item"
                                                        href="remove/_video.php?id=<?php echo $final['id']?>">Delete</a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php }
         ?>
                                    </tbody>
                                </table>
                            </div> <!-- .card-body -->
                        </div> <!-- .card -->
                    </div> <!-- / .col-md-8 -->
                    <!-- Recent Activity -->
                    <!-- / .col-md-3 -->
                </div> <!-- end section -->
            </div>
    </div> <!-- .row -->
    </div> <!-- .container-fluid -->
        <script>
    function showHideCustomRange() {
        var dateRange = document.getElementById("date_range");
        var customRange = document.getElementById("custom_range");

        if (dateRange.value === "custom") {
            customRange.style.display = "block";
        } else {
            customRange.style.display = "none";
        }
    }

    </script>

    <?php include "admin/footer.php"; ?>