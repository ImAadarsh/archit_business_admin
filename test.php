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
                                <form role="form" action="expense.php" method="GET" enctype="multipart/form-data">
    <div class="row">
        <div class="col-md-2 mb-3">
            <label for="expense_type">Expense Type</label>
            <select id="expense_type" class="form-control" name="expense_type">
                <option value="">All</option>
                <option value="0">Monthly</option>
                <option value="1">Adhoc</option>
            </select>
        </div>
        <div class="col-md-2 mb-3">
            <label for="amount_min">Minimum Amount</label>
            <input type="number" id="amount_min" class="form-control" name="amount_min">
        </div>
        <div class="col-md-2 mb-3">
            <label for="amount_max">Maximum Amount</label>
            <input type="number" id="amount_max" class="form-control" name="amount_max">
        </div>
        <div class="col-md-4 mb-3">
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
        <div class="col-md-2 mb-3">
            <label for="filter">&nbsp;</label>
            <input type="submit" name="filter" id="example-placeholder" class="btn btn-primary form-control" value="Filter">
        </div>
    </div>
    <div class="row" id="custom_range" style="display:none;">
        <div class="col-md-6 mb-3">
            <label for="start_date">Start date:</label>
            <input class="form-control" type="date" name="start_date" id="start_date">
        </div>
        <div class="col-md-6 mb-3">
            <label for="end_date">End date:</label>
            <input class="form-control" type="date" name="end_date" id="end_date">
        </div>
    </div>
</form>
                                    
                                    <?php
                                    $b_id = $_SESSION['business_id'];
                                    
                                    if(isset($_GET['filter'])){
                                        $expense_type = $_GET['expense_type'];
                                        $amount_min = $_GET['amount_min'];
                                        $amount_max = $_GET['amount_max'];
                                        $date_range = $_GET['date_range'];

                                        $where_clauses = ["e.business_id = $b_id"];

                                        if($expense_type !== '') {
                                            $where_clauses[] = "type = $expense_type";
                                        }
                                        if($amount_min !== '') {
                                            $where_clauses[] = "amount >= '$amount_min'";
                                        }
                                        if($amount_max !== '') {
                                            $where_clauses[] = "amount <= '$amount_max'";
                                        }

                                        switch ($date_range) {
                                            case "today":
                                                $where_clauses[] = "DATE(e.created_at) = CURDATE()";
                                                break;
                                            case "yesterday":
                                                $where_clauses[] = "DATE(e.created_at) = CURDATE() - INTERVAL 1 DAY";
                                                break;
                                            case "this_week":
                                                $where_clauses[] = "YEARWEEK(e.created_at) = YEARWEEK(CURDATE())";
                                                break;
                                            case "this_month":
                                                $where_clauses[] = "YEAR(e.created_at) = YEAR(CURDATE()) AND MONTH(e.created_at) = MONTH(CURDATE())";
                                                break;
                                            case "custom":
                                                $start_date = $_GET['start_date'];
                                                $end_date = $_GET['end_date'];
                                                $where_clauses[] = "DATE(e.created_at) BETWEEN '$start_date' AND '$end_date'";
                                                break;
                                        }

                                        $where_clause = implode(" AND ", $where_clauses);
                                        $sql = "SELECT e.*, u.name as user_name, l.location_name 
                                                FROM expenses e
                                                LEFT JOIN users u ON e.user_id = u.id
                                                LEFT JOIN locations l ON e.location_id = l.id
                                                WHERE $where_clause";
                                    } else {
                                        $sql = "SELECT e.*, u.name as user_name, l.location_name 
                                                FROM expenses e
                                                LEFT JOIN users u ON e.user_id = u.id
                                                LEFT JOIN locations l ON e.location_id = l.id
                                                WHERE e.business_id = '$b_id'";
                                    }
                                    ?>
                                    <form action="expenses-excel.php" method="GET" id="exportForm">
    <div class="form-group mb-3">
        <input hidden value="<?php echo $sql; ?>" name="query">
        <input type="hidden" name="selected_rows" id="selected_rows">
        <input type="submit" name="export" id="example-placeholder" class="btn btn-primary" value="Export to Excel">
    </div>
</form>
<form action="expense-pdf.php" method="POST" id="pdfForm">
    <input type="hidden" name="selected_rows" id="pdf_selected_rows">
    <button type="submit" class="btn btn-secondary">Download Selected PDFs</button>
</form>

                                </div>
                            </div>
                        </div>
                        <!-- <div id="expenseChartContainer" style="height: 500px; width: 100%;"></div> -->
                        <div id="mobileChartMessage" style="display: none;">
                            <p>The expense chart is not available on mobile devices. Please view on a larger screen to see the chart.</p>
                        </div>
                        <div class="card shadow eq-card">
                            <div class="card-header">
                            <div class="card shadow mb-4">
    <div class="card-body">
        <h5 class="card-title">Total Expenses</h5>
        <h3 id="totalExpenses">₹0</h3>
    </div>
</div>
                                <strong class="card-title">Expenses</strong>
                            </div>
                            <div class="card-body">
                                <table class="table datatables" id="dataTable-1">
                                <thead>
    <tr>
        <th><input type="checkbox" id="select_all"></th>
        <th>ID</th>
        <th>Name</th>
        <th>Amount</th>
        <th>Type</th>
        <th>Location</th>
        <th>Created By</th>
        <th>Created At</th>
        <th>Action</th>
    </tr>
</thead>
<tbody>
    <?php
    $results = $connect->query($sql);
    $dataPoints = array();
    $totalExpenses = 0; 
    while($final = $results->fetch_assoc()){
        $date = strtotime($final['created_at']);
        $formatted_date = date('Y, n, j', $date);
        $dataPoints[] = "{ x: new Date($formatted_date), y: {$final['amount']} }";
        $totalExpenses += $final['amount'];
        ?>
        <tr>
            <td><input type="checkbox" class="row-select" value="<?php echo $final['id']; ?>"></td>
            <td><?php echo $final['id']?></td>
            <td><?php echo $final['name']?></td>
            <td><?php echo $final['amount']?></td>
            <td><?php echo $final['type'] == 0 ? 'Monthly' : 'Adhoc'?></td>
            <td><?php echo $final['location_name']?></td>
            <td><?php echo $final['user_name']?></td>
            <td><?php echo date('d M Y', $date) ?></td>
            <td>
                <div class="dropdown">
                    <button class="btn btn-sm dropdown-toggle more-horizontal" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="text-muted sr-only">Action</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="<?php echo $uri.$final['file']?>">View Receipt</a>
                    </div>
                </div>
            </td>
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
            text: "Expense Amount Over Time"
        },
        axisX: {
            valueFormatString: "DD MMM YYYY",
            crosshair: {
                enabled: true,
                snapToDataPoint: true
            }
        },
        axisY: {
            title: "Amount",
            includeZero: true,
            crosshair: {
                enabled: true
            }
        },
        axisY2: {
            title: "Cumulative Amount",
            includeZero: true
        },
        toolTip:{
            shared:true
        },
        legend:{
            cursor:"pointer",
            verticalAlign: "bottom",
            horizontalAlign: "left",
            dockInsidePlotArea: true,
        },
        data: [
            {
                type: "column",
                showInLegend: true,
                name: "Daily Expense",
                xValueFormatString: "DD MMM YYYY",
                color: "#1B5F92",
                dataPoints: dataPoints
            },
            {
                type: "line",
                showInLegend: true,
                name: "Cumulative Expense",
                markerType: "square",
                xValueFormatString: "DD MMM YYYY",
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
        var customRange = document.getElementById("custom_range");

        if (dateRange.value === "custom") {
            customRange.style.display = "block";
        } else {
            customRange.style.display = "none";
        }
    }
    </script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const totalExpensesElement = document.getElementById('totalExpenses');
    totalExpensesElement.textContent = '₹<?php echo number_format($totalExpenses, 2); ?>';
});

document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select_all');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const exportForm = document.getElementById('exportForm');
    const pdfForm = document.getElementById('pdfForm');
    const selectedRowsInput = document.getElementById('selected_rows');
    const pdfSelectedRowsInput = document.getElementById('pdf_selected_rows');

    selectAllCheckbox.addEventListener('change', function() {
        rowCheckboxes.forEach(checkbox => {
            checkbox.checked = selectAllCheckbox.checked;
        });
    });

    exportForm.addEventListener('submit', function(event) {
        const selectedRows = Array.from(rowCheckboxes)
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.value);
        selectedRowsInput.value = selectedRows.join(',');
    });

    pdfForm.addEventListener('submit', function(event) {
        const selectedRows = Array.from(rowCheckboxes)
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.value);
        pdfSelectedRowsInput.value = selectedRows.join(',');
    });
});

</script>

    <?php include "admin/footer.php"; ?>
</body>