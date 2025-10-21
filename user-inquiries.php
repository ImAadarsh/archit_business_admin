<?php
// Include access control at the very top
include 'admin/access_control.php';

$business_id = $_SESSION['business_id'] ?? 1;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Inquiries - Archit Art Gallery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/app-light.css" rel="stylesheet">
    <style>
        .inquiry-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: box-shadow 0.2s;
        }
        .inquiry-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.8em;
            padding: 5px 10px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .product-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
        }
    </style>
</head>
<body>
    <?php include 'partials/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin/aside.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">User Inquiries</h1>
                </div>

                <!-- Filters -->
                <div class="filter-section">
                    <h5><i class="fas fa-filter"></i> Filter Inquiries</h5>
                    <form id="filterForm">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="contacted">Contacted</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" id="searchFilter" placeholder="Name, email, mobile...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="startDate">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" id="endDate">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Inquiries List -->
                <div id="inquiriesContainer">
                    <!-- Inquiries will be loaded here -->
                </div>

                <!-- Pagination -->
                <div id="paginationContainer" class="d-flex justify-content-center mt-4">
                    <!-- Pagination will be loaded here -->
                </div>
            </main>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Inquiry Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="statusForm">
                        <input type="hidden" id="inquiryId">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="newStatus" required>
                                <option value="pending">Pending</option>
                                <option value="contacted">Contacted</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateStatus()">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentPage = 1;

        $(document).ready(function() {
            loadInquiries();
        });

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentPage = 1;
            loadInquiries();
        });

        function loadInquiries() {
            const filters = {
                business_id: <?= $business_id ?>,
                page: currentPage,
                status: $('#statusFilter').val(),
                search: $('#searchFilter').val(),
                start_date: $('#startDate').val(),
                end_date: $('#endDate').val()
            };

            $.ajax({
                url: 'http://localhost:8000/api/inquiries',
                method: 'GET',
                data: filters,
                success: function(response) {
                    if (response.status) {
                        displayInquiries(response.data.data);
                        displayPagination(response.data);
                    } else {
                        alert('Error loading inquiries: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error connecting to server');
                }
            });
        }

        function displayInquiries(inquiries) {
            const container = $('#inquiriesContainer');
            container.empty();

            if (inquiries.length === 0) {
                container.html('<div class="text-center"><p class="text-muted">No inquiries found.</p></div>');
                return;
            }

            inquiries.forEach(inquiry => {
                const statusClass = getStatusClass(inquiry.status);
                const card = `
                    <div class="card inquiry-card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="card-title">${inquiry.name}</h6>
                                    <p class="card-text">
                                        <strong>Contact:</strong> 
                                        ${inquiry.email ? inquiry.email : 'No email'} | 
                                        ${inquiry.mobile ? inquiry.mobile : 'No mobile'}<br>
                                        <strong>Date:</strong> ${new Date(inquiry.created_at).toLocaleDateString()}<br>
                                        <strong>Location:</strong> ${inquiry.location?.location_name || 'N/A'}
                                    </p>
                                    ${inquiry.inquiry_notes ? `<p><strong>Notes:</strong> ${inquiry.inquiry_notes}</p>` : ''}
                                    
                                    ${inquiry.selected_products && inquiry.selected_products.length > 0 ? 
                                        `<div class="product-list">
                                            <strong>Selected Products:</strong><br>
                                            ${inquiry.selected_products.map(id => `Product ID: ${id}`).join('<br>')}
                                        </div>` : ''
                                    }
                                </div>
                                <div class="col-md-4 text-end">
                                    <span class="badge ${statusClass} status-badge">${inquiry.status.toUpperCase()}</span>
                                    <br><br>
                                    <button class="btn btn-sm btn-outline-primary" onclick="showStatusModal(${inquiry.id}, '${inquiry.status}')">
                                        <i class="fas fa-edit"></i> Update Status
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                container.append(card);
            });
        }

        function getStatusClass(status) {
            switch(status) {
                case 'pending': return 'bg-warning';
                case 'contacted': return 'bg-info';
                case 'completed': return 'bg-success';
                case 'cancelled': return 'bg-danger';
                default: return 'bg-secondary';
            }
        }

        function displayPagination(data) {
            const container = $('#paginationContainer');
            container.empty();

            if (data.last_page <= 1) return;

            let pagination = '<nav><ul class="pagination">';
            
            if (data.current_page > 1) {
                pagination += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${data.current_page - 1})">Previous</a></li>`;
            }

            for (let i = 1; i <= data.last_page; i++) {
                if (i === data.current_page) {
                    pagination += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
                } else {
                    pagination += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${i})">${i}</a></li>`;
                }
            }

            if (data.current_page < data.last_page) {
                pagination += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${data.current_page + 1})">Next</a></li>`;
            }

            pagination += '</ul></nav>';
            container.html(pagination);
        }

        function goToPage(page) {
            currentPage = page;
            loadInquiries();
        }

        function clearFilters() {
            $('#filterForm')[0].reset();
            currentPage = 1;
            loadInquiries();
        }

        function showStatusModal(inquiryId, currentStatus) {
            $('#inquiryId').val(inquiryId);
            $('#newStatus').val(currentStatus);
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        function updateStatus() {
            const inquiryId = $('#inquiryId').val();
            const newStatus = $('#newStatus').val();

            $.ajax({
                url: 'http://localhost:8000/api/inquiries/status',
                method: 'PUT',
                data: {
                    inquiry_id: inquiryId,
                    status: newStatus
                },
                success: function(response) {
                    if (response.status) {
                        alert('Status updated successfully!');
                        bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
                        loadInquiries();
                    } else {
                        alert('Error updating status: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error connecting to server');
                }
            });
        }
    </script>
</body>
</html> 