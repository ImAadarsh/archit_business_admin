<?php
// Include access control at the very top
include 'admin/access_control.php';
include 'admin/header.php';
?>

<body class="vertical light">
    <div class="wrapper">
        <?php
        include 'admin/navbar.php';
        include 'admin/aside.php';
        $b_id = $_SESSION['business_id'];
        ?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-2">
                            <div class="col">
                                <h2 class="h5 page-title">Shop Orders</h2>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card shadow">
                                    <div class="card-body">
                                        <table class="table datatables" id="dataTable-1">
                                            <thead>
                                                <tr>
                                                    <th>Order #</th>
                                                    <th>Customer</th>
                                                    <th>Total (INR)</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT id, order_number, customer_name, total, status, created_at 
                                                        FROM shop_orders 
                                                        WHERE business_id = $b_id 
                                                        ORDER BY created_at DESC";
                                                $results = $connect->query($sql);
                                                while ($order = $results->fetch_assoc()) {
                                                    $statusBadge = 'badge-secondary';
                                                    switch ($order['status']) {
                                                        case 'pending':
                                                            $statusBadge = 'badge-warning';
                                                            break;
                                                        case 'confirmed':
                                                            $statusBadge = 'badge-info';
                                                            break;
                                                        case 'processing':
                                                            $statusBadge = 'badge-primary';
                                                            break;
                                                        case 'shipped':
                                                            $statusBadge = 'badge-info';
                                                            break;
                                                        case 'delivered':
                                                            $statusBadge = 'badge-success';
                                                            break;
                                                        case 'cancelled':
                                                            $statusBadge = 'badge-danger';
                                                            break;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $order['order_number']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo number_format($order['total'], 2); ?>
                                                        </td>
                                                        <td><span class="badge <?php echo $statusBadge; ?>">
                                                                <?php echo ucfirst($order['status']); ?>
                                                            </span></td>
                                                        <td>
                                                            <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                                                        </td>
                                                        <td>
                                                            <a href="shop-order-details.php?id=<?php echo $order['id']; ?>"
                                                                class="btn btn-sm btn-outline-primary">View</a>
                                                        </td>
                                                    </tr>
                                                    <?php
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php include "admin/footer.php"; ?>
</body>

</html>