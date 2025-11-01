<?php
// Include access control at the very top
include 'admin/access_control.php';
include 'admin/header.php';
$b_id = $_SESSION['business_id'];

// Initialize filter variables from GET parameters (for form persistence)
$location_id = isset($_GET['location_id']) ? $_GET['location_id'] : '';
$price_min = isset($_GET['price_min']) ? $_GET['price_min'] : '';
$price_max = isset($_GET['price_max']) ? $_GET['price_max'] : '';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$gst_rate = isset($_GET['gst_rate']) ? $_GET['gst_rate'] : '';
?>
<body class="vertical light">
    <div class="wrapper">
        <?php
        include 'admin/navbar.php';
include 'admin/aside.php';
?>
        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                <form role="form" action="itemised.php" method="GET" enctype="multipart/form-data">

  
  <div class="row">
  <div class="col-md-3 mb-3">
           
                <label for="location_id">Business Location</label>
                <select id="location_id" class="form-control" name="location_id">
                    <option value="">All</option>
                    <?php
                                        // echo $sql;
                                        $sql_loc = "SELECT * FROM locations where business_id=$b_id";
                                        $results_loc = $connect->query($sql_loc);
                                        while($final_loc=$results_loc->fetch_assoc()){
                                            $selected = ($location_id == $final_loc['id']) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo $final_loc['id'] ?>" <?php echo $selected; ?>><?php echo $final_loc['location_name'] ?></option>

                                        <?php } ?>
                </select>

        </div>
  <div class="col-md-2 mb-3">
      <label for="price_min">Minimum Price</label>
      <input type="number" id="price_min" class="form-control" name="price_min" value="<?php echo htmlspecialchars($price_min); ?>">
    </div>
    <div class="col-md-2 mb-3">
      <label for="price_max">Maximum Price</label>
      <input type="number" id="price_max" class="form-control" name="price_max" value="<?php echo htmlspecialchars($price_max); ?>">
    </div>
    <div class="col-md-2 mb-3">
      <label for="gst_rate">GST Rate</label>
      <select id="gst_rate" class="form-control" name="gst_rate">
        <option value="">All</option>
        <option value="5" <?php echo ($gst_rate == '5') ? 'selected' : ''; ?>>5%</option>
        <option value="12" <?php echo ($gst_rate == '12') ? 'selected' : ''; ?>>12%</option>
        <option value="18" <?php echo ($gst_rate == '18') ? 'selected' : ''; ?>>18%</option>
      </select>
    </div>
    <div class="col-md-3 mb-3">
      <label for="date_range">Date Range</label>
      <select onchange="showHideCustomRange()" name="date_range" id="date_range" class="form-control">
        <option value="all" <?php echo ($date_range == 'all') ? 'selected' : ''; ?>>All Time</option>
        <option value="today" <?php echo ($date_range == 'today') ? 'selected' : ''; ?>>Today</option>
        <option value="yesterday" <?php echo ($date_range == 'yesterday') ? 'selected' : ''; ?>>Yesterday</option>
        <option value="this_week" <?php echo ($date_range == 'this_week') ? 'selected' : ''; ?>>This week</option>
        <option value="this_month" <?php echo ($date_range == 'this_month') ? 'selected' : ''; ?>>This month</option>
        <option value="custom" <?php echo ($date_range == 'custom') ? 'selected' : ''; ?>>Custom range</option>
      </select>
    </div>
    <div class="col-md-6 mb-3" id="custom_range" style="display:<?php echo ($date_range == 'custom') ? 'block' : 'none'; ?>;">
      <div class="row">
        <div class="col-md-6">
          <label for="start_date">Start date:</label>
          <input class="form-control" type="date" name="start_date" id="start_date" value="<?php echo isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : ''; ?>">
        </div>
        <div class="col-md-6">
          <label for="end_date">End date:</label>
          <input class="form-control" type="date" name="end_date" id="end_date" value="<?php echo isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : ''; ?>">
        </div>
      </div>
    </div>
  </div>
  
  <div class="row">
    <div class="col-md-12">
      <input type="submit" name="filter" class="form-control btn btn-primary" value="Filter">
    </div>
  </div>
</form>
<div class="row mt-3  align-items-end">
    <div class="col-md-4">
        <div class="form-group mb-0">
            <label for="purchase_at">Purchase At (%)</label>
            <input type="number" id="purchase_at" class="form-control" name="purchase_at" min="0" max="100" value="100">
        </div>
    </div>
    <div class="col-md-2 mt-3 mb-3">
        <button id="exportExcel" class="btn btn-success">Export to Purchase @</button>
    </div>
    <div class="col-md-6">
    <div class="shadow card form-group mb-0">
    <div class="card-body">
        <h5 class="card-title">Total Itemised Sales</h5>
        <h3 id="totalSales">₹0</h3>
    </div>
    </div>
    
</div>
</div>

<?php


if(isset($_GET['filter'])) {
    $where_clauses = ["inv.business_id = $b_id", "inv.is_completed = 1"];
    $where_clauses[] = "inv.type = 'normal'";

    if($price_min !== '') {
        $where_clauses[] = "i.price_of_one >= '$price_min'";
    }
    if($price_max !== '') {
        $where_clauses[] = "i.price_of_one <= '$price_max'";
    }
    if($location_id !== '') {
        $where_clauses[] = "inv.location_id = '$location_id'";
    }
    if($gst_rate !== '') {
        $where_clauses[] = "i.gst_rate = '$gst_rate'";
    }

    switch ($date_range) {
        case "today":
            $where_clauses[] = "DATE(inv.invoice_date) = CURDATE()";
            break;
        case "yesterday":
            $where_clauses[] = "DATE(inv.invoice_date) = CURDATE() - INTERVAL 1 DAY";
            break;
        case "this_week":
            $where_clauses[] = "YEARWEEK(inv.invoice_date) = YEARWEEK(CURDATE())";
            break;
        case "this_month":
            $where_clauses[] = "YEAR(inv.invoice_date) = YEAR(CURDATE()) AND MONTH(inv.invoice_date) = MONTH(CURDATE())";
            break;
        case "custom":
            $start_date = $_GET['start_date'];
            $end_date = $_GET['end_date'];
            $where_clauses[] = "DATE(inv.invoice_date) BETWEEN '$start_date' AND '$end_date'";
            break;
    }

    $where_clause = implode(" AND ", $where_clauses);
    $sql = "SELECT p.name AS product_name, i.price_of_one, i.gst_rate, SUM(i.quantity) AS total_quantity, SUM(i.price_of_all) AS total_sales
            FROM items i
            JOIN products p ON i.product_id = p.id
            JOIN invoices inv ON i.invoice_id = inv.id
            WHERE $where_clause
            GROUP BY i.product_id, i.price_of_one, i.gst_rate
            ORDER BY i.price_of_one";
} else {
    $sql = "SELECT p.name AS product_name, i.price_of_one, i.gst_rate, SUM(i.quantity) AS total_quantity, SUM(i.price_of_all) AS total_sales
            FROM items i
            JOIN products p ON i.product_id = p.id
            JOIN invoices inv ON i.invoice_id = inv.id
            WHERE inv.business_id = '$b_id' AND inv.is_completed = 1 AND inv.type = 'normal'
            GROUP BY i.product_id, i.price_of_one, i.gst_rate
            ORDER BY i.price_of_one";
}
// echo $sql;
?>
                                    
                                    <!-- Add export to Excel button if needed -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- Add a chart container -->
                        <!-- <div id="itemisedSalesChartContainer" style="height: 500px; width: 100%;"></div> -->
                        
                        <div class="card shadow eq-card">
                            
                            <div class="card-header">
                            
                                <strong class="card-title">Itemised Sales</strong>
                            </div>
                           
                            <div class="card-body">
                                <table class="table datatables" id="dataTable-1">
                                    <thead>
                                        <tr>
                                            <th>Product Name</th>
                                            <th>GST Rate</th>
                                            <th>Price per Item</th>
                                            <th>Total Quantity Sold</th>
                                            <th>Total</th>
                                           
                                        </tr>
                                    </thead>
                                    <tbody>
    <?php
    $results = $connect->query($sql);
$dataPoints = array();
$totalSales = 0;
while($row = $results->fetch_assoc()) {
    $dataPoints[] = array(
        "label" => $row['product_name'] . " (₹" . $row['price_of_one'] . ")",
        "y" => $row['total_quantity']
    );
    $totalSales += $row['price_of_one'] * $row['total_quantity'];
    ?>
    <tr>
        <td><?php echo $row['product_name']; ?></td>
        <td class="gst-rate"><strong><?php 
            if (isset($row['gst_rate']) && $row['gst_rate'] !== null) {
                // Convert decimal to percentage (0.05 -> 5, 0.18 -> 18)
                $gst_display = $row['gst_rate'];
                if ($gst_display < 1) {
                    $gst_display = $gst_display * 100;
                }
                echo round($gst_display) . '%';
            } else {
                echo 'N/A';
            }
        ?></strong></td>
        <td class="price-per-item" data-original="<?php echo $row['price_of_one']; ?>">₹<?php echo $row['price_of_one']; ?></td>
        <td><?php echo $row['total_quantity']; ?></td>
        <td class="total" data-quantity="<?php echo $row['total_quantity']; ?>">₹<?php echo $row['price_of_one'] * $row['total_quantity']; ?></td>
    </tr>
    <?php } ?>
</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.canvasjs.com/canvasjs.min.js"></script>
    <script>
    window.onload = function () {
        var chart = new CanvasJS.Chart("itemisedSalesChartContainer", {
            animationEnabled: true,
            theme: "light2",
            title: {
                text: "Itemised Sales"
            },
            axisY: {
                title: "Quantity Sold"
            },
            data: [{
                type: "column",
                dataPoints: <?php echo json_encode($dataPoints, JSON_NUMERIC_CHECK); ?>
            }]
        });
        chart.render();
    }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.16.9/xlsx.full.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const purchaseAtInput = document.getElementById('purchase_at');
    const table = document.getElementById('dataTable-1');
    const totalSalesElement = document.getElementById('totalSales');
    const exportButton = document.getElementById('exportExcel');

    function updateTable() {
        let purchaseAt = parseFloat(purchaseAtInput.value) / 100;
        let totalSales = 0;

        table.querySelectorAll('tbody tr').forEach(row => {
            let pricePerItem = row.querySelector('.price-per-item');
            let total = row.querySelector('.total');
            let quantity = parseFloat(total.dataset.quantity);
            let originalPrice = parseFloat(pricePerItem.dataset.original);
            
            let adjustedPrice = originalPrice * purchaseAt;
            let adjustedTotal = adjustedPrice * quantity;

            pricePerItem.textContent = '₹' + adjustedPrice.toFixed(2);
            total.textContent = '₹' + adjustedTotal.toFixed(2);

            totalSales += adjustedTotal;
        });

        totalSalesElement.textContent = '₹' + totalSales.toFixed(2);
    }

    purchaseAtInput.addEventListener('input', updateTable);

    exportButton.addEventListener('click', function() {
        let wb = XLSX.utils.table_to_book(table, {sheet: "Itemised Sales"});
        XLSX.writeFile(wb, 'itemised_sales.xlsx');
    });

    // Initial update
    updateTable();
});
</script>
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
</body>