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
                                <strong class="card-title"> All Blogs </strong>
                                <a class="float-right small text-muted" href="#!"></a>
                            </div>
                            <div class="card-body">
                                <table class="table datatables" id="dataTable-1">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Mobile</th>
                                            <th>Serv.</th>
                                            <th>Serv. Satisfy</th>
                                            <th>Service Feed.</th>
                                            <th>Website</th>
                                            <th>Technical Issue</th>
                                            <th>Suggestions</th>
                                            <th>Recommend.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                         <?php
                                        // echo $sql;
                                        $sql = "SELECT * FROM feedback";
                                        $results = $connect->query($sql);
                                        while($final=$results->fetch_assoc()){?>
                                        <tr>
                                            <td><?php echo $final['id']?></td>
                                            <th scope="col"><?php echo $final['name']?></th>
                                            <td><?php echo $final['email']?>
                                            </td>
                                            <td><?php echo $final['mobile']?>
                                            </td>
                                            <td><?php echo $final['service']?>
                                            </td>
                                            <td><?php echo $final['service_satisfy']?>
                                            </td>
                                            <td><?php echo $final['service_feedback']?>
                                            </td>
                                            <td><?php echo $final['website']?>
                                            </td>
                                            <td><?php echo $final['technical_issue']?>
                                            </td>
                                            <td><?php echo $final['suggestion']?>
                                            </td>
                                            <td><?php echo $final['recommendation']?>
                                            </td>
                                            
                                        </tr>
                                        <?php }
         ?>
                                    </tbody>
                                </table>
                            </div> <!-- .card-body -->
                        </div> <!-- .card -->
                    </div> <!-- / .col-md-8 -->
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