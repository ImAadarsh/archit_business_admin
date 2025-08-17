<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Art Category ID is required.";
    header("Location: art-category-view.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch art category details
$sql = "SELECT pc.*, c.name as category_name 
        FROM product_category pc 
        LEFT JOIN categories c ON pc.category_id = c.id 
        WHERE pc.id = ? AND c.business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Art Category not found or you don't have permission to edit it.";
    header("Location: art-category-view.php");
    exit();
}

$art_category = $result->fetch_assoc();
$stmt->close();
?>

<body class="vertical  light  ">
    <div class="wrapper">
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="card shadow mb-4">
                    <a href="art-category-view.php">
                        <button type="button" class="btn btn-primary">Back to Art Categories</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Art Category</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-art-category.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $art_category['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $business_id; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="name">Art Category Name</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Art Category Name" name="name" value="<?php echo htmlspecialchars($art_category['name']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="category_id">Product Type</label>
                                        <select id="category_id" class="form-control" name="category_id" required>
                                            <option value="">Choose Product Type</option>
                                            <?php
                                            $cat_sql = "SELECT id, name FROM categories WHERE business_id = $business_id ORDER BY name";
                                            $cat_results = $connect->query($cat_sql);
                                            while($cat = $cat_results->fetch_assoc()) {
                                                $selected = ($cat['id'] == $art_category['category_id']) ? 'selected' : '';
                                                echo '<option value="' . $cat['id'] . '" ' . $selected . '>' . htmlspecialchars($cat['name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Art Category">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?> 