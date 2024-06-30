<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
                                <form role="form" action="purchase.php" method="GET" enctype="multipart/form-data">
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="form-group">
                <label for="invoice_type">Invoice Type</label>
                <select id="invoice_type" class="form-control" name="invoice_type">
                    <option value="">Choose Invoice Type</option>
                    <option value="normal">Normal</option>
                    <option value="performa">Performa</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="payment_type">Payment Type</label>
                <select id="payment_type" class="form-control" name="payment_type">
                    <option value="">All</option>
                    <option value="cash">Cash</option>
                    <option value="online">Online</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="amount_min">Minimum Amount</label>
                <input type="number" id="amount_min" class="form-control" name="amount_min">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="amount_max">Maximum Amount</label>
                <input type="number" id="amount_max" class="form-control" name="amount_max">
            </div>
        </div>
    </div>

    <div class="row mb-3">
        
        <div class="col-md-3">
            <div class="form-group">
                <label for="date_range">Date Range</label>
                <select onchange="showHideCustomRange()" name="date_range" id="date_range" class="form-control">
                    <!-- options here -->
                    <option value="all">All Time</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This week</option>
                    <option value="this_month">This month</option>
                    <option value="custom">Custom range</option>
                </select>
            </div>
        </div>
        <div class="col-md-3" id="start_date_col" style="display:none;">
            <div class="form-group">
                <label for="start_date">Start date:</label>
                <input class="form-control" type="date" name="start_date" id="start_date">
            </div>
        </div>
        <div class="col-md-3" id="end_date_col" style="display:none;">
            <div class="form-group">
                <label for="end_date">End date:</label>
                <input class="form-control" type="date" name="end_date" id="end_date">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>&nbsp;</label>
                <input type="submit" name="filter" id="example-placeholder" class="btn btn-primary form-control" value="Filter">
            </div>
        </div>
    </div>
</form>
                                    
                                    <?php
                            $b_id = $_SESSION['business_id'];

if(isset($_GET['filter'])) {
    $invoice_type = $_GET['invoice_type'];
    $payment_type = $_GET['payment_type'];
    $amount_min = $_GET['amount_min'];
    $amount_max = $_GET['amount_max'];
    $date_range = $_GET['date_range'];

    $where_clauses = ["business_id = $b_id", "is_completed = 1"];

    if($invoice_type !== '') {
        $where_clauses[] = "type = '$invoice_type'";
    }
    if($payment_type !== '') {
        $where_clauses[] = "payment_mode = '$payment_type'";
    }
    if($amount_min !== '') {
        $where_clauses[] = "total_amount >= '$amount_min'";
    }
    if($amount_max !== '') {
        $where_clauses[] = "total_amount <= '$amount_max'";
    }

    switch ($date_range) {
        case "today":
            $where_clauses[] = "DATE(invoice_date) = CURDATE()";
            break;
        case "yesterday":
            $where_clauses[] = "DATE(invoice_date) = CURDATE() - INTERVAL 1 DAY";
            break;
        case "this_week":
            $where_clauses[] = "YEARWEEK(invoice_date) = YEARWEEK(CURDATE())";
            break;
        case "this_month":
            $where_clauses[] = "YEAR(invoice_date) = YEAR(CURDATE()) AND MONTH(invoice_date) = MONTH(CURDATE())";
            break;
        case "custom":
            $start_date = $_GET['start_date'];
            $end_date = $_GET['end_date'];
            $where_clauses[] = "DATE(invoice_date) BETWEEN '$start_date' AND '$end_date'";
            break;
    }

    $where_clause = implode(" AND ", $where_clauses);
    $sql = "SELECT * FROM invoices WHERE $where_clause";
} else {
    $sql = "SELECT * FROM invoices WHERE business_id = '$b_id' AND is_completed = 1";
}
?>
                                    <div class="row">
    <div class="col-md-4">
        <form action="purchase-excel.php" method="GET">
            <div class="form-group">
                <input hidden value="<?php echo $sql; ?>" name="query">
                <input type="submit" name="export" id="example-placeholder" class="btn btn-primary form-control" value="Export to Excel">
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form action="https://invoice.architartgallery.in/invoices.html" method="GET">
            <div class="form-group">
                <input type="hidden" name="business_id" value="<?php echo $b_id; ?>">
                <input type="hidden" name="type" value="<?php echo isset($_GET['invoice_type']) ? $_GET['invoice_type'] : ''; ?>">
                <input type="hidden" name="payment_mode" value="<?php echo isset($_GET['payment_type']) ? $_GET['payment_type'] : ''; ?>">
                <input type="hidden" name="min_amount" value="<?php echo isset($_GET['amount_min']) ? $_GET['amount_min'] : ''; ?>">
                <input type="hidden" name="max_amount" value="<?php echo isset($_GET['amount_max']) ? $_GET['amount_max'] : ''; ?>">
                <?php
                if (isset($_GET['date_range'])) {
                    switch ($_GET['date_range']) {
                        case 'today':
                            echo '<input type="hidden" name="start_date" value="' . date('Y-m-d') . '">';
                            echo '<input type="hidden" name="end_date" value="' . date('Y-m-d') . '">';
                            break;
                        case 'yesterday':
                            echo '<input type="hidden" name="start_date" value="' . date('Y-m-d', strtotime('-1 day')) . '">';
                            echo '<input type="hidden" name="end_date" value="' . date('Y-m-d', strtotime('-1 day')) . '">';
                            break;
                        case 'this_week':
                            echo '<input type="hidden" name="start_date" value="' . date('Y-m-d', strtotime('this week monday')) . '">';
                            echo '<input type="hidden" name="end_date" value="' . date('Y-m-d', strtotime('this sunday')) . '">';
                            break;
                        case 'this_month':
                            echo '<input type="hidden" name="start_date" value="' . date('Y-m-01') . '">';
                            echo '<input type="hidden" name="end_date" value="' . date('Y-m-t') . '">';
                            break;
                        case 'custom':
                            echo '<input type="hidden" name="start_date" value="' . (isset($_GET['start_date']) ? $_GET['start_date'] : '') . '">';
                            echo '<input type="hidden" name="end_date" value="' . (isset($_GET['end_date']) ? $_GET['end_date'] : '') . '">';
                            break;
                    }
                }
                ?>
                <input type="submit" name="bulk_download" id="bulk-download" class="btn btn-primary form-control" value="Bulk Invoice Downloader">
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form action="https://invoice.architartgallery.in/selected.html" method="GET" id="selectedForm">
            <div class="form-group">
                <input type="hidden" name="ids" id="selectedIds">
                <input type="submit" value="Download Selected Invoice" id="selectedOnlineBtn" class="btn btn-primary form-control">
            </div>
        </form>
    </div>
</div>
                                </div>
                            </div>
                        </div>
                        <!-- <div id="expenseChartContainer" style="height: 500px; width: 100%;"></div>
                        <div id="mobileChartMessage" style="display: none;">
                            <p>The expense chart is not available on mobile devices. Please view on a larger screen to see the chart.</p>
                        </div>
                        <br> -->
                        <div class="row mb-4 mt-25">
    <div class="col-md-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Total Actual Sale Amount
                        </div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800" id="totalExpenses">₹0</div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Total GST Amount
                        </div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800" id="totalExpenses1">₹0</div>
                    </div>
                   
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                            Total Amount
                        </div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800" id="totalExpenses2">₹0</div>
                    </div>
                   
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-fail text-uppercase mb-1">
                            Total Cash Sale (Actual)
                        </div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800" id="totalExpenses3">₹0</div>
                    </div>
                   
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Total Online Sale (Actual)
                        </div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800" id="totalExpenses4">₹0</div>
                    </div>
                   
                </div>
            </div>
        </div>
    </div>
</div>
                       
                        <?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ensure database connection is established
if (!isset($connect) || !$connect) {
    die("Database connection not established. Check your connection script.");
}

// Your existing query execution
$results = $connect->query($sql);
if (!$results) {
    die("Query failed: " . $connect->error);
}

echo "<!-- Debug: Query executed successfully. Number of rows: " . $results->num_rows . " -->";

$dataPoints = array();
$temp = 0;
$t_amount = 0;
$gst = 0;
$final_total = 0;
$cash = 0;
$online = 0;
?>

<div class="card shadow eq-card">
    <div class="card-header">
        <strong class="card-title">Expenses</strong>
    </div>
    
    <div class="card-body">
    <table class="table datatables" id="dataTable-1" style="width:100%">
    <thead>
        <tr>
            <th><input type="checkbox" id="checkAll"></th>
            <th>ID</th>
            <th>Customer Name</th>
            <th>Type</th>
            <th>Payment Mode</th>
            <th>Sale Amount</th>
            <th>GST Amount</th>
            <th>Total Amount</th>
            <th>Invoice Date</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php
        echo "<!-- Debug: Starting loop -->\n";
        while ($final = $results->fetch_assoc()) {
            $temp++;
                    echo "<!-- Debug: Processing row $temp -->\n";
                    
                    $temp_gst = (isset($final['total_dgst']) ? floatval($final['total_dgst']) : 0) +
                                (isset($final['total_cgst']) ? floatval($final['total_cgst']) : 0) +
                                (isset($final['total_igst']) ? floatval($final['total_igst']) : 0);
                    
                    $total_amount = isset($final['total_amount']) ? floatval($final['total_amount']) : 0;
                    $temp_amount_wgst = $total_amount - $temp_gst;
                    $cash = $cash + ($final['payment_mode']=='cash'?$temp_amount_wgst:0);
                    $online = $online + ($final['payment_mode']=='online'?$temp_amount_wgst:0);
                    $t_amount += $temp_amount_wgst;
                    $gst += $temp_gst;
                    $final_total = $final_total + $final['total_amount'];
                    $date = isset($final['invoice_date']) ? strtotime($final['invoice_date']) : time();
                    $formatted_date = date('Y, n, j', $date);
                    $dataPoints[] = "{ x: new Date($formatted_date), y: $total_amount }";

            ?>
            <tr>
                <td><input type="checkbox" class="rowCheckbox" value="<?php echo isset($final['id']) ? htmlspecialchars($final['id']) : ''; ?>"></td>
                <td><?php echo $temp; ?></td>
                <td><?php echo isset($final['name']) ? htmlspecialchars($final['name']) : 'N/A'; ?></td>
                <td><?php echo (!empty($final['type']) && strtolower($final['type']) !== 'null') ? ucfirst(htmlspecialchars($final['type'])) : 'N/A'; ?></td>
                <td><?php echo isset($final['payment_mode']) ? ucfirst(htmlspecialchars($final['payment_mode'])) : 'N/A'; ?></td>
                <td><?php echo number_format($temp_amount_wgst, 2); ?></td>
                <td><?php echo number_format($temp_gst, 2); ?></td>
                <td><?php echo number_format($total_amount, 2); ?></td>
                <td><?php echo date('d M Y', $date); ?></td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-sm dropdown-toggle more-horizontal" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="text-muted sr-only">Action</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="https://invoice.architartgallery.in/invoice.html?invoiceid=<?php echo isset($final['id']) ? htmlspecialchars($final['id']) : ''; ?>">View Invoice</a>
                        </div>
                    </div>
                </td>
            </tr>
        <?php 
            echo "<!-- Debug: Finished processing row $temp -->\n";
        }
        echo "<!-- Debug: Loop completed. Total rows: $temp -->\n";
        if ($temp == 0) {
            echo "<tr><td colspan='10'>No results found</td></tr>";
        }
        ?>
    </tbody>
</table>

    </div>
    
</div>

<script>
console.log("About to initialize DataTable");
$(document).ready(function() {
    $('#dataTable-1').DataTable({
        // Your DataTables options here
    });
});
</script>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.canvasjs.com/canvasjs.min.js"></script>
    <?php
// Convert array to string
$dataPointsString = implode(",", $dataPoints);
?>

<script>
    console.log("Data points:", <?php echo json_encode($dataPoints); ?>);
window.onload = function () {
    if (window.innerWidth > 767) {
    var dataPoints = [<?php echo $dataPointsString; ?>];
    var cumulativeDataPoints = [];
    var cumulativeTotal = 0;

    // Calculate cumulative values
    for (var i = 0; i < dataPoints.length; i++) {
        cumulativeTotal += dataPoints[i].y;
        cumulativeDataPoints.push({
            x: new Date(dataPoints[i].x),
            y: cumulativeTotal
        });
    }

    var chart = new CanvasJS.Chart("expenseChartContainer", {
    animationEnabled: true,
    zoomEnabled: true,
    theme: "light2",
    title: {
        text: "Invoice Amounts Over Time",
        fontFamily: "Arial",
        fontSize: 24
    },
    axisX: {
        valueFormatString: "DD MMM YY",
        labelAngle: -45,
        labelFontSize: 12,
        crosshair: {
            enabled: true,
            snapToDataPoint: true
        }
    },
    axisY: {
        title: "Daily Amount (₹)",
        titleFontSize: 16,
        includeZero: true,
        labelFontSize: 12,
        crosshair: {
            enabled: true
        }
    },
    axisY2: {
        title: "Cumulative Total (₹)",
        titleFontSize: 16,
        includeZero: true,
        labelFontSize: 12
    },
    toolTip: {
        shared: true,
        contentFormatter: function(e) {
            var content = "";
            for (var i = 0; i < e.entries.length; i++) {
                content += "<strong>" + e.entries[i].dataSeries.name + "</strong>: ₹" + e.entries[i].dataPoint.y.toLocaleString() + "<br/>";
            }
            return content;
        }
    },
    legend: {
        cursor: "pointer",
        verticalAlign: "bottom",
        horizontalAlign: "center",
        dockInsidePlotArea: false,
        fontSize: 14
    },
    data: [
        {
        type: "column",
        showInLegend: true,
        legendText: "Daily Amount",
        name: "Daily Amount",
        xValueFormatString: "DD MMM YYYY",
        yValueFormatString: "₹#,##0",
        color: "#1B5F92",
        dataPoints: dataPoints
    },
    {
        type: "line",
        showInLegend: true,
        legendText: "Cumulative Total",
        name: "Cumulative Total",
        markerType: "circle",
        markerSize: 5,
        xValueFormatString: "DD MMM YYYY",
        yValueFormatString: "₹#,##0",
        color: "#F08080",
        axisYType: "secondary",
        dataPoints: cumulativeDataPoints
    }
    ]
});
    chart.render();
}}
</script>

<script>
function showHideCustomRange() {
    var dateRange = document.getElementById("date_range");
    var startDateCol = document.getElementById("start_date_col");
    var endDateCol = document.getElementById("end_date_col");

    if (dateRange.value === "custom") {
        startDateCol.style.display = "block";
        endDateCol.style.display = "block";
    } else {
        startDateCol.style.display = "none";
        endDateCol.style.display = "none";
    }
}

// Call the function on page load to ensure correct initial state
document.addEventListener('DOMContentLoaded', showHideCustomRange);
</script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const totalExpensesElement = document.getElementById('totalExpenses');
    totalExpensesElement.textContent = '₹<?php echo number_format($t_amount, 2); ?>';
    const totalExpensesElement1 = document.getElementById('totalExpenses1');
    totalExpensesElement1.textContent = '₹<?php echo number_format($gst, 2); ?>';
    const totalExpensesElement2 = document.getElementById('totalExpenses2');
    totalExpensesElement2.textContent = '₹<?php echo number_format($final_total, 2); ?>';
    const totalExpensesElement3 = document.getElementById('totalExpenses3');
    totalExpensesElement3.textContent = '₹<?php echo number_format($cash, 2); ?>';
    const totalExpensesElement4 = document.getElementById('totalExpenses4');
    totalExpensesElement4.textContent = '₹<?php echo number_format($online, 2); ?>';
});
</script>
<script>
document.getElementById('checkAll').addEventListener('change', function() {
    var checkboxes = document.getElementsByClassName('rowCheckbox');
    for (var checkbox of checkboxes) {
        checkbox.checked = this.checked;
    }
});

document.getElementById('selectedForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var checkboxes = document.getElementsByClassName('rowCheckbox');
    var selectedIds = [];
    for (var checkbox of checkboxes) {
        if (checkbox.checked) {
            selectedIds.push(checkbox.value);
        }
    }
    if (selectedIds.length > 0) {
        document.getElementById('selectedIds').value = selectedIds.join(',');
        this.submit();
    } else {
        alert('Please select at least one item.');
    }
});
</script>

    <?php include "admin/footer.php"; ?>
</body>