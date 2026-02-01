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
                                <h2 class="h5 page-title">Shop Wishlist</h2>
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
                                                    <th>Product Image</th>
                                                    <th>Date Added</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT w.*, u.name as user_name, p.product_name, p.image as p_image
                                                        FROM shop_wishlist w
                                                        LEFT JOIN shop_users u ON w.user_id = u.id
                                                        LEFT JOIN products p ON w.product_id = p.id
                                                        WHERE w.business_id = $b_id 
                                                        ORDER BY w.created_at DESC";
                                                $results = $connect->query($sql);
                                                while ($wish = $results->fetch_assoc()) {
                                                    $imagePath = "https://api.invoicemate.in/storage/app/public/product_images/";
                                                    // Handle full URL vs relative path in product image
                                                    $p_img = $wish['p_image'];
                                                    if (strpos($p_img, 'http') !== 0) {
                                                        $p_img = $imagePath . $p_img;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $wish['id']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($wish['user_name'] ?? 'Guest (' . $wish['session_id'] . ')'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($wish['product_name']); ?>
                                                        </td>
                                                        <td>
                                                            <img src="<?php echo $p_img; ?>" width="60" class="rounded">
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y H:i', strtotime($wish['created_at'])); ?>
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