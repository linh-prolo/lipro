<?php
require_once '../config/database.php';
checkLogin();

$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;
if ($shipment_id == 0) { header("Location: ../shipments/index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*, c.company_name FROM shipments s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: ../shipments/index.php"); exit(); }

// ── POST: Import từ Arrival Notice ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_arrival'])) {
    $selected = $_POST['selected_rows'] ?? [];
    if (!empty($selected)) {
        $insStmt = $conn->prepare("
            INSERT INTO shipment_sells
                (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($selected as $anId) {
            $anId  = intval($anId);
            $anRow = $conn->query(
                "SELECT * FROM arrival_notice_charges
                 WHERE id=$anId AND shipment_id=$shipment_id LIMIT 1"
            )->fetch_assoc();
            if (!$anRow) continue;

            // Tìm cost_code_id
            $ccCode = strtoupper(trim($anRow['cost_code']));
            $ccRes  = $conn->query(
                "SELECT id FROM cost_codes
                 WHERE UPPER(code)='" . mysqli_real_escape_string($conn, $ccCode) . "' LIMIT 1"
            );
            $ccRow  = $ccRes ? $ccRes->fetch_assoc() : null;
            if (!$ccRow) continue; // bỏ qua nếu không có mã

            $ccId     = intval($ccRow['id']);
            $qty      = floatval($anRow['quantity']   ?? 1);
            $amtVnd   = floatval($anRow['amount_vnd'] ?? 0);
            $totalVnd = floatval($anRow['total_vnd']  ?? 0);
            $vat      = floatval($anRow['vat']        ?? 0);
            // ✅ Đơn giá = amount_vnd / quantity (đã quy đổi VND)
            $unitVnd  = ($qty > 0) ? round($amtVnd / $qty) : 0;
            $notes    = trim($anRow['description'] ?? '');
            $userId   = intval($_SESSION['user_id']);

            // ✅ type string đúng: i=shipment_id, i=ccId, d=qty, d=unitVnd, d=vat, d=totalVnd, s=notes, i=userId
            $insStmt->bind_param("iidddisi",
                $shipment_id, $ccId, $qty, $unitVnd, $vat, $totalVnd, $notes, $userId
            );
            $insStmt->execute();
        }
        $insStmt->close();
    }
    header("Location: manage.php?shipment_id=$shipment_id&success=added");
    exit();
}

// ── Lấy danh sách sell ───────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT ss.*,
            COALESCE(cc.code, '(Chưa có mã)') AS code,
            COALESCE(cc.description, ss.notes) AS description
     FROM shipment_sells ss
     LEFT JOIN cost_codes cc ON ss.cost_code_id = cc.id
     WHERE ss.shipment_id = ?
     ORDER BY ss.id ASC"
);
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$sells = $stmt->get_result();

// ── Tổng hợp ────────────────────────────────────────────────────
$total_sell        = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells WHERE shipment_id=$shipment_id")->fetch_assoc()['t']);
$total_cost        = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_costs  WHERE shipment_id=$shipment_id")->fetch_assoc()['t']);
$total_pob         = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells WHERE shipment_id=$shipment_id AND is_pob=1")->fetch_assoc()['t']);
$total_vat_invoice = $total_sell - $total_pob;
$profit            = $total_sell - $total_cost;
$profit_percent    = $total_sell > 0 ? ($profit / $total_sell * 100) : 0;

// ── Load arrival charges cho modal ──────────────────────────────
$anCharges = $conn->query(
    "SELECT anc.*,
            COALESCE(cc.id, 0) AS cost_code_id_found
     FROM arrival_notice_charges anc
     LEFT JOIN cost_codes cc ON UPPER(cc.code) = UPPER(anc.cost_code)
     WHERE anc.shipment_id = $shipment_id
     ORDER BY anc.charge_group, anc.sort_order"
)->fetch_all(MYSQLI_ASSOC);

$anForeign  = array_values(array_filter($anCharges, fn($r) => $r['charge_group'] === 'foreign'));
$anLocal    = array_values(array_filter($anCharges, fn($r) => $r['charge_group'] === 'local'));
$hasArrival = count($anCharges) > 0;

// ── Đếm POB (dùng lại conn trước khi close) ─────────────────────
$pobCnt = 0;
if ($total_pob > 0) {
    $pobCnt = intval($conn->query("SELECT COUNT(*) c FROM shipment_sells WHERE shipment_id=$shipment_id AND is_pob=1")->fetch_assoc()['c']);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Doanh thu - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table tbody td { vertical-align:middle; font-size:.87rem; }
        .row-pob { background:#fffbeb !important; opacity:.88; }
        .pob-badge { font-size:.7rem; padding:2px 7px; border-radius:10px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; white-space:nowrap; }
        .trucking-note { font-size:.78rem; color:#6b7280; font-style:italic; }
        .an-row-foreign { background:#e8f4fd; }
        .an-row-local   { background:#e8f9ee; }
        .an-badge-foreign { background:#2F5496; color:#fff; font-size:.72rem; padding:2px 7px; border-radius:8px; }
        .an-badge-local   { background:#538135; color:#fff; font-size:.72rem; padding:2px 7px; border-radius:8px; }
        .no-cc { opacity:.5; }
        .modal-xl { max-width:92vw; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
    </div>
</nav>

<div class="container-fluid mt-4">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-currency-dollar text-success"></i>
            Doanh thu bán ra (SELL) —
            <strong class="text-primary"><?php echo htmlspecialchars($shipment['job_no']); ?></strong>
        </h5>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($hasArrival): ?>
            <button type="button" class="btn btn-primary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#modalArrival">
                <i class="bi bi-file-earmark-text"></i> Lấy từ Arrival Notice
            </button>
            <?php endif; ?>
            <a href="add.php?shipment_id=<?php echo $shipment_id; ?>" class="btn btn-success btn-sm">
                <i class="bi bi-plus-circle"></i> Thêm thủ công
            </a>
            <a href="../shipments/view.php?id=<?php echo $shipment_id; ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </div>

    <!-- CARDS -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card bg-danger text-white text-center p-2 shadow-sm">
                <small><i class="bi bi-cash-stack"></i> Tổng COST</small>
                <strong><?php echo number_format($total_cost, 0, ',', '.'); ?></strong>
                <small>VND</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card bg-success text-white text-center p-2 shadow-sm">
                <small><i class="bi bi-currency-dollar"></i> Tổng SELL</small>
                <strong><?php echo number_format($total_sell, 0, ',', '.'); ?></strong>
                <small>VND</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card <?php echo $profit >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white text-center p-2 shadow-sm">
                <small><i class="bi bi-graph-up"></i> Lợi nhuận</small>
                <strong><?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?></strong>
                <small><?php echo number_format($profit_percent, 1); ?>%</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-2 shadow-sm" style="background:#fef9c3;border:1px solid #fcd34d;">
                <small><i class="bi bi-arrow-left-right text-warning"></i> Chi hộ (POB)</small>
                <strong class="text-warning"><?php echo number_format($total_pob, 0, ',', '.'); ?></strong>
                <small class="text-muted">VND</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-2 shadow-sm" style="background:#dcfce7;border:1px solid #86efac;">
                <small><i class="bi bi-receipt-cutoff text-success"></i> Xuất HĐ VAT</small>
                <strong class="text-success"><?php echo number_format($total_vat_invoice, 0, ',', '.'); ?></strong>
                <small class="text-muted">VND</small>
            </div>
        </div>
    </div>

    <!-- THÔNG BÁO -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="bi bi-check-circle"></i>
        <?php
            if ($_GET['success'] == 'added')   echo 'Thêm doanh thu thành công!';
            if ($_GET['success'] == 'deleted') echo 'Xóa doanh thu thành công!';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- BẢNG SELL -->
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white py-2">
            <i class="bi bi-table"></i> Danh sách doanh thu
            <span class="badge bg-light text-dark ms-2"><?php echo $sells->num_rows; ?> dòng</span>
            <?php if ($total_pob > 0): ?>
            <span class="badge ms-1" style="background:#fcd34d;color:#92400e;">
                <i class="bi bi-arrow-left-right"></i> <?php echo $pobCnt; ?> khoản chi hộ
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:35px">#</th>
                            <th>Mã CP</th>
                            <th>Nội dung</th>
                            <th class="text-center">SL</th>
                            <th class="text-end">Đơn giá</th>
                            <th class="text-center">VAT%</th>
                            <th class="text-end">Thành tiền</th>
                            <th class="text-center" style="width:80px">Chi hộ</th>
                            <th>Ghi chú</th>
                            <th style="width:60px">Xóa</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($sells->num_rows > 0):
                        $stt = 1;
                        while ($row = $sells->fetch_assoc()):
                            $is_pob      = intval($row['is_pob'] ?? 0);
                            $is_trucking = stripos($row['code'] ?? '', 'TRUCK') !== false;
                    ?>
                    <tr class="<?php echo $is_pob ? 'row-pob' : ''; ?>">
                        <td class="text-center text-muted"><?php echo $stt++; ?></td>
                        <td>
                            <span class="badge <?php echo $row['code'] === '(Chưa có mã)' ? 'bg-secondary' : 'bg-success'; ?>">
                                <?php echo htmlspecialchars($row['code']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($row['description']); ?>
                            <?php if ($is_trucking && !empty($row['notes'])): ?>
                            <br><span class="trucking-note">
                                <i class="bi bi-truck"></i> <?php echo htmlspecialchars($row['notes']); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo number_format($row['quantity'], 2); ?></td>
                        <td class="text-end"><?php echo number_format($row['unit_price'], 0, ',', '.'); ?></td>
                        <td class="text-center"><?php echo number_format($row['vat'], 1); ?>%</td>
                        <td class="text-end fw-bold text-success">
                            <?php echo number_format($row['total_amount'], 0, ',', '.'); ?>
                        </td>
                        <td class="text-center">
                            <?php if ($is_pob): ?>
                            <span class="pob-badge">
                                <i class="bi bi-check-circle-fill text-warning"></i> Chi hộ
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo !$is_trucking ? htmlspecialchars($row['notes'] ?? '') : ''; ?>
                            </small>
                        </td>
                        <td>
                            <a href="delete.php?id=<?php echo $row['id']; ?>&shipment_id=<?php echo $shipment_id; ?>"
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Xóa dòng này?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>

                    <!-- Dòng TỔNG -->
                    <tr class="table-success fw-bold">
                        <td colspan="6" class="text-end">TỔNG SELL:</td>
                        <td class="text-end text-success"><?php echo number_format($total_sell, 0, ',', '.'); ?></td>
                        <td colspan="3"></td>
                    </tr>
                    <?php if ($total_pob > 0): ?>
                    <tr style="background:#fffbeb;">
                        <td colspan="6" class="text-end text-muted">
                            <i class="bi bi-arrow-left-right"></i> Trong đó Chi hộ (không xuất HĐ VAT):
                        </td>
                        <td class="text-end" style="color:#92400e;">
                            <?php echo number_format($total_pob, 0, ',', '.'); ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                    <tr style="background:#dcfce7;">
                        <td colspan="6" class="text-end fw-bold">
                            <i class="bi bi-receipt-cutoff text-success"></i> Tổng xuất Hoá đơn VAT:
                        </td>
                        <td class="text-end fw-bold text-success">
                            <?php echo number_format($total_vat_invoice, 0, ',', '.'); ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                    <?php endif; ?>

                    <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox" style="font-size:2.5rem;color:#ccc;"></i>
                            <p class="mt-2">Chưa có doanh thu nào</p>
                            <?php if ($hasArrival): ?>
                            <button type="button" class="btn btn-sm btn-primary me-2"
                                    data-bs-toggle="modal" data-bs-target="#modalArrival">
                                <i class="bi bi-file-earmark-text"></i> Lấy từ Arrival Notice
                            </button>
                            <?php endif; ?>
                            <a href="add.php?shipment_id=<?php echo $shipment_id; ?>"
                               class="btn btn-sm btn-success">
                                <i class="bi bi-plus-circle"></i> Thêm thủ công
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- BẢNG TỔNG HỢP -->
    <div class="card mt-3 border-primary shadow-sm">
        <div class="card-header bg-primary text-white py-2">
            <i class="bi bi-calculator"></i> Tổng hợp chi phí & lợi nhuận
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered mb-0">
                <tr>
                    <td width="55%">
                        <i class="bi bi-cash-stack text-danger"></i> Tổng chi phí đầu vào (COST):
                    </td>
                    <td class="text-end fw-bold text-danger">
                        <?php echo number_format($total_cost, 0, ',', '.'); ?> VND
                    </td>
                </tr>
                <tr>
                    <td><i class="bi bi-currency-dollar text-success"></i> Tổng doanh thu (SELL):</td>
                    <td class="text-end fw-bold text-success">
                        <?php echo number_format($total_sell, 0, ',', '.'); ?> VND
                    </td>
                </tr>
                <?php if ($total_pob > 0): ?>
                <tr style="background:#fffbeb;">
                    <td class="text-muted">
                        <i class="bi bi-arrow-left-right text-warning"></i> Trong đó Chi hộ (POB):
                    </td>
                    <td class="text-end" style="color:#92400e;">
                        <?php echo number_format($total_pob, 0, ',', '.'); ?> VND
                    </td>
                </tr>
                <tr style="background:#dcfce7;">
                    <td>
                        <i class="bi bi-receipt-cutoff text-success"></i>
                        Tổng xuất Hoá đơn VAT (SELL - Chi hộ):
                    </td>
                    <td class="text-end fw-bold text-success">
                        <?php echo number_format($total_vat_invoice, 0, ',', '.'); ?> VND
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="<?php echo $profit >= 0 ? 'table-success' : 'table-danger'; ?>">
                    <td><i class="bi bi-graph-up"></i> Lợi nhuận (SELL - COST):</td>
                    <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?> VND
                        <span class="badge <?php echo $profit >= 0 ? 'bg-success' : 'bg-danger'; ?> ms-1">
                            <?php echo number_format($profit_percent, 1); ?>%
                        </span>
                        <?php echo $profit >= 0 ? '✅' : '❌'; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

</div><!-- /container -->

<!-- ================================================================ -->
<!-- MODAL: Lấy từ Arrival Notice                                      -->
<!-- ================================================================ -->
<?php if ($hasArrival): ?>
<div class="modal fade" id="modalArrival" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text"></i>
                    Chọn phí từ Arrival Notice — <?php echo htmlspecialchars($shipment['job_no']); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="importForm">
                <input type="hidden" name="import_arrival" value="1">

                <div class="modal-body p-0">
                    <!-- Toolbar -->
                    <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light border-bottom flex-wrap">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(true)">
                            <i class="bi bi-check-all"></i> Chọn tất cả
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(false)">
                            <i class="bi bi-x-circle"></i> Bỏ chọn
                        </button>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Dòng mờ = mã chưa có trong Cost Codes (sẽ bị bỏ qua khi import)
                        </small>
                        <span class="ms-auto badge bg-primary" id="selectedCount">0 dòng được chọn</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width:40px" class="text-center">✓</th>
                                    <th>Nhóm</th>
                                    <th>Cost Code</th>
                                    <th>Diễn giải</th>
                                    <th class="text-center">Tiền tệ</th>
                                    <th class="text-end">Đơn giá</th>
                                    <th class="text-center">SL</th>
                                    <th class="text-end">Thành tiền VND</th>
                                    <th class="text-center">VAT%</th>
                                    <th class="text-end fw-bold">Tổng VND</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $renderRows = function(array $rows, string $label, string $rowCls, string $badgeCls) {
                                foreach ($rows as $row):
                                    $hasCC = !empty($row['cost_code_id_found']);
                            ?>
                            <tr class="<?php echo $rowCls . (!$hasCC ? ' no-cc' : ''); ?>">
                                <td class="text-center">
                                    <?php if ($hasCC): ?>
                                    <input type="checkbox" name="selected_rows[]"
                                           value="<?php echo $row['id']; ?>"
                                           class="form-check-input an-checkbox"
                                           onchange="updateCount()">
                                    <?php else: ?>
                                    <i class="bi bi-slash-circle text-muted"
                                       title="Mã '<?php echo htmlspecialchars($row['cost_code']); ?>' chưa có trong Cost Codes"></i>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $badgeCls; ?>"><?php echo $label; ?></span></td>
                                <td><strong><?php echo htmlspecialchars($row['cost_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($row['currency']); ?></td>
                                <td class="text-end">
                                    <?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?>
                                </td>
                                <td class="text-center">
                                    <?php echo number_format(floatval($row['quantity']), 2, ',', '.'); ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format(floatval($row['amount_vnd']), 0, ',', '.'); ?>
                                </td>
                                <td class="text-center">
                                    <?php echo floatval($row['vat']) > 0 ? floatval($row['vat']).'%' : '-'; ?>
                                </td>
                                <td class="text-end fw-bold <?php echo $label === 'Nước ngoài' ? 'text-primary' : 'text-success'; ?>">
                                    <?php echo number_format(floatval($row['total_vnd']), 0, ',', '.'); ?>
                                </td>
                            </tr>
                            <?php
                                endforeach;
                            };
                            $renderRows($anForeign, 'Nước ngoài', 'an-row-foreign', 'an-badge-foreign');
                            $renderRows($anLocal,   'Việt Nam',   'an-row-local',   'an-badge-local');
                            ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-3 py-2 bg-light border-top">
                        <small class="text-muted">
                            <i class="bi bi-pencil-square text-warning"></i>
                            Muốn <strong>chỉnh sửa</strong> số liệu? Vào
                            <a href="../shipments/arrival_notice.php?id=<?php echo $shipment_id; ?>"
                               target="_blank">
                                Arrival Notice <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                            rồi lấy lại.
                        </small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i> Đóng
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnImport" disabled>
                        <i class="bi bi-download"></i>
                        Nhập <span id="btnCount">0</span> dòng vào SELL
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateCount() {
    const n = document.querySelectorAll('.an-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = n + ' dòng được chọn';
    document.getElementById('btnCount').textContent      = n;
    document.getElementById('btnImport').disabled        = (n === 0);
}
function selectAll(checked) {
    document.querySelectorAll('.an-checkbox').forEach(cb => cb.checked = checked);
    updateCount();
}
</script>
</body>
</html>