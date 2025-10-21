<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Include access control at the very top
include 'admin/access_control.php';
include 'admin/header.php';
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
                        <button type="button" class="btn btn-primary">View Products</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add New Product</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_add-product.php" method="POST" enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="category_id">Product Type *</label>
                                        <select id="category_id" class="form-control" name="category_id" required>
                                            <option value="">Choose Product Type</option>
                                            <?php
                                            $b_id = $_SESSION['business_id'];
                                            $cat_sql = "SELECT c.id, c.name, c.hsn_code, c.location_id, l.location_name 
                                                       FROM categories c 
                                                       LEFT JOIN locations l ON c.location_id = l.id 
                                                       WHERE c.business_id=$b_id 
                                                       ORDER BY c.name";
                                            $cat_results = $connect->query($cat_sql);
                                            if ($cat_results) {
                                                while($cat = $cat_results->fetch_assoc()) {
                                                    echo '<option value="' . (int)$cat['id'] . '" 
                                                              data-hsn="' . htmlspecialchars($cat['hsn_code'] ?? '') . '" 
                                                              data-location="' . (int)$cat['location_id'] . '">' 
                                                              . htmlspecialchars($cat['name']) . ' (' . htmlspecialchars($cat['location_name']) . ')</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="hsn_code">HSN Code *</label>
                                        <input required type="text" id="hsn_code" class="form-control"
                                            placeholder="HSN Code" name="hsn_code">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="art_category_id">Art Category *</label>
                                        <select id="art_category_id" class="form-control" name="art_category_id" required disabled>
                                            <option value="">First select a Product Type</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="name">Product Name *</label>
                                        <input required type="text" id="name" class="form-control"
                                            placeholder="Product Name" name="name">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="price">Price *</label>
                                        <input required type="number" step="0.01" id="price" class="form-control"
                                            placeholder="Price" name="price">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label for="item_code">itemCode</label>
                                        <input type="text" id="item_code" class="form-control" placeholder="itemCode" name="item_code">
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="height">Height (inches)</label>
                                            <input type="number" step="0.01" id="height" class="form-control" placeholder="Height" name="height">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="width">Width (inches)</label>
                                            <input type="number" step="0.01" id="width" class="form-control" placeholder="Width" name="width">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="artist_name">Artist Name</label>
                                            <input type="text" id="artist_name" class="form-control" placeholder="Artist Name" name="artist_name">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="quantity">Quantity</label>
                                            <input type="number" id="quantity" class="form-control" placeholder="Quantity" name="quantity" min="0">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="is_framed" name="is_framed" value="1">
                                                <label class="custom-control-label" for="is_framed">Framed</label>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="is_include_gst" name="is_include_gst" value="1">
                                                <label class="custom-control-label" for="is_include_gst">Include GST in Price</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="images">Product Images</label>
                                        <input type="file" id="images" class="form-control" name="images[]" accept="image/*" multiple>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="location_id">Business Location *</label>
                                        <select required id="location_id" class="form-control" name="location_id">
                                            <option value="">Choose Business Location</option>
                                            <?php
                                            $b_id = $_SESSION['business_id'];
                                            // Fetch locations from the database
                                            $sql = "SELECT * FROM locations WHERE business_id=$b_id ORDER BY location_name";
                                            $results = $connect->query($sql);
                                            while($final = $results->fetch_assoc()) {
                                                echo '<option value="' . $final['id'] . '">' . $final['location_name'] . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <input hidden value="<?php echo $b_id; ?>" name="business_id">
                                    </div>
                                    <div class="form-group mb-3">
                                        <input type="submit" class="btn btn-primary" value="Add Product">
                                    </div>
                                </form>
                            </div> <!-- /.col -->
                        </div>
                    </div>
                </div>

            </div> <!-- .container-fluid -->

            <script>
            // Store all art categories data
            const allArtCategories = <?php 
                $art_cat_sql = "SELECT pc.id, pc.name, pc.category_id, c.name as category_name 
                               FROM product_category pc 
                               LEFT JOIN categories c ON pc.category_id = c.id 
                               WHERE c.business_id = $b_id 
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
                
                // Update Art Category dropdown based on selected Product Type
                updateArtCategories(this.value);
            });
            
            // Function to update Art Categories based on Product Type
            function updateArtCategories(categoryId) {
                const artCategorySelect = document.getElementById('art_category_id');
                artCategorySelect.innerHTML = '<option value="">Choose Art Category</option>';
                
                if (categoryId) {
                    const filteredCategories = allArtCategories.filter(art => art.category_id == categoryId);
                    
                    if (filteredCategories.length > 0) {
                        filteredCategories.forEach(art => {
                            const option = document.createElement('option');
                            option.value = art.id;
                            option.textContent = art.name + ' (' + art.category_name + ')';
                            option.setAttribute('data-name', art.name);
                            artCategorySelect.appendChild(option);
                        });
                        artCategorySelect.disabled = false;
                    } else {
                        artCategorySelect.innerHTML = '<option value="">No Art Categories found for this Product Type</option>';
                        artCategorySelect.disabled = true;
                    }
                } else {
                    artCategorySelect.innerHTML = '<option value="">First select a Product Type</option>';
                    artCategorySelect.disabled = true;
                }
                
                // Clear Product Name when Art Category changes
                document.getElementById('name').value = '';
            }
            
            // Auto-populate Product Name when Art Category is selected
            document.getElementById('art_category_id').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const artCategoryName = selectedOption.getAttribute('data-name');
                document.getElementById('name').value = artCategoryName || '';
            });
            </script>

            <?php include "admin/footer.php"; ?>