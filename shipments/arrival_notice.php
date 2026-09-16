<?php
/**
 * shipments/arrival_notice.php
 * - Cost Code: dropdown từ arrival_cost_codes, OTHER = nhập tay diễn giải
 * - Dấu . = nghìn, dấu , = thập phân (chuẩn VN)
 * - Nút Xuất Excel → download_arrival.php
 * - Nút Gửi Email  → send_arrival.php
 * - [MỚI] Nút "Lấy phí từ Báo giá" → modal chọn quotation items
 */

require_once '../config/database.php';
checkLogin();

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseNumber(string $input): float {
    $input = trim($input);
    if ($input === '') return 0.0;
    $hasDot   = strpos($input, '.') !== false;
    $hasComma = strpos($input, ',') !== false;
    if ($hasDot && $hasComma) {
        if (strpos($input, '.') < strpos($input, ',')) {
            $input = str_replace('.', '', $input);
            $input = str_replace(',', '.', $input);
        } else {
            $input = str_replace(',', '', $input);
        }
        return floatval($input);
    }
    if ($hasDot) {
        $parts = explode('.', $input);
        if (count($parts) === 2 && strlen($parts[1]) === 3 && ctype_digit($parts[1]))
            return floatval(str_replace('.', '', $input));
        return floatval($input);
    }
    if ($hasComma) {
        $parts = explode(',', $input);
        if (count($parts) === 2 && strlen($parts[1]) === 3 && ctype_digit($parts[1]))
            return floatval(str_replace(',', '', $input));
        return floatval(str_replace(',', '.', $input));
    }
    return floatval($input);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php'); exit; }

$conn = getDBConnection();

// Load shipment + customer
$stmt = $conn->prepare(
    "SELECT s.*, c.company_name AS customer_name, c.address AS customer_address,
            c.tax_code AS customer_tax, c.email AS customer_email
     FROM shipments s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$shipment) { $conn->close(); header('Location: index.php'); exit; }

$messages = [];
$errors   = [];

// ------------------------------------------------------------------
// POST: Save
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $usdRate = parseNumber((string)($_POST['an_exchange_usd'] ?? '0'));
    $eurRate = parseNumber((string)($_POST['an_exchange_eur'] ?? '0'));

    if ($usdRate > 0 && $usdRate < 100)
        $errors[] = 'Tỷ giá USD có vẻ sai (' . number_format($usdRate, 2, ',', '.') . '). Vui lòng nhập đầy đủ, ví dụ: 25.000';
    if ($eurRate > 0 && $eurRate < 100)
        $errors[] = 'Tỷ giá EUR có vẻ sai (' . number_format($eurRate, 2, ',', '.') . '). Vui lòng nhập đầy đủ, ví dụ: 27.000';

    if (empty($errors)) {
        $upd = $conn->prepare("UPDATE shipments SET an_exchange_usd=?, an_exchange_eur=? WHERE id=?");
        $upd->bind_param('ddi', $usdRate, $eurRate, $id);
        $upd->execute(); $upd->close();

        $del = $conn->prepare("DELETE FROM arrival_notice_charges WHERE shipment_id=?");
        $del->bind_param('i', $id); $del->execute(); $del->close();

        $groups     = $_POST['charge_group'] ?? [];
        $costCodes  = $_POST['cost_code']    ?? [];
        $descs      = $_POST['description']  ?? [];
        $currencies = $_POST['currency']     ?? [];
        $unitPrices = $_POST['unit_price']   ?? [];
        $quantities = $_POST['quantity']     ?? [];
        $vats       = $_POST['vat']          ?? [];

        $ins = $conn->prepare(
            "INSERT INTO arrival_notice_charges
             (shipment_id,charge_group,cost_code,description,currency,
              unit_price,quantity,amount,exchange_rate,amount_vnd,vat,total_vnd,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $foreignTotalVnd = 0.0;
        $localTotalVnd   = 0.0;

        foreach ($groups as $i => $group) {
            $group     = in_array($group, ['foreign','local'], true) ? $group : 'local';
            $costCode  = strtoupper(trim($costCodes[$i] ?? ''));
            $desc      = trim($descs[$i] ?? '');
            $currency  = in_array($currencies[$i] ?? '', ['USD','EUR','VND'], true) ? $currencies[$i] : 'USD';
            $unitPrice = parseNumber((string)($unitPrices[$i] ?? '0'));
            $quantity  = parseNumber((string)($quantities[$i] ?? '1'));
            $vat       = parseNumber((string)($vats[$i] ?? '0'));
            $amount    = $unitPrice * $quantity;
            $exRate    = $currency === 'USD' ? $usdRate : ($currency === 'EUR' ? $eurRate : 1.0);
            $amountVnd = $currency === 'VND' ? $amount : $amount * $exRate;
            $totalVnd  = $amountVnd * (1 + $vat / 100);
            $sortOrder = $i;

            $ins->bind_param('issssdddddddi',
                $id, $group, $costCode, $desc, $currency,
                $unitPrice, $quantity, $amount, $exRate, $amountVnd, $vat, $totalVnd, $sortOrder
            );
            $ins->execute();

            if ($group === 'foreign') $foreignTotalVnd += $totalVnd;
            else $localTotalVnd += $totalVnd;
        }
        $ins->close();

        // Reload shipment
        $s2 = $conn->prepare(
            "SELECT s.*, c.company_name AS customer_name, c.address AS customer_address,
                    c.tax_code AS customer_tax, c.email AS customer_email
             FROM shipments s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?"
        );
        $s2->bind_param('i', $id); $s2->execute();
        $shipment = $s2->get_result()->fetch_assoc(); $s2->close();

        $messages[] = 'Đã lưu Arrival Notice thành công. Tỷ giá: USD = '
            . number_format($usdRate, 0, ',', '.') . ' | EUR = ' . number_format($eurRate, 0, ',', '.');
    }
}

// ------------------------------------------------------------------
// Load charges
// ------------------------------------------------------------------
$chargeStmt = $conn->prepare(
    "SELECT * FROM arrival_notice_charges WHERE shipment_id=? ORDER BY charge_group, sort_order"
);
$chargeStmt->bind_param('i', $id);
$chargeStmt->execute();
$allCharges = $chargeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$chargeStmt->close();

// Load danh sách mã Arrival Notice
$arrivalCodes = $conn->query(
    "SELECT code, description, default_currency, default_unit_price
     FROM arrival_cost_codes WHERE status='active' ORDER BY code ASC"
)->fetch_all(MYSQLI_ASSOC);
$arrivalCodeList = array_column($arrivalCodes, 'code');

// ------------------------------------------------------------------
// Load danh sách quotation có thể chọn để import phí
// Điều kiện: chưa link với shipment nào HOẶC đang link với shipment này
// ------------------------------------------------------------------
$availableQuotations = $conn->query(
    "SELECT q.id, q.quotation_no, q.status,
            c.company_name, c.short_name
     FROM quotations q
     LEFT JOIN customers c ON c.id = q.customer_id
     WHERE (q.shipment_id IS NULL OR q.shipment_id = $id)
       AND q.status IN ('draft','sent','accepted')
     ORDER BY q.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Load quotation items cho tất cả các quotation available
$availableQuotIds = array_column($availableQuotations, 'id');
$quotItemsMap = [];

if (!empty($availableQuotIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($availableQuotIds), '?'));
    $types          = str_repeat('i', count($availableQuotIds));
    $itemStmt = $conn->prepare(
        "SELECT * FROM quotation_items WHERE quotation_id IN ($inPlaceholders) ORDER BY sort_order, id"
    );
    $itemStmt->bind_param($types, ...$availableQuotIds);
    $itemStmt->execute();
    $allQuotItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemStmt->close();
    foreach ($allQuotItems as $qi) {
        $quotItemsMap[$qi['quotation_id']][] = $qi;
    }
}

// Quotation đang link với shipment này
$linkedQuotStmt = $conn->prepare(
    "SELECT q.id, q.quotation_no FROM quotations q WHERE q.shipment_id = ?"
);
$linkedQuotStmt->bind_param('i', $id);
$linkedQuotStmt->execute();
$linkedQuot            = $linkedQuotStmt->get_result()->fetch_assoc();
$linkedQuotStmt->close();
$currentLinkedQuotId   = (int)($linkedQuot['id'] ?? 0);



$foreignCharges  = array_values(array_filter($allCharges, fn($r) => $r['charge_group'] === 'foreign'));
$localCharges    = array_values(array_filter($allCharges, fn($r) => $r['charge_group'] === 'local'));
$foreignTotalVnd = array_sum(array_column($foreignCharges, 'total_vnd'));
$localTotalVnd   = array_sum(array_column($localCharges,   'total_vnd'));
$grandTotal      = $foreignTotalVnd + $localTotalVnd;
$currentUsdRate  = floatval($shipment['an_exchange_usd'] ?? 25000);
$currentEurRate  = floatval($shipment['an_exchange_eur'] ?? 27000);

// Các mã thường thuộc nhóm nước ngoài
$foreignCostCodes = ['EXW','FREIGHT','INSUR','ORIGIN','FRIGHT','FCL','LCL','AIR FREIGHT'];

function renderCostCodeCell(array $arrivalCodes, array $arrivalCodeList, string $selectedCode = ''): string {
    $isOther = $selectedCode !== '' && !in_array($selectedCode, $arrivalCodeList);
    $opts    = '<option value="">-- Chọn mã --</option>';
    foreach ($arrivalCodes as $ac) {
        $sel   = (!$isOther && $ac['code'] === $selectedCode) ? 'selected' : '';
        $opts .= sprintf(
            '<option value="%s" data-desc="%s" data-curr="%s" data-price="%s" %s>%s – %s</option>',
            h($ac['code']),
            h($ac['description']),
            h($ac['default_currency']),
            floatval($ac['default_unit_price']),
            $sel,
            h($ac['code']),
            h($ac['description'])
        );
    }
    $selOther   = $isOther ? 'selected' : '';
    $opts      .= '<option value="OTHER" ' . $selOther . '>✏️ OTHER (nhập tay)</option>';
    $customVal  = $isOther ? h($selectedCode) : '';
    $customShow = $isOther ? '' : 'display:none';

    return '
    <div style="min-width:180px">
        <select name="cost_code_sel[]" class="form-select form-select-sm cost-code-sel mb-1">' . $opts . '</select>
        <input type="text"
               name="cost_code[]"
               class="form-control form-control-sm cost-code-custom"
               placeholder="Nhập mã tay..."
               value="' . $customVal . '"
               style="' . $customShow . '">
    </div>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giấy Báo Hàng Đến – <?php echo h($shipment['job_no'] ?? $id); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-size: 0.875rem; }
        .table-charges th { white-space: nowrap; }
        .section-title { background:#0d6efd; color:#fff; padding:6px 12px; border-radius:4px; font-weight:600; }
        .grand-total   { font-size:1.1rem; font-weight:700; color:#dc3545; }
        .rate-hint     { font-size:.78rem; color:#6c757d; }
        .cost-code-sel { font-size:.8rem; }

        /* ── Modal chọn báo giá ── */
        #modalQuotation .modal-header  { background:#0dcaf0; }
        #modalQuotation .modal-dialog  { max-width:1020px; }

        .quot-select-table th {
            background:#343a40; color:#fff;
            font-size:.78rem; white-space:nowrap; padding:8px 10px;
            position: sticky; top: 0; z-index: 1;
        }
        .quot-select-table td { font-size:.83rem; vertical-align:middle; padding:8px 10px; }

        /* Checkbox to, dễ click */
        .quot-select-table .row-check {
            width: 22px; height: 22px;
            cursor: pointer;
            accent-color: #0d6efd;
            display: block; margin: auto;
        }
        /* Cả dòng đều clickable */
        .quot-select-table tbody tr { cursor: pointer; transition: background .1s; }
        .quot-select-table tbody tr:hover   { background: #f0f7ff; }
        .quot-select-table tr.selected-row  { background: #ddeeff !important; }
        .quot-select-table tr.no-data-row   { cursor: default; }

        .badge-foreign { background:#0d6efd; color:#fff; font-size:.72rem; padding:3px 8px; border-radius:20px; white-space:nowrap; }
        .badge-local   { background:#198754; color:#fff; font-size:.72rem; padding:3px 8px; border-radius:20px; white-space:nowrap; }
        .warn-code     { color:#dc3545; font-size:.74rem; }
        .vnd-sub       { color:#6c757d; font-size:.74rem; }
        .total-vnd-col { font-weight:700; color:#0a6640; }
        .selected-count-badge { font-size:.85rem; }
    </style>
</head>
<body>
<div class="container-fluid py-3">

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Lô hàng</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $id; ?>"><?php echo h($shipment['job_no'] ?? $id); ?></a></li>
            <li class="breadcrumb-item active">Giấy Báo Hàng Đến</li>
        </ol>
    </nav>

    <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo h($msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo h($err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>

    <!-- Header công ty -->
    <div class="row mb-3">
        <div class="col-md-6">
            <h5 class="mb-0 fw-bold">CÔNG TY TNHH LIPRO LOGISTICS</h5>
            <small class="text-muted">
                Địa chỉ: No. 6 Lane 1002 Lang Street, Lang Ha Ward, Hanoi<br>
                Email: lipro.logistics@gmail.com | Tel: (+84) 366 666 322
            </small>
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Kính gửi:</strong> <?php echo h($shipment['customer_name'] ?? ''); ?><br>
            <small class="text-muted">
                Địa chỉ: <?php echo h($shipment['customer_address'] ?? ''); ?><br>
                MST: <?php echo h($shipment['customer_tax'] ?? ''); ?>
            </small>
        </div>
    </div>

    <h4 class="text-center fw-bold mb-3">GIẤY BÁO HÀNG ĐẾN / ARRIVAL NOTICE</h4>

    <form method="post" action="arrival_notice.php?id=<?php echo $id; ?>" id="mainForm">
        <input type="hidden" name="action" value="save">

        <!-- ── Thông tin lô hàng + tỷ giá ── -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">Thông tin lô hàng</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Shipper</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['shipper'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">POL</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['pol'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">POD</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['pod'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tàu / Chuyến bay</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['vessel_flight'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">MAWB</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['mawb'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">HAWB</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['hawb'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary">Tỷ giá USD → VND</label>
                        <input type="text" name="an_exchange_usd" id="usdRate"
                               class="form-control form-control-sm"
                               value="<?php echo $currentUsdRate > 0 ? number_format($currentUsdRate, 0, ',', '.') : '25.000'; ?>"
                               placeholder="Ví dụ: 25.000" autocomplete="off">
                        <div class="rate-hint">Nhập: <kbd>25.000</kbd> hoặc <kbd>25000</kbd></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary">Tỷ giá EUR → VND</label>
                        <input type="text" name="an_exchange_eur" id="eurRate"
                               class="form-control form-control-sm"
                               value="<?php echo $currentEurRate > 0 ? number_format($currentEurRate, 0, ',', '.') : '27.000'; ?>"
                               placeholder="Ví dụ: 27.000" autocomplete="off">
                        <div class="rate-hint">Nhập: <kbd>27.000</kbd> hoặc <kbd>27000</kbd></div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="p-2 bg-light rounded border w-100">
                            <small class="text-muted d-block">Tỷ giá đang lưu:</small>
                            <span class="fw-bold text-primary">USD = <?php echo number_format($currentUsdRate, 0, ',', '.'); ?> VND</span><br>
                            <span class="fw-bold text-info">EUR = <?php echo number_format($currentEurRate, 0, ',', '.'); ?> VND</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── PHÍ NƯỚC NGOÀI ── -->
        <div class="mb-3">
            <div class="section-title mb-2">3A. Phí Nước Ngoài (EXW + FREIGHT)</div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-charges">
                    <thead class="table-primary">
                        <tr>
                            <th>Cost Code</th><th>Diễn giải</th><th>Tiền tệ</th>
                            <th>Đơn giá</th><th>SL</th><th>Thành tiền</th>
                            <th>Tỷ giá</th><th>Thành tiền (VND)</th>
                            <th>VAT (%)</th><th>Tổng VND</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="foreignBody">
                    <?php foreach ($foreignCharges as $row): ?>
                        <tr>
                            <td><?php echo renderCostCodeCell($arrivalCodes, $arrivalCodeList, $row['cost_code']); ?></td>
                            <td><input type="text" name="description[]" class="form-control form-control-sm desc-field" value="<?php echo h($row['description']); ?>" style="min-width:160px"></td>
                            <td>
                                <select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">
                                    <?php foreach (['USD','EUR','VND'] as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo ($row['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price" value="<?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?>" style="width:110px"></td>
                            <td><input type="text" name="quantity[]"   class="form-control form-control-sm quantity"   value="<?php echo number_format(floatval($row['quantity']),   2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm amount-field bg-light"  value="<?php echo number_format(floatval($row['amount']        ?? 0), 2, ',', '.'); ?>" readonly style="width:110px"></td>
                            <td><input type="text" class="form-control form-control-sm exrate-field bg-light"  value="<?php echo number_format(floatval($row['exchange_rate']  ?? 0), 0, ',', '.'); ?>" readonly style="width:100px"></td>
                            <td><input type="text" class="form-control form-control-sm amtvnd-field bg-light"  value="<?php echo number_format(floatval($row['amount_vnd']     ?? 0), 0, ',', '.'); ?>" readonly style="width:120px"></td>
                            <td><input type="text" name="vat[]" class="form-control form-control-sm vat-field" value="<?php echo number_format(floatval($row['vat']),            2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold" value="<?php echo number_format(floatval($row['total_vnd']  ?? 0), 0, ',', '.'); ?>" readonly style="width:130px"></td>
                            <td>
                                <input type="hidden" name="charge_group[]" value="foreign">
                                <button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-semibold">
                            <td colspan="9" class="text-end">TỔNG CƯỚC + PHÍ NƯỚC NGOÀI (VND)</td>
                            <td id="foreignTotalVnd"><?php echo number_format($foreignTotalVnd, 0, ',', '.'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="d-flex gap-2 mt-1">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRow('foreignBody','foreign')">
                    <i class="bi bi-plus-circle"></i> Thêm dòng mới
                </button>
                <button type="button" class="btn btn-info btn-sm text-white fw-semibold" onclick="openQuotModal('foreign')">
                    <i class="bi bi-file-earmark-text"></i> Lấy phí từ Báo giá
                </button>
            </div>
        </div>

        <!-- ── PHÍ TẠI VIỆT NAM ── -->
        <div class="mb-3">
            <div class="section-title mb-2" style="background:#198754;">3B. Phí Tại Việt Nam</div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-charges">
                    <thead class="table-success">
                        <tr>
                            <th>Cost Code</th><th>Diễn giải</th><th>Tiền tệ</th>
                            <th>Đơn giá</th><th>SL</th><th>Thành tiền</th>
                            <th>Tỷ giá</th><th>Thành tiền (VND)</th>
                            <th>VAT (%)</th><th>Tổng VND</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="localBody">
                    <?php foreach ($localCharges as $row): ?>
                        <tr>
                            <td><?php echo renderCostCodeCell($arrivalCodes, $arrivalCodeList, $row['cost_code']); ?></td>
                            <td><input type="text" name="description[]" class="form-control form-control-sm desc-field" value="<?php echo h($row['description']); ?>" style="min-width:160px"></td>
                            <td>
                                <select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">
                                    <?php foreach (['USD','EUR','VND'] as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo ($row['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price" value="<?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?>" style="width:110px"></td>
                            <td><input type="text" name="quantity[]"   class="form-control form-control-sm quantity"   value="<?php echo number_format(floatval($row['quantity']),   2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm amount-field bg-light"  value="<?php echo number_format(floatval($row['amount']        ?? 0), 2, ',', '.'); ?>" readonly style="width:110px"></td>
                            <td><input type="text" class="form-control form-control-sm exrate-field bg-light"  value="<?php echo number_format(floatval($row['exchange_rate']  ?? 0), 0, ',', '.'); ?>" readonly style="width:100px"></td>
                            <td><input type="text" class="form-control form-control-sm amtvnd-field bg-light"  value="<?php echo number_format(floatval($row['amount_vnd']     ?? 0), 0, ',', '.'); ?>" readonly style="width:120px"></td>
                            <td><input type="text" name="vat[]" class="form-control form-control-sm vat-field" value="<?php echo number_format(floatval($row['vat']),            2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold" value="<?php echo number_format(floatval($row['total_vnd']  ?? 0), 0, ',', '.'); ?>" readonly style="width:130px"></td>
                            <td>
                                <input type="hidden" name="charge_group[]" value="local">
                                <button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-semibold">
                            <td colspan="9" class="text-end">TỔNG PHÍ TẠI VIỆT NAM (VND)</td>
                            <td id="localTotalVnd"><?php echo number_format($localTotalVnd, 0, ',', '.'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="d-flex gap-2 mt-1">
                <button type="button" class="btn btn-outline-success btn-sm" onclick="addRow('localBody','local')">
                    <i class="bi bi-plus-circle"></i> Thêm dòng mới
                </button>
                <button type="button" class="btn btn-info btn-sm text-white fw-semibold" onclick="openQuotModal('local')">
                    <i class="bi bi-file-earmark-text"></i> Lấy phí từ Báo giá
                </button>
            </div>
        </div>

        <!-- Grand total -->
        <div class="text-end mb-3">
            <span class="grand-total">TỔNG THANH TOÁN: <span id="grandTotal"><?php echo number_format($grandTotal, 0, ',', '.'); ?></span> VND</span>
        </div>

        <!-- Thông tin chuyển khoản -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">Thông tin chuyển khoản</div>
            <div class="card-body">
                <table class="table table-sm table-bordered w-auto">
                    <tr><td class="fw-semibold">Số tài khoản / Account No</td><td>9039998888</td></tr>
                    <tr><td class="fw-semibold">Ngân hàng / Bank</td><td>Military Commercial Joint Stock Bank (MB Bank)</td></tr>
                    <tr><td class="fw-semibold">Người thụ hưởng / Beneficiary</td><td>CONG TY TNHH LIPRO LOGISTICS</td></tr>
                </table>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="d-flex gap-2 flex-wrap mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Lưu</button>
            <a href="download_arrival.php?id=<?php echo $id; ?>" class="btn btn-success" target="_blank">
                <i class="bi bi-file-earmark-excel"></i> Xuất Excel
            </a>
            <a href="send_arrival.php?id=<?php echo $id; ?>" class="btn btn-warning">
                <i class="bi bi-envelope"></i> Gửi Email
            </a>
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </form>
</div>

<!-- ================================================================ -->
<!-- MODAL: Chọn phí từ Báo giá                                        -->
<!-- ================================================================ -->
<div class="modal fade" id="modalQuotation" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header" style="background:#0dcaf0;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-file-earmark-text"></i>
                    Chọn phí từ Báo Giá —
                    <span class="text-dark"><?php echo h($shipment['job_no'] ?? ('Shipment #' . $id)); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">
                <?php if (count($availableQuotations) > 0): ?>

                <!-- ── Dropdown chọn báo giá ── -->
                <div class="px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
                    <label class="fw-semibold text-muted mb-0" style="white-space:nowrap; font-size:.85rem;">
                        <i class="bi bi-file-earmark-text text-primary"></i> Báo giá:
                    </label>
                    <select id="quotDropdownSelect"
                            class="form-select form-select-sm"
                            style="max-width:420px"
                            onchange="switchQuotTab(parseInt(this.value))">
                        <?php foreach ($availableQuotations as $quot): ?>
                        <option value="<?php echo $quot['id']; ?>">
                            <?php echo h($quot['quotation_no']); ?>
                            <?php if (!empty($quot['company_name'])): ?> – <?php echo h($quot['company_name']); ?><?php endif; ?>
                            <?php if ($quot['id'] == $currentLinkedQuotId): ?> ✔ Đang link<?php endif; ?>
                            (<?php echo h($quot['status']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($currentLinkedQuotId > 0): ?>
                    <span class="badge bg-success">
                        <i class="bi bi-link-45deg"></i> Đã liên kết
                    </span>
                    <?php endif; ?>
                </div>

                <!-- ── Toolbar ── -->
                <div class="px-3 py-2 border-bottom d-flex align-items-center gap-3 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllRows()">
                        <i class="bi bi-check-all"></i> Chọn tất cả
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllRows()">
                        <i class="bi bi-x-circle"></i> Bỏ chọn
                    </button>
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i>
                        Dòng có ⚠️ = mã chưa có trong Cost Codes (vẫn có thể chọn)
                    </small>
                    <span class="ms-auto badge bg-info text-dark selected-count-badge" id="selectedCountBadge">
                        0 dòng được chọn
                    </span>
                </div>

                <!-- ── Bảng items ── -->
                <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
                    <table class="table table-hover table-sm mb-0 quot-select-table" id="quotItemsTable">
                        <thead>
                            <tr>
                                <th style="width:44px" class="text-center">
                                    <input type="checkbox" id="checkAll" class="form-check-input"
                                           style="width:20px;height:20px;cursor:pointer;accent-color:#0d6efd;"
                                           onchange="toggleCheckAll(this)">
                                </th>
                                <th style="width:95px">Nhóm</th>
                                <th style="width:135px">Cost Code</th>
                                <th>Diễn giải</th>
                                <th style="width:65px" class="text-center">Tiền tệ</th>
                                <th style="width:105px" class="text-end">Đơn giá</th>
                                <th style="width:55px" class="text-center">SL</th>
                                <th style="width:140px" class="text-end">Quy đổi VND</th>
                                <th style="width:60px" class="text-center">VAT%</th>
                                <th style="width:125px" class="text-end">Tổng VND</th>
                            </tr>
                        </thead>
                        <tbody id="quotItemsTbody">
                            <tr class="no-data-row">
                                <td colspan="10" class="text-center text-muted py-4">Đang tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Ghi chú cuối modal ── -->
                <div class="px-3 py-2 border-top bg-light" style="font-size:.8rem; color:#555;">
                    <i class="bi bi-pencil-square"></i>
                    Muốn <strong>chỉnh sửa</strong> số liệu? Vào
                    <a href="#" id="linkToQuotView" target="_blank">
                        Xem báo giá <i class="bi bi-box-arrow-up-right"></i>
                    </a> rồi lấy lại.
                    &nbsp;|&nbsp;
                    Mỗi báo giá chỉ liên kết được với <strong>1 lô hàng</strong>.
                </div>

                <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox" style="font-size:3rem; opacity:.4;"></i>
                    <p class="mt-3 mb-0 fw-semibold">Không có báo giá nào khả dụng</p>
                    <small>Báo giá đã liên kết với lô hàng khác sẽ không hiển thị ở đây.</small>
                </div>
                <?php endif; ?>
            </div>

            <div class="modal-footer d-flex justify-content-between align-items-center">
                <small class="text-muted" id="modalTargetLabel">
                    Sẽ thêm vào: <strong>—</strong>
                </small>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i> Đóng
                    </button>
                    <button type="button" class="btn btn-info text-white fw-bold" onclick="addSelectedRows()">
                        <i class="bi bi-download"></i>
                        Thêm <span id="addBtnCount">0</span> dòng vào bảng
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SHIPMENT_ID = <?php echo $id; ?>;

const QUOT_DATA = <?php echo json_encode(
    array_map(function($quot) use ($quotItemsMap) {
        return [
            'id'           => $quot['id'],
            'quotation_no' => $quot['quotation_no'],
            'status'       => $quot['status'],
            'company_name' => $quot['company_name'] ?? '',
            'items'        => $quotItemsMap[$quot['id']] ?? [],
        ];
    }, $availableQuotations),
    JSON_UNESCAPED_UNICODE
); ?>;

const ARRIVAL_CODES = <?php echo json_encode(
    array_map(fn($ac) => [
        'code'        => $ac['code'],
        'description' => $ac['description'],
        'currency'    => $ac['default_currency'],
        'price'       => floatval($ac['default_unit_price']),
    ], $arrivalCodes),
    JSON_UNESCAPED_UNICODE
); ?>;

const ARRIVAL_CODE_LIST   = <?php echo json_encode($arrivalCodeList,    JSON_UNESCAPED_UNICODE); ?>;
const FOREIGN_COST_CODES  = <?php echo json_encode($foreignCostCodes); ?>;

// ── State ──
var modalTargetGroup = 'foreign';
var activeQuotId     = QUOT_DATA.length > 0 ? QUOT_DATA[0].id : 0;

// ================================================================
// Mở modal
// ================================================================
function openQuotModal(group) {
    modalTargetGroup = group;

    var label = group === 'foreign' ? '🔵 Phí Nước Ngoài' : '🟢 Phí Tại Việt Nam';
    document.getElementById('modalTargetLabel').innerHTML =
        'Sẽ thêm vào: <strong>' + label + '</strong>';

    if (QUOT_DATA.length > 0) {
        var ddSel = document.getElementById('quotDropdownSelect');
        if (ddSel) ddSel.value = activeQuotId;
        renderQuotItems(activeQuotId);
    }

    new bootstrap.Modal(document.getElementById('modalQuotation')).show();
}

// ================================================================
// Chuyển quotation qua dropdown
// ================================================================
function switchQuotTab(quotId) {
    activeQuotId = quotId;
    // Reset checkAll
    var ca = document.getElementById('checkAll');
    if (ca) ca.checked = false;
    renderQuotItems(quotId);
}

// ================================================================
// Render bảng items
// ================================================================
function renderQuotItems(quotId) {
    var quotObj = QUOT_DATA.find(function(q) { return q.id == quotId; });
    var tbody   = document.getElementById('quotItemsTbody');
    var linkEl  = document.getElementById('linkToQuotView');
    if (linkEl) linkEl.href = '../quotations/view.php?id=' + quotId;

    if (!quotObj || !quotObj.items || quotObj.items.length === 0) {
        tbody.innerHTML = '<tr class="no-data-row"><td colspan="10" class="text-center text-muted py-5">'
            + '<i class="bi bi-inbox" style="font-size:2rem;opacity:.5;"></i>'
            + '<p class="mt-2 mb-0">Báo giá này chưa có dòng chi phí nào.</p></td></tr>';
        updateSelectedCount();
        return;
    }

    var usdRate = parseVN(document.getElementById('usdRate').value) || 25000;
    var eurRate = parseVN(document.getElementById('eurRate').value) || 27000;

    var html = '';
    quotObj.items.forEach(function(item, idx) {
        var code      = (item.cost_code || '').toUpperCase();
        var isForeign = FOREIGN_COST_CODES.includes(code);
        var grpBadge  = isForeign
            ? '<span class="badge-foreign">Nước ngoài</span>'
            : '<span class="badge-local">Việt Nam</span>';
        var inList    = ARRIVAL_CODE_LIST.includes(code);
        var warnHtml  = !inList
            ? '<br><span class="warn-code"><i class="bi bi-exclamation-triangle"></i> Chưa có trong Cost Codes</span>'
            : '';

        var unitPrice = parseFloat(item.unit_price) || 0;
        var quantity  = parseFloat(item.quantity)   || 1;
        var currency  = item.currency || 'USD';
        var vat       = parseFloat(item.vat)        || 0;
        var amount    = unitPrice * quantity;
        var rate      = currency === 'USD' ? usdRate : (currency === 'EUR' ? eurRate : 1);
        var amtVnd    = currency === 'VND' ? amount : amount * rate;
        var totalVnd  = amtVnd * (1 + vat / 100);

        var amtVndFmt   = fmtVN(Math.round(amtVnd),  0);
        var totalVndFmt = fmtVN(Math.round(totalVnd), 0);
        var rateSub     = currency !== 'VND'
            ? '<br><span class="vnd-sub">× ' + fmtVN(rate,0) + ' = ' + amtVndFmt + ' VND</span>'
            : '';

        html += '<tr data-idx="' + idx + '" onclick="toggleRow(this)">'
            + '<td class="text-center" onclick="event.stopPropagation(); toggleRow(this.closest(\'tr\'))">'
            +   '<input type="checkbox" class="row-check form-check-input"'
            +   ' style="width:22px;height:22px;cursor:pointer;accent-color:#0d6efd;"'
            +   ' onclick="event.stopPropagation(); onCheckboxClick(this)">'
            + '</td>'
            + '<td>' + grpBadge + '</td>'
            + '<td><strong>' + escHtml(code) + '</strong>' + warnHtml + '</td>'
            + '<td>' + escHtml(item.description || '') + '</td>'
            + '<td class="text-center">' + escHtml(currency) + '</td>'
            + '<td class="text-end">' + fmtVN(unitPrice, 2) + '</td>'
            + '<td class="text-center">' + fmtVN(quantity, 2) + '</td>'
            + '<td class="text-end">' + amtVndFmt + rateSub + '</td>'
            + '<td class="text-center">' + (vat > 0 ? vat + '%' : '–') + '</td>'
            + '<td class="text-end total-vnd-col">' + totalVndFmt + '</td>'
            + '</tr>';
    });

    tbody.innerHTML = html;
    updateSelectedCount();
}

// ================================================================
// Toggle checkbox khi click dòng
// ================================================================
function toggleRow(tr) {
    if (tr.classList.contains('no-data-row')) return;
    var cb = tr.querySelector('.row-check');
    if (!cb) return;
    cb.checked = !cb.checked;
    tr.classList.toggle('selected-row', cb.checked);
    syncCheckAll();
    updateSelectedCount();
}

// Click trực tiếp vào checkbox (không bubble lên tr)
function onCheckboxClick(cb) {
    var tr = cb.closest('tr');
    tr.classList.toggle('selected-row', cb.checked);
    syncCheckAll();
    updateSelectedCount();
}

function toggleCheckAll(masterCb) {
    document.querySelectorAll('#quotItemsTbody .row-check').forEach(function(cb) {
        cb.checked = masterCb.checked;
        cb.closest('tr').classList.toggle('selected-row', masterCb.checked);
    });
    updateSelectedCount();
}

function syncCheckAll() {
    var all  = document.querySelectorAll('#quotItemsTbody .row-check');
    var chk  = document.querySelectorAll('#quotItemsTbody .row-check:checked');
    var ca   = document.getElementById('checkAll');
    if (!ca) return;
    ca.checked       = all.length > 0 && chk.length === all.length;
    ca.indeterminate = chk.length > 0 && chk.length < all.length;
}

function selectAllRows() {
    document.querySelectorAll('#quotItemsTbody .row-check').forEach(function(cb) {
        cb.checked = true;
        cb.closest('tr').classList.add('selected-row');
    });
    var ca = document.getElementById('checkAll');
    if (ca) { ca.checked = true; ca.indeterminate = false; }
    updateSelectedCount();
}

function deselectAllRows() {
    document.querySelectorAll('#quotItemsTbody .row-check').forEach(function(cb) {
        cb.checked = false;
        cb.closest('tr').classList.remove('selected-row');
    });
    var ca = document.getElementById('checkAll');
    if (ca) { ca.checked = false; ca.indeterminate = false; }
    updateSelectedCount();
}

function updateSelectedCount() {
    var count = document.querySelectorAll('#quotItemsTbody .row-check:checked').length;
    document.getElementById('selectedCountBadge').textContent = count + ' dòng được chọn';
    document.getElementById('addBtnCount').textContent        = count;
}

// ================================================================
// Thêm dòng đã chọn vào bảng arrival
// ================================================================
function addSelectedRows() {
    var quotObj = QUOT_DATA.find(function(q) { return q.id == activeQuotId; });
    if (!quotObj) return;

    var checked = document.querySelectorAll('#quotItemsTbody .row-check:checked');
    if (checked.length === 0) { alert('Vui lòng chọn ít nhất 1 dòng phí.'); return; }

    var tbodyId = modalTargetGroup === 'foreign' ? 'foreignBody' : 'localBody';

    checked.forEach(function(cb) {
        var tr   = cb.closest('tr');
        var idx  = parseInt(tr.getAttribute('data-idx'));
        var item = quotObj.items[idx];
        if (!item) return;

        var tbody = document.getElementById(tbodyId);
        var newTr = document.createElement('tr');
        newTr.innerHTML =
            '<td>' + buildCostCodeCell() + '</td>' +
            '<td><input type="text" name="description[]" class="form-control form-control-sm desc-field"'
                + ' value="' + escAttr(item.description || '') + '" style="min-width:160px"></td>' +
            '<td><select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">' +
                '<option value="USD"' + (item.currency==='USD'?' selected':'') + '>USD</option>' +
                '<option value="EUR"' + (item.currency==='EUR'?' selected':'') + '>EUR</option>' +
                '<option value="VND"' + (item.currency==='VND'?' selected':'') + '>VND</option>' +
            '</select></td>' +
            '<td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price"'
                + ' value="' + fmtVN(parseFloat(item.unit_price)||0, 2) + '" style="width:110px"></td>' +
            '<td><input type="text" name="quantity[]" class="form-control form-control-sm quantity"'
                + ' value="' + fmtVN(parseFloat(item.quantity)||1, 2) + '" style="width:70px"></td>' +
            '<td><input type="text" class="form-control form-control-sm amount-field bg-light" value="0" readonly style="width:110px"></td>' +
            '<td><input type="text" class="form-control form-control-sm exrate-field bg-light" value="0" readonly style="width:100px"></td>' +
            '<td><input type="text" class="form-control form-control-sm amtvnd-field bg-light" value="0" readonly style="width:120px"></td>' +
            '<td><input type="text" name="vat[]" class="form-control form-control-sm vat-field"'
                + ' value="' + fmtVN(parseFloat(item.vat)||0, 2) + '" style="width:70px"></td>' +
            '<td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold" value="0" readonly style="width:130px"></td>' +
            '<td>' +
                '<input type="hidden" name="charge_group[]" value="' + modalTargetGroup + '">' +
                '<button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>' +
            '</td>';

        tbody.appendChild(newTr);
        attachRowEvents(newTr);

        // Set cost_code
        var code      = (item.cost_code || '').toUpperCase();
        var sel       = newTr.querySelector('.cost-code-sel');
        var customInp = newTr.querySelector('.cost-code-custom');
        var matchOpt  = Array.from(sel.options).find(function(o) { return o.value === code; });
        if (matchOpt) {
            sel.value = code;
            customInp.style.display = 'none';
            customInp.value = code;
        } else {
            sel.value = 'OTHER';
            customInp.style.display = '';
            customInp.value = code;
        }

        // Trigger recalc
        var currSel = newTr.querySelector('.currency-sel');
        if (currSel) currSel.dispatchEvent(new Event('change'));
    });

    updateTotals();
    bootstrap.Modal.getInstance(document.getElementById('modalQuotation')).hide();

    var target = modalTargetGroup === 'foreign' ? 'Phí Nước Ngoài' : 'Phí Tại Việt Nam';
    showToast('✅ Đã thêm ' + checked.length + ' dòng vào ' + target, 'success');
}

// ================================================================
// Toast
// ================================================================
function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'position-fixed bottom-0 end-0 m-3 alert alert-' + (type||'info') + ' shadow';
    t.style.cssText = 'z-index:9999;min-width:280px;font-size:.88rem;';
    t.innerHTML = msg;
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 3000);
}

// ================================================================
// Helpers
// ================================================================
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) { return String(s).replace(/"/g,'&quot;'); }

// ================================================================
// Parse số kiểu VN
// ================================================================
function parseVN(val) {
    val = String(val).trim().replace(/\s/g,'');
    if (!val) return 0;
    var hasDot = val.indexOf('.') !== -1, hasComma = val.indexOf(',') !== -1;
    if (hasDot && hasComma) {
        if (val.indexOf('.') < val.indexOf(',')) val = val.replace(/\./g,'').replace(',','.');
        else val = val.replace(/,/g,'');
        return parseFloat(val) || 0;
    }
    if (hasDot) {
        var p = val.split('.');
        if (p.length===2 && p[1].length===3 && /^\d+$/.test(p[1])) return parseFloat(val.replace(/\./g,''))||0;
        return parseFloat(val)||0;
    }
    if (hasComma) {
        var p2 = val.split(',');
        if (p2.length===2 && p2[1].length===3 && /^\d+$/.test(p2[1])) return parseFloat(val.replace(/,/g,''))||0;
        return parseFloat(val.replace(',','.'))||0;
    }
    return parseFloat(val)||0;
}

function fmtVN(num, dec) {
    dec = dec === undefined ? 0 : dec;
    return num.toLocaleString('vi-VN', {minimumFractionDigits:dec, maximumFractionDigits:dec});
}

// ================================================================
// Build dropdown cost code cell
// ================================================================
function buildCostCodeCell() {
    var opts = '<option value="">-- Chọn mã --</option>';
    ARRIVAL_CODES.forEach(function(ac) {
        opts += '<option value="' + ac.code + '"'
              + ' data-desc="'  + ac.description.replace(/"/g,'&quot;') + '"'
              + ' data-curr="'  + ac.currency  + '"'
              + ' data-price="' + ac.price     + '">'
              + ac.code + ' \u2013 ' + ac.description + '</option>';
    });
    opts += '<option value="OTHER">\u270F\uFE0F OTHER (nh\u1eadp tay)</option>';
    return '<div style="min-width:180px">'
         + '<select name="cost_code_sel[]" class="form-select form-select-sm cost-code-sel mb-1">' + opts + '</select>'
         + '<input type="text" name="cost_code[]" class="form-control form-control-sm cost-code-custom"'
         + ' placeholder="Nh\u1eadp m\u00e3 tay..." style="display:none">'
         + '</div>';
}

// ================================================================
// Gắn sự kiện dropdown cost code
// ================================================================
function attachCostCodeSelect(tr) {
    var sel       = tr.querySelector('.cost-code-sel');
    var customInp = tr.querySelector('.cost-code-custom');
    var descInput = tr.querySelector('.desc-field');
    var currSel   = tr.querySelector('.currency-sel');
    var unitInput = tr.querySelector('.unit-price');
    if (!sel) return;

    sel.addEventListener('change', function() {
        var code = this.value;
        if (code === 'OTHER') {
            customInp.style.display = '';
            customInp.value = '';
            if (descInput) { descInput.value = ''; descInput.removeAttribute('readonly'); }
            customInp.focus();
            return;
        }
        customInp.style.display = 'none';
        customInp.value = code;
        if (!code) { if (descInput) descInput.value = ''; return; }

        if (code === 'FREIGHT') {
            fetch('../api/get_arrival_cost_code.php?code=FREIGHT&shipment_id=' + SHIPMENT_ID)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (descInput) descInput.value = data.description;
                        if (currSel && data.default_currency) currSel.value = data.default_currency;
                        if (unitInput && parseVN(unitInput.value) === 0 && data.default_unit_price > 0)
                            unitInput.value = fmtVN(parseFloat(data.default_unit_price), 2);
                    }
                    if (currSel) currSel.dispatchEvent(new Event('change'));
                })
                .catch(function() { if (currSel) currSel.dispatchEvent(new Event('change')); });
            return;
        }

        var opt   = sel.options[sel.selectedIndex];
        var desc  = opt.getAttribute('data-desc')  || '';
        var curr  = opt.getAttribute('data-curr')  || 'USD';
        var price = parseFloat(opt.getAttribute('data-price')) || 0;
        if (descInput) descInput.value = desc;
        if (currSel)   currSel.value   = curr;
        if (price > 0 && unitInput && parseVN(unitInput.value) === 0)
            unitInput.value = fmtVN(price, 2);
        if (currSel) currSel.dispatchEvent(new Event('change'));
    });

    if (customInp) {
        customInp.addEventListener('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }
}

// ================================================================
// Gắn sự kiện tính toán cho 1 row
// ================================================================
function attachRowEvents(tr) {
    var currSel   = tr.querySelector('.currency-sel');
    var unitInput = tr.querySelector('.unit-price');
    var qtyInput  = tr.querySelector('.quantity');
    var vatInput  = tr.querySelector('.vat-field');
    var amtField  = tr.querySelector('.amount-field');
    var exrField  = tr.querySelector('.exrate-field');
    var amtVnd    = tr.querySelector('.amtvnd-field');
    var tvnd      = tr.querySelector('.tvnd-field');
    var removeBtn = tr.querySelector('.remove-row');

    function getRate(cur) {
        var u = parseVN(document.getElementById('usdRate').value);
        var e = parseVN(document.getElementById('eurRate').value);
        return cur === 'USD' ? u : (cur === 'EUR' ? e : 1);
    }

    function recalc() {
        var up   = parseVN(unitInput.value);
        var qty  = parseVN(qtyInput.value);
        var vat  = parseVN(vatInput.value);
        var cur  = currSel.value;
        var rate = getRate(cur);
        var amt  = up * qty;
        var aVnd = cur === 'VND' ? amt : amt * rate;
        var tVnd = aVnd * (1 + vat / 100);
        amtField.value = fmtVN(amt,  2);
        exrField.value = fmtVN(rate, 0);
        amtVnd.value   = fmtVN(Math.round(aVnd), 0);
        tvnd.value     = fmtVN(Math.round(tVnd), 0);
        updateTotals();
    }

    if (unitInput) { unitInput.addEventListener('input', recalc); unitInput.addEventListener('change', recalc); }
    if (qtyInput)  { qtyInput.addEventListener('input',  recalc); qtyInput.addEventListener('change',  recalc); }
    if (vatInput)  { vatInput.addEventListener('input',  recalc); vatInput.addEventListener('change',  recalc); }
    if (currSel)     currSel.addEventListener('change',  recalc);

    attachCostCodeSelect(tr);

    if (removeBtn) {
        removeBtn.addEventListener('click', function() { tr.remove(); updateTotals(); });
    }
}

// ================================================================
// Cập nhật tổng
// ================================================================
function updateTotals() {
    var fVnd = 0, lVnd = 0;
    document.querySelectorAll('#foreignBody tr').forEach(function(tr) {
        var f = tr.querySelector('.tvnd-field');
        if (f) fVnd += parseFloat(f.value.replace(/\./g,'').replace(',','.')) || 0;
    });
    document.querySelectorAll('#localBody tr').forEach(function(tr) {
        var f = tr.querySelector('.tvnd-field');
        if (f) lVnd += parseFloat(f.value.replace(/\./g,'').replace(',','.')) || 0;
    });
    document.getElementById('foreignTotalVnd').textContent = fmtVN(Math.round(fVnd), 0);
    document.getElementById('localTotalVnd').textContent   = fmtVN(Math.round(lVnd), 0);
    document.getElementById('grandTotal').textContent      = fmtVN(Math.round(fVnd + lVnd), 0);
}

// ================================================================
// Thêm row mới trống
// ================================================================
function addRow(tbodyId, group) {
    var tbody = document.getElementById(tbodyId);
    var tr    = document.createElement('tr');
    tr.innerHTML =
        '<td>' + buildCostCodeCell() + '</td>' +
        '<td><input type="text" name="description[]" class="form-control form-control-sm desc-field" style="min-width:160px"></td>' +
        '<td><select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">' +
            '<option value="USD" selected>USD</option>' +
            '<option value="EUR">EUR</option>' +
            '<option value="VND">VND</option>' +
        '</select></td>' +
        '<td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price" value="0" style="width:110px"></td>' +
        '<td><input type="text" name="quantity[]"   class="form-control form-control-sm quantity"   value="1" style="width:70px"></td>' +
        '<td><input type="text" class="form-control form-control-sm amount-field bg-light" value="0" readonly style="width:110px"></td>' +
        '<td><input type="text" class="form-control form-control-sm exrate-field bg-light" value="0" readonly style="width:100px"></td>' +
        '<td><input type="text" class="form-control form-control-sm amtvnd-field bg-light" value="0" readonly style="width:120px"></td>' +
        '<td><input type="text" name="vat[]" class="form-control form-control-sm vat-field" value="0" style="width:70px"></td>' +
        '<td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold" value="0" readonly style="width:130px"></td>' +
        '<td>' +
            '<input type="hidden" name="charge_group[]" value="' + group + '">' +
            '<button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>' +
        '</td>';
    tbody.appendChild(tr);
    attachRowEvents(tr);
    updateTotals();
}

// ================================================================
// Recompute khi đổi tỷ giá
// ================================================================
function recomputeAllRates() {
    document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
        var c = tr.querySelector('.currency-sel');
        if (c) c.dispatchEvent(new Event('change'));
    });
}

// ================================================================
// Sync cost_code[] trước khi submit
// ================================================================
document.getElementById('mainForm').addEventListener('submit', function() {
    document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
        var sel       = tr.querySelector('.cost-code-sel');
        var customInp = tr.querySelector('.cost-code-custom');
        if (!sel || !customInp) return;
        if (sel.value !== 'OTHER' && sel.value !== '') customInp.value = sel.value;
    });
});

// ================================================================
// Init
// ================================================================
document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
    attachRowEvents(tr);
});
document.getElementById('usdRate').addEventListener('input', recomputeAllRates);
document.getElementById('eurRate').addEventListener('input', recomputeAllRates);
updateTotals();
</script>
</body>
</html>
<?php $conn->close(); ?>