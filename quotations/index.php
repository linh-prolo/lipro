<?php
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /forwarder/shipments/index.php?error=no_permission");
    exit();
}

$conn = getDBConnection();

$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$where  = "1=1";
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (q.quotation_no LIKE ? OR c.company_name LIKE ? OR c.short_name LIKE ? OR q.shipper LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($status_filter !== '') {
    $where .= " AND q.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql = "SELECT q.*,
        c.company_name, c.short_name,
        COALESCE(SUM(qi.unit_price * qi.quantity), 0) AS total_amount,
        MAX(qi.currency) AS main_currency
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        LEFT JOIN quotation_items qi ON qi.quotation_id = q.id
        WHERE $where
        GROUP BY q.id
        ORDER BY q.created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Đếm theo trạng thái
$countSql = "SELECT status, COUNT(*) as cnt FROM quotations GROUP BY status";
$countRes = $conn->query($countSql);
$statusCount = [];
while ($cr = $countRes->fetch_assoc()) {
    $statusCount[$cr['status']] = $cr['cnt'];
}
$totalCount = array_sum($statusCount);

$statusBadge = [
    'draft'    => ['color' => 'secondary', 'text' => 'Nháp'],
    'sent'     => ['color' => 'primary',   'text' => 'Đã gửi'],
    'accepted' => ['color' => 'success',   'text' => 'Chấp nhận'],
    'rejected' => ['color' => 'danger',    'text' => 'Từ chối'],
    'expired'  => ['color' => 'warning',   'text' => 'Hết hạn'],
];

function fmtNum($val): string {
    $val = floatval($val);
    if ($val == 0) return '—';
    $s = number_format($val, 2, ',', '.');
    $s = rtrim($s, '0');
    $s = rtrim($s, ',');
    return $s;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo Giá - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table thead th {
            background: #343a40;
            color: white;
            font-size: .78rem;
            white-space: nowrap;
            padding: 8px 6px;
            vertical-align: middle;
        }
        .table tbody td { font-size: .83rem; vertical-align: middle; }
        .filter-badge { cursor: pointer; font-size: .78rem; }
        .filter-badge.active { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #0d6efd; }
        .notes-cell {
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .shipper-cell {
            max-width: 130px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .total-cell { font-weight: 600; color: #0a6640; }
    </style>
</head>
<body class="bg-light">

<?php include '../partials/navbar.php'; ?>

<div class="container-fluid mt-3 pb-5">

    <!-- Title + Button -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-file-earmark-text text-primary"></i> Báo Giá
            <span class="badge bg-secondary ms-1"><?php echo $totalCount; ?></span>
        </h4>
        <a href="add.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> + Tạo báo giá
        </a>
    </div>

    <!-- Thông báo -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php
                if ($_GET['success'] === 'added')   echo '<i class="bi bi-check-circle"></i> Tạo báo giá thành công!';
                if ($_GET['success'] === 'updated') echo '<i class="bi bi-check-circle"></i> Cập nhật báo giá thành công!';
                if ($_GET['success'] === 'deleted') echo '<i class="bi bi-check-circle"></i> Xóa báo giá thành công!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'copy_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> Sao chép báo giá thất bại, vui lòng thử lại!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Bộ lọc nhanh theo trạng thái -->
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small me-1">Lọc:</span>
        <a href="index.php<?php echo $search ? '?search='.urlencode($search) : ''; ?>"
           class="badge filter-badge text-decoration-none bg-dark <?php echo $status_filter==='' ? 'active' : ''; ?>">
            Tất cả (<?php echo $totalCount; ?>)
        </a>
        <?php foreach ($statusBadge as $sk => $sv): ?>
        <a href="index.php?status=<?php echo $sk; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"
           class="badge filter-badge text-decoration-none bg-<?php echo $sv['color']; ?> <?php echo $status_filter===$sk ? 'active' : ''; ?>">
            <?php echo $sv['text']; ?> (<?php echo $statusCount[$sk] ?? 0; ?>)
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Tìm kiếm -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <?php if ($status_filter): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <?php endif; ?>
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Tìm theo số báo giá, tên công ty, shipper..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search"></i> Tìm kiếm
                    </button>
                </div>
                <?php if ($search || $status_filter): ?>
                <div class="col-md-2">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-x-circle"></i> Xóa bộ lọc
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Bảng danh sách -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px">STT</th>
                            <th style="width:110px">Số BG</th>
                            <th>Tên công ty</th>
                            <th style="width:130px">Shipper</th>
                            <th style="width:130px">POL → POD</th>
                            <th style="width:70px" class="text-end">Số kiện</th>
                            <th style="width:80px" class="text-end">GW (kg)</th>
                            <th style="width:80px" class="text-end">CW (kg)</th>
                            <th style="width:110px" class="text-end">Tổng tiền</th>
                            <th style="width:60px" class="text-center">Tiền tệ</th>
                            <th style="width:85px">Ngày lập</th>
                            <th style="width:85px">Hiệu lực</th>
                            <th style="width:120px">Ghi chú</th>
                            <th style="width:65px" class="text-center">Trạng thái</th>
                            <th style="width:130px" class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php $stt = 1; while ($row = $result->fetch_assoc()): ?>
                            <?php
                                $s       = $row['status'];
                                $badge   = $statusBadge[$s] ?? ['color' => 'secondary', 'text' => $s];
                                $pol     = $row['pol']      ?? '';
                                $pod     = $row['pod']      ?? '';
                                $pkgs    = $row['packages'] ?? '';
                                $gw      = $row['gw']       ?? '';
                                $cw      = $row['cw']       ?? '';
                                $notes   = $row['notes']    ?? '';
                                $shipper = $row['shipper']  ?? '';
                                $total   = floatval($row['total_amount'] ?? 0);
                            ?>
                            <tr>
                                <td class="text-muted"><?php echo $stt++; ?></td>

                                <td>
                                    <a href="view.php?id=<?php echo $row['id']; ?>"
                                       class="fw-bold text-primary text-decoration-none">
                                        <?php echo htmlspecialchars($row['quotation_no']); ?>
                                    </a>
                                </td>

                                <td>
                                    <strong><?php echo htmlspecialchars($row['company_name'] ?? ''); ?></strong>
                                    <?php if ($row['short_name']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['short_name']); ?></small>
                                    <?php endif; ?>
                                </td>

                                <td class="shipper-cell" title="<?php echo htmlspecialchars($shipper); ?>">
                                    <?php echo !empty($shipper)
                                        ? htmlspecialchars($shipper)
                                        : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <td>
                                    <?php if (!empty($pol) || !empty($pod)): ?>
                                        <small class="text-nowrap">
                                            <span class="text-primary"><?php echo htmlspecialchars($pol ?: '?'); ?></span>
                                            <i class="bi bi-arrow-right text-muted mx-1"></i>
                                            <span class="text-primary"><?php echo htmlspecialchars($pod ?: '?'); ?></span>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <?php echo !empty($pkgs) ? fmtNum($pkgs) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <td class="text-end">
                                    <?php echo !empty($gw) ? fmtNum($gw) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <td class="text-end">
                                    <?php echo !empty($cw) ? fmtNum($cw) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <td class="text-end total-cell">
                                    <?php echo $total > 0 ? number_format($total, 0, ',', '.') : '<span class="text-muted fw-normal">—</span>'; ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($row['currency'] ?? 'USD'); ?>
                                    </span>
                                </td>

                                <td class="text-nowrap">
                                    <?php echo $row['issue_date'] ? date('d/m/Y', strtotime($row['issue_date'])) : '—'; ?>
                                </td>

                                <td class="text-nowrap">
                                    <?php if ($row['valid_until']): ?>
                                        <?php
                                            $validDate = strtotime($row['valid_until']);
                                            $isExpired = $validDate < strtotime('today');
                                        ?>
                                        <span class="<?php echo $isExpired ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo date('d/m/Y', $validDate); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="notes-cell" title="<?php echo htmlspecialchars($notes); ?>">
                                    <?php echo !empty($notes) ? htmlspecialchars($notes) : '<span class="text-muted">—</span>'; ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge bg-<?php echo $badge['color']; ?>">
                                        <?php echo $badge['text']; ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="view.php?id=<?php echo $row['id']; ?>"
                                           class="btn btn-info" title="Xem chi tiết">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $row['id']; ?>"
                                           class="btn btn-warning" title="Sửa">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="copy.php?id=<?php echo $row['id']; ?>"
                                           class="btn btn-outline-primary" title="Sao chép báo giá"
                                           onclick="return confirm('Sao chép báo giá này?')">
                                            <i class="bi bi-copy"></i>
                                        </a>
                                        <a href="export_excel.php?id=<?php echo $row['id']; ?>"
                                           class="btn btn-success" title="Xuất Excel">
                                            <i class="bi bi-file-earmark-excel"></i>
                                        </a>
                                        <?php if (isAdmin()): ?>
                                        <a href="delete.php?id=<?php echo $row['id']; ?>"
                                           class="btn btn-danger" title="Xóa"
                                           onclick="return confirm('Bạn có chắc muốn xóa báo giá này?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size:3rem; color:#ccc;"></i>
                                    <p class="text-muted mt-2">
                                        Chưa có báo giá nào<?php echo ($search || $status_filter) ? ' phù hợp' : ''; ?>
                                    </p>
                                    <a href="add.php" class="btn btn-sm btn-success">
                                        <i class="bi bi-plus-circle"></i> Tạo báo giá đầu tiên
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<footer class="bg-light text-center py-3 mt-4">
    <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>