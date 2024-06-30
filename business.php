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
                                <strong class="card-title"> View Business </strong>
                                <a class="float-right small text-muted" href="#!"></a>
                            </div>
                            <div class="card-body">
                            <table class="table datatables" id="dataTable-1">
    <thead>
        <tr>
            <th>ID</th>
            <th>Business Name</th>
            <th>GST</th>
            <th>Owner Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Alternate Phone</th>
            <th>Primary location</th>
            <th>Logo</th>
            <th>Is Active</th>
            <th>Action</th> <!-- Added Action column -->
        </tr>
    </thead>
    <tbody>
        <?php
     

        // Fetch locations from the database
        $sql = "SELECT * from businessses";
        $results = $connect->query($sql);
        while ($final = $results->fetch_assoc()) {
            if(isset($final['primary_location_id'])){
                $l_id = $final['primary_location_id'];
                $sql = "SELECT * from locations where id = $l_id";
            $results1 = $connect->query($sql);
            $final1 = $results1->fetch_assoc();
            }
            
            ?>
            <tr>
                <td><?php echo $final['id'] ?></td>
                <td><?php echo $final['business_name'] ?></td>
                <td><?php echo $final['gst'] ?></td>
                <td><?php echo $final['owner_name'] ?></td>
                <td><?php echo $final['email'] ?></td>
                <td><?php echo $final['phone'] ?></td>
                <td><?php echo $final['alternate_phone'] ?></td>
                <td><?php echo $final1['location_name']?$final1['location_name']:"Not Selected" ?></td>
                <td><a href="<?php echo $uri.$final['logo'] ?>">Click Here</a></td>
                <td><?php echo ($final['is_active'] == 1) ? "Active" : "Inactive"; ?></td>
                <td>
                    <div class="btn-group">
                        <button class="btn btn-sm dropdown-toggle more-horizontal" type="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="text-muted sr-only">Action</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="remove/_business.php?id=<?php echo $final['id'] ?>">Delete</a>
                            <a class="dropdown-item" href="change/business.php?id=<?php echo $final['id'] ?>&state=<?php echo ($final['is_active'] == 1) ? "0" : "1"; ?>"><?php echo ($final['is_active'] == 1) ? "Make Inactive" : "Make Active"; ?></a>
                            <!-- Assuming remove_location.php handles deletion -->
                        </div>
                       
                    </div>
                </td>
            </tr>
            <?php
        }
        // Close database connection
        $connect->close();
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