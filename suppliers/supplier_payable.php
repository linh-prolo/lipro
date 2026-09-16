<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// ============================================================
// BỘ LỌC
// ============================================================
$month_filter = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$sup_filter   = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

$filter_year  = '';
$filter_month = '';
if ($month_filter) {
    $parts        = explode('-', $month_filter);
    $filter_year  = $parts[0] ?? '';
    $filter_month = $parts[1] ?? '';
}

// ============================================================
// BUILD WHERE
// ============================================================
$where = ["1=1"];

if ($filter_year && $filter_month) {
    $where[] = "YEAR(s.created_at) = "  . intval($filter_year);
    $where[] = "MONTH(s.created_at) = " . intval($filter_month);
}
if ($sup_filter > 0) {
    $where[] = "sc.supplier_id = " . intval($sup_filter);
} elseif ($sup_filter === -1) {
    // Lọc riêng nhóm chưa phân NCC
    $where[] = "sc.supplier_id IS NULL";
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// ============================================================
// LẤY CHI TIẾT TỪNG DÒNG COST
// ============================================================
$sql = "SELECT
            sc.id,
            sc.shipment_id,
            sc.quantity,
            sc.unit_price,
            sc.vat,
            sc.total_amount,
            sc.notes,
            sc.created_at  AS cost_created_at,
            cc.code        AS cost_code,
            cc.description AS cost_desc,
            s.job_no,
            s.hawb,
            s.mawb,
            s.customs_declaration_no,
            s.created_at   AS shipment_date,
            sup.id         AS supplier_id,
            sup.supplier_name,
            sup.short_name  AS sup_short,
            sup.bank_name,
            sup.bank_account,
            sup.phone       AS sup_phone,
            sup.email       AS sup_email
        FROM shipment_costs sc
        JOIN cost_codes cc      ON sc.cost_code_id = cc.id
        JOIN shipments s        ON sc.shipment_id  = s.id
        LEFT JOIN suppliers sup ON sc.supplier_id  = sup.id
        {$whereClause}
        ORDER BY
            CASE WHEN sc.supplier_id IS NULL THEN 1 ELSE 0 END ASC,
            sup.supplier_name ASC,
            s.created_at ASC,
            sc.id ASC";

$result = $conn->query($sql);
$rows   = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

// ============================================================
// NHÓM THEO NCC
// ============================================================
$grouped = [];
foreach ($rows as $row) {
    $sid = $row['supplier_id'] ?? 'unassigned';

    if (!isset($grouped[$sid])) {
        if ($sid === 'unassigned') {
            $grouped[$sid] = [
                'info'  => [
                    'id'           => null,
                    'supplier_name'=> 'Chưa phân nhà cung cấp',
                    'short_name'   => 'N/A',
                    'bank_name'    => null,
                    'bank_account' => null,
                    'phone'        => null,
                    'email'        => null,
                ],
                'items' => [],
                'total' => 0,
            ];
        } else {
            $grouped[$sid] = [
                'info'  => [
                    'id'           => $sid,
                    'supplier_name'=> $row['supplier_name'],
                    'short_name'   => $row['sup_short'],
                    'bank_name'    => $row['bank_name'],
                    'bank_account' => $row['bank_account'],
                    'phone'        => $row['sup_phone'],
                    'email'        => $row['sup_email'],
                ],
                'items' => [],
                'total' => 0,
            ];
        }
    }
    $grouped[$sid]['items'][] = $row;
    $grouped[$sid]['total']  += (float)$row['total_amount'];
}

$grand_total = array_sum(array_column($grouped, 'total'));

// ============================================================
// DANH SÁCH NCC ĐỂ LỌC
// ============================================================
$all_suppliers = $conn->query(
    "SELECT id, supplier_name, short_name FROM suppliers WHERE status='active' ORDER BY supplier_name ASC"
);

// ============================================================
// DANH SÁCH THÁNG CÓ DỮ LIỆU
// ============================================================
$months_rs = $conn->query(
    "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                     DATE_FORMAT(created_at, '%m/%Y') AS label
     FROM shipments
     ORDER BY ym DESC
     LIMIT 24"
);
$months = [];
while ($m = $months_rs->fetch_assoc()) {
    $months[] = $m;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Công nợ Nhà cung cấp - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .supplier-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-left: 5px solid #fd7e14;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 0;
        }
        .supplier-header.unassigned {
            border-left-color: #6c757d;
            background: linear-gradient(135deg, #f8f9fa, #dee2e6);
        }
        .supplier-total {
            font-size: 1.3rem;
            font-weight: 700;
            color: #dc3545;
        }
        .table-cost th { font-size: .78rem; white-space: nowrap; }
        .table-cost td { font-size: .82rem; vertical-align: middle; }
        .grand-total-card {
            background: linear-gradient(135deg, #1B3A6B, #2E75B6);
            color: #fff;
            border-radius: 10px;
            padding: 20px 28px;
        }
        .month-badge {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: .85rem;
            font-weight: 600;
        }
        @media print {
            .print-hide { display: none !important; }
            .card { box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top print-hide">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../shipments/index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="index.php">Nhà cung cấp</a></li>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Quản trị</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="../accounts/index.php">Tài khoản</a></li>
                        <li><a class="dropdown-item" href="../cost_codes/index.php">Mã chi phí</a></li>
                    </ul>
                </li>
                <?php endif; ?>
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

    <!-- BREADCRUMB -->
    <nav aria-label="breadcrumb" class="mb-3 print-hide">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Nhà cung cấp</a></li>
            <li class="breadcrumb-item active">Công nợ phải trả</li>
        </ol>
    </nav>

    <!-- TIÊU ĐỀ + GRAND TOTAL -->
    <div class="row g-3 mb-3 align-items-center">
        <div class="col-md-6">
            <h4 class="mb-0">
                <i class="bi bi-wallet2 text-warning"></i>
                Công nợ phải trả Nhà cung cấp
            </h4>
            <small class="text-muted">
                Tháng:
                <span class="month-badge">
                    <?php echo ($filter_month && $filter_year)
                        ? $filter_month . '/' . $filter_year
                        : 'Tất cả'; ?>
                </span>
                &nbsp;|&nbsp;
                <strong><?php echo count($grouped); ?></strong> nhà cung cấp
                &nbsp;|&nbsp;
                <strong><?php echo count($rows); ?></strong> khoản phí
            </small>
        </div>
        <div class="col-md-6">
            <div class="grand-total-card d-flex justify-content-between align-items-center">
                <div>
                    <div style="font-size:.85rem;opacity:.8;">TỔNG PHẢI TRẢ (tất cả NCC)</div>
                    <div style="font-size:1.8rem;font-weight:800;letter-spacing:1px;">
                        <?php echo number_format($grand_total, 0, ',', '.'); ?>
                        <span style="font-size:1rem;opacity:.8;">VND</span>
                    </div>
                </div>
                <i class="bi bi-cash-coin" style="font-size:3rem;opacity:.3;"></i>
            </div>
        </div>
    </div>

    <!-- BỘ LỌC -->
    <div class="card shadow-sm mb-4 print-hide">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <!-- Lọc tháng -->
                <div class="col-md-3">
                    <label class="form-label fw-bold mb-1">
                        <i class="bi bi-calendar-month text-primary"></i> Tháng
                    </label>
                    <input type="month" name="month" class="form-control"
                           value="<?php echo htmlspecialchars($month_filter); ?>">
                </div>

                <!-- Lọc NCC -->
                <div class="col-md-4">
                    <label class="form-label fw-bold mb-1">
                        <i class="bi bi-truck text-warning"></i> Nhà cung cấp
                    </label>
                    <select name="supplier_id" class="form-select">
                        <option value="0">-- Tất cả nhà cung cấp --</option>
                        <option value="-1" <?php echo $sup_filter == -1 ? 'selected' : ''; ?>>
                            ⚠️ Chưa phân nhà cung cấp
                        </option>
                        <?php while ($sup = $all_suppliers->fetch_assoc()): ?>
                        <option value="<?php echo $sup['id']; ?>"
                            <?php echo $sup_filter == $sup['id'] ? 'selected' : ''; ?>>
                            [<?php echo htmlspecialchars($sup['short_name']); ?>]
                            <?php echo htmlspecialchars($sup['supplier_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="col-md-5 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Lọc
                    </button>
                    <a href="supplier_payable.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Xóa lọc
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-outline-dark">
                        <i class="bi bi-printer"></i> In
                    </button>
                    <!-- Xuất Excel toàn bộ (theo bộ lọc hiện tại) -->
                    <a href="export_supplier_excel.php?mode=all&month=<?php echo urlencode($month_filter); ?>&supplier_id=<?php echo $sup_filter; ?>"
                       class="btn btn-success">
                        <i class="bi bi-file-earmark-excel"></i> Xuất Excel (tất cả)
                    </a>
                </div>

                <!-- Tháng gợi ý -->
                <?php if (!empty($months)): ?>
                <div class="col-12">
                    <small class="text-muted me-2">Tháng có dữ liệu:</small>
                    <?php foreach ($months as $m): ?>
                    <a href="?month=<?php echo $m['ym']; ?>&supplier_id=<?php echo $sup_filter; ?>"
                       class="badge me-1 text-decoration-none
                              <?php echo $month_filter == $m['ym'] ? 'bg-primary' : 'bg-light text-dark border'; ?>">
                        <?php echo $m['label']; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- KHÔNG CÓ DỮ LIỆU -->
    <?php if (empty($grouped)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox text-muted" style="font-size:3.5rem;"></i>
            <p class="text-muted mt-3 mb-0">
                Không có chi phí nào trong tháng
                <strong><?php echo $filter_month . '/' . $filter_year; ?></strong>
            </p>
            <a href="supplier_payable.php" class="btn btn-primary btn-sm mt-2">
                Xem tất cả tháng
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- DANH SÁCH THEO NCC -->
    <?php foreach ($grouped as $sid => $group): ?>
    <?php
        $info         = $group['info'];
        $isUnassigned = ($info['id'] === null);
    ?>

    <div class="card shadow-sm mb-4">
        <!-- Header NCC -->
        <div class="card-header p-0 border-0">
            <div class="supplier-header <?php echo $isUnassigned ? 'unassigned' : ''; ?>
                        d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <span class="badge <?php echo $isUnassigned ? 'bg-secondary' : 'bg-warning text-dark'; ?> fs-6 me-2">
                        <?php echo htmlspecialchars($info['short_name']); ?>
                    </span>
                    <strong class="fs-5 <?php echo $isUnassigned ? 'text-secondary fst-italic' : ''; ?>">
                        <?php if ($isUnassigned): ?>
                            <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($info['supplier_name']); ?>
                    </strong>
                    <?php if (!$isUnassigned): ?>
                    <div class="mt-1 d-flex flex-wrap gap-3">
                        <?php if ($info['phone']): ?>
                        <small class="text-muted">
                            <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($info['phone']); ?>
                        </small>
                        <?php endif; ?>
                        <?php if ($info['email']): ?>
                        <small class="text-muted">
                            <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($info['email']); ?>
                        </small>
                        <?php endif; ?>
                        <?php if ($info['bank_name'] || $info['bank_account']): ?>
                        <small class="text-success fw-bold">
                            <i class="bi bi-bank"></i>
                            <?php echo htmlspecialchars($info['bank_name']); ?>
                            &nbsp;|&nbsp;
                            <i class="bi bi-credit-card"></i>
                            <?php echo htmlspecialchars($info['bank_account']); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="mt-1">
                        <small class="text-muted fst-italic">
                            Các khoản phí này chưa được gán nhà cung cấp. Vui lòng cập nhật.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="text-muted" style="font-size:.8rem;">Tổng phải trả</div>
                    <div class="supplier-total">
                        <?php echo number_format($group['total'], 0, ',', '.'); ?>
                        <span style="font-size:.9rem;color:#6c757d;">VND</span>
                    </div>
                    <small class="text-muted"><?php echo count($group['items']); ?> khoản phí</small>
                    <br>
                    <!-- Xuất Excel từng NCC (ẩn với nhóm unassigned vì dùng mode=unassigned) -->
                    <?php if (!$isUnassigned): ?>
                    <a href="export_supplier_excel.php?mode=single&supplier_id=<?php echo $sid; ?>&month=<?php echo urlencode($month_filter); ?>"
                       class="btn btn-success btn-sm mt-2 print-hide"
                       title="Xuất Excel cho <?php echo htmlspecialchars($info['supplier_name']); ?>">
                        <i class="bi bi-file-earmark-excel"></i> Xuất Excel
                    </a>
                    <?php else: ?>
                    <a href="export_supplier_excel.php?mode=unassigned&month=<?php echo urlencode($month_filter); ?>"
                       class="btn btn-outline-secondary btn-sm mt-2 print-hide"
                       title="Xuất Excel danh sách chưa phân NCC">
                        <i class="bi bi-file-earmark-excel"></i> Xuất Excel
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bảng chi tiết -->
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-cost mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Job No</th>
                            <th>HAWB</th>
                            <th>Tờ khai</th>
                            <th>Ngày lô hàng</th>
                            <th>Mã CP</th>
                            <th>Nội dung</th>
                            <th class="text-center">SL</th>
                            <th class="text-end">Đơn giá</th>
                            <th class="text-center">VAT%</th>
                            <th class="text-end">Thành tiền</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $stt = 1; foreach ($group['items'] as $item): ?>
                        <tr <?php echo $isUnassigned ? 'class="table-warning"' : ''; ?>>
                            <td class="ps-3 text-muted"><?php echo $stt++; ?></td>
                            <td>
                                <a href="../shipments/view.php?id=<?php echo $item['shipment_id']; ?>"
                                   class="fw-bold text-primary text-decoration-none">
                                    <?php echo htmlspecialchars($item['job_no']); ?>
                                </a>
                            </td>
                            <td>
                                <small class="fw-bold">
                                    <?php echo htmlspecialchars($item['hawb'] ?: '—'); ?>
                                </small>
                            </td>
                            <td>
                                <small>
                                    <?php echo htmlspecialchars($item['customs_declaration_no'] ?: '—'); ?>
                                </small>
                            </td>
                            <td>
                                <small>
                                    <?php echo date('d/m/Y', strtotime($item['shipment_date'])); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-danger">
                                    <?php echo htmlspecialchars($item['cost_code']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($item['cost_desc']); ?></td>
                            <td class="text-center"><?php echo number_format($item['quantity'], 2); ?></td>
                            <td class="text-end"><?php echo number_format($item['unit_price'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($item['vat'], 1); ?>%</td>
                            <td class="text-end fw-bold text-danger">
                                <?php echo number_format($item['total_amount'], 0, ',', '.'); ?>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($item['notes'] ?? ''); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-bold">
                            <td colspan="10" class="text-end pe-3">
                                TỔNG PHẢI TRẢ
                                <span class="badge <?php echo $isUnassigned ? 'bg-secondary' : 'bg-warning text-dark'; ?> ms-1">
                                    <?php echo htmlspecialchars($info['short_name']); ?>
                                </span>:
                            </td>
                            <td class="text-end text-danger fs-6">
                                <?php echo number_format($group['total'], 0, ',', '.'); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Footer ngân hàng -->
        <?php if (!$isUnassigned && ($info['bank_name'] || $info['bank_account'])): ?>
        <div class="card-footer bg-light py-2">
            <small>
                <i class="bi bi-info-circle text-success"></i>
                <strong>Chuyển khoản:</strong>
                STK <strong class="text-success"><?php echo htmlspecialchars($info['bank_account']); ?></strong>
                &nbsp;–&nbsp; <?php echo htmlspecialchars($info['bank_name']); ?>
                &nbsp;–&nbsp; <?php echo htmlspecialchars($info['supplier_name']); ?>
            </small>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- TỔNG KẾT CUỐI TRANG -->
    <?php if (!empty($grouped)): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-bordered mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nhà cung cấp</th>
                        <th class="text-center">Số khoản phí</th>
                        <th class="text-end">Tổng phải trả (VND)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped as $gSid => $group): ?>
                    <?php $isU = ($group['info']['id'] === null); ?>
                    <tr <?php echo $isU ? 'class="table-secondary"' : ''; ?>>
                        <td>
                            <span class="badge <?php echo $isU ? 'bg-secondary' : 'bg-warning text-dark'; ?> me-1">
                                <?php echo htmlspecialchars($group['info']['short_name']); ?>
                            </span>
                            <?php if ($isU): ?><em><?php endif; ?>
                            <?php echo htmlspecialchars($group['info']['supplier_name']); ?>
                            <?php if ($isU): ?></em><?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo count($group['items']); ?></td>
                        <td class="text-end fw-bold text-danger">
                            <?php echo number_format($group['total'], 0, ',', '.'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-danger fw-bold">
                        <td>TỔNG CỘNG</td>
                        <td class="text-center"><?php echo count($rows); ?></td>
                        <td class="text-end fs-5">
                            <?php echo number_format($grand_total, 0, ',', '.'); ?> VND
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<footer class="bg-white text-center py-2 border-top print-hide">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>