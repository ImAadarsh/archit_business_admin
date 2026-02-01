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

        // Handle update
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_setting'])) {
            $id = $_POST['setting_id'];
            $value = $_POST['setting_value'];
            $stmt = $connect->prepare("UPDATE shop_setting SET value = ? WHERE id = ? AND business_id = ?");
            $stmt->bind_param("sii", $value, $id, $b_id);
            if ($stmt->execute()) {
                $msg = "Setting updated successfully.";
            } else {
                $error = "Failed to update setting.";
            }
        }
        ?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-4">
                            <div class="col">
                                <h2 class="h3 page-title">Shop Settings</h2>
                                <p class="text-muted">Manage website content and configurations.</p>
                            </div>
                        </div>

                        <?php if (isset($msg))
                            echo "<div class='alert alert-success'>$msg</div>"; ?>
                        <?php if (isset($error))
                            echo "<div class='alert alert-danger'>$error</div>"; ?>

                        <?php
                        $pages = ['home', 'about', 'gallery', 'cart', 'wishlist', 'orders', 'artwork', 'common', 'footer'];
                        foreach ($pages as $p) {
                            $sql = "SELECT * FROM shop_setting WHERE business_id = $b_id AND page = '$p' ORDER BY id ASC";
                            $results = $connect->query($sql);
                            if ($results && $results->num_rows > 0) {
                                ?>
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">
                                            <?php echo ucfirst($p); ?> Page Settings
                                        </strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th width="30%">Key</th>
                                                    <th>Value</th>
                                                    <th width="10%">Type</th>
                                                    <th width="10%">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($setting = $results->fetch_assoc()) { ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars($setting['key']); ?></code></td>
                                                        <form method="POST">
                                                            <td>
                                                                <?php if (strlen($setting['value']) > 100 || $setting['type'] == 'paragraph') { ?>
                                                                    <textarea name="setting_value" class="form-control"
                                                                        rows="3"><?php echo htmlspecialchars($setting['value']); ?></textarea>
                                                                <?php } else { ?>
                                                                    <input type="text" name="setting_value" class="form-control"
                                                                        value="<?php echo htmlspecialchars($setting['value']); ?>">
                                                                <?php } ?>
                                                                <input type="hidden" name="setting_id"
                                                                    value="<?php echo $setting['id']; ?>">
                                                            </td>
                                                            <td><span class="badge badge-secondary">
                                                                    <?php echo $setting['type']; ?>
                                                                </span></td>
                                                            <td>
                                                                <button type="submit" name="update_setting"
                                                                    class="btn btn-sm btn-primary">Save</button>
                                                            </td>
                                                        </form>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php include "admin/footer.php"; ?>
</body>

</html>