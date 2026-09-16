<?php
require_once '../config/database.php';
checkLogin();

$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;
if ($shipment_id == 0) { header("Location: ../shipments/index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT job_no FROM shipments WHERE id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: ../shipments/index.php"); exit(); }

// Load arrival_notice_charges để hiển thị modal
$anCharges = $conn->query(
    "SELECT anc.*, 
            COALESCE(cc.id, 0) AS cost_code_id_found,
            COALESCE(cc.code, '') AS cc_code
     FROM arrival_notice_charges anc
     LEFT JOIN cost_codes cc ON cc.code = anc.cost_code
     WHERE anc.shipment_id = $shipment_id
     ORDER BY anc.charge_group, anc.sort_order"
)->fetch_all(MYSQLI_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // ── Lưu nhiều dòng từ Arrival Notice ──────────────────────────
    if (isset($_POST['import_arrival'])) {
        $selected = $_POST['selected_rows'] ?? [];
        if (empty($selected)) {
            $error = 'Vui lòng chọn ít nh���t 1 dòng!';
        } else {
            $insStmt = $conn->prepare("
                INSERT INTO shipment_sells
                    (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($selected as $anId) {
                $anId = intval($anId);
                // Lấy dòng arrival
                $anRow = $conn->query("SELECT * FROM arrival_notice_charges WHERE id=$anId AND shipment_id=$shipment_id")->fetch_assoc();
                if (!$anRow) continue;

                // Tìm cost_code_id trong cost_codes
                $ccCode  = strtoupper(trim($anRow['cost_code']));
                $ccRes   = $conn->query("SELECT id FROM cost_codes WHERE code='$ccCode' LIMIT 1");
                $ccRow   = $ccRes ? $ccRes->fetch_assoc() : null;
                if (!$ccRow) continue; // bỏ qua nếu không tìm thấy cost code

                $ccId       = intval($ccRow['id']);
                $qty        = floatval($anRow['quantity']   ?? 1);
                $unitPrice  = floatval($anRow['unit_price'] ?? 0);
                $vat        = floatval($anRow['vat']        ?? 0);
                $totalVnd   = floatval($anRow['total_vnd']  ?? 0);
                $notes      = $anRow['description'] ?? '';
                $userId     = $_SESSION['user_id'];

                $insStmt->bind_param("iidddids",
                    $shipment_id, $ccId, $qty, $unitPrice, $vat, $totalVnd, $notes, $userId
                );
                $insStmt->execute();
            }
            $insStmt->close();
            $conn->close();
            header("Location: manage.php?shipment_id=$shipment_id&success=added");
            exit();
        }
    }

    // ── Lưu 1 dòng thủ công ───────────────────────────────────────
    else {
        $cost_code_id = intval($_POST['cost_code_id']);
        $quantity     = floatval($_POST['quantity']);
        $unit_price   = floatval($_POST['unit_price']);
        $vat          = floatval($_POST['vat']);
        $is_pob       = isset($_POST['is_pob']) ? 1 : 0;
        $notes        = trim($_POST['notes']);
        $total_amount = $quantity * $unit_price * (1 + $vat / 100);

        if ($cost_code_id == 0) {
            $error = 'Vui lòng chọn mã chi phí!';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO shipment_sells
                    (shipment_id, cost_code_id, quantity, unit_price, vat, is_pob, total_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iidddidsi",
                $shipment_id, $cost_code_id,
                $quantity, $unit_price, $vat,
                $is_pob, $total_amount, $notes,
                $_SESSION['user_id']
            );
            if ($stmt->execute()) {
                $conn->close();
                header("Location: manage.php?shipment_id=$shipment_id&success=added");
                exit();
            } else {
                $error = 'Có lỗi xảy ra: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Group arrival charges
$anForeign  = array_values(array_filter($anCharges, fn($r) => $r['charge_group'] === 'foreign'));
$anLocal    = array_values(array_filter($anCharges, fn($r) => $r['charge_group'] === 'local'));
$hasArrival = count($anCharges) > 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thêm Doanh thu - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .pob-check-wrap { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:10px 15px; }
        .pob-check-wrap label { cursor:pointer; font-weight:600; }
        .an-row-foreign { background:#e8f4fd; }
        .an-row-local   { background:#e8f9ee; }
        .an-row-foreign:hover, .an-row-local:hover { filter: brightness(.96); }
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

<div class="container mt-4">
    <div class="row">
        <div class="col-md-9 offset-md-1">

            <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- ── NÚT LẤY TỪ ARRIVAL NOTICE ── -->
            <?php if ($hasArrival): ?>
            <div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3">
                <span>
                    <i class="bi bi-file-earmark-text text-primary"></i>
                    Lô hàng này đã có <strong><?php echo count($anCharges); ?> dòng phí</strong> trong Arrival Notice.
                </span>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalArrival">
                    <i class="bi bi-download"></i> Lấy từ Arrival Notice
                </button>
            </div>
            <?php endif; ?>

            <!-- ── FORM NHẬP THỦ CÔNG ── -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-plus-circle"></i>
                        Thêm Doanh thu bán ra (SELL) — <?php echo htmlspecialchars($shipment['job_no']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="sellForm">

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mã chi phí <span class="text-danger">*</span></label>
                                <input type="text" id="costCode" class="form-control text-uppercase"
                                       placeholder="VD: THC, TRUCKING" required>
                                <input type="hidden" name="cost_code_id" id="costCodeId">
                                <small class="text-muted">Nhập mã để tự động điền nội dung</small>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Nội dung <span class="text-muted small">(Tự động)</span></label>
                                <input type="text" id="costDescription" class="form-control bg-light" readonly>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Số lượng</label>
                                <input type="number" name="quantity" id="quantity"
                                       class="form-control" step="0.01" value="1" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Đơn giá (VND)</label>
                                <input type="number" name="unit_price" id="unitPrice"
                                       class="form-control" step="0.01" value="0" required>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">VAT (%)</label>
                                <input type="number" name="vat" id="vat"
                                       class="form-control" step="0.1" value="8" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Thành tiền <span class="text-muted small">(Tự động)</span></label>
                                <input type="text" id="totalAmount"
                                       class="form-control bg-light fw-bold text-success" readonly value="0 VND">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="pob-check-wrap">
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox"
                                           name="is_pob" id="isPob" value="1">
                                    <label class="form-check-label" for="isPob">
                                        <i class="bi bi-arrow-left-right text-warning"></i> Chi hộ (POB / B2B)
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    <i class="bi bi-info-circle"></i>
                                    Tích vào nếu đây là khoản <strong>chi hộ</strong> —
                                    sẽ <strong class="text-danger">không xuất hoá đơn VAT</strong> cho khoản này.
                                </small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                Ghi chú
                                <span id="noteHint" class="text-muted fw-normal small" style="display:none;">
                                    — <span class="text-danger">Trucking cần ghi: tuyến đường + biển số xe</span>
                                </span>
                            </label>
                            <textarea name="notes" id="noteField" class="form-control" rows="2"
                                      placeholder="Ghi chú thêm..."></textarea>
                            <div id="truckingHint" class="alert alert-warning py-1 mt-1 small" style="display:none;">
                                <i class="bi bi-truck"></i>
                                <strong>Trucking:</strong> Ghi đầy đủ tuyến đường + biển số xe.
                                VD: <em>Nội Bài - Hà Nội, 29E 25946</em>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="manage.php?shipment_id=<?php echo $shipment_id; ?>"
                               class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Quay lại
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i> Lưu doanh thu
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

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
                    <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light border-bottom">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(true)">
                            <i class="bi bi-check-all"></i> Chọn tất cả
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(false)">
                            <i class="bi bi-x-circle"></i> Bỏ chọn
                        </button>
                        <span class="text-muted small ms-2">
                            <i class="bi bi-info-circle"></i>
                            Dòng màu xám = mã chưa có trong Cost Codes (sẽ bị bỏ qua khi nhập)
                        </span>
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
                                    <th class="text-end">Tổng VND</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $renderGroup = function(array $rows, string $groupLabel, string $rowClass, string $badgeClass) {
                                foreach ($rows as $row):
                                    $hasCC   = !empty($row['cost_code_id_found']);
                                    $disabledClass = $hasCC ? '' : 'no-cc';
                                    $disabled      = $hasCC ? '' : 'disabled title="Mã này chưa có trong Cost Codes"';
                            ?>
                            <tr class="<?php echo $rowClass . ' ' . $disabledClass; ?>">
                                <td class="text-center">
                                    <?php if ($hasCC): ?>
                                    <input type="checkbox" name="selected_rows[]"
                                           value="<?php echo $row['id']; ?>"
                                           class="form-check-input an-checkbox"
                                           onchange="updateCount()">
                                    <?php else: ?>
                                    <i class="bi bi-slash-circle text-muted" title="Chưa có trong Cost Codes"></i>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $badgeClass; ?>"><?php echo $groupLabel; ?></span></td>
                                <td><strong><?php echo htmlspecialchars($row['cost_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($row['currency']); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?></td>
                                <td class="text-center"><?php echo number_format(floatval($row['quantity']), 2, ',', '.'); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['amount_vnd']), 0, ',', '.'); ?></td>
                                <td class="text-center"><?php echo floatval($row['vat']) > 0 ? floatval($row['vat']).'%' : '-'; ?></td>
                                <td class="text-end fw-bold <?php echo $groupLabel === 'Nước ngoài' ? 'text-primary' : 'text-success'; ?>">
                                    <?php echo number_format(floatval($row['total_vnd']), 0, ',', '.'); ?>
                                </td>
                            </tr>
                            <?php
                                endforeach;
                            };
                            $renderGroup($anForeign, 'Nước ngoài', 'an-row-foreign', 'an-badge-foreign');
                            $renderGroup($anLocal,   'Việt Nam',   'an-row-local',   'an-badge-local');
                            ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Ghi chú cuối modal -->
                    <div class="px-3 py-2 bg-light border-top">
                        <small class="text-muted">
                            <i class="bi bi-info-circle text-primary"></i>
                            Dữ liệu được lấy trực tiếp từ Arrival Notice.
                            Nếu muốn <strong>chỉnh sửa</strong>, vui lòng vào
                            <a href="../shipments/arrival_notice.php?id=<?php echo $shipment_id; ?>" target="_blank">
                                Arrival Notice <i class="bi bi-box-arrow-up-right"></i>
                            </a> để sửa rồi lấy lại.
                        </small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i> Đóng
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnImport" disabled>
                        <i class="bi bi-download"></i> Nhập <span id="btnCount">0</span> dòng vào SELL
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Nhập thủ công ──────────────────────────────────────────────
document.getElementById('costCode').addEventListener('blur', function () {
    const code = this.value.trim().toUpperCase();
    if (!code) return;
    fetch('../api/get_cost_code.php?code=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('costCodeId').value      = data.id;
                document.getElementById('costDescription').value = data.description;
                checkTruckingCode(code);
            } else {
                alert('Không tìm thấy mã chi phí: ' + code);
                this.value = '';
                document.getElementById('costCodeId').value      = '';
                document.getElementById('costDescription').value = '';
                hideTruckingHint();
            }
        });
});
document.getElementById('costCode').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
});

function checkTruckingCode(code) {
    const isTruck = code.includes('TRUCK');
    document.getElementById('truckingHint').style.display = isTruck ? 'block' : 'none';
    document.getElementById('noteHint').style.display     = isTruck ? 'inline' : 'none';
    if (isTruck) {
        document.getElementById('noteField').placeholder = 'VD: Nội Bài - Hà Nội, 29E 25946';
        document.getElementById('noteField').focus();
    }
}
function hideTruckingHint() {
    document.getElementById('truckingHint').style.display = 'none';
    document.getElementById('noteHint').style.display     = 'none';
}

function calculateTotal() {
    const qty   = parseFloat(document.getElementById('quantity').value)  || 0;
    const price = parseFloat(document.getElementById('unitPrice').value) || 0;
    const vat   = parseFloat(document.getElementById('vat').value)       || 0;
    document.getElementById('totalAmount').value =
        (qty * price * (1 + vat / 100)).toLocaleString('vi-VN') + ' VND';
}
['quantity','unitPrice','vat'].forEach(id =>
    document.getElementById(id).addEventListener('input', calculateTotal)
);

document.getElementById('sellForm').addEventListener('submit', function(e) {
    if (!document.getElementById('costCodeId').value) {
        e.preventDefault();
        alert('Vui lòng chọn mã chi phí hợp lệ!');
        document.getElementById('costCode').focus();
        return;
    }
    const code  = document.getElementById('costCode').value.toUpperCase();
    const notes = document.getElementById('noteField').value.trim();
    if (code.includes('TRUCK') && !notes) {
        e.preventDefault();
        alert('⚠️ Trucking cần ghi chú tuyến đường + biển số xe!');
        document.getElementById('noteField').focus();
    }
});

// ── Modal Arrival Notice ───────────────────────────────────────
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