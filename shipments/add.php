<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

$auto_job_no = generateJobNo($conn);

$suppliers  = $conn->query("SELECT id, supplier_name, short_name FROM suppliers WHERE status='active' ORDER BY short_name");
$cost_codes = $conn->query("SELECT id, code, description FROM cost_codes WHERE status='active' ORDER BY code");

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $job_no                 = $auto_job_no;
    $customer_id            = intval($_POST['customer_id']);
    $mawb                   = trim($_POST['mawb']);
    $hawb                   = trim($_POST['hawb']);
    $customs_declaration_no = trim($_POST['customs_declaration_no']);
    $shipper                = trim($_POST['shipper']);
    $cnee                   = trim($_POST['cnee']);
    $vessel_flight          = trim($_POST['vessel_flight']);
    $pol                    = strtoupper(trim($_POST['pol']));
    $pod                    = strtoupper(trim($_POST['pod']));
    $packages               = intval($_POST['packages']);
    $gw                     = floatval($_POST['gw']);
    $cw                     = floatval($_POST['cw']);
    $warehouse              = trim($_POST['warehouse']);
    $cont_seal              = trim($_POST['cont_seal']);
    $arrival_date           = $_POST['arrival_date'];
    $status                 = $_POST['status'];
    $notes                  = trim($_POST['notes']);
    $supplier_ids           = isset($_POST['supplier_ids']) ? $_POST['supplier_ids'] : [];
    $costs                  = isset($_POST['costs'])        ? $_POST['costs']        : [];
    $sells                  = isset($_POST['sells'])        ? $_POST['sells']        : [];

    if ($customer_id == 0) {
        $error = 'Vui lòng chọn Khách hàng!';
    } elseif (empty($hawb)) {
        $error = 'HAWB là bắt buộc!';
    } elseif (empty($mawb)) {
        $error = 'MAWB là bắt buộc!';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO shipments
                    (job_no, customer_id, mawb, hawb, customs_declaration_no,
                     shipper, cnee, vessel_flight, pol, pod,
                     packages, gw, cw, warehouse, cont_seal,
                     arrival_date, status, notes, is_locked, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'no', ?)
            ");
            $stmt->bind_param(
                "sissssssssiddsssssi",
                $job_no, $customer_id, $mawb, $hawb, $customs_declaration_no,
                $shipper, $cnee, $vessel_flight, $pol, $pod,
                $packages, $gw, $cw, $warehouse, $cont_seal,
                $arrival_date, $status, $notes,
                $_SESSION['user_id']
            );
            $stmt->execute();
            $shipment_id = $conn->insert_id;

            if (!empty($supplier_ids)) {
                $stmt_sup = $conn->prepare("INSERT INTO shipment_suppliers (shipment_id, supplier_id) VALUES (?, ?)");
                foreach ($supplier_ids as $sup_id) {
                    $sup_id = intval($sup_id);
                    $stmt_sup->bind_param("ii", $shipment_id, $sup_id);
                    $stmt_sup->execute();
                }
            }

            if (!empty($costs)) {
                $stmt_cost = $conn->prepare("
                    INSERT INTO shipment_costs
                        (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, supplier_id, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($costs as $cost) {
                    if (empty($cost['cost_code_id'])) continue;
                    $cc_id  = intval($cost['cost_code_id']);
                    $qty    = floatval($cost['quantity']);
                    $price  = floatval($cost['unit_price']);
                    $vat    = floatval($cost['vat']);
                    $total  = $qty * $price * (1 + $vat / 100);
                    $sup_id = !empty($cost['supplier_id']) ? intval($cost['supplier_id']) : null;
                    $note_c = trim($cost['notes'] ?? '');
                    $stmt_cost->bind_param(
                        "iiddddisi",
                        $shipment_id, $cc_id, $qty, $price, $vat, $total, $sup_id, $note_c, $_SESSION['user_id']
                    );
                    $stmt_cost->execute();
                }
            }

            if (!empty($sells)) {
                $stmt_sell = $conn->prepare("
                    INSERT INTO shipment_sells
                        (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, is_pob, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($sells as $sell) {
                    if (empty($sell['cost_code_id'])) continue;
                    $cc_id  = intval($sell['cost_code_id']);
                    $qty    = floatval($sell['quantity']);
                    $price  = floatval($sell['unit_price']);
                    $vat    = floatval($sell['vat']);
                    $total  = $qty * $price * (1 + $vat / 100);
                    $is_pob = isset($sell['is_pob']) ? intval($sell['is_pob']) : 0;
                    $note_s = trim($sell['notes'] ?? '');
                    $stmt_sell->bind_param(
                        "iidddidsi",
                        $shipment_id, $cc_id, $qty, $price, $vat, $total, $is_pob, $note_s, $_SESSION['user_id']
                    );
                    $stmt_sell->execute();
                }
            }

            $conn->commit();
            header("Location: index.php?success=added");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Có lỗi xảy ra: ' . $e->getMessage();
        }
    }
}

$cost_codes_json = [];
$cost_codes->data_seek(0);
while ($cc = $cost_codes->fetch_assoc()) {
    $cost_codes_json[$cc['id']] = ['code' => $cc['code'], 'description' => $cc['description']];
}

$suppliers_json = [];
$suppliers->data_seek(0);
while ($s = $suppliers->fetch_assoc()) {
    $suppliers_json[$s['id']] = ['short_name' => $s['short_name'], 'supplier_name' => $s['supplier_name']];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Lô hàng - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            margin-top: 20px;
        }
        .section-header.green  { background: linear-gradient(135deg, #11998e, #38ef7d); color: #222; }
        .section-header.orange { background: linear-gradient(135deg, #f7971e, #ffd200); color: #333; }
        .section-header.red    { background: linear-gradient(135deg, #eb3349, #f45c43); }
        .cost-row, .sell-row {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            transition: border-color .2s, background .2s;
        }
        .cost-row:hover, .sell-row:hover { border-color: #adb5bd; }
        .sell-row.is-pob {
            background: #fffbeb !important;
            border-color: #fcd34d !important;
        }
        .pob-check-wrap {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .trucking-hint {
            display: none;
            font-size: .82rem;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 4px 8px;
            margin-top: 4px;
        }
        .required-field { color: red; }
        .dup-ok   { color: #198754; font-size: .82rem; }
        .dup-warn { font-size: .82rem; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="../dashboard.php">
            <i class="bi bi-box-seam"></i> Forwarder System
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../customers/index.php">Khách hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a></li>
                <li class="nav-item"><a class="nav-link" href="../debt/index.php">Công Nợ</a></li>
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

<div class="container-fluid mt-4 pb-5">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Lô hàng mới</h5>
        </div>
        <div class="card-body">

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="shipmentForm">

                <!-- THÔNG TIN CƠ BẢN -->
                <div class="section-header">
                    <i class="bi bi-info-circle"></i> Thông tin cơ bản
                </div>
                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Job No <span class="text-muted small">(Tự động)</span></label>
                        <input type="text" class="form-control bg-light fw-bold text-primary"
                               value="<?php echo htmlspecialchars($auto_job_no); ?>" readonly>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Ngày tạo <span class="text-muted small">(Tự động)</span></label>
                        <input type="text" class="form-control bg-light"
                               value="<?php echo date('d/m/Y H:i'); ?>" readonly>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Mã KH <span class="required-field">*</span></label>
                        <input type="text" id="customerCode" class="form-control text-uppercase"
                               placeholder="VD: ABC" autocomplete="off"
                               value="<?php echo isset($_POST['customer_short']) ? htmlspecialchars($_POST['customer_short']) : ''; ?>">
                        <input type="hidden" name="customer_id" id="customerId"
                               value="<?php echo isset($_POST['customer_id']) ? intval($_POST['customer_id']) : ''; ?>">
                        <small class="text-muted">Nhập mã để tự động điền</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Khách hàng <span class="text-muted small">(Tự động)</span></label>
                        <input type="text" id="customerName" class="form-control bg-light" readonly
                               placeholder="Tên khách hàng sẽ hiện ở đây..."
                               value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = [
                                'pending'    => 'Chờ xử lý',
                                'in_transit' => 'Đang vận chuyển',
                                'arrived'    => 'Đã đến',
                                'cleared'    => 'Đã thông quan',
                                'delivered'  => 'Đã giao',
                            ];
                            foreach ($statuses as $val => $label):
                                $sel = (isset($_POST['status']) && $_POST['status'] == $val) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- THÔNG TIN VẬN ĐƠN -->
                <div class="section-header green">
                    <i class="bi bi-file-earmark-text"></i> Thông tin vận đơn
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">MAWB <span class="required-field">*</span></label>
                        <input type="text" name="mawb" id="mawbInput" class="form-control" required
                               placeholder="Nhập số MAWB"
                               value="<?php echo isset($_POST['mawb']) ? htmlspecialchars($_POST['mawb']) : ''; ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">HAWB <span class="required-field">*</span></label>
                        <input type="text" name="hawb" id="hawbInput" class="form-control" required
                               placeholder="Nhập số HAWB"
                               value="<?php echo isset($_POST['hawb']) ? htmlspecialchars($_POST['hawb']) : ''; ?>">
                        <div id="hawbWarning" class="mt-1"></div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Số tờ khai</label>
                        <input type="text" name="customs_declaration_no" id="tkInput" class="form-control"
                               placeholder="Số tờ khai hải quan"
                               value="<?php echo isset($_POST['customs_declaration_no']) ? htmlspecialchars($_POST['customs_declaration_no']) : ''; ?>">
                        <div id="tkWarning" class="mt-1"></div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Ngày hàng đến</label>
                        <input type="date" name="arrival_date" class="form-control"
                               value="<?php echo isset($_POST['arrival_date']) ? $_POST['arrival_date'] : ''; ?>">
                    </div>
                </div>

                <!-- THÔNG TIN HÀNG HÓA -->
                <div class="section-header orange">
                    <i class="bi bi-box-seam"></i> Thông tin hàng hóa
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Shipper</label>
                        <input type="text" name="shipper" class="form-control"
                               placeholder="Tên người gửi"
                               value="<?php echo isset($_POST['shipper']) ? htmlspecialchars($_POST['shipper']) : ''; ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">CNEE (Người nhận)</label>
                        <input type="text" name="cnee" class="form-control"
                               placeholder="Tên người nhận"
                               value="<?php echo isset($_POST['cnee']) ? htmlspecialchars($_POST['cnee']) : ''; ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">VSL / FLIGHT</label>
                        <input type="text" name="vessel_flight" class="form-control"
                               placeholder="VD: VN123 / EVERGREEN"
                               value="<?php echo isset($_POST['vessel_flight']) ? htmlspecialchars($_POST['vessel_flight']) : ''; ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Kho hàng</label>
                        <input type="text" name="warehouse" class="form-control"
                               placeholder="Tên kho hàng"
                               value="<?php echo isset($_POST['warehouse']) ? htmlspecialchars($_POST['warehouse']) : ''; ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label">POL (Cảng đi)</label>
                        <input type="text" name="pol" class="form-control text-uppercase"
                               placeholder="VD: SGN, HAN"
                               value="<?php echo isset($_POST['pol']) ? htmlspecialchars($_POST['pol']) : ''; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">POD (Cảng đến)</label>
                        <input type="text" name="pod" class="form-control text-uppercase"
                               placeholder="VD: LAX, JFK"
                               value="<?php echo isset($_POST['pod']) ? htmlspecialchars($_POST['pod']) : ''; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Số kiện</label>
                        <input type="number" name="packages" class="form-control" min="0"
                               value="<?php echo isset($_POST['packages']) ? intval($_POST['packages']) : '0'; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">GW (kg)</label>
                        <input type="number" step="0.01" name="gw" class="form-control" min="0"
                               value="<?php echo isset($_POST['gw']) ? floatval($_POST['gw']) : '0'; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">CW / CBM</label>
                        <input type="number" step="0.01" name="cw" class="form-control" min="0"
                               value="<?php echo isset($_POST['cw']) ? floatval($_POST['cw']) : '0'; ?>">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Cont / Seal</label>
                        <input type="text" name="cont_seal" class="form-control"
                               placeholder="VD: ABCD1234567 / 123456"
                               value="<?php echo isset($_POST['cont_seal']) ? htmlspecialchars($_POST['cont_seal']) : ''; ?>">
                    </div>
                </div>

                <!-- NHÀ CUNG CẤP -->
                <div class="section-header">
                    <i class="bi bi-building"></i> Nhà cung cấp
                </div>
                <div class="mb-3">
                    <?php
                    $suppliers->data_seek(0);
                    while ($sup = $suppliers->fetch_assoc()):
                    ?>
                    <div class="form-check form-check-inline mb-2">
                        <input class="form-check-input" type="checkbox"
                               name="supplier_ids[]"
                               value="<?php echo $sup['id']; ?>"
                               id="sup_<?php echo $sup['id']; ?>">
                        <label class="form-check-label badge bg-warning text-dark"
                               for="sup_<?php echo $sup['id']; ?>">
                            <?php echo htmlspecialchars($sup['short_name']); ?>
                        </label>
                    </div>
                    <?php endwhile; ?>
                    <?php if ($suppliers->num_rows == 0): ?>
                    <p class="text-muted small">
                        Chưa có nhà cung cấp nào.
                        <a href="../suppliers/add.php">Thêm nhà cung cấp</a>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- COST -->
                <div class="section-header red">
                    <i class="bi bi-cash-stack"></i> Chi phí đầu vào (COST)
                    <button type="button" class="btn btn-sm btn-light float-end" onclick="addCostRow()">
                        <i class="bi bi-plus-circle"></i> Thêm dòng
                    </button>
                </div>
                <div id="costRows">
                    <p class="text-muted text-center py-2" id="noCostMsg">
                        <i class="bi bi-info-circle"></i> Chưa có chi phí. Click "Thêm dòng" để thêm.
                    </p>
                </div>

                <!-- SELL -->
                <div class="section-header green">
                    <i class="bi bi-currency-dollar"></i> Doanh thu bán ra (SELL)
                    <button type="button" class="btn btn-sm btn-light float-end" onclick="addSellRow()">
                        <i class="bi bi-plus-circle"></i> Thêm dòng
                    </button>
                </div>
                <div id="sellRows">
                    <p class="text-muted text-center py-2" id="noSellMsg">
                        <i class="bi bi-info-circle"></i> Chưa có doanh thu. Click "Thêm dòng" để thêm.
                    </p>
                </div>

                <!-- GHI CHÚ -->
                <div class="section-header">
                    <i class="bi bi-chat-left-text"></i> Ghi chú
                </div>
                <div class="mb-3">
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Ghi chú thêm..."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Quay lại
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save"></i> Lưu lô hàng
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<footer class="bg-light text-center py-3 mt-4">
    <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const costCodes = <?php echo json_encode($cost_codes_json); ?>;
const suppliers = <?php echo json_encode($suppliers_json); ?>;

let costRowIndex = 0;
let sellRowIndex = 0;

// -------------------------------------------------------
// AUTO-FILL KHÁCH HÀNG
// -------------------------------------------------------
document.getElementById('customerCode').addEventListener('blur', function () {
    const code = this.value.trim().toUpperCase();
    if (!code) return;
    fetch('../api/get_customer.php?short_name=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('customerId').value   = data.id;
                document.getElementById('customerName').value = data.company_name;
                document.getElementById('customerCode').value = data.short_name;
            } else {
                alert('Không tìm thấy khách hàng: ' + code);
                document.getElementById('customerId').value   = '';
                document.getElementById('customerName').value = '';
                this.value = '';
                this.focus();
            }
        })
        .catch(() => alert('Lỗi kết nối API!'));
});

document.getElementById('customerCode').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
});

// -------------------------------------------------------
// KIỂM TRA TRÙNG HAWB / TỜ KHAI
// -------------------------------------------------------
function checkDuplicate(field, value, warningId) {
    const box = document.getElementById(warningId);
    if (!value.trim()) { box.innerHTML = ''; return; }

    fetch('../api/check_duplicate.php?field=' + field + '&value=' + encodeURIComponent(value.trim()))
        .then(r => r.json())
        .then(data => {
            if (!data.duplicate) {
                box.innerHTML = '<span class="dup-ok"><i class="bi bi-check-circle-fill"></i> Chưa có trong hệ thống</span>';
                return;
            }
            const label = field === 'hawb' ? 'HAWB' : 'Số tờ khai';
            let html = '<div class="alert alert-warning py-2 px-3 mt-1 mb-0 dup-warn">'
                     + '<i class="bi bi-exclamation-triangle-fill"></i> '
                     + '<strong>Trùng ' + label + '!</strong> Đã tồn tại trong:<ul class="mb-0 mt-1">';
            data.matches.forEach(function(m) {
                const date = m.arrival_date
                    ? new Date(m.arrival_date).toLocaleDateString('vi-VN')
                    : '—';
                html += '<li><a href="view.php?id=' + m.id + '" target="_blank" class="fw-bold text-danger">'
                      + m.job_no + '</a> — ' + (m.company_name || '—')
                      + ' <span class="text-muted">(' + date + ')</span></li>';
            });
            html += '</ul></div>';
            box.innerHTML = html;
        })
        .catch(function() { box.innerHTML = ''; });
}

document.getElementById('hawbInput').addEventListener('blur', function () {
    checkDuplicate('hawb', this.value, 'hawbWarning');
});

document.getElementById('tkInput').addEventListener('blur', function () {
    checkDuplicate('customs_declaration_no', this.value, 'tkWarning');
});

// -------------------------------------------------------
// COST ROW
// -------------------------------------------------------
function addCostRow() {
    document.getElementById('noCostMsg')?.remove();
    const supOpts = Object.keys(suppliers)
        .map(id => '<option value="' + id + '">' + suppliers[id].short_name + '</option>').join('');
    const ccOpts = Object.keys(costCodes)
        .map(id => '<option value="' + id + '">' + costCodes[id].code + '</option>').join('');

    const html = '<div class="cost-row" id="cost-row-' + costRowIndex + '">'
        + '<div class="row align-items-end g-2">'
        + '<div class="col-md-2"><label class="form-label small mb-1">Mã chi phí</label>'
        + '<select name="costs[' + costRowIndex + '][cost_code_id]" class="form-select form-select-sm" onchange="updateCostDesc(' + costRowIndex + ')">'
        + '<option value="">-- Chọn --</option>' + ccOpts + '</select></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Nội dung</label>'
        + '<input type="text" id="cost-desc-' + costRowIndex + '" class="form-control form-control-sm bg-light" readonly placeholder="Tự động"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">SL</label>'
        + '<input type="number" name="costs[' + costRowIndex + '][quantity]" class="form-control form-control-sm" value="1" step="0.01" min="0" oninput="calcCostTotal(' + costRowIndex + ')"></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Đơn giá</label>'
        + '<input type="number" name="costs[' + costRowIndex + '][unit_price]" class="form-control form-control-sm" value="0" step="0.01" min="0" oninput="calcCostTotal(' + costRowIndex + ')"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">VAT %</label>'
        + '<input type="number" name="costs[' + costRowIndex + '][vat]" class="form-control form-control-sm" value="0" step="0.1" min="0" oninput="calcCostTotal(' + costRowIndex + ')"></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Thành tiền</label>'
        + '<input type="text" id="cost-total-' + costRowIndex + '" class="form-control form-control-sm bg-light text-danger fw-bold" readonly value="0"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">NCC</label>'
        + '<select name="costs[' + costRowIndex + '][supplier_id]" class="form-select form-select-sm">'
        + '<option value="">--</option>' + supOpts + '</select></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">Ghi chú</label>'
        + '<input type="text" name="costs[' + costRowIndex + '][notes]" class="form-control form-control-sm"></div>'
        + '<div class="col-md-auto"><label class="form-label small mb-1 d-block">&nbsp;</label>'
        + '<button type="button" class="btn btn-sm btn-danger" onclick="removeRow(\'cost-row-' + costRowIndex + '\')">'
        + '<i class="bi bi-trash"></i></button></div>'
        + '</div></div>';

    document.getElementById('costRows').insertAdjacentHTML('beforeend', html);
    costRowIndex++;
}

function updateCostDesc(i) {
    const sel  = document.querySelector('select[name="costs[' + i + '][cost_code_id]"]');
    const desc = document.getElementById('cost-desc-' + i);
    desc.value = sel.value && costCodes[sel.value] ? costCodes[sel.value].description : '';
}

function calcCostTotal(i) {
    const qty   = parseFloat(document.querySelector('input[name="costs[' + i + '][quantity]"]').value)   || 0;
    const price = parseFloat(document.querySelector('input[name="costs[' + i + '][unit_price]"]').value) || 0;
    const vat   = parseFloat(document.querySelector('input[name="costs[' + i + '][vat]"]').value)        || 0;
    document.getElementById('cost-total-' + i).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
}

// -------------------------------------------------------
// SELL ROW
// -------------------------------------------------------
function addSellRow() {
    document.getElementById('noSellMsg')?.remove();
    const ccOpts = Object.keys(costCodes)
        .map(id => '<option value="' + id + '">' + costCodes[id].code + '</option>').join('');
    const idx = sellRowIndex;

    const html = '<div class="sell-row" id="sell-row-' + idx + '">'
        + '<div class="row align-items-end g-2">'
        + '<div class="col-md-2"><label class="form-label small mb-1">Mã chi phí</label>'
        + '<select name="sells[' + idx + '][cost_code_id]" class="form-select form-select-sm" onchange="updateSellDesc(' + idx + ')">'
        + '<option value="">-- Chọn --</option>' + ccOpts + '</select></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Nội dung</label>'
        + '<input type="text" id="sell-desc-' + idx + '" class="form-control form-control-sm bg-light" readonly placeholder="Tự động"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">SL</label>'
        + '<input type="number" name="sells[' + idx + '][quantity]" class="form-control form-control-sm" value="1" step="0.01" min="0" oninput="calcSellTotal(' + idx + ')"></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Đơn giá</label>'
        + '<input type="number" name="sells[' + idx + '][unit_price]" class="form-control form-control-sm" value="0" step="0.01" min="0" oninput="calcSellTotal(' + idx + ')"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">VAT %</label>'
        + '<input type="number" name="sells[' + idx + '][vat]" class="form-control form-control-sm" value="8" step="0.1" min="0" oninput="calcSellTotal(' + idx + ')"></div>'
        + '<div class="col-md-2"><label class="form-label small mb-1">Thành tiền</label>'
        + '<input type="text" id="sell-total-' + idx + '" class="form-control form-control-sm bg-light text-success fw-bold" readonly value="0"></div>'
        + '<div class="col-md-1"><label class="form-label small mb-1">Ghi chú</label>'
        + '<input type="text" name="sells[' + idx + '][notes]" id="sell-notes-' + idx + '" class="form-control form-control-sm" placeholder="Ghi chú...">'
        + '<div class="trucking-hint" id="truck-hint-' + idx + '"><i class="bi bi-truck"></i> Ghi: <strong>tuyến đường + biển số</strong><br>VD: <em>Nội Bài - Hà Nội, 29E 25946</em></div></div>'
        + '<div class="col-md-auto"><label class="form-label small mb-1 d-block">&nbsp;</label>'
        + '<div class="pob-check-wrap" title="Chi hộ = không xuất hoá đơn VAT">'
        + '<input type="checkbox" name="sells[' + idx + '][is_pob]" value="1" id="sell-pob-' + idx + '" class="form-check-input mt-0" onchange="togglePob(' + idx + ')">'
        + '<label for="sell-pob-' + idx + '" class="small fw-bold mb-0" style="cursor:pointer;color:#92400e;">'
        + '<i class="bi bi-arrow-left-right"></i> Chi hộ</label></div></div>'
        + '<div class="col-md-auto"><label class="form-label small mb-1 d-block">&nbsp;</label>'
        + '<button type="button" class="btn btn-sm btn-danger" onclick="removeRow(\'sell-row-' + idx + '\')">'
        + '<i class="bi bi-trash"></i></button></div>'
        + '</div></div>';

    document.getElementById('sellRows').insertAdjacentHTML('beforeend', html);
    sellRowIndex++;
}

function updateSellDesc(i) {
    const sel  = document.querySelector('select[name="sells[' + i + '][cost_code_id]"]');
    const desc = document.getElementById('sell-desc-' + i);
    if (!sel || !sel.value || !costCodes[sel.value]) {
        if (desc) desc.value = '';
        return;
    }
    const code = costCodes[sel.value].code || '';
    desc.value = costCodes[sel.value].description;

    const hint       = document.getElementById('truck-hint-' + i);
    const notesField = document.getElementById('sell-notes-' + i);
    if (hint && code.toUpperCase().includes('TRUCK')) {
        hint.style.display = 'block';
        if (notesField) { notesField.placeholder = 'VD: Nội Bài - Hà Nội, 29E 25946'; notesField.focus(); }
    } else if (hint) {
        hint.style.display = 'none';
        if (notesField) notesField.placeholder = 'Ghi chú...';
    }
}

function calcSellTotal(i) {
    const qty   = parseFloat(document.querySelector('input[name="sells[' + i + '][quantity]"]').value)   || 0;
    const price = parseFloat(document.querySelector('input[name="sells[' + i + '][unit_price]"]').value) || 0;
    const vat   = parseFloat(document.querySelector('input[name="sells[' + i + '][vat]"]').value)        || 0;
    document.getElementById('sell-total-' + i).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
}

function togglePob(i) {
    const checkbox = document.getElementById('sell-pob-' + i);
    const row      = document.getElementById('sell-row-' + i);
    if (checkbox && row) row.classList.toggle('is-pob', checkbox.checked);
}

function removeRow(id) {
    document.getElementById(id)?.remove();
}

document.getElementById('shipmentForm').addEventListener('submit', function (e) {
    if (!document.getElementById('customerId').value) {
        e.preventDefault();
        alert('Vui lòng chọn khách hàng hợp lệ!');
        document.getElementById('customerCode').focus();
    }
});
</script>
</body>
</html>