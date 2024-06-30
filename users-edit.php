<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

$invoice_id = $_GET['id'];
$b_id = $_SESSION['business_id'];

$sql = "SELECT 
            i.*, 
            a.state, 
            b.gst AS gst_number
        FROM 
            `invoices` i
        LEFT JOIN 
            `addres` a ON i.billing_address_id = a.id
        LEFT JOIN 
            `businessses` b ON i.business_id = b.id
        WHERE 
            i.id = ? AND i.business_id = ?";

$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $invoice_id, $b_id);
$stmt->execute();
$result = $stmt->get_result();
$invoice = $result->fetch_assoc();

if (!$invoice) {
    die("Invoice not found");
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
                        <h2 class="page-title">Edit Invoice</h2>
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <form action="edit/user.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $invoice['id']; ?>">
                                    
                                    <div class="form-group">
                                        <label for="name">Name</label>
                                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($invoice['name']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="customer_type">Customer Type</label>
                                        <input type="text" class="form-control" id="customer_type" name="customer_type" value="<?php echo htmlspecialchars($invoice['customer_type']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="invoice_type">Invoice Type</label>
                                        <input type="text" class="form-control" id="invoice_type" name="invoice_type" value="<?php echo htmlspecialchars($invoice['type']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="mobile_number">Mobile Number</label>
                                        <input type="text" class="form-control" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($invoice['mobile_number']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="state">State</label>
                                        <input type="text" class="form-control" id="state" name="state" value="<?php echo htmlspecialchars($invoice['state']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="doc_no">Document Number</label>
                                        <input type="text" class="form-control" id="doc_no" name="doc_no" value="<?php echo htmlspecialchars($invoice['doc_no']); ?>" required>
                                    </div>
                                    
                                   
                                    
                                    <button type="submit" class="btn btn-primary">Update Invoice</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php include "admin/footer.php"; ?>
</body>