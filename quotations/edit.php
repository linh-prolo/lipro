<?php
// Thêm 2 dòng này để hiện lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /forwarder/shipments/index.php?error=no_permission");
    exit();
}

$conn = getDBConnection();
$error = '';

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

// Kiểm tra các cột mới đã tồn tại chưa
$colCheck = $conn->query("SHOW COLUMNS FROM `quotations` LIKE 'pol'");
$hasNewCols = ($colCheck && $colCheck->num_rows > 0);

// Load quotation
$stmt = $conn->prepare("SELECT q.*, c.company_name, c.short_name FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quot) {
    header("Location: index.php");
    exit();
}

// Load existing items
$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Load customers
$customers = $conn->query("SELECT id, company_name, short_name FROM customers WHERE status='active' ORDER BY company_name ASC");

// Load arrival cost codes
$cost_codes = $conn->query("SELECT id, code, description, default_currency, default_unit_price FROM arrival_cost_codes WHERE status='active' ORDER BY code ASC");
$cost_codes_arr = [];
while ($cc = $cost_codes->fetch_assoc()) {
    $cost_codes_arr[] = $cc;
}

// Safe get optional new fields
$pol      = $quot['pol']      ?? null;
$pod      = $quot['pod']      ?? null;
$shipper  = $quot['shipper']  ?? null;
$packages = $quot['packages'] ?? null;
$gw       = $quot['gw']       ?? null;
$cw       = $quot['cw']       ?? null;

// --- Xử lý POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quotation_no  = trim($_POST['quotation_no'] ?? '');
    $customer_id   = intval($_POST['customer_id'] ?? 0);
    $issue_date    = trim($_POST['issue_date'] ?? '');
    $valid_until   = trim($_POST['valid_until'] ?? '') ?: null;
    $currency      = $_POST['currency'] ?? 'USD';
    $exchange_rate = floatval($_POST['exchange_rate'] ?? 1);
    $notes         = trim($_POST['notes'] ?? '');
    $status        = $_POST['status'] ?? 'draft';

    if ($hasNewCols) {
        $pol      = trim($_POST['pol'] ?? '');
        $pod      = trim($_POST['pod'] ?? '');
        $shipper  = trim($_POST['shipper'] ?? '');
        $packages = ($_POST['packages'] ?? '') !== '' ? floatval($_POST['packages']) : null;
        $gw       = ($_POST['gw']       ?? '') !== '' ? floatval($_POST['gw'])       : null;
        $cw       = ($_POST['cw']       ?? '') !== '' ? floatval($_POST['cw'])       : null;
    }

    $items = [];
    $arrival_code_ids = $_POST['arrival_code_id'] ?? [];
    $cost_codes_post  = $_POST['cost_code']       ?? [];
    $descriptions     = $_POST['description']     ?? [];
    $currencies       = $_POST['item_currency']   ?? [];
    $unit_prices      = $_POST['unit_price']      ?? [];
    $quantities       = $_POST['quantity']        ?? [];
    $item_notes       = $_POST['item_notes']      ?? [];

    foreach ($cost_codes_post as $i => $code) {
        if (trim($code) === '' && trim($descriptions[$i] ?? '') === '') continue;
        $items[] = [
            'arrival_code_id' => intval($arrival_code_ids[$i] ?? 0) ?: null,
            'cost_code'       => trim($code),
            'description'     => trim($descriptions[$i] ?? ''),
            'currency'        => $currencies[$i] ?? 'USD',
            'unit_price'      => floatval($unit_prices[$i] ?? 0),
            'quantity'        => floatval($quantities[$i] ?? 1),
            'notes'           => trim($item_notes[$i] ?? ''),
            'sort_order'      => $i,
        ];
    }

    if (empty($quotation_no)) {
        $error = 'Vui lòng nhập số báo giá!';
    } elseif ($customer_id <= 0) {
        $error = 'Vui lòng chọn khách hàng!';
    } elseif (empty($issue_date)) {
        $error = 'Vui lòng nhập ngày lập!';
    } elseif (count($items) === 0) {
        $error = 'Vui lòng thêm ít nhất 1 dòng chi phí!';
    } else {
        $chk = $conn->prepare("SELECT id FROM quotations WHERE quotation_no = ? AND id != ?");
        $chk->bind_param("si", $quotation_no, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'Số báo giá đã tồn tại, vui lòng dùng số khác!';
        }
        $chk->close();
    }

    if (empty($error)) {
    $conn->begin_transaction();
    try {
        if ($hasNewCols) {
            $qno    = $conn->real_escape_string($quotation_no);
            $cidInt = intval($customer_id);
            $idate  = $conn->real_escape_string($issue_date);
            $vuntil = $valid_until ? "'" . $conn->real_escape_string($valid_until) . "'" : "NULL";
            $cur    = $conn->real_escape_string($currency);
            $exr    = floatval($exchange_rate);
            $polE   = $conn->real_escape_string($pol   ?? '');
            $podE   = $conn->real_escape_string($pod   ?? '');
            $shipE  = $conn->real_escape_string($shipper ?? '');
            $pkgE   = ($packages !== null && $packages !== '') ? floatval($packages) : "NULL";
            $gwE    = ($gw       !== null && $gw       !== '') ? floatval($gw)       : "NULL";
            $cwE    = ($cw       !== null && $cw       !== '') ? floatval($cw)       : "NULL";
            $notesE = $conn->real_escape_string($notes);
            $statE  = $conn->real_escape_string($status);
            $idInt  = intval($id);

            $sql = "UPDATE quotations SET
                        quotation_no  = '$qno',
                        customer_id   = $cidInt,
                        issue_date    = '$idate',
                        valid_until   = $vuntil,
                        currency      = '$cur',
                        exchange_rate = $exr,
                        pol           = '$polE',
                        pod           = '$podE',
                        shipper       = '$shipE',
                        packages      = $pkgE,
                        gw            = $gwE,
                        cw            = $cwE,
                        notes         = '$notesE',
                        status        = '$statE'
                    WHERE id = $idInt";

            if (!$conn->query($sql)) {
                throw new Exception("Update failed: " . $conn->error);
            }

        } else {
            $stmt = $conn->prepare(
                "UPDATE quotations SET quotation_no=?, customer_id=?, issue_date=?, valid_until=?,
                 currency=?, exchange_rate=?, notes=?, status=?
                 WHERE id=?"
            );
            $stmt->bind_param("sisssdssi",
                $quotation_no, $customer_id, $issue_date, $valid_until,
                $currency, $exchange_rate, $notes, $status, $id
            );
            if (!$stmt->execute()) {
                throw new Exception("Update failed: " . $stmt->error);
            }
            $stmt->close();
        }

        // Xóa items cũ
        $del = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();

        // Insert items mới
        foreach ($items as $item) {
            $qid     = intval($id);
            $acid    = intval($item['arrival_code_id'] ?? 0);
            $ccE     = $conn->real_escape_string($item['cost_code']   ?? '');
            $descE   = $conn->real_escape_string($item['description'] ?? '');
            $icurE   = $conn->real_escape_string($item['currency']    ?? 'USD');
            $uprice  = floatval($item['unit_price'] ?? 0);
            $qty     = floatval($item['quantity']   ?? 1);
            $amt     = round($uprice * $qty, 4);
            $inotesE = $conn->real_escape_string($item['notes']       ?? '');
            $sortord = intval($item['sort_order'] ?? 0);

            $isql = "INSERT INTO quotation_items
                        (quotation_id, arrival_code_id, cost_code, description,
                         currency, unit_price, quantity, amount, notes, sort_order)
                     VALUES
                        ($qid, $acid, '$ccE', '$descE',
                         '$icurE', $uprice, $qty, $amt, '$inotesE', $sortord)";

            if (!$conn->query($isql)) {
                throw new Exception("Insert item failed: " . $conn->error);
            }
        }

        $conn->commit();
        header("Location: view.php?id=$id&success=updated");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Có lỗi xảy ra: ' . $e->getMessage();
    }
}

    $quot['quotation_no']  = $quotation_no;
    $quot['customer_id']   = $customer_id;
    $quot['issue_date']    = $issue_date;
    $quot['valid_until']   = $valid_until ?? '';
    $quot['currency']      = $currency;
    $quot['exchange_rate'] = $exchange_rate;
    $quot['notes']         = $notes;
    $quot['status']        = $status;
    $existing_items        = $items;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sửa Báo Giá <?php echo htmlspecialchars($quot['quotation_no']); ?> - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .items-table th { background:#343a40; color:#fff; font-size:.78rem; white-space:nowrap; padding:6px 8px; }
        .items-table td { vertical-align:middle; padding:4px 6px; }
        .suggest-box { position:absolute; z-index:999; width:100%; background:#fff; border:1px solid #ccc; border-radius:4px; max-height:180px; overflow-y:auto; }
        .suggest-box .suggest-item { padding:6px 10px; cursor:pointer; font-size:.85rem; }
        .suggest-box .suggest-item:hover { background:#f0f6fc; }
    </style>
</head>
<body class="bg-light">

<?php include '../partials/navbar.php'; ?>

<div class="container-fluid mt-3 pb-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-pencil-square text-warning"></i>
            Sửa Báo Giá: <span class="text-primary"><?php echo htmlspecialchars($quot['quotation_no']); ?></span>
        </h4>
        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Quay lại
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'copied'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-copy"></i> Đã sao chép báo giá thành công! Vui lòng kiểm tra và lưu.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="quotForm">

        <div class="card mb-3">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Thông tin báo giá</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">Số báo giá <span class="text-danger">*</span></label>
                        <input type="text" name="quotation_no" class="form-control"
                               value="<?php echo htmlspecialchars($quot['quotation_no']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Khách hàng <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php
                            $customers->data_seek(0);
                            while ($c = $customers->fetch_assoc()):
                            ?>
                                <option value="<?php echo $c['id']; ?>"
                                    <?php echo $quot['customer_id'] == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['short_name'] . ' — ' . $c['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ngày lập <span class="text-danger">*</span></label>
                        <input type="date" name="issue_date" class="form-control"
                               value="<?php echo htmlspecialchars($quot['issue_date']); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Hiệu lực đến</label>
                        <input type="date" name="valid_until" class="form-control"
                               value="<?php echo htmlspecialchars($quot['valid_until'] ?? ''); ?>">
                    </div>

                    <?php if ($hasNewCols): ?>
                    <div class="col-md-2">
                        <label class="form-label">POL <small class="text-muted">(Cảng đi)</small></label>
                        <input type="text" name="pol" class="form-control"
                               placeholder="VD: HAN, SGN"
                               value="<?php echo htmlspecialchars($pol ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">POD <small class="text-muted">(Cảng đến)</small></label>
                        <input type="text" name="pod" class="form-control"
                               placeholder="VD: LAX, HKG"
                               value="<?php echo htmlspecialchars($pod ?? ''); ?>">
                    </div>
                    <div class="col-md-4 position-relative">
                        <label class="form-label">Shipper <small class="text-muted">(Người gửi)</small></label>
                        <input type="text" name="shipper" id="shipperInput" class="form-control"
                               placeholder="Nhập tên người gửi..."
                               autocomplete="off"
                               value="<?php echo htmlspecialchars($shipper ?? ''); ?>">
                        <div id="shipperSuggest" class="suggest-box" style="display:none;"></div>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Số kiện</label>
                        <input type="number" name="packages" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($packages ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">GW (kg)</label>
                        <input type="number" name="gw" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($gw ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">CW (kg)</label>
                        <input type="number" name="cw" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($cw ?? ''); ?>">
                    </div>
                    <?php endif; ?>

                    <div class="col-md-2">
                        <label class="form-label">Tiền tệ chính</label>
                        <select name="currency" class="form-select">
                            <?php foreach (['USD','VND','EUR'] as $cur): ?>
                                <option value="<?php echo $cur; ?>"
                                    <?php echo ($quot['currency'] === $cur) ? 'selected' : ''; ?>>
                                    <?php echo $cur; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tỉ giá</label>
                        <input type="number" name="exchange_rate" class="form-control"
                               step="0.0001" min="0"
                               value="<?php echo htmlspecialchars($quot['exchange_rate']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = ['draft'=>'Nháp','sent'=>'Đã gửi','accepted'=>'Chấp nhận','rejected'=>'Từ chối','expired'=>'Hết hạn'];
                            foreach ($statuses as $k => $v):
                            ?>
                                <option value="<?php echo $k; ?>"
                                    <?php echo $quot['status'] === $k ? 'selected' : ''; ?>>
                                    <?php echo $v; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Ghi chú</label>
                        <textarea name="notes" class="form-control" rows="1"><?php echo htmlspecialchars($quot['notes'] ?? ''); ?></textarea>
                    </div>

                    <?php if (!$hasNewCols): ?>
                    <div class="col-12">
                        <div class="alert alert-warning py-2 mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            Chưa có cột <strong>POL / POD / Shipper / Số kiện / GW / CW</strong> trong database.
                            Vui lòng chạy ALTER TABLE SQL để kích hoạt các trường này.
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- Dòng chi phí -->
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Dòng chi phí</h6>
                <button type="button" class="btn btn-light btn-sm" id="addRowBtn">
                    <i class="bi bi-plus-circle"></i> + Thêm dòng chi phí
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table items-table mb-0" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:160px">Mã chi phí</th>
                                <th>Diễn giải</th>
                                <th style="width:90px">Tiền tệ</th>
                                <th style="width:110px">Đơn giá</th>
                                <th style="width:80px">SL</th>
                                <th style="width:120px">Thành tiền</th>
                                <th style="width:140px">Ghi chú</th>
                                <th style="width:40px"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsTbody">
                            <?php foreach ($existing_items as $ri => $item): ?>
                            <tr class="item-row">
                                <td>
                                    <input type="hidden" name="arrival_code_id[]" class="arrival-code-id"
                                           value="<?php echo intval($item['arrival_code_id'] ?? 0); ?>">
                                    <select class="form-select form-select-sm cost-code-select" name="_cc_select_<?php echo $ri; ?>">
                                        <option value="">-- Chọn mã --</option>
                                        <?php foreach ($cost_codes_arr as $cc): ?>
                                            <option value="<?php echo $cc['id']; ?>"
                                                data-code="<?php echo htmlspecialchars($cc['code']); ?>"
                                                data-desc="<?php echo htmlspecialchars($cc['description']); ?>"
                                                data-currency="<?php echo htmlspecialchars($cc['default_currency']); ?>"
                                                data-price="<?php echo htmlspecialchars($cc['default_unit_price']); ?>"
                                                <?php echo (intval($item['arrival_code_id'] ?? 0) == $cc['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cc['code']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="cost_code[]" class="form-control form-control-sm mt-1 cost-code-text"
                                           placeholder="hoặc nhập tay"
                                           value="<?php echo htmlspecialchars($item['cost_code'] ?? ''); ?>">
                                </td>
                                <td>
                                    <input type="text" name="description[]" class="form-control form-control-sm item-desc"
                                           value="<?php echo htmlspecialchars($item['description'] ?? ''); ?>">
                                </td>
                                <td>
                                    <select name="item_currency[]" class="form-select form-select-sm item-currency">
                                        <?php foreach (['USD','VND','EUR'] as $cur): ?>
                                            <option value="<?php echo $cur; ?>"
                                                <?php echo (($item['currency'] ?? 'USD') === $cur) ? 'selected' : ''; ?>>
                                                <?php echo $cur; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="unit_price[]" class="form-control form-control-sm item-price"
                                           step="0.0001" min="0"
                                           value="<?php echo htmlspecialchars($item['unit_price'] ?? '0'); ?>">
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control form-control-sm item-qty"
                                           step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($item['quantity'] ?? '1'); ?>">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm item-amount bg-light"
                                           readonly value="<?php echo htmlspecialchars(fmtNum(floatval($item['amount'] ?? 0))); ?>">
                                </td>
                                <td>
                                    <input type="text" name="item_notes[]" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-danger btn-sm remove-row">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between">
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Hủy bỏ
            </a>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-save"></i> Cập nhật báo giá
            </button>
        </div>

    </form>

</div>

<footer class="bg-light text-center py-3 mt-4">
    <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const costCodesData = <?php echo json_encode($cost_codes_arr); ?>;

function fmtNumJS(val) {
    val = parseFloat(val) || 0;
    let s = val.toFixed(2);
    s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    let parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? parts[0] + ',' + parts[1] : parts[0];
}

function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function newRowHtml(idx) {
    const ccOptions = costCodesData.map(cc =>
        `<option value="${cc.id}"
            data-code="${escHtml(cc.code)}"
            data-desc="${escHtml(cc.description)}"
            data-currency="${escHtml(cc.default_currency)}"
            data-price="${escHtml(cc.default_unit_price)}"
        >${escHtml(cc.code)}</option>`
    ).join('');

    return `<tr class="item-row">
        <td>
            <input type="hidden" name="arrival_code_id[]" class="arrival-code-id" value="0">
            <select class="form-select form-select-sm cost-code-select" name="_cc_select_new${idx}">
                <option value="">-- Chọn mã --</option>
                ${ccOptions}
            </select>
            <input type="text" name="cost_code[]" class="form-control form-control-sm mt-1 cost-code-text" placeholder="hoặc nhập tay" value="">
        </td>
        <td><input type="text" name="description[]" class="form-control form-control-sm item-desc" value=""></td>
        <td>
            <select name="item_currency[]" class="form-select form-select-sm item-currency">
                <option value="USD">USD</option>
                <option value="VND">VND</option>
                <option value="EUR">EUR</option>
            </select>
        </td>
        <td><input type="number" name="unit_price[]" class="form-control form-control-sm item-price" step="0.0001" min="0" value="0"></td>
        <td><input type="number" name="quantity[]" class="form-control form-control-sm item-qty" step="0.01" min="0" value="1"></td>
        <td><input type="text" class="form-control form-control-sm item-amount bg-light" readonly value=""></td>
        <td><input type="text" name="item_notes[]" class="form-control form-control-sm" value=""></td>
        <td class="text-center">
            <button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>
        </td>
    </tr>`;
}

function calcAmount(row) {
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
    row.querySelector('.item-amount').value = fmtNumJS(price * qty);
}

function bindCostCodeSelect(row) {
    const sel = row.querySelector('.cost-code-select');
    if (!sel) return;
    sel.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        row.querySelector('.arrival-code-id').value = this.value || '0';
        row.querySelector('.cost-code-text').value  = opt.dataset.code  || '';
        row.querySelector('.item-desc').value       = opt.dataset.desc  || '';
        const cur = opt.dataset.currency || 'USD';
        const curSel = row.querySelector('.item-currency');
        for (let o of curSel.options) {
            if (o.value === cur) { o.selected = true; break; }
        }
        row.querySelector('.item-price').value = opt.dataset.price || '0';
        calcAmount(row);
    });
}

function bindCalcEvents(row) {
    row.querySelector('.item-price').addEventListener('input', () => calcAmount(row));
    row.querySelector('.item-qty').addEventListener('input',   () => calcAmount(row));
}

function bindRemove(row) {
    row.querySelector('.remove-row').addEventListener('click', function() {
        const tbody = document.getElementById('itemsTbody');
        if (tbody.querySelectorAll('.item-row').length > 1) {
            row.remove();
        } else {
            alert('Phải có ít nhất 1 dòng chi phí!');
        }
    });
}

document.querySelectorAll('.item-row').forEach(row => {
    bindCostCodeSelect(row);
    bindCalcEvents(row);
    bindRemove(row);
    calcAmount(row);
});

let rowIdx = 1000;
document.getElementById('addRowBtn').addEventListener('click', function() {
    rowIdx++;
    const tbody = document.getElementById('itemsTbody');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = newRowHtml(rowIdx);
    tbody.appendChild(tr);
    const newRow = tbody.lastElementChild;
    bindCostCodeSelect(newRow);
    bindCalcEvents(newRow);
    bindRemove(newRow);
});

document.getElementById('quotForm').addEventListener('submit', function(e) {
    const rows = document.querySelectorAll('.item-row');
    let hasItem = false;
    rows.forEach(row => {
        const code = row.querySelector('.cost-code-text').value.trim();
        const desc = row.querySelector('.item-desc').value.trim();
        if (code || desc) hasItem = true;
    });
    if (!hasItem) {
        e.preventDefault();
        alert('Vui lòng thêm ít nhất 1 dòng chi phí!');
    }
});

// Shipper autocomplete
const shipperInput   = document.getElementById('shipperInput');
const shipperSuggest = document.getElementById('shipperSuggest');
if (shipperInput) {
    shipperInput.addEventListener('input', function() {
        const q = this.value.trim();
        if (q.length < 1) { shipperSuggest.style.display = 'none'; return; }
        fetch('shipper_suggest.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { shipperSuggest.style.display = 'none'; return; }
                shipperSuggest.innerHTML = data.map(s =>
                    `<div class="suggest-item">${escHtml(s)}</div>`
                ).join('');
                shipperSuggest.style.display = 'block';
                shipperSuggest.querySelectorAll('.suggest-item').forEach(item => {
                    item.addEventListener('click', function() {
                        shipperInput.value = this.textContent;
                        shipperSuggest.style.display = 'none';
                    });
                });
            });
    });
    document.addEventListener('click', function(e) {
        if (!shipperInput.contains(e.target) && !shipperSuggest.contains(e.target)) {
            shipperSuggest.style.display = 'none';
        }
    });
}
</script>
</body>
</html>
<?php $conn->close(); ?>