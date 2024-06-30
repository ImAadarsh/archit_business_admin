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
                                <strong class="card-title"> All Admins </strong>
                                <a class="float-right small text-muted" href="#!"></a>
                            </div>
                            <div class="card-body">
                            <table class="table datatables" id="dataTable-1">
    <thead>
        <tr>
            <th style="color: black;" >ID</th>
            <th style="color: black;" >Name</th>
            <th style="color: black;" >Email</th>
            <th style="color: black;" >Mobile</th>
            <th style="color: black;" >Passcode</th>
            <th style="color: black;" >Business Name</th>
            <th style="color: black;" >Location Name</th>
            <th style="color: black;" >User Role</th>
            <th style="color: black;" >Status</th>
            <th style="color: black;" >Action</th>
        </tr>
    </thead>
    <tbody>
        <?php
     
     $b_id = $_SESSION['business_id'];
        // Fetch user data from the database
        $sql = "SELECT users.* from users where business_id=$b_id";
        $results = $connect->query($sql);
        while ($final = $results->fetch_assoc()) {
            if(isset($final['location_id'])){
                $l_id = $final['location_id'];
                $sql = "SELECT * from locations where id = $l_id";
            $results2 = $connect->query($sql);
            $final2 = $results2->fetch_assoc();
            }

            if(isset($final['business_id'])){
                $l_id = $final['business_id'];
                $sql = "SELECT * from businessses where id = $l_id";
            $results1 = $connect->query($sql);
            $final1 = $results1->fetch_assoc();
            }
            ?>
            <tr>
                <td><?php echo $final['id']?></td>
                <td><?php echo $final['name']?></td>
                <td><?php echo $final['email']?></td>
                <td><?php echo $final['phone']?></td>
                <td><?php echo $final['passcode']?></td>
                <td><?php echo $final1['business_name']?$final1['business_name']:"Not Avaliable" ?></td>
                <td><?php echo $final2['location_name']?$final2['location_name']:"Not Avaliable" ?></td>
               
                <td>
                    <?php
                    echo  $final['role'];
;                    ?>
                </td>
                <td><?php echo $final['is_active']==1?"<b style='color:green;'>Active</b>":"<b style='color:red;'>Inactive</b>" ?></td>
                <td>
    <div class="btn-group">
        <button class="btn btn-sm dropdown-toggle more-horizontal" type="button"
                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <span class="text-muted sr-only">Action</span>
        </button>
        <div class="dropdown-menu dropdown-menu-right">
            <?php if ($final['is_active'] == 1): ?>
                <a class="dropdown-item" href="change/team.php?id=<?php echo $final['id']?>&status=0">Deactivate</a>
            <?php else: ?>
                <a class="dropdown-item" href="change/team.php?id=<?php echo $final['id']?>&status=1">Activate</a>
            <?php endif; ?>
            <a class="dropdown-item" href="team-edit.php?id=<?php echo $final['id']?>">Edit</a>
            <a class="dropdown-item" href="remove/_users.php?id=<?php echo $final['id']?>">Delete</a>
        </div>
    </div>
</td>
                
            </tr>
            <?php
        }
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