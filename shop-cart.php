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
                                <h2 class="h5 page-title">Shop Cart</h2>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card shadow">
                                    <div class="card-body">
                                        <table class="table datatables" id="dataTable-1">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>User</th>
                                                    <th>Product Name</th>
                                                    <th>Quantity</th>
                                                    <th>Date Added</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT c.*, u.name as user_name, p.product_name
                                                        FROM shop_cart c
                                                        LEFT JOIN shop_users u ON c.user_id = u.id
                                                        LEFT JOIN products p ON c.product_id = p.id
                                                        WHERE c.business_id = $b_id 
                                                        ORDER BY c.created_at DESC";
                                                $results = $connect->query($sql);
                                                while ($item = $results->fetch_assoc()) {
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $item['id']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($item['user_name'] ?? 'Guest (' . $item['session_id'] . ')'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $item['quantity']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y H:i', strtotime($item['created_at'])); ?>
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