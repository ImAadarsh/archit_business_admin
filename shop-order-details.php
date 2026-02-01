<?php
// Include access control at the very top
include 'admin/access_control.php';
include 'admin/header.php';

if (!isset($_GET['id'])) {
    header("Location: shop-orders.php");
    exit;
}

$orderId = $_GET['id'];
$b_id = $_SESSION['business_id'];

// Fetch order
$orderStmt = $connect->prepare("SELECT * FROM shop_orders WHERE id = ? AND business_id = ?");
$orderStmt->bind_param("ii", $orderId, $b_id);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    echo "Order not found.";
    exit;
}

// Fetch order items
$itemsStmt = $connect->prepare("SELECT * FROM shop_order_items WHERE order_id = ?");
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$items = $itemsStmt->get_result();
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
                        <h2 class="h3 mb-4 page-title">Order #<?php echo $order['order_number']; ?></h2>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">Customer Information</strong>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($order['customer_email']); ?></p>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                                        <hr>
                                        <p><strong>Shipping Address:</strong><br><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                                    </div>
                                </div>
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">Order Summary</strong>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Status:</strong> <?php echo ucfirst($order['status']); ?></p>
                                        <p><strong>Subtotal:</strong> <?php echo number_format($order['subtotal'], 2); ?></p>
                                        <p><strong>Tax:</strong> <?php echo number_format($order['tax'], 2); ?></p>
                                        <p><strong>Total:</strong> <?php echo number_format($order['total'], 2); ?></p>
                                        <p><strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="card shadow">
                                    <div class="card-header">
                                        <strong class="card-title">Order Items</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Image</th>
                                                    <th>Quantity</th>
                                                    <th>Price</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($item = $items->fetch_assoc()) { ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                        <td><img src="<?php echo htmlspecialchars($item['product_image']); ?>" width="50" class="rounded"></td>
                                                        <td><?php echo $item['quantity']; ?></td>
                                                        <td><?php echo number_format($item['unit_price'], 2); ?></td>
                                                        <td><?php echo number_format($item['total_price'], 2); ?></td>
                                                    </tr>
                                                <?php } ?>
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
