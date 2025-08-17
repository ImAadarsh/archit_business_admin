<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';

$business_id = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 1;
$location_id = isset($_SESSION['location_id']) ? (int)$_SESSION['location_id'] : 1;

$categories = [];
if ($stmt = $connect->prepare('SELECT id, name FROM categories WHERE business_id = ? ORDER BY name')) {
    $stmt->bind_param('i', $business_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $categories[] = $row; }
    }
    $stmt->close();
}
?>

<body class="vertical light">
    <div class="wrapper">
        <?php include 'admin/navbar.php'; include 'admin/aside.php'; ?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        
                        <!-- Header Section -->
                        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
                            <div>
                                <h1 class="h2 mb-1">Product Gallery</h1>
                                <p class="text-muted mb-0">Browse and select products for your inquiry</p>
                            </div>
                            <div class="btn-toolbar mb-2 mb-md-0">
                                <button class="btn btn-primary btn-lg" onclick="showInquiryForm()" id="submitInquiryBtn" disabled>
                                    <i class="fas fa-paper-plane me-2"></i>Submit Inquiry
                                </button>
                            </div>
                        </div>

                        <!-- Stats Cards -->
                        <div class="row mb-4">
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-primary shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Products</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalProducts">-</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-box fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-success shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Selected</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="selectedCount">0</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-info shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Categories</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($categories) ?></div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-tags fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-warning shadow h-100 py-2">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Price Range</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="priceRange">-</div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-rupee-sign fa-2x text-gray-300"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters Section -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-filter me-2"></i>Advanced Filters
                                </h6>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleFilters()">
                                    <i class="fas fa-chevron-up" id="filterToggleIcon"></i>
                                </button>
                            </div>
                            <div class="card-body" id="filterBody">
                                <form id="filterForm">
                                    <div class="row">
                                        <div class="col-lg-3 col-md-6 mb-3">
                                            <label class="form-label fw-bold">Categories</label>
                                            <select class="form-select form-select-sm" id="categories" multiple size="4">
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-lg-3 col-md-6 mb-3">
                                            <label class="form-label fw-bold">Price Range (₹)</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control" id="minPrice" placeholder="Min">
                                                <span class="input-group-text">-</span>
                                                <input type="number" class="form-control" id="maxPrice" placeholder="Max">
                                            </div>
                                        </div>
                                        <div class="col-lg-3 col-md-6 mb-3">
                                            <label class="form-label fw-bold">Height (cm)</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control" id="minHeight" placeholder="Min">
                                                <span class="input-group-text">-</span>
                                                <input type="number" class="form-control" id="maxHeight" placeholder="Max">
                                            </div>
                                        </div>
                                        <div class="col-lg-3 col-md-6 mb-3">
                                            <label class="form-label fw-bold">Width (cm)</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control" id="minWidth" placeholder="Min">
                                                <span class="input-group-text">-</span>
                                                <input type="number" class="form-control" id="maxWidth" placeholder="Max">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">Artist</label>
                                            <input type="text" class="form-control form-control-sm" id="artistName" placeholder="Artist name...">
                                        </div>
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">Framed</label>
                                            <select class="form-select form-select-sm" id="isFramed">
                                                <option value="">All</option>
                                                <option value="1">Framed</option>
                                                <option value="0">Unframed</option>
                                            </select>
                                        </div>
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">GST</label>
                                            <select class="form-select form-select-sm" id="isIncludeGst">
                                                <option value="">All</option>
                                                <option value="1">Included</option>
                                                <option value="0">Extra</option>
                                            </select>
                                        </div>
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">Min Qty</label>
                                            <input type="number" class="form-control form-control-sm" id="minQuantity" placeholder="Min">
                                        </div>
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">Sort By</label>
                                            <select class="form-select form-select-sm" id="sortBy">
                                                <option value="created_at">Date</option>
                                                <option value="name">Name</option>
                                                <option value="price">Price</option>
                                                <option value="artist_name">Artist</option>
                                            </select>
                                        </div>
                                        <div class="col-lg-2 col-md-4 mb-3">
                                            <label class="form-label fw-bold">Order</label>
                                            <select class="form-select form-select-sm" id="sortOrder">
                                                <option value="desc">Desc</option>
                                                <option value="asc">Asc</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-lg-6 mb-3">
                                            <label class="form-label fw-bold">Search</label>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                <input type="text" class="form-control" id="searchText" placeholder="Product name, HSN code, Item code...">
                                            </div>
                                        </div>
                                        <div class="col-lg-3 mb-3">
                                            <label class="form-label fw-bold">Per Page</label>
                                            <select class="form-select form-select-sm" id="perPage">
                                                <option value="12">12 items</option>
                                                <option value="24">24 items</option>
                                                <option value="48">48 items</option>
                                            </select>
                                        </div>
                                        <div class="col-lg-3 mb-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary me-2">
                                                <i class="fas fa-search me-1"></i>Apply
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                                                <i class="fas fa-times me-1"></i>Clear
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Products Grid -->
                        <div class="card shadow">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-th me-2"></i>Products
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="loadingSpinner" class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading products...</p>
                                </div>
                                
                                <div id="productsContainer" class="row g-4"></div>
                                
                                <div id="paginationContainer" class="d-flex justify-content-center mt-4"></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <?php include 'admin/footer.php'; ?>
        </main>
    </div>

    <!-- Inquiry Modal -->
    <div class="modal fade" id="inquiryModal" tabindex="-1" aria-labelledby="inquiryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="inquiryModalLabel">
                        <i class="fas fa-envelope me-2"></i>Submit Product Inquiry
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="inquiryFormData">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Name *</label>
                                <input type="text" class="form-control" id="inquiryName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" class="form-control" id="inquiryEmail">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Mobile</label>
                                <input type="tel" class="form-control" id="inquiryMobile">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Selected Products</label>
                                <div class="form-control-plaintext" id="selectedProductsCount">0 products</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" id="inquiryNotes" rows="3" placeholder="Additional notes about your inquiry..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Selected Products</label>
                            <div id="selectedProductsList" class="border rounded p-3" style="max-height: 200px; overflow-y: auto; background-color: #f8f9fa;">
                                <small class="text-muted">No products selected</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Submit Inquiry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        .border-left-primary { border-left: 0.25rem solid #4e73df !important; }
        .border-left-success { border-left: 0.25rem solid #1cc88a !important; }
        .border-left-info { border-left: 0.25rem solid #36b9cc !important; }
        .border-left-warning { border-left: 0.25rem solid #f6c23e !important; }
        
        .text-gray-800 { color: #5a5c69 !important; }
        .text-gray-300 { color: #dddfeb !important; }
        
        .product-card {
            border: 1px solid #e3e6f0;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
            height: 100%;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border-color: #4e73df;
        }
        
        .product-card.selected {
            border: 2px solid #1cc88a;
            background: linear-gradient(135deg, #f8fff9 0%, #e8f5e8 100%);
        }
        
        .product-card.selected::before {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            background: #1cc88a;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
            z-index: 10;
        }
        
        .product-image {
            height: 200px;
            object-fit: cover;
            border-radius: 0.75rem 0.75rem 0 0;
            transition: transform 0.3s ease;
        }
        
        .product-card:hover .product-image {
            transform: scale(1.05);
        }
        
        .product-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: #4e73df;
        }
        
        .product-details {
            font-size: 0.875rem;
            color: #858796;
        }
        
        .product-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }
        
        .badge-framed { background-color: #e8f5e8; color: #1cc88a; }
        .badge-gst { background-color: #fff3cd; color: #856404; }
        
        .filter-section { transition: all 0.3s ease; }
        
        .btn-primary { background-color: #4e73df; border-color: #4e73df; }
        .btn-primary:hover { background-color: #2e59d9; border-color: #2653d4; }
        
        .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; }
        
        .form-control:focus, .form-select:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .pagination .page-link { color: #4e73df; }
        .pagination .page-item.active .page-link { background-color: #4e73df; border-color: #4e73df; }
        
        .spinner-border { width: 3rem; height: 3rem; }
        
        @media (max-width: 768px) {
            .product-card { margin-bottom: 1rem; }
            .filter-section .row > div { margin-bottom: 1rem; }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let selectedProducts = [];
        let currentPage = 1;
        let allProducts = [];

        function buildImageUrl(path) {
            if (!path) return 'assets/images/default-product.jpg';
            path = String(path).replace(/^public\//, '');
            return 'http://localhost:8000/storage/' + path;
        }

        $(document).ready(function() {
            loadProducts();
            updateSelectedCount();
        });

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentPage = 1;
            loadProducts();
        });

        function loadProducts() {
            $('#loadingSpinner').show();
            $('#productsContainer').empty();
            
            const filters = {
                business_id: <?= $business_id ?>,
                location_id: <?= $location_id ?>,
                page: currentPage,
                categories: $('#categories').val(),
                min_price: $('#minPrice').val(),
                max_price: $('#maxPrice').val(),
                min_height: $('#minHeight').val(),
                max_height: $('#maxHeight').val(),
                min_width: $('#minWidth').val(),
                max_width: $('#maxWidth').val(),
                artist_name: $('#artistName').val(),
                is_framed: $('#isFramed').val(),
                is_include_gst: $('#isIncludeGst').val(),
                min_quantity: $('#minQuantity').val(),
                name: $('#searchText').val(),
                hsn_code: $('#searchText').val(),
                item_code: $('#searchText').val(),
                sort_by: $('#sortBy').val(),
                sort_order: $('#sortOrder').val(),
                per_page: $('#perPage').val()
            };
            
            // Debug: Log the filters being sent
            console.log('Sending filters:', filters);

            $.ajax({
                url: 'http://localhost:8000/api/products/filter',
                method: 'POST',
                data: filters,
                success: function(response) {
                    $('#loadingSpinner').hide();
                    if (response.status) {
                        allProducts = response.data.data;
                        displayProducts(response.data.data);
                        displayPagination(response.data);
                        updateStats(response.data);
                    } else {
                        $('#productsContainer').html(`
                            <div class="col-12 text-center py-5">
                                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                <h5 class="text-muted">No products found</h5>
                                <p class="text-muted">Try adjusting your filters or search criteria.</p>
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#loadingSpinner').hide();
                    $('#productsContainer').html(`
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-exclamation-circle fa-3x text-danger mb-3"></i>
                            <h5 class="text-danger">Connection Error</h5>
                            <p class="text-muted">Unable to connect to server. Please try again.</p>
                        </div>
                    `);
                }
            });
        }

        function displayProducts(products) {
            const container = $('#productsContainer');
            container.empty();
            
            if (!products || products.length === 0) {
                container.html(`
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No products found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria.</p>
                    </div>
                `);
                return;
            }

            products.forEach(product => {
                const isSelected = selectedProducts.includes(product.id);
                const imagePath = (product.images && product.images.length) ? product.images[0].image : null;
                const imgUrl = buildImageUrl(imagePath);
                
                const card = `
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card product-card ${isSelected ? 'selected' : ''}" onclick="toggleProductSelection(${product.id})">
                            <img src="${imgUrl}" class="card-img-top product-image" alt="${product.name}" onerror="this.src='assets/images/default-product.jpg'">
                            <div class="card-body">
                                <h6 class="card-title fw-bold mb-2">${product.name}</h6>
                                <div class="product-price mb-2">₹${product.price}</div>
                                <div class="product-details mb-3">
                                    ${product.artist_name ? `<div><i class="fas fa-palette me-1"></i>${product.artist_name}</div>` : ''}
                                    <div><i class="fas fa-ruler-combined me-1"></i>${product.height || 0} × ${product.width || 0} cm</div>
                                    <div><i class="fas fa-boxes me-1"></i>Qty: ${product.quantity || 0}</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-secondary">${product.category ? product.category.name : 'Uncategorized'}</span>
                                    <div class="d-flex gap-1">
                                        ${Number(product.is_framed) ? '<span class="badge badge-framed">Framed</span>' : ''}
                                        ${Number(product.is_include_gst) ? '<span class="badge badge-gst">GST Inc.</span>' : ''}
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" ${isSelected ? 'checked' : ''} 
                                           onclick="event.stopPropagation(); toggleProductSelection(${product.id})">
                                    <label class="form-check-label small">Select</label>
                                </div>
                            </div>
                        </div>
                    </div>`;
                container.append(card);
            });
        }

        function displayPagination(data) {
            const container = $('#paginationContainer');
            container.empty();
            
            if (!data || data.last_page <= 1) return;
            
            let html = '<nav><ul class="pagination pagination-sm">';
            
            if (data.current_page > 1) {
                html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${data.current_page - 1})">Previous</a></li>`;
            }
            
            for (let i = 1; i <= data.last_page; i++) {
                if (i === data.current_page) {
                    html += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
                } else {
                    html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${i})">${i}</a></li>`;
                }
            }
            
            if (data.current_page < data.last_page) {
                html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${data.current_page + 1})">Next</a></li>`;
            }
            
            html += '</ul></nav>';
            container.html(html);
        }

        function updateStats(data) {
            if (data.data && data.data.length > 0) {
                const prices = data.data.map(p => parseFloat(p.price)).filter(p => !isNaN(p));
                const minPrice = Math.min(...prices);
                const maxPrice = Math.max(...prices);
                
                $('#totalProducts').text(data.total || data.data.length);
                $('#priceRange').text(`₹${minPrice} - ₹${maxPrice}`);
            }
        }

        function goToPage(page) {
            currentPage = page;
            loadProducts();
        }

        function toggleProductSelection(productId) {
            const index = selectedProducts.indexOf(productId);
            if (index > -1) {
                selectedProducts.splice(index, 1);
            } else {
                selectedProducts.push(productId);
            }
            updateSelectedCount();
            updateSubmitButton();
        }

        function updateSelectedCount() {
            const count = selectedProducts.length;
            $('#selectedCount').text(count);
            updateSubmitButton();
        }

        function updateSubmitButton() {
            const count = selectedProducts.length;
            const btn = $('#submitInquiryBtn');
            if (count > 0) {
                btn.prop('disabled', false);
                btn.html(`<i class="fas fa-paper-plane me-2"></i>Submit Inquiry (${count})`);
            } else {
                btn.prop('disabled', true);
                btn.html(`<i class="fas fa-paper-plane me-2"></i>Submit Inquiry`);
            }
        }

        function clearFilters() {
            $('#filterForm')[0].reset();
            currentPage = 1;
            loadProducts();
        }

        function toggleFilters() {
            const body = $('#filterBody');
            const icon = $('#filterToggleIcon');
            body.slideToggle();
            icon.toggleClass('fa-chevron-up fa-chevron-down');
        }

        function showInquiryForm() {
            if (selectedProducts.length === 0) {
                alert('Please select at least one product before submitting an inquiry.');
                return;
            }
            updateSelectedProductsList();
            $('#selectedProductsCount').text(`${selectedProducts.length} products`);
            new bootstrap.Modal(document.getElementById('inquiryModal')).show();
        }

        function updateSelectedProductsList() {
            const container = $('#selectedProductsList');
            if (selectedProducts.length === 0) {
                container.html('<small class="text-muted">No products selected</small>');
                return;
            }
            
            const productsList = selectedProducts.map(id => {
                const product = allProducts.find(p => p.id === id);
                return product ? `
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong>${product.name}</strong><br>
                            <small class="text-muted">₹${product.price} | ${product.height || 0}×${product.width || 0} cm</small>
                        </div>
                        <span class="badge bg-primary">${product.category ? product.category.name : 'Uncategorized'}</span>
                    </div>
                ` : `Product ID: ${id}`;
            }).join('');
            
            container.html(productsList);
        }

        $('#inquiryFormData').on('submit', function(e) {
            e.preventDefault();
            
            const data = {
                name: $('#inquiryName').val(),
                email: $('#inquiryEmail').val(),
                mobile: $('#inquiryMobile').val(),
                business_id: <?= $business_id ?>,
                location_id: <?= $location_id ?>,
                selected_products: selectedProducts,
                inquiry_notes: $('#inquiryNotes').val(),
                filter_data: {
                    categories: $('#categories').val(),
                    min_price: $('#minPrice').val(),
                    max_price: $('#maxPrice').val(),
                    min_height: $('#minHeight').val(),
                    max_height: $('#maxHeight').val(),
                    min_width: $('#minWidth').val(),
                    max_width: $('#maxWidth').val(),
                    artist_name: $('#artistName').val(),
                    is_framed: $('#isFramed').val(),
                    is_include_gst: $('#isIncludeGst').val(),
                    min_quantity: $('#minQuantity').val(),
                    search: $('#searchText').val()
                }
            };

            $.ajax({
                url: 'http://localhost:8000/api/inquiries/submit',
                method: 'POST',
                data: data,
                success: function(response) {
                    if (response.status) {
                        alert('Inquiry submitted successfully!');
                        bootstrap.Modal.getInstance(document.getElementById('inquiryModal')).hide();
                        selectedProducts = [];
                        updateSelectedCount();
                        $('#inquiryFormData')[0].reset();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error connecting to server. Please try again.');
                }
            });
        });
    </script>
</body>
</html> 