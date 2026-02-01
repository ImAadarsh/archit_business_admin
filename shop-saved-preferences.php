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
                                <h2 class="h5 page-title">Saved Preferences</h2>
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
                                                    <th>Preference Name</th>
                                                    <th>Room Details</th>
                                                    <th>Style/Color</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sql = "SELECT s.*, u.name as user_name 
                                                        FROM shop_saved_preferences s
                                                        LEFT JOIN shop_users u ON s.user_id = u.id
                                                        ORDER BY s.created_at DESC";
                                                $results = $connect->query($sql);
                                                while ($pref = $results->fetch_assoc()) {
                                                    $styles = json_decode($pref['style_preference'], true);
                                                    $colors = json_decode($pref['color_preference'], true);
                                                    $styleStr = is_array($styles) ? implode(', ', $styles) : $pref['style_preference'];
                                                    $colorStr = is_array($colors) ? implode(', ', $colors) : $pref['color_preference'];
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo $pref['id']; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($pref['user_name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($pref['name']); ?>
                                                        </td>
                                                        <td>
                                                            <small>
                                                                Type:
                                                                <?php echo ucfirst($pref['room_type']); ?><br>
                                                                Size:
                                                                <?php echo ucfirst($pref['room_size']); ?><br>
                                                                Wall:
                                                                <?php echo htmlspecialchars($pref['wall_color']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <small>
                                                                Styles:
                                                                <?php echo htmlspecialchars($styleStr); ?><br>
                                                                Colors:
                                                                <?php echo htmlspecialchars($colorStr); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y', strtotime($pref['created_at'])); ?>
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