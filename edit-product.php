<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Product ID is required.";
    header("Location: products.php");
    exit();
}

$id = $_GET['id'];
$business_id = $_SESSION['business_id'];

// Fetch product details
$sql = "SELECT * FROM products WHERE id = ? AND business_id = ?";
$stmt = $connect->prepare($sql);
$stmt->bind_param("ii", $id, $business_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Product not found or you don't have permission to edit it.";
    header("Location: products.php");
    exit();
}

$product = $result->fetch_assoc();
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
                    <a href="products.php">
                        <button type="button" class="btn btn-primary">Back to Products</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Edit Product</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_update-product.php" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo $product['business_id']; ?>">
                                    <input type="hidden" name="location_id" value="<?php echo $product['location_id']; ?>">
                                    
                                    <div class="form-group mb-3">
                                        <label for="category_id">Product Type *</label>
                                        <select id="category_id" class="form-control" name="category_id" required>
                                            <option value="">Choose Product Type</option>
                                            <?php
                                            $cat_sql = "SELECT c.id, c.name, c.hsn_code, c.location_id, l.location_name 
                                                       FROM categories c 
                                                       LEFT JOIN locations l ON c.location_id = l.id 
                                                       WHERE c.business_id=$business_id 
                                                       ORDER BY c.name";
                                            $cat_results = $connect->query($cat_sql);
                                            while($cat = $cat_results->fetch_assoc()) {
                                                $selected = ($cat['id'] == $product['category_id']) ? 'selected' : '';
                                                echo '<option value="' . $cat['id'] . '" 
                                                              data-hsn="' . htmlspecialchars($cat['hsn_code'] ?? '') . '" 
                                                              data-location="' . (int)$cat['location_id'] . '" ' . $selected . '>' 
                                                              . htmlspecialchars($cat['name']) . ' (' . htmlspecialchars($cat['location_name']) . ')</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code *</label>
                                        <input required type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code" value="<?php echo htmlspecialchars($product['hsn_code']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="art_category_id">Product Category *</label>
                                        <select id="art_category_id" class="form-control" name="art_category_id" required>
                                            <option value="">Loading Product Categories...</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="name">Product Name *</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Product Name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="price">Price *</label>
                                        <input required type="number" step="0.01" id="price" class="form-control"
                                            placeholder="Price" name="price" value="<?php echo $product['price']; ?>">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="item_code">itemCode</label>
                                        <input type="text" id="item_code" class="form-control" placeholder="itemCode" name="item_code" value="<?php echo htmlspecialchars($product['item_code'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="height">Height (inches)</label>
                                            <input type="number" step="0.01" id="height" class="form-control" placeholder="Height" name="height" value="<?php echo $product['height']; ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="width">Width (inches)</label>
                                            <input type="number" step="0.01" id="width" class="form-control" placeholder="Width" name="width" value="<?php echo $product['width']; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="orientation">Orientation</label>
                                        <select id="orientation" class="form-control" name="orientation">
                                            <option value="">Choose Orientation</option>
                                            <option value="horizontal" <?php echo (isset($product['orientation']) && $product['orientation'] == 'horizontal') ? 'selected' : ''; ?>>Horizontal</option>
                                            <option value="vertical" <?php echo (isset($product['orientation']) && $product['orientation'] == 'vertical') ? 'selected' : ''; ?>>Vertical</option>
                                        </select>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="artist_name">Artist Name</label>
                                            <input type="text" id="artist_name" class="form-control" placeholder="Artist Name" name="artist_name" value="<?php echo htmlspecialchars($product['artist_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="quantity">Quantity</label>
                                            <input type="number" id="quantity" class="form-control" placeholder="Quantity" name="quantity" min="0" value="<?php echo $product['quantity']; ?>">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="is_framed" name="is_framed" value="1" <?php echo $product['is_framed'] ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="is_framed">Framed</label>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="is_include_gst" name="is_include_gst" value="1" <?php echo $product['is_include_gst'] ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="is_include_gst">Include GST in Price</label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Existing Images Section -->
                                    <div class="form-group mb-3">
                                        <label>Current Images</label>
                                        <div class="row">
                                            <?php
                                            $image_sql = "SELECT * FROM product_images WHERE product_id = ?";
                                            $image_stmt = $connect->prepare($image_sql);
                                            $image_stmt->bind_param("i", $product['id']);
                                            $image_stmt->execute();
                                            $image_result = $image_stmt->get_result();
                                            
                                            if ($image_result->num_rows > 0) {
                                                while($image = $image_result->fetch_assoc()) {
                                                    echo '<div class="col-md-3 mb-2">';
                                                    echo '<div class="position-relative">';
                                                    echo '<img src="'. $uri. $image['image'] . '" class="img-fluid rounded" style="max-height: 100px;">';
                                                    echo '<div class="position-absolute top-0 end-0">';
                                                    echo '<input type="checkbox" name="delete_image_ids[]" value="' . $image['id'] . '" class="form-check-input">';
                                                    echo '</div>';
                                                    echo '</div>';
                                                    echo '</div>';
                                                }
                                            } else {
                                                echo '<div class="col-12"><p class="text-muted">No images uploaded</p></div>';
                                            }
                                            $image_stmt->close();
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="images">Add New Images</label>
                                        <input type="file" id="images" class="form-control" name="images[]" accept="image/*" multiple>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Update Product">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>
            </div> <!-- .container-fluid -->

            <script>
            // Store all Product Categories data
            const allArtCategories = <?php 
                $art_cat_sql = "SELECT pc.id, pc.name, pc.category_id, c.name as category_name 
                               FROM product_category pc 
                               LEFT JOIN categories c ON pc.category_id = c.id 
                               WHERE c.business_id = $business_id 
                               ORDER BY c.name, pc.name";
                $art_cat_results = $connect->query($art_cat_sql);
                $art_categories = [];
                if ($art_cat_results) {
                    while($art_cat = $art_cat_results->fetch_assoc()) {
                        $art_categories[] = [
                            'id' => $art_cat['id'],
                            'name' => $art_cat['name'],
                            'category_id' => $art_cat['category_id'],
                            'category_name' => $art_cat['category_name']
                        ];
                    }
                }
                echo json_encode($art_categories);
            ?>;
            
            // Store current product's Product Category for initialization
            const currentArtCategoryId = <?php echo $product['art_category_id'] ?: 'null'; ?>;
            
            // Auto-populate HSN Code and Location when Product Type is selected
            document.getElementById('category_id').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const hsnCode = selectedOption.getAttribute('data-hsn');
                const locationId = selectedOption.getAttribute('data-location');
                
                document.getElementById('hsn_code').value = hsnCode || '';
                
                // Auto-select the location
                if (locationId) {
                    document.getElementById('location_id').value = locationId;
                }
                
                // Update Product Category dropdown based on selected Product Type
                updateArtCategories(this.value);
            });
            
            // Function to update Product Categories based on Product Type
            function updateArtCategories(categoryId) {
                const artCategorySelect = document.getElementById('art_category_id');
                artCategorySelect.innerHTML = '<option value="">Choose Product Category</option>';
                
                if (categoryId) {
                    const filteredCategories = allArtCategories.filter(art => art.category_id == categoryId);
                    
                    if (filteredCategories.length > 0) {
                        filteredCategories.forEach(art => {
                            const option = document.createElement('option');
                            option.value = art.id;
                            option.textContent = art.name + ' (' + art.category_name + ')';
                            option.setAttribute('data-name', art.name);
                            
                            // Select the current Product Category if it matches
                            if (currentArtCategoryId && art.id == currentArtCategoryId) {
                                option.selected = true;
                            }
                            
                            artCategorySelect.appendChild(option);
                        });
                        artCategorySelect.disabled = false;
                    } else {
                        artCategorySelect.innerHTML = '<option value="">No Product Categories found for this Product Type</option>';
                        artCategorySelect.disabled = true;
                    }
                } else {
                    artCategorySelect.innerHTML = '<option value="">First select a Product Type</option>';
                    artCategorySelect.disabled = true;
                }
            }
            
            // Auto-populate Product Name when Product Category is selected
            document.getElementById('art_category_id').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const artCategoryName = selectedOption.getAttribute('data-name');
                document.getElementById('name').value = artCategoryName || '';
            });
            
            // Initialize Product Categories and Location on page load
            window.addEventListener('load', function() {
                const categorySelect = document.getElementById('category_id');
                if (categorySelect.value) {
                    updateArtCategories(categorySelect.value);
                }
                
                // Initialize HSN code and location on page load if Product Type is selected
                if (categorySelect.value) {
                    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                    const hsnCode = selectedOption.getAttribute('data-hsn');
                    const locationId = selectedOption.getAttribute('data-location');
                    
                    if (hsnCode && !document.getElementById('hsn_code').value) {
                        document.getElementById('hsn_code').value = hsnCode;
                    }
                    
                    if (locationId && !document.getElementById('location_id').value) {
                        document.getElementById('location_id').value = locationId;
                    }
                }
            });
            </script>

            <?php include "admin/footer.php"; ?> 