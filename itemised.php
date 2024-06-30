<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
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
      <label for="product">Product</label>
      <select id="product" class="form-control" name="product">
        <option value="">All Products</option>
        <?php
          $product_query = "SELECT id, name FROM products";
          $product_result = $connect->query($product_query);
          while($product = $product_result->fetch_assoc()) {
            echo "<option value='".$product['id']."'>".$product['name']."</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-md-3 mb-3">
      <label for="type">Invoice Type</label>
      <select id="type" class="form-control" name="type">
        <option value="">All</option>
        <option value="normal">Normal</option>
        <option value="performa">Performa</option>
      </select>
    </div>
    <div class="col-md-3 mb-3">
      <label for="payment_type">Payment Type</label>
      <select id="payment_type" class="form-control" name="payment_type">
        <option value="">All</option>
        <option value="cash">Cash</option>
        <option value="online">Online</option>
      </select>
    </div>
    <div class="col-md-3 mb-3">
      <label for="price_min">Minimum Price</label>
      <input type="number" id="price_min" class="form-control" name="price_min">
    </div>
  </div>
  
  <div class="row">
    <div class="col-md-3 mb-3">
      <label for="price_max">Maximum Price</label>
      <input type="number" id="price_max" class="form-control" name="price_max">
    </div>
    <div class="col-md-3 mb-3">
      <label for="date_range">Date Range</label>
      <select onchange="showHideCustomRange()" name="date_range" id="date_range" class="form-control">
        <option value="all">All Time</option>
        <option value="today">Today</option>
        <option value="yesterday">Yesterday</option>
        <option value="this_week">This week</option>
        <option value="this_month">This month</option>
        <option value="custom">Custom range</option>
      </select>
    </div>
    <div class="col-md-6 mb-3" id="custom_range" style="display:none;">
      <div class="row">
        <div class="col-md-6">
          <label for="start_date">Start date:</label>
          <input class="form-control" type="date" name="start_date" id="start_date">
        </div>
        <div class="col-md-6">
          <label for="end_date">End date:</label>
          <input class="form-control" type="date" name="end_date" id="end_date">
        </div>
      </div>
    </div>
  </div>
  
  <div class="row">
    <div class="col-md-12">
      <input type="submit" name="filter" class="btn btn-primary" value="Filter">
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
    <div class="col-md-2">
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
$b_id = $_SESSION['business_id'];

if(isset($_GET['filter'])) {
    $product = $_GET['product'];
    $payment_type = $_GET['payment_type'];
    $type = $_GET['type'];
    $price_min = $_GET['price_min'];
    $price_max = $_GET['price_max'];
    $date_range = $_GET['date_range'];

    $where_clauses = ["inv.business_id = $b_id", "inv.is_completed = 1"];

    if($product !== '') {
        $where_clauses[] = "i.product_id = '$product'";
    }
    if($payment_type !== '') {
        $where_clauses[] = "inv.payment_type = '$payment_type'";
    }
    if($type !== '') {
        $where_clauses[] = "inv.type = '$type'";
    }
    if($price_min !== '') {
        $where_clauses[] = "i.price_of_one >= '$price_min'";
    }
    if($price_max !== '') {
        $where_clauses[] = "i.price_of_one <= '$price_max'";
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
    $sql = "SELECT p.name AS product_name, i.price_of_one, SUM(i.quantity) AS total_quantity, SUM(i.price_of_all) AS total_sales
            FROM items i
            JOIN products p ON i.product_id = p.id
            JOIN invoices inv ON i.invoice_id = inv.id
            WHERE $where_clause
            GROUP BY i.product_id, i.price_of_one
            ORDER BY i.price_of_one";
} else {
    $sql = "SELECT p.name AS product_name, i.price_of_one, SUM(i.quantity) AS total_quantity, SUM(i.price_of_all) AS total_sales
            FROM items i
            JOIN products p ON i.product_id = p.id
            JOIN invoices inv ON i.invoice_id = inv.id
            WHERE inv.business_id = '$b_id' AND inv.is_completed = 1
            GROUP BY i.product_id, i.price_of_one
            ORDER BY i.price_of_one";
}
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