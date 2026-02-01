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
                                <h2 class="h5 page-title">AI Wall Images</h2>
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
                                                    <th>Product ID</th>
                                                    <th>Wall Image</th>
                                                    <th>Generated Image</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                // Adjust query to join with shop_users if needed for names
                                                $sql = "SELECT i.*, u.name as user_name 
                                                        FROM shop_ai_wall_images i
                                                        LEFT JOIN shop_users u ON i.user_id = u.id
                                                        WHERE i.business_id = $b_id 
                                                        ORDER BY i.created_at DESC";

                                                // Handle column check: some databases might use 'businesses' vs 'businessses' 
                                                // but our shop tables seem to use 'business_id' based on shop_users
                                                $results = $connect->query($sql);
                                                if (!$results) {
                                                    // Fallback if business_id doesn't exist in the table yet (depending on migration state)
                                                    $sql = "SELECT i.*, u.name as user_name 
                                                            FROM shop_ai_wall_images i
                                                            LEFT JOIN shop_users u ON i.user_id = u.id
                                                            ORDER BY i.created_at DESC";
                                                    $results = $connect->query($sql);
                                                }

                                                while ($img = $results->fetch_assoc()) {
                                                    $imagePath = "https://api.invoicemate.in/storage/app/";
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $img['id']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($img['user_name'] ?? 'Guest'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $img['product_id']; ?>
                                                        </td>
                                                        <td>
                                                            <a href="<?php echo $imagePath . $img['wall_image']; ?>"
                                                                target="_blank">
                                                                <img src="<?php echo $imagePath . $img['wall_image']; ?>"
                                                                    width="60" class="rounded">
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <a href="<?php echo $imagePath . $img['ai_generated_image']; ?>"
                                                                target="_blank">
                                                                <img src="<?php echo $imagePath . $img['ai_generated_image']; ?>"
                                                                    width="60" class="rounded">
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y', strtotime($img['created_at'])); ?>
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