<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
include 'controller/EwayBillController.php';

$business_id = $_SESSION['business_id'];
$controller = new EwayBillController($connect);

if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Handle Generation Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_eway'])) {
    $invoice_id = $_POST['invoice_id'];
    $transport_details = [
        'transMode' => $_POST['trans_mode'],
        'transDistance' => $_POST['trans_distance'],
        'vehicleNo' => $_POST['vehicle_no'],
        'transporterId' => $_POST['transporter_id'],
        'transporterName' => $_POST['transporter_name'] ?? '',
    ];

    $response = $controller->generateEwayBill($invoice_id, $transport_details);
    if ($response['status'] === 'success') {
        $success_message = "e-Way Bill generated successfully! Number: " . $response['ewayBillNo'];
    } else {
        $error_message = "Failed to generate e-Way Bill: " . $response['message'];
        if (isset($response['api_http_code'])) {
            $error_message .= " (HTTP " . (int)$response['api_http_code'] . ")";
        }
    }
}

// Handle Transporter Addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transporter'])) {
    $name = $_POST['t_name'];
    $id = $_POST['t_id'];
    $sql = "INSERT INTO transporters (business_id, transporter_name, transporter_id) VALUES (?, ?, ?)";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("iss", $business_id, $name, $id);
    if ($stmt->execute()) {
        $success_message = "Transporter added successfully!";
    } else {
        $error_message = "Error adding transporter: " . $connect->error;
    }
    $stmt->close();
}

// Handle Transporter Deletion
if (isset($_GET['delete_transporter'])) {
    $t_id = $_GET['delete_transporter'];
    $sql = "UPDATE transporters SET is_active = 0 WHERE id = ? AND business_id = ?";
    $stmt = $connect->prepare($sql);
    $stmt->bind_param("ii", $t_id, $business_id);
    if ($stmt->execute()) {
        $success_message = "Transporter removed successfully!";
    }
    $stmt->close();
}

// Handle Configuration Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $api_email = $_POST['api_email'];
    $api_username = $_POST['api_username'];
    $api_password = $_POST['api_password'];
    $gstin = $_POST['api_gstin'];
    $client_id = $_POST['api_client_id'];
    $client_secret = $_POST['api_client_secret'];

    $check_sql = "SELECT id FROM eway_bill_settings WHERE business_id = ?";
    $stmt = $connect->prepare($check_sql);
    $stmt->bind_param("i", $business_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows > 0) {
        $sql = "UPDATE eway_bill_settings SET api_email=?, api_username=?, api_password=?, gstin=?, client_id=?, client_secret=? WHERE business_id=?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("ssssssi", $api_email, $api_username, $api_password, $gstin, $client_id, $client_secret, $business_id);
    } else {
        $sql = "INSERT INTO eway_bill_settings (business_id, api_email, api_username, api_password, gstin, client_id, client_secret) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("issssss", $business_id, $api_email, $api_username, $api_password, $gstin, $client_id, $client_secret);
    }

    if ($stmt->execute()) {
        $success_message = "Configuration saved successfully!";
    } else {
        $error_message = "Error saving configuration: " . $connect->error;
    }
    $stmt->close();
}

// Fetch current configuration
$settings = $controller->getEwayBillSettings($business_id);

// Fetch generated e-Way bills
$eway_sql = "SELECT ew.*, i.serial_no as invoice_no, i.name as customer_name, i.total_amount
             FROM eway_bills ew
             JOIN invoices i ON ew.invoice_id = i.id
             WHERE ew.business_id = ?
             ORDER BY ew.created_at DESC";
$stmt = $connect->prepare($eway_sql);
if (!$stmt) {
    die("Error preparing statement (eway_sql): " . $connect->error);
}
$stmt->bind_param("i", $business_id);
$stmt->execute();
$eway_bills = $stmt->get_result();
$stmt->close();

// Fetch invoices eligible for e-Way (Completed but no e-Way bill yet)
$eligible_sql = "SELECT i.* FROM invoices i
                 LEFT JOIN eway_bills ew ON i.id = ew.invoice_id AND ew.status = 'generated'
                 WHERE i.business_id = ? AND i.is_completed = 1 AND ew.id IS NULL
                 ORDER BY i.invoice_date DESC";
$stmt = $connect->prepare($eligible_sql);
if (!$stmt) {
    die("Error preparing statement (eligible_sql): " . $connect->error);
}
$stmt->bind_param("i", $business_id);
$stmt->execute();
$eligible_invoices = $stmt->get_result();
$stmt->close();

// Fetch transporters for selection and management
$transporters_res = $controller->getTransporters($business_id);
$transporters = [];
while ($t = $transporters_res->fetch_assoc()) {
    $transporters[] = $t;
}
?>

<body class="vertical light">
    <div class="wrapper">
        <?php
        include 'admin/navbar.php';
        include 'admin/aside.php';
        ?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-4">
                            <div class="col">
                                <h2 class="h3 page-title">e-Way Bill Management</h2>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-outline-secondary" type="button" data-toggle="collapse"
                                    data-target="#configSection">
                                    <i class="fe fe-settings"></i> API Configuration
                                </button>
                            </div>
                        </div>

                        <!-- Configuration Section -->
                        <div class="collapse <?php echo !$settings ? 'show' : ''; ?> mb-4" id="configSection">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">API Credentials (Business-wise)</strong>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row">
                                        <div class="form-group col-md-4">
                                            <label>API Email</label>
                                            <input type="email" class="form-control" name="api_email"
                                                value="<?php echo $settings['api_email'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>API Username</label>
                                            <input type="text" class="form-control" name="api_username"
                                                value="<?php echo $settings['api_username'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>API Password</label>
                                            <input type="password" class="form-control" name="api_password"
                                                value="<?php echo $settings['api_password'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>GSTIN</label>
                                            <input type="text" class="form-control" name="api_gstin"
                                                value="<?php echo $settings['gstin'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Client ID</label>
                                            <input type="text" class="form-control" name="api_client_id"
                                                value="<?php echo $settings['client_id'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Client Secret</label>
                                            <input type="text" class="form-control" name="api_client_secret"
                                                value="<?php echo $settings['client_secret'] ?? ''; ?>" required>
                                        </div>
                                        <div class="col-12 text-right">
                                            <button type="submit" name="save_config" class="btn btn-primary">Save
                                                Configuration</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo $success_message; ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <div><?php echo $error_message; ?></div>
                                <?php if (isset($response) && (!empty($response['api_response']) || !empty($response['api_decoded']))): ?>
                                    <details class="mt-2 mb-0">
                                        <summary class="small">API response</summary>
                                        <pre class="small bg-dark text-light p-2 rounded mt-1 mb-0" style="max-height:200px;overflow:auto;"><?php
                                            if (!empty($response['api_decoded'])) {
                                                echo htmlspecialchars(json_encode($response['api_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                            } else {
                                                echo htmlspecialchars($response['api_response']);
                                            }
                                        ?></pre>
                                    </details>
                                <?php endif; ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <!-- Recently Generated List -->
                            <div class="col-md-12">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">Generated e-Way Bills</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover datatables">
                                            <thead>
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Customer</th>
                                                    <th>e-Way Bill No</th>
                                                    <th>Generated Date</th>
                                                    <th>Valid Until</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($row = $eway_bills->fetch_assoc()): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $row['invoice_no']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $row['customer_name']; ?>
                                                        </td>
                                                        <td><strong>
                                                                <?php echo $row['eway_bill_no']; ?>
                                                            </strong></td>
                                                        <td>
                                                            <?php echo date('d-M Y H:i', strtotime($row['eway_bill_date'])); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('d-M Y H:i', strtotime($row['valid_until'])); ?>
                                                        </td>
                                                        <td>
                                                            <span
                                                                class="badge badge-<?php echo $row['status'] == 'generated' ? 'success' : 'warning'; ?>">
                                                                <?php echo ucfirst($row['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary"><i
                                                                    class="fe fe-eye"></i> View</button>
                                                            <button class="btn btn-sm btn-outline-danger"><i
                                                                    class="fe fe-x-circle"></i> Cancel</button>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Transporter Management Section -->
                            <div class="col-md-12">
                                <div class="card shadow mb-4">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <strong class="card-title">Transporter Directory</strong>
                                        <button class="btn btn-sm btn-primary" data-toggle="modal"
                                            data-target="#addTransModal">
                                            <i class="fe fe-plus"></i> Add Transporter
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php foreach ($transporters as $t): ?>
                                                <div class="col-md-3 mb-3">
                                                    <div class="border rounded p-3 bg-light position-relative">
                                                        <a href="?delete_transporter=<?php echo $t['id']; ?>"
                                                            class="text-danger position-absolute"
                                                            style="top:5px; right:10px;"
                                                            onclick="return confirm('Remove this transporter?')">
                                                            <i class="fe fe-trash-2"></i>
                                                        </a>
                                                        <h6 class="mb-1">
                                                            <?php echo htmlspecialchars($t['transporter_name']); ?>
                                                        </h6>
                                                        <p class="text-muted small mb-0">ID:
                                                            <?php echo htmlspecialchars($t['transporter_id']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($transporters)): ?>
                                                <div class="col-12 text-center py-3">
                                                    <p class="text-muted">No transporters saved yet.</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Transporter Modal -->
                            <div class="modal fade" id="addTransModal" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Add New Transporter</h5>
                                                <button type="button" class="close"
                                                    data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group">
                                                    <label>Transporter Name</label>
                                                    <input type="text" class="form-control" name="t_name" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Transporter GSTIN/TRANSIN</label>
                                                    <input type="text" class="form-control" name="t_id"
                                                        placeholder="15 Digit ID" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary"
                                                    data-dismiss="modal">Close</button>
                                                <button type="submit" name="add_transporter"
                                                    class="btn btn-primary">Save
                                                    Transporter</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Pending Invoices Section -->
                            <div class="col-md-12">
                                <div class="card shadow">
                                    <div class="card-header">
                                        <strong class="card-title">Create New e-Way Bill</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Date</th>
                                                    <th>Customer</th>
                                                    <th>Amount</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($row = $eligible_invoices->fetch_assoc()): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $row['serial_no']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('d-M Y', strtotime($row['invoice_date'])); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $row['name']; ?>
                                                        </td>
                                                        <td>₹
                                                            <?php echo number_format($row['total_amount'], 2); ?>
                                                        </td>
                                                        <td>
                                                            <a href="eway-bill-generate.php?invoice_id=<?php echo (int)$row['id']; ?>"
                                                                class="btn btn-primary btn-sm">
                                                                <i class="fe fe-plus"></i> Generate (Full Form)
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- .row -->
                    </div> <!-- .col-12 -->
                </div> <!-- .row -->
            </div> <!-- .container-fluid -->
        </main>

        <?php include "admin/footer.php"; ?>
    </div>
</body>

</html>