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
                                <h2 class="h5 page-title">Shop Users</h2>
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
                                                    <th>Name</th>
                                                    <th>Email</th>
                                                    <th>AI Credits</th>
                                                    <th>Last Login</th>
                                                    <th>Joined Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT id, name, email, ai_image_left, last_login_at, created_at 
                                                        FROM shop_users 
                                                        WHERE business_id = $b_id 
                                                        ORDER BY created_at DESC";
                                                $results = $connect->query($sql);
                                                while ($user = $results->fetch_assoc()) {
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $user['id']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($user['name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($user['email']); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $user['ai_image_left']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $user['last_login_at'] ? date('M d, Y H:i', strtotime($user['last_login_at'])) : 'Never'; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
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