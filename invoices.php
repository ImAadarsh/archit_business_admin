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
                                <form role="form" action="invoices.php" method="GET" enctype="multipart/form-data">
    <div class="form-group mb-3">
        <label for="invoice_type">Invoice Type</label>
        <select id="invoice_type" class="form-control" name="invoice_type">
            <option value="">All</option>
            <option value="normal">Normal</option>
            <option value="performa">Performa</option>
        </select>
    </div>
    <div class="form-group mb-3">
        <label for="invoice_type">Business Location</label>
        <select id="invoice_type" class="form-control" name="invoice_type">
            <option value="">All</option>
            <option value="normal">Normal</option>
            <option value="performa">Performa</option>
        </select>
    </div>
    <div class="form-group mb-3">
        <label for="amount_min">Minimum Amount</label>
        <input type="number" id="amount_min" class="form-control" name="amount_min">
    </div>
    <div class="form-group mb-3">
        <label for="amount_max">Maximum Amount</label>
        <input type="number" id="amount_max" class="form-control" name="amount_max">
    </div>
    <div class="form-group mb-3">
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
    <div id="custom_range" style="display:none;">
        <label for="start_date">Start date:</label>
        <input class="form-control" type="date" name="start_date" id="start_date">
        <label for="end_date">End date:</label>
        <input class="form-control" type="date" name="end_date" id="end_date">
    </div>
    <div class="form-group mb-3">
        <input type="submit" name="filter" id="example-placeholder" class="btn btn-primary" value="Filter">
    </div>
</form>
                                    
                                    <?php
                                    $b_id = $_SESSION['business_id'];
                                    
                                    if(isset($_GET['filter'])){
                                        $invoice_type = $_GET['invoice_type'];
                                        $amount_min = $_GET['amount_min'];
                                        $amount_max = $_GET['amount_max'];
                                        $date_range = $_GET['date_range'];
                                    
                                        $where_clauses = ["business_id = $b_id", "is_completed = 1"];
                                    
                                        if($invoice_type !== '') {
                                            $where_clauses[] = "type = '$invoice_type'";
                                        }
                                        if($amount_min !== '') {
                                            $where_clauses[] = "total_amount >= '$amount_min'";
                                        }
                                        if($amount_max !== '') {
                                            $where_clauses[] = "total_amount <= '$amount_max'";
                                        }
                                        if($location_id !== '') {
                                            $where_clauses[] = "location_id <= '$location_id'";
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
                                        $sql = "SELECT i.*, l.location_name, b.business_name 
                                               FROM invoices i 
                                               LEFT JOIN locations l ON i.location_id = l.id 
                                               LEFT JOIN businessses b ON i.business_id = b.id 
                                               WHERE $where_clause";
                                    } else {
                                        $sql = "SELECT i.*, l.location_name, b.business_name 
                                               FROM invoices i 
                                               LEFT JOIN locations l ON i.location_id = l.id 
                                               LEFT JOIN businessses b ON i.business_id = b.id 
                                               WHERE i.business_id = '$b_id' AND i.is_completed = 1";
                                    }
                                    ?>
                                    <form action="invoices-excel.php" method="GET">
                                        <div class="form-group mb-3">
                                            <input hidden value="<?php echo $sql; ?>" name="query">
                                            <input type="submit" name="export" id="example-placeholder" class="btn btn-primary" value="Export to Excel">
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div id="expenseChartContainer" style="height: 500px; width: 100%;"></div>
                        <div id="mobileChartMessage" style="display: none;">
                            <p>The expense chart is not available on mobile devices. Please view on a larger screen to see the chart.</p>
                        </div>
                        <div class="card shadow eq-card">
                            <div class="card-header">
                                <strong class="card-title">Expenses</strong>
                            </div>
                            <div class="card-body">
                            <table class="table datatables" id="dataTable-1">
    <thead>
        <tr>
            <th>ID</th>
            <th>Serial No</th>
            <th>Customer Name</th>
            <th>Type</th>
            <th>Business</th>
            <th>Location</th>
            <th>Total Amount</th>
            <th>Invoice Date</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $results = $connect->query($sql);
        $dataPoints = array();
        $temp = 0;
        while($final = $results->fetch_assoc()){
            $temp = $temp+1;
            $date = strtotime($final['invoice_date']);
            $formatted_date = date('Y, n, j', $date);
            $dataPoints[] = "{ x: new Date($formatted_date), y: {$final['total_amount']} }";
        ?>
        <tr>
            <td><?php echo $temp?></td>
            <td>
                <div class="d-flex align-items-center">
                    <span class="serial-no"><?php echo (!empty($final['serial_no']) && strtolower($final['serial_no']) !== 'null') ? ($final['serial_no']) : 'N/A';?></span>
                    <button class="btn btn-sm btn-link edit-serial-btn" data-invoice-id="<?php echo $final['id']?>" data-serial-no="<?php echo $final['serial_no']?>">
                        <i class="fe fe-edit-2"></i>
                    </button>
                </div>
            </td>
            <td><?php echo $final['name']?></td>
            <td>
                <?php echo (!empty($final['type']) && strtolower($final['type']) !== 'null') ? ucfirst($final['type']) : 'N/A'; ?>
            </td>
            <td><?php echo $final['business_name'] ?? 'N/A' ?></td>
            <td><?php echo $final['location_name'] ?? 'N/A' ?></td>
            <td><?php echo $final['total_amount']?></td>
            <td><?php echo  date('d M Y | H:i', $date);?></td>
            <td>
                <div class="dropdown">
                    <button class="btn btn-sm dropdown-toggle more-horizontal" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="text-muted sr-only">Action</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="https://invoice.invoicemate.in/invoice.html?invoiceid=<?php echo $final['id']?>">View Invoice</a>
                    </div>
                </div>
            </td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<!-- Edit Serial Number Modal -->
<div class="modal fade" id="editSerialModal" tabindex="-1" role="dialog" aria-labelledby="editSerialModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editSerialModalLabel">Edit Invoice Serial Number</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editSerialForm">
                    <input type="hidden" id="editInvoiceId" name="invoice_id">
                    <div class="form-group">
                        <label for="newSerialNo">New Serial Number</label>
                        <input type="text" class="form-control" id="newSerialNo" name="serial_no" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="saveSerialNo">Save changes</button>
            </div>
        </div>
    </div>
</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <!-- Required Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.canvasjs.com/canvasjs.min.js"></script>
    <?php
    // Convert array to string
    $dataPointsString = implode(",", $dataPoints);
    ?>

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

    $(document).ready(function() {
        // Function to open the edit modal
        function openEditModal(invoiceId, currentSerialNo) {
            $('#editInvoiceId').val(invoiceId);
            $('#newSerialNo').val(currentSerialNo);
            $('#editSerialModal').modal('show');
        }

        // Handle save button click
        $('#saveSerialNo').click(function() {
            var invoiceId = $('#editInvoiceId').val();
            var newSerialNo = $('#newSerialNo').val();

            $.ajax({
                url: 'edit/serial_no.php',
                type: 'POST',
                data: {
                    invoice_id: invoiceId,
                    serial_no: newSerialNo
                },
                success: function(response) {
                    if (response.success) {
                        // Update the serial number in the table without reloading
                        $('button[data-invoice-id="' + invoiceId + '"]').closest('td').find('.serial-no').text(newSerialNo);
                        $('#editSerialModal').modal('hide');
                        // Show success message
                        alert('Serial number updated successfully');
                    } else {
                        alert('Error updating serial number: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error updating serial number. Please try again.');
                }
            });
        });

        // Handle edit button click
        $(document).on('click', '.edit-serial-btn', function(e) {
            e.preventDefault();
            var invoiceId = $(this).data('invoice-id');
            var currentSerialNo = $(this).data('serial-no');
            openEditModal(invoiceId, currentSerialNo);
        });

        // Enhanced chart configuration
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
                    text: "Invoice Analysis"
                },
                subtitles: [{
                    text: "Daily and Cumulative Invoice Amounts"
                }],
                axisX: {
                    valueFormatString: "DD MMM YYYY",
                    crosshair: {
                        enabled: true,
                        snapToDataPoint: true
                    }
                },
                axisY: {
                    title: "Daily Invoice Amount",
                    includeZero: true,
                    crosshair: {
                        enabled: true
                    }
                },
                axisY2: {
                    title: "Cumulative Invoice Amount",
                    includeZero: true
                },
                toolTip:{
                    shared:true,
                    contentFormatter: function(e) {
                        var content = "";
                        for(var i = 0; i < e.entries.length; i++){
                            content += e.entries[i].dataSeries.name + ": " + e.entries[i].dataPoint.y.toFixed(2) + "<br/>";
                        }
                        return content;
                    }
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
                        name: "Daily Invoice Amount",
                        xValueFormatString: "DD MMM YYYY",
                        color: "#1B5F92",
                        dataPoints: dataPoints
                    },
                    {
                        type: "line",
                        showInLegend: true,
                        name: "Cumulative Invoice Amount",
                        markerType: "square",
                        xValueFormatString: "DD MMM YYYY",
                        color: "#F08080",
                        axisYType: "secondary",
                        dataPoints: cumulativeDataPoints
                    }
                ]
            });
            chart.render();
        }
    });
    </script>

    <?php include "admin/footer.php"; ?>
</body>
</html>