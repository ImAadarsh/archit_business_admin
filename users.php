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
$b_id = $_SESSION['business_id'];
$sql = "SELECT 
            i.id,
            i.name,
            i.customer_type,
            i.type AS invoice_type,
            i.doc_no,
            i.mobile_number,
            a.state,
            SUM(i.total_amount) AS total_amount,
            COUNT(*) AS total_invoices
        FROM 
            `invoices` i
        LEFT JOIN 
            `addres` a ON i.billing_address_id = a.id
        LEFT JOIN 
            `businessses` b ON i.business_id = b.id
        WHERE 
            i.business_id = '$b_id'";
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
                            <form action="excel-user.php" method="GET">
                                        <div class="form-group mb-3">
<input hidden value="<?php echo $sql; ?>" name="query">
                                                <input type="submit" name="filter" id="example-palaceholder"
                                                    class="btn btn-primary" value="Download Report">
                                            
                                            </div>
                                            </form>
                            <div class="card-body">
                            <table class="table datatables" id="dataTable-1">
                            <thead>
    <tr>
        <th style="color: black;" >ID</th>
        <th style="color: black;" >Name</th>
        <th style="color: black;" >Customer Type</th>
        <th style="color: black;" >Invoice Type</th>
        <th style="color: black;" >Mobile</th>
        <th style="color: black;" >State</th>
        <th style="color: black;" >Document Number</th>
        <th style="color: black;" >Total Amount</th>
        <th style="color: black;" >Action</th>
    </tr>
</thead>
    <tbody>
        <?php
$b_id = $_SESSION['business_id'];
$sql = "SELECT 
            i.id,
            i.name,
            i.customer_type,
            i.doc_no,
            i.type AS invoice_type,
            i.mobile_number,
            a.state,
            b.gst AS gst_number,
            i.total_amount
        FROM 
            `invoices` i
        LEFT JOIN 
            `addres` a ON i.billing_address_id = a.id
        LEFT JOIN 
            `businessses` b ON i.business_id = b.id
        WHERE 
            i.business_id = $b_id";

$results = $connect->query($sql);

while ($final = $results->fetch_assoc()) {
    ?>
    <tr>
        <td><?php echo $final['id']?></td>
        <td><?php echo $final['name']?></td>
        <td><?php echo $final['customer_type']?></td>
        <td><?php echo $final['invoice_type']?></td>
        <td><?php echo $final['mobile_number']?></td>
        <td><?php echo $final['state']?></td>
        <td><?php echo $final['doc_no']?></td>
        <td><?php echo $final['total_amount']?></td>
        <td>
    <div class="btn-group">
        <button class="btn btn-sm dropdown-toggle more-horizontal" type="button"
                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <span class="text-muted sr-only">Action</span>
        </button>
        <div class="dropdown-menu dropdown-menu-right">
            <a class="dropdown-item" href="users-edit.php?id=<?php echo $final['id']?>">Edit</a>
            <a class="dropdown-item" href="https://invoice.invoicemate.in/invoice.html?invoiceid=<?php echo $final['id']?>">View</a>
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