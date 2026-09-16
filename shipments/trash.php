<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$id     = isset($_GET['id'])     ? intval($_GET['id'])   : 0;

// ── KHÔI PHỤC ──────────────────────────────────────────────
if ($action === 'restore' && $id > 0) {
    $stmt = $conn->prepare("UPDATE shipments SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $conn->close();
    header("Location: trash.php?success=restored");
    exit();
}

// ── XÓA VĨNH VIỄN 1 LÔ (admin) ────────────────────────────
if ($action === 'purge' && $id > 0) {
    checkAdmin();
    $conn->begin_transaction();
    try {
        foreach (['shipment_attachments', 'shipment_costs', 'shipment_sells', 'shipment_suppliers'] as $tbl) {
            $s = $conn->prepare("DELETE FROM $tbl WHERE shipment_id = ?");
            $s->bind_param("i", $id);
            $s->execute();
        }
        $s = $conn->prepare("DELETE FROM shipments WHERE id = ? AND deleted_at IS NOT NULL");
        $s->bind_param("i", $id);
        $s->execute();
        $conn->commit();
        $conn->close();
        header("Location: trash.php?success=purged");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        header("Location: trash.php?error=purge_failed");
        exit();
    }
}

// ── LÀM TRỐNG THÙNG RÁC (admin) ────────────────────────────
if ($action === 'empty_trash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkAdmin();
    $conn->begin_transaction();
    try {
        $ids_result = $conn->query("SELECT id FROM shipments WHERE deleted_at IS NOT NULL");
        $trash_ids  = [];
        while ($row = $ids_result->fetch_assoc()) {
            $trash_ids[] = $row['id'];
        }
        if (!empty($trash_ids)) {
            $ph    = implode(',', array_fill(0, count($trash_ids), '?'));
            $types = str_repeat('i', count($trash_ids));
            foreach (['shipment_attachments', 'shipment_costs', 'shipment_sells', 'shipment_suppliers'] as $tbl) {
                $s = $conn->prepare("DELETE FROM $tbl WHERE shipment_id IN ($ph)");
                $s->bind_param($types, ...$trash_ids);
                $s->execute();
            }
            $s = $conn->prepare("DELETE FROM shipments WHERE id IN ($ph) AND deleted_at IS NOT NULL");
            $s->bind_param($types, ...$trash_ids);
            $s->execute();
        }
        $conn->commit();
        $conn->close();
        header("Location: trash.php?success=emptied");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        header("Location: trash.php?error=empty_failed");
        exit();
    }
}

// ── DANH SÁCH THÙNG RÁC ────────────────────────────────────
$result = $conn->query("
    SELECT s.*,
           c.company_name, c.short_name AS customer_short,
           a.full_name AS deleted_by_name
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN accounts a ON s.deleted_by = a.id
    WHERE s.deleted_at IS NOT NULL
    ORDER BY s.deleted_at DESC
");
$trash_count = $result->num_rows;
$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thùng rác - Lô hàng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table thead th {
            background: #343a40;
            color: white;
            font-size: .78rem;
            white-space: nowrap;
            padding: 8px 6px;
        }
        .table tbody td { font-size: .82rem; padding: 6px; vertical-align: middle; }
        .job-no { font-weight: 700; color: #6c757d; font-size: .9rem; letter-spacing: .4px; }
        .trash-row:hover { background: #fff8f8 !important; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-secondary sticky-top shadow">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="../dashboard.php">
            <i class="bi bi-box-seam"></i> Forwarder System
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-box"></i> Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="trash.php"><i class="bi bi-trash3"></i> Thùng rác</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../logout.php">Đăng xuất</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3 pb-5">

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="bi bi-check-circle-fill"></i>
        <?php
            if ($_GET['success'] === 'restored') echo '<strong>Khôi phục thành công!</strong> Lô hàng đã được khôi phục về danh sách.';
            if ($_GET['success'] === 'purged')   echo '<strong>Đã xóa vĩnh viễn!</strong>';
            if ($_GET['success'] === 'emptied')  echo '<strong>Thùng rác đã được làm trống!</strong>';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <i class="bi bi-exclamation-triangle"></i> Có lỗi xảy ra, vui lòng thử lại!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-trash3-fill text-secondary"></i> Thùng rác
            <span class="badge bg-secondary ms-2"><?php echo $trash_count; ?> lô hàng</span>
        </h4>
        <div class="d-flex gap-2">
            <?php if ($_SESSION['role'] === 'admin' && $trash_count > 0): ?>
            <form method="POST" action="trash.php?action=empty_trash"
                  onsubmit="return confirm('⚠️ XÓA VĨNH VIỄN toàn bộ <?php echo $trash_count; ?> lô hàng trong thùng rác?\n\nHành động này KHÔNG THỂ HOÀN TÁC!')">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3-fill"></i> Làm trống thùng rác
                </button>
            </form>
            <?php endif; ?>
            <a href="index.php" class="btn btn-primary">
                <i class="bi bi-arrow-left"></i> Quay lại danh sách
            </a>
        </div>
    </div>

    <!-- Thông tin hướng dẫn -->
    <div class="alert alert-info py-2 mb-3">
        <i class="bi bi-info-circle-fill"></i>
        <strong>Thùng rác:</strong> Các lô hàng đã xóa được lưu tại đây.
        Nhấn <span class="badge bg-success"><i class="bi bi-arrow-counterclockwise"></i> Khôi phục</span> để hoàn tác.
        <?php if ($_SESSION['role'] === 'admin'): ?>
        Admin có thể <span class="badge bg-danger"><i class="bi bi-trash-fill"></i> Xóa vĩnh viễn</span> từng lô hoặc làm trống toàn bộ thùng rác.
        <?php endif; ?>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
            <span>
                <i class="bi bi-trash3"></i> Danh sách lô hàng đã xóa
                <span class="badge bg-secondary ms-2"><?php echo $trash_count; ?></span>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="width:35px">#</th>
                            <th>Job No</th>
                            <th>Khách hàng</th>
                            <th>MAWB / HAWB</th>
                            <th>Số tờ khai</th>
                            <th>Shipper / CNEE</th>
                            <th>Trạng thái</th>
                            <th>Ngày xóa</th>
                            <th>Người xóa</th>
                            <th style="width:160px">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($trash_count > 0):
                            $stt = 1;
                            $statusBadge = [
                                'pending'    => ['color' => 'warning',  'text' => 'Chờ xử lý'],
                                'in_transit' => ['color' => 'primary',  'text' => 'Đang vận chuyển'],
                                'arrived'    => ['color' => 'info',     'text' => 'Đã đến'],
                                'cleared'    => ['color' => 'success',  'text' => 'Đã thông quan'],
                                'delivered'  => ['color' => 'dark',     'text' => 'Đã giao'],
                                'cancelled'  => ['color' => 'danger',   'text' => 'Đã hủy'],
                            ];
                            while ($row = $result->fetch_assoc()):
                                $badge = $statusBadge[$row['status']] ?? ['color' => 'secondary', 'text' => $row['status']];
                        ?>
                        <tr class="trash-row">
                            <td class="text-center text-muted"><?php echo $stt++; ?></td>
                            <td>
                                <span class="job-no">
                                    <i class="bi bi-trash3 text-secondary"></i>
                                    <?php echo htmlspecialchars($row['job_no']); ?>
                                </span>
                                <?php if ($row['is_locked'] == 'yes'): ?>
                                    <br><span class="badge bg-danger" style="font-size:.65rem;">
                                        <i class="bi bi-lock-fill"></i> Đã khóa
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($row['customer_short'] ?? ''); ?>
                                </span>
                                <br><small class="text-muted">
                                    <?php echo htmlspecialchars($row['company_name'] ?? ''); ?>
                                </small>
                            </td>
                            <td>
                                <small>
                                    <span class="text-muted">M:</span>
                                    <strong><?php echo htmlspecialchars($row['mawb'] ?? '—'); ?></strong><br>
                                    <span class="text-muted">H:</span>
                                    <?php echo htmlspecialchars($row['hawb'] ?? '—'); ?>
                                </small>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($row['customs_declaration_no'] ?? '—') ?: '—'; ?>
                                </small>
                            </td>
                            <td>
                                <small>
                                    <?php echo htmlspecialchars($row['shipper'] ?? ''); ?>
                                    <?php if ($row['shipper'] && $row['cnee']): ?> / <?php endif; ?>
                                    <?php echo htmlspecialchars($row['cnee'] ?? ''); ?>
                                    <?php if (!$row['shipper'] && !$row['cnee']): ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $badge['color']; ?>">
                                    <?php echo $badge['text']; ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-danger fw-bold">
                                    <i class="bi bi-clock"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($row['deleted_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($row['deleted_by_name'] ?? '—'); ?></small>
                            </td>
                            <td>
                                <a href="trash.php?action=restore&id=<?php echo $row['id']; ?>"
                                   class="btn btn-success btn-sm"
                                   onclick="return confirm('Khôi phục lô hàng <?php echo htmlspecialchars($row['job_no']); ?> về danh sách?')"
                                   title="Khôi phục lô hàng này">
                                    <i class="bi bi-arrow-counterclockwise"></i> Khôi phục
                                </a>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                <a href="trash.php?action=purge&id=<?php echo $row['id']; ?>"
                                   class="btn btn-danger btn-sm ms-1"
                                   onclick="return confirm('⚠️ XÓA VĨNH VIỄN lô hàng <?php echo htmlspecialchars($row['job_no']); ?>?\n\nToàn bộ dữ liệu (chi phí, doanh thu, file đính kèm...) sẽ bị xóa.\nHành động này KHÔNG THỂ HOÀN TÁC!')"
                                   title="Xóa vĩnh viễn (Admin only)">
                                    <i class="bi bi-trash-fill"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile;
                        else: ?>
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <i class="bi bi-trash3" style="font-size:3rem;color:#ccc"></i>
                                <p class="text-muted mt-2 mb-0">Thùng rác trống</p>
                                <small class="text-muted">Các lô hàng bị xóa sẽ xuất hiện ở đây</small>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<footer class="bg-white text-center py-2 border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>