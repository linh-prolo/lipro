<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Xử lý tìm kiếm
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$whereConditions = [];
if ($search) {
    $s = $conn->real_escape_string($search);
    $whereConditions[] = "(supplier_name LIKE '%$s%' OR short_name LIKE '%$s%' OR tax_code LIKE '%$s%' OR contact_person LIKE '%$s%')";
}
if ($status_filter) {
    $sf = $conn->real_escape_string($status_filter);
    $whereConditions[] = "status = '$sf'";
}

$whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Phân trang
$per_page    = 25;
$page        = max(1, intval($_GET['page'] ?? 1));
$count_res   = $conn->query("SELECT COUNT(*) c FROM suppliers $whereClause");
$total_count = intval($count_res->fetch_assoc()['c'] ?? 0);
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;
$offset      = ($page - 1) * $per_page;

$sql    = "SELECT * FROM suppliers $whereClause ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
$result = $conn->query($sql);

// Thống kê
$total_suppliers    = $conn->query("SELECT COUNT(*) as count FROM suppliers")->fetch_assoc()['count'];
$active_suppliers   = $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE status='active'")->fetch_assoc()['count'];
$inactive_suppliers = $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE status='inactive'")->fetch_assoc()['count'];

$page_title = 'Nhà cung cấp';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhà cung cấp - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php include '../partials/sidebar.php'; ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

    <!-- Content -->
    <div class="container-fluid px-4 py-3">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-truck"></i> Quản lý Nhà cung cấp</h2>
            <div>
        	<a href="supplier_payable.php" class="btn btn-danger">
          	  <i class="bi bi-wallet2"></i> Công nợ phải trả
       		 </a>

        	<?php if ($_SESSION['role'] == 'admin'): ?>
        	<a href="import.php" class="btn btn-warning">
            		<i class="bi bi-file-earmark-excel"></i> Import Excel
       		 </a>
      		  <?php endif; ?>

       		 <a href="add.php" class="btn btn-primary">
            		<i class="bi bi-plus-circle"></i> Thêm nhà cung cấp
      		  </a>
   	     </div>
	</div>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                <a href="import.php" class="btn btn-warning">
                    <i class="bi bi-file-earmark-excel"></i> Import Excel
                </a>
                <?php endif; ?>
                <a href="add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Thêm nhà cung cấp
                </a>
            </div>
        </div>

        <!-- Thống kê -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h6 class="text-primary">Tổng nhà cung cấp</h6>
                        <h2 class="text-primary"><?php echo $total_suppliers; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h6 class="text-success">Đang hoạt động</h6>
                        <h2 class="text-success"><?php echo $active_suppliers; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h6 class="text-secondary">Ngưng hoạt động</h6>
                        <h2 class="text-secondary"><?php echo $inactive_suppliers; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tìm kiếm -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo tên NCC, tên viết tắt, MST, người liên hệ..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">-- Tất cả trạng thái --</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Ngưng hoạt động</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Tìm kiếm
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Thông báo -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php
                    if ($_GET['success'] == 'added')    echo '<i class="bi bi-check-circle"></i> Thêm nhà cung cấp thành công!';
                    if ($_GET['success'] == 'updated')  echo '<i class="bi bi-check-circle"></i> Cập nhật nhà cung cấp thành công!';
                    if ($_GET['success'] == 'deleted')  echo '<i class="bi bi-check-circle"></i> Xóa nhà cung cấp thành công!';
                    if ($_GET['success'] == 'imported') echo '<i class="bi bi-check-circle"></i> Import dữ liệu thành công!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php
                    if ($_GET['error'] == 'delete_failed') echo '<i class="bi bi-exclamation-triangle"></i> Không thể xóa nhà cung cấp!';
                    if ($_GET['error'] == 'in_use')        echo '<i class="bi bi-exclamation-triangle"></i> Không thể xóa NCC đang được sử dụng!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Bảng danh sách -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>STT</th>
                                <th>Tên viết tắt</th>
                                <th>Tên nhà cung cấp</th>
                                <th>MST</th>
                                <th>Địa chỉ</th>
                                <th>Liên hệ</th>
                                <th>Ngân hàng</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php $stt = $offset + 1; while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $stt++; ?></td>
                                        <td>
                                            <span class="badge bg-warning text-dark fs-6"><?php echo htmlspecialchars($row['short_name']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['supplier_name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['tax_code']); ?></td>
                                        <td><small><?php echo htmlspecialchars($row['address']); ?></small></td>
                                        <td>
                                            <small>
                                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($row['contact_person']); ?><br>
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($row['phone']); ?><br>
                                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($row['email']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small>
                                                <?php if ($row['bank_name']): ?>
                                                    <i class="bi bi-bank"></i> <?php echo htmlspecialchars($row['bank_name']); ?><br>
                                                    <i class="bi bi-credit-card"></i> <?php echo htmlspecialchars($row['bank_account']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] == 'active'): ?>
                                                <span class="badge bg-success">Hoạt động</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Ngưng</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-info" title="Xem chi tiết">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-warning" title="Sửa">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                                <a href="delete.php?id=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Bạn có chắc muốn xóa nhà cung cấp này?')" title="Xóa">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                                        <p class="text-muted mt-2">Không tìm thấy nhà cung cấp nào</p>
                                        <?php if ($search || $status_filter): ?>
                                            <a href="index.php" class="btn btn-sm btn-primary">Xóa bộ lọc</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Hướng dẫn -->
        <div class="card mt-4 bg-light">
            <div class="card-body">
                <h6><i class="bi bi-lightbulb"></i> Hướng dẫn sử dụng:</h6>
                <ul class="mb-0">
                    <li><strong>Thêm nhà cung cấp:</strong> Click nút "Thêm nhà cung cấp" để tạo mới thủ công</li>
                    <li><strong>Import Excel:</strong> Click nút "Import Excel" để nhập hàng loạt từ file Excel (chỉ Admin)</li>
                    <li><strong>Tìm kiếm:</strong> Nhập từ khóa để lọc danh sách</li>
                    <li><strong>Sửa/Xóa:</strong> Click icon tương ứng trong cột "Thao tác"</li>
                </ul>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Phân trang" class="mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Hiển thị <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_count); ?> trong tổng <?php echo $total_count; ?> kết quả
                </small>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹</a></li>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">›</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
        <?php endif; ?>

    </div><!-- /container-fluid -->
    </div><!-- /main-content -->

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>