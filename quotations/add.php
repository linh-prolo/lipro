<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ghi vào file log
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/error.log';
ini_set('error_log', $log_file);

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

// --- Sinh số báo giá ---
$year = date('Y');
$like = "BG-$year-%";
$stmt = $conn->prepare("SELECT MAX(quotation_no) AS max_no FROM quotations WHERE quotation_no LIKE ?");
$stmt->bind_param("s", $like);
$stmt->execute();
$row_no = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($row_no['max_no']) {
    $parts   = explode('-', $row_no['max_no']);
    $counter = intval($parts[2] ?? 0) + 1;
} else {
    $counter = 1;
}
$auto_quotation_no = sprintf("BG-%s-%03d", $year, $counter);

// --- Load khách hàng ---
$customers = $conn->query("SELECT id, company_name, short_name FROM customers WHERE status='active' ORDER BY company_name ASC");

// --- Load arrival cost codes ---
$cost_codes = $conn->query("SELECT id, code, description, default_currency, default_unit_price FROM arrival_cost_codes WHERE status='active' ORDER BY code ASC");
$cost_codes_arr = [];
while ($cc = $cost_codes->fetch_assoc()) {
    $cost_codes_arr[] = $cc;
}

// --- Xử lý POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quotation_no  = trim($_POST['quotation_no'] ?? '');
    $customer_id   = intval($_POST['customer_id'] ?? 0);
    $issue_date    = trim($_POST['issue_date'] ?? '');
    $valid_until   = trim($_POST['valid_until'] ?? '');
    $currency      = trim($_POST['currency'] ?? 'USD');
    $exchange_rate = floatval($_POST['exchange_rate'] ?? 1);
    $pol           = trim($_POST['pol'] ?? '');
    $pod           = trim($_POST['pod'] ?? '');
    $shipper       = trim($_POST['shipper'] ?? '');
    $packages      = !empty($_POST['packages']) ? floatval($_POST['packages']) : null;
    $gw            = !empty($_POST['gw']) ? floatval($_POST['gw']) : null;
    $cw            = !empty($_POST['cw']) ? floatval($_POST['cw']) : null;
    $notes         = trim($_POST['notes'] ?? '');
    $status        = trim($_POST['status'] ?? 'draft');
    $created_by    = intval($_SESSION['user_id']);

    error_log("=== FORM SUBMISSION ===");
    error_log("quotation_no: $quotation_no");
    error_log("customer_id: $customer_id");
    error_log("status: $status");
    error_log("created_by: $created_by");

    // Lấy items
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

    error_log("Total items: " . count($items));

    // Validate
    if (empty($quotation_no)) {
        $error = 'Vui lòng nhập số báo giá!';
    } elseif ($customer_id <= 0) {
        $error = 'Vui lòng chọn khách hàng!';
    } elseif (empty($issue_date)) {
        $error = 'Vui lòng nhập ngày lập!';
    } elseif (count($items) === 0) {
        $error = 'Vui lòng thêm ít nhất 1 dòng chi phí!';
    } else {
        $chk = $conn->prepare("SELECT id FROM quotations WHERE quotation_no = ?");
        $chk->bind_param("s", $quotation_no);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'Số báo giá đã tồn tại, vui lòng dùng số khác!';
        }
        $chk->close();
    }

    if (empty($error)) {
        $conn->begin_transaction();
        try {
            error_log("Starting database transaction...");
            
            // ===== INSERT QUOTATIONS =====
            $sql_quotation = "INSERT INTO quotations 
                (quotation_no, customer_id, issue_date, valid_until, currency, exchange_rate, pol, pod, shipper, packages, gw, cw, notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            error_log("Preparing quotation insert...");
            $stmt = $conn->prepare($sql_quotation);
            
            if (!$stmt) {
                throw new Exception("Prepare quotation failed: " . $conn->error);
            }

            error_log("Executing quotation with array parameters...");
            error_log("  quotation_no: $quotation_no");
            error_log("  customer_id: $customer_id");
            error_log("  issue_date: $issue_date");
            error_log("  valid_until: $valid_until");
            error_log("  currency: $currency");
            error_log("  exchange_rate: $exchange_rate");
            error_log("  pol: $pol");
            error_log("  pod: $pod");
            error_log("  shipper: $shipper");
            error_log("  packages: " . ($packages === null ? 'NULL' : $packages));
            error_log("  gw: " . ($gw === null ? 'NULL' : $gw));
            error_log("  cw: " . ($cw === null ? 'NULL' : $cw));
            error_log("  notes: $notes");
            error_log("  status: $status");
            error_log("  created_by: $created_by");

            // Execute dengan array parameters (xử lý NULL tốt hơn)
            $stmt->execute([
                $quotation_no,
                $customer_id,
                $issue_date,
                $valid_until,
                $currency,
                $exchange_rate,
                $pol,
                $pod,
                $shipper,
                $packages,
                $gw,
                $cw,
                $notes,
                $status,
                $created_by
            ]);

            $quot_id = $conn->insert_id;
            $stmt->close();
            error_log("Quotation inserted successfully with ID: $quot_id");

            // ===== INSERT QUOTATION ITEMS =====
            if (count($items) > 0) {
                error_log("Preparing quotation items insert...");
                
                $sql_items = "INSERT INTO quotation_items 
                    (quotation_id, arrival_code_id, cost_code, description, currency, unit_price, quantity, notes, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $istmt = $conn->prepare($sql_items);
                
                if (!$istmt) {
                    throw new Exception("Prepare items failed: " . $conn->error);
                }

                error_log("Inserting " . count($items) . " items...");

                foreach ($items as $idx => $item) {
                    $arrival_code_id = $item['arrival_code_id'];
                    $cost_code = $item['cost_code'];
                    $description = $item['description'];
                    $currency_item = $item['currency'];
                    $unit_price = $item['unit_price'];
                    $quantity = $item['quantity'];
                    $notes_item = $item['notes'];
                    $sort_order = $item['sort_order'];

                    error_log("Item $idx - code: $cost_code, desc: $description, price: $unit_price, qty: $quantity");

                    // Execute với array parameters
                    $istmt->execute([
                        $quot_id,
                        $arrival_code_id,
                        $cost_code,
                        $description,
                        $currency_item,
                        $unit_price,
                        $quantity,
                        $notes_item,
                        $sort_order
                    ]);

                    error_log("Item $idx inserted successfully");
                }

                $istmt->close();
                error_log("All items inserted successfully");
            }

            $conn->commit();
            error_log("Transaction committed successfully");
            
            header("Location: index.php?success=added");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            error_log("EXCEPTION: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $error = 'Có lỗi xảy ra: ' . htmlspecialchars($e->getMessage());
        }
    }

    $auto_quotation_no = $quotation_no;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo Báo Giá - Forwarder System</title>
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
        <h4 class="mb-0"><i class="bi bi-file-earmark-plus text-success"></i> Tạo Báo Giá Mới</h4>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Quay lại
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="quotForm">

        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Thông tin báo giá</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Số báo giá <span class="text-danger">*</span></label>
                        <input type="text" name="quotation_no" class="form-control"
                               value="<?php echo htmlspecialchars($auto_quotation_no); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Khách hàng <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php
                            $sel_cid = intval($_POST['customer_id'] ?? 0);
                            $customers->data_seek(0);
                            while ($c = $customers->fetch_assoc()):
                            ?>
                                <option value="<?php echo $c['id']; ?>"
                                    <?php echo $sel_cid == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['short_name'] . ' — ' . $c['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ngày lập <span class="text-danger">*</span></label>
                        <input type="date" name="issue_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Hiệu lực đến</label>
                        <input type="date" name="valid_until" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['valid_until'] ?? ''); ?>">
                    </div>

                    <!-- POL / POD / Shipper -->
                    <div class="col-md-2">
                        <label class="form-label">POL <small class="text-muted">(Cảng đi)</small></label>
                        <input type="text" name="pol" class="form-control"
                               placeholder="VD: HAN, SGN"
                               value="<?php echo htmlspecialchars($_POST['pol'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">POD <small class="text-muted">(Cảng đến)</small></label>
                        <input type="text" name="pod" class="form-control"
                               placeholder="VD: LAX, HKG"
                               value="<?php echo htmlspecialchars($_POST['pod'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4 position-relative">
                        <label class="form-label">Shipper <small class="text-muted">(Người gửi)</small></label>
                        <input type="text" name="shipper" id="shipperInput" class="form-control"
                               placeholder="Nhập tên người gửi..."
                               autocomplete="off"
                               value="<?php echo htmlspecialchars($_POST['shipper'] ?? ''); ?>">
                        <div id="shipperSuggest" class="suggest-box" style="display:none;"></div>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Số kiện</label>
                        <input type="number" name="packages" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($_POST['packages'] ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">GW (kg)</label>
                        <input type="number" name="gw" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($_POST['gw'] ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">CW (kg)</label>
                        <input type="number" name="cw" class="form-control"
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($_POST['cw'] ?? ''); ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Tiền tệ chính</label>
                        <select name="currency" class="form-select">
                            <?php foreach (['USD','VND','EUR'] as $cur): ?>
                                <option value="<?php echo $cur; ?>"
                                    <?php echo (($_POST['currency'] ?? 'USD') === $cur) ? 'selected' : ''; ?>>
                                    <?php echo $cur; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tỉ giá</label>
                        <input type="number" name="exchange_rate" class="form-control"
                               step="0.0001" min="0"
                               value="<?php echo htmlspecialchars($_POST['exchange_rate'] ?? '1'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = ['draft'=>'Nháp','sent'=>'Đã gửi','accepted'=>'Chấp nhận','rejected'=>'Từ chối','expired'=>'Hết hạn'];
                            $sel_status = $_POST['status'] ?? 'draft';
                            foreach ($statuses as $k => $v):
                            ?>
                                <option value="<?php echo $k; ?>"
                                    <?php echo $sel_status === $k ? 'selected' : ''; ?>>
                                    <?php echo $v; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Ghi chú</label>
                        <textarea name="notes" class="form-control" rows="1"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
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
                            <?php
                            $post_codes  = $_POST['cost_code']      ?? [''];
                            $post_desc   = $_POST['description']    ?? [''];
                            $post_cur    = $_POST['item_currency']  ?? ['USD'];
                            $post_up     = $_POST['unit_price']     ?? [0];
                            $post_qty    = $_POST['quantity']       ?? [1];
                            $post_anotes = $_POST['item_notes']     ?? [''];
                            $post_acid   = $_POST['arrival_code_id']?? [0];
                            foreach ($post_codes as $ri => $rc):
                            ?>
                            <tr class="item-row">
                                <td>
                                    <input type="hidden" name="arrival_code_id[]" class="arrival-code-id"
                                           value="<?php echo intval($post_acid[$ri] ?? 0); ?>">
                                    <select class="form-select form-select-sm cost-code-select" name="_cc_select_<?php echo $ri; ?>">
                                        <option value="">-- Chọn mã --</option>
                                        <?php foreach ($cost_codes_arr as $cc): ?>
                                            <option value="<?php echo $cc['id']; ?>"
                                                data-code="<?php echo htmlspecialchars($cc['code']); ?>"
                                                data-desc="<?php echo htmlspecialchars($cc['description']); ?>"
                                                data-currency="<?php echo htmlspecialchars($cc['default_currency']); ?>"
                                                data-price="<?php echo htmlspecialchars($cc['default_unit_price']); ?>"
                                                <?php echo (intval($post_acid[$ri] ?? 0) == $cc['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cc['code']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="cost_code[]" class="form-control form-control-sm mt-1 cost-code-text"
                                           placeholder="hoặc nhập tay"
                                           value="<?php echo htmlspecialchars($rc); ?>">
                                </td>
                                <td>
                                    <input type="text" name="description[]" class="form-control form-control-sm item-desc"
                                           value="<?php echo htmlspecialchars($post_desc[$ri] ?? ''); ?>">
                                </td>
                                <td>
                                    <select name="item_currency[]" class="form-select form-select-sm item-currency">
                                        <?php foreach (['USD','VND','EUR'] as $cur): ?>
                                            <option value="<?php echo $cur; ?>"
                                                <?php echo (($post_cur[$ri] ?? 'USD') === $cur) ? 'selected' : ''; ?>>
                                                <?php echo $cur; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="unit_price[]" class="form-control form-control-sm item-price"
                                           step="0.0001" min="0"
                                           value="<?php echo htmlspecialchars($post_up[$ri] ?? '0'); ?>">
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control form-control-sm item-qty"
                                           step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($post_qty[$ri] ?? '1'); ?>">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm item-amount bg-light"
                                           readonly value="">
                                </td>
                                <td>
                                    <input type="text" name="item_notes[]" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($post_anotes[$ri] ?? ''); ?>">
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
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Hủy bỏ
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Lưu báo giá
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
const shipperInput  = document.getElementById('shipperInput');
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
            })
            .catch(err => console.error('Shipper suggest error:', err));
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