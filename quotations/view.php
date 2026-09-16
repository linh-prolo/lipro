<?php
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /app/shipments/index.php?error=no_permission");
    exit();
}

$conn = getDBConnection();

function fmtNum($val, $maxDec = 2): string {
    $val = floatval($val);
    $formatted = number_format($val, $maxDec, ',', '.');
    if (str_contains($formatted, ',')) {
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, ',');
    }
    return $formatted;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Xử lý action POST (đổi trạng thái)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_sent') {
        $stmt = $conn->prepare("UPDATE quotations SET status='sent' WHERE id=? AND status='draft'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        header("Location: view.php?id=$id" . ($affected > 0 ? '&success=sent' : '&error=no_change'));
        exit();
    } elseif ($action === 'mark_accepted') {
        $stmt = $conn->prepare("UPDATE quotations SET status='accepted' WHERE id=? AND status='sent'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        header("Location: view.php?id=$id" . ($affected > 0 ? '&success=accepted' : '&error=no_change'));
        exit();
    }
    header("Location: view.php?id=$id");
    exit();
}

// Load quotation
$stmt = $conn->prepare(
    "SELECT q.*, c.company_name, c.short_name, c.tax_code, c.address, c.phone, c.email
     FROM quotations q
     LEFT JOIN customers c ON q.customer_id = c.id
     WHERE q.id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quot) {
    header("Location: index.php");
    exit();
}

// Load items
$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Tính tổng theo từng tiền tệ
$totals = [];
foreach ($items as $item) {
    $cur = $item['currency'];
    $totals[$cur] = ($totals[$cur] ?? 0) + floatval($item['amount']);
}

$statusBadge = [
    'draft'    => ['color' => 'secondary', 'text' => 'Nháp',       'icon' => 'pencil'],
    'sent'     => ['color' => 'primary',   'text' => 'Đã gửi',     'icon' => 'send'],
    'accepted' => ['color' => 'success',   'text' => 'Chấp nhận',  'icon' => 'check-circle'],
    'rejected' => ['color' => 'danger',    'text' => 'Từ chối',    'icon' => 'x-circle'],
    'expired'  => ['color' => 'warning',   'text' => 'Hết hạn',    'icon' => 'clock'],
];
$badge = $statusBadge[$quot['status']] ?? ['color' => 'secondary', 'text' => $quot['status'], 'icon' => 'question'];

$pol      = $quot['pol']      ?? null;
$pod      = $quot['pod']      ?? null;
$shipper  = $quot['shipper']  ?? null;
$packages = $quot['packages'] ?? null;
$gw       = $quot['gw']       ?? null;
$cw       = $quot['cw']       ?? null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo Giá <?php echo htmlspecialchars($quot['quotation_no']); ?> - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
            .card { border:none !important; }
        }
        .items-table th { background:#343a40; color:#fff; font-size:.78rem; padding:8px 10px; }
        .items-table td { font-size:.85rem; vertical-align:middle; padding:6px 10px; }
        .total-row td { font-weight:700; background:#f8f9fa; }
    </style>
</head>
<body class="bg-light">

<?php include '../partials/navbar.php'; ?>

<div class="container-fluid mt-3 pb-5">

    <!-- Breadcrumb + Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm me-1">
                <i class="bi bi-arrow-left"></i> Danh sách
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning btn-sm me-1">
                <i class="bi bi-pencil"></i> Sửa
            </a>
            <a href="copy.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm me-1"
               onclick="return confirm('Sao chép báo giá này thành báo giá mới?')">
                <i class="bi bi-copy"></i> Copy báo giá
            </a>
            <a href="export_excel.php?id=<?php echo $id; ?>" class="btn btn-success btn-sm me-1">
                <i class="bi bi-file-earmark-excel"></i> Xuất Excel
            </a>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-printer"></i> In/PDF
            </button>
        </div>
        <div class="d-flex gap-2">
            <?php if ($quot['status'] === 'draft'): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="mark_sent">
                    <button type="submit" class="btn btn-primary btn-sm"
                            onclick="return confirm('Đánh dấu đã gửi báo giá này?')">
                        <i class="bi bi-send"></i> Đánh dấu đã gửi
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($quot['status'] === 'sent'): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="mark_accepted">
                    <button type="submit" class="btn btn-success btn-sm"
                            onclick="return confirm('Đánh dấu khách hàng chấp nhận báo giá này?')">
                        <i class="bi bi-check-circle"></i> Đánh dấu chấp nhận
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($quot['status'] === 'accepted'): ?>
                <a href="create_shipment.php?id=<?php echo $id; ?>"
                   class="btn btn-primary btn-sm"
                   onclick="return confirm('Tạo lô hàng mới từ báo giá này?\nCác dòng doanh thu sẽ được điền tự động từ báo giá.')">
                    <i class="bi bi-plus-circle"></i> Tạo lô hàng
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show no-print">
            <?php
                if ($_GET['success'] === 'updated')        echo '<i class="bi bi-check-circle"></i> Cập nhật báo giá thành công!';
                if ($_GET['success'] === 'sent')           echo '<i class="bi bi-send"></i> Đã đánh dấu báo giá là Đã gửi!';
                if ($_GET['success'] === 'accepted')       echo '<i class="bi bi-check-circle"></i> Đã đánh dấu báo giá là Chấp nhận!';
                if ($_GET['success'] === 'from_quotation') echo '<i class="bi bi-box"></i> Đã tạo lô hàng thành công từ báo giá này!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-warning alert-dismissible fade show no-print">
            <?php
                if ($_GET['error'] === 'no_change')       echo '<i class="bi bi-exclamation-triangle"></i> Không thể thay đổi trạng thái.';
                if ($_GET['error'] === 'not_accepted')    echo '<i class="bi bi-exclamation-triangle"></i> Chỉ có thể tạo lô hàng từ báo giá đã được chấp nhận.';
                if ($_GET['error'] === 'shipment_failed') echo '<i class="bi bi-exclamation-triangle"></i> Không thể tạo lô hàng. Vui lòng thử lại.';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Thông tin header -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-file-earmark-text"></i>
                    BÁO GIÁ: <?php echo htmlspecialchars($quot['quotation_no']); ?>
                </h5>
                <span class="badge bg-<?php echo $badge['color']; ?> fs-6">
                    <i class="bi bi-<?php echo $badge['icon']; ?>"></i>
                    <?php echo $badge['text']; ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <!-- Thông tin khách hàng -->
                <div class="col-md-6">
                    <h6 class="text-muted mb-2"><i class="bi bi-building"></i> Thông tin khách hàng</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:140px">Tên công ty:</td>
                            <td><strong><?php echo htmlspecialchars($quot['company_name'] ?? ''); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tên viết tắt:</td>
                            <td><?php echo htmlspecialchars($quot['short_name'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">MST:</td>
                            <td><?php echo htmlspecialchars($quot['tax_code'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Địa chỉ:</td>
                            <td><?php echo htmlspecialchars($quot['address'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Email:</td>
                            <td><?php echo htmlspecialchars($quot['email'] ?? ''); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Thông tin báo giá -->
                <div class="col-md-6">
                    <h6 class="text-muted mb-2"><i class="bi bi-file-text"></i> Thông tin báo giá</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:160px">Số báo giá:</td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($quot['quotation_no']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Ngày lập:</td>
                            <td><?php echo $quot['issue_date'] ? date('d/m/Y', strtotime($quot['issue_date'])) : ''; ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Hiệu lực đến:</td>
                            <td><?php echo $quot['valid_until'] ? date('d/m/Y', strtotime($quot['valid_until'])) : '<span class="text-muted">—</span>'; ?></td>
                        </tr>

                        <?php if (!empty($pol) || !empty($pod)): ?>
                        <tr>
                            <td class="text-muted">POL → POD:</td>
                            <td>
                                <strong><?php echo htmlspecialchars($pol ?: '—'); ?></strong>
                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <strong><?php echo htmlspecialchars($pod ?: '—'); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if (!empty($shipper)): ?>
                        <tr>
                            <td class="text-muted">Shipper (Người gửi):</td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($shipper); ?></strong></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($packages !== null && $packages !== ''): ?>
                        <tr>
                            <td class="text-muted">Số kiện:</td>
                            <td><?php echo fmtNum(floatval($packages)); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($gw !== null && $gw !== ''): ?>
                        <tr>
                            <td class="text-muted">GW:</td>
                            <td><?php echo fmtNum(floatval($gw)); ?> kg</td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($cw !== null && $cw !== ''): ?>
                        <tr>
                            <td class="text-muted">CW:</td>
                            <td><?php echo fmtNum(floatval($cw)); ?> kg</td>
                        </tr>
                        <?php endif; ?>

                        <tr>
                            <td class="text-muted">Tiền tệ:</td>
                            <td><?php echo htmlspecialchars($quot['currency']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tỉ giá:</td>
                            <td><?php echo fmtNum(floatval($quot['exchange_rate']), 4); ?></td>
                        </tr>

                        <?php if (!empty($quot['notes'])): ?>
                        <tr>
                            <td class="text-muted">Ghi chú:</td>
                            <td><?php echo nl2br(htmlspecialchars($quot['notes'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>

            </div>
        </div>
    </div>

    <!-- Bảng chi phí -->
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Chi tiết dòng chi phí</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table items-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Mã chi phí</th>
                            <th>Diễn giải</th>
                            <th>Tiền tệ</th>
                            <th class="text-end">Đơn giá</th>
                            <th class="text-end">Số lượng</th>
                            <th class="text-end">Thành tiền</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($items) > 0): ?>
                            <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($item['cost_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                                <td><?php echo htmlspecialchars($item['currency']); ?></td>
                                <td class="text-end"><?php echo fmtNum(floatval($item['unit_price'])); ?></td>
                                <td class="text-end"><?php echo fmtNum(floatval($item['quantity'])); ?></td>
                                <td class="text-end"><strong><?php echo fmtNum(floatval($item['amount'])); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['notes'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-3">Chưa có dòng chi phí nào</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (count($totals) > 0): ?>
                    <tfoot>
                        <?php foreach ($totals as $cur => $total): ?>
                        <tr class="total-row">
                            <td colspan="6" class="text-end">Tổng cộng (<?php echo htmlspecialchars($cur); ?>):</td>
                            <td class="text-end text-primary"><?php echo fmtNum($total); ?></td>
                            <td></td>
                        </tr>
                        <?php endforeach; ?>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer actions -->
    <div class="d-flex justify-content-between no-print">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Quay lại danh sách
        </a>
        <div class="d-flex gap-2">
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning">
                <i class="bi bi-pencil"></i> Sửa
            </a>
            <a href="copy.php?id=<?php echo $id; ?>" class="btn btn-outline-primary"
               onclick="return confirm('Sao chép báo giá này thành báo giá mới?')">
                <i class="bi bi-copy"></i> Copy báo giá
            </a>
            <a href="export_excel.php?id=<?php echo $id; ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Xuất Excel
            </a>
            <button onclick="window.print()" class="btn btn-outline-dark">
                <i class="bi bi-printer"></i> In/PDF
            </button>
        </div>
    </div>

</div>

<footer class="bg-light text-center py-3 mt-4 no-print">
    <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>