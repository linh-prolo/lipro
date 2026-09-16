<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
require_once '../config/EHoaDonClient.php';
checkLogin();
if (isset($_GET['ver'])) {
    echo defined('EHOADON_CLIENT_VERSION') ? EHOADON_CLIENT_VERSION : 'OLD FILE - chua update';
    exit;
}
if (isset($_GET['findfile'])) {
    $ref = new ReflectionClass('EHoaDonClient');
    echo 'File đang chạy: ' . $ref->getFileName();
    exit;
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
    c.company_name, c.short_name AS customer_short,
    c.address AS customer_address,
    c.tax_code AS customer_tax,
    c.email AS customer_email,
    c.phone AS customer_phone,
    c.contact_person AS customer_contact,
    a.full_name AS issued_by_name
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN accounts a ON s.vat_issued_by = a.id
    WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

// ── [NEW] Use LEFT JOIN + COALESCE for description ─────────────────────────
$stmt_sell = $conn->prepare(
    "SELECT ss.*,
            COALESCE(cc.code, '') AS code,
            COALESCE(
                NULLIF(TRIM(ss.description), ''),
                cc.description,
                ss.notes,
                ''
            ) AS description
     FROM shipment_sells ss
     LEFT JOIN cost_codes cc ON ss.cost_code_id = cc.id
     WHERE ss.shipment_id = ? ORDER BY ss.id"
);
$stmt_sell->bind_param("i", $id);
$stmt_sell->execute();
$sells_all = $stmt_sell->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Tách POB ──────────────────────────────────────────────────────────────
$sells_vat = array_values(array_filter($sells_all, function($s) {
    return empty($s['is_pob']) || intval($s['is_pob']) !== 1;
}));
$sells_pob = array_values(array_filter($sells_all, function($s) {
    return !empty($s['is_pob']) && intval($s['is_pob']) === 1;
}));

// ── [NEW] Tách VAT > 0% và VAT = 0% ─────────────────────────────────────
$sells_vat_8pct = array_values(array_filter($sells_vat, function($s) {
    return floatval($s['vat']) > 0;
}));
$sells_vat_0pct = array_values(array_filter($sells_vat, function($s) {
    return floatval($s['vat']) == 0;
}));

// ── [NEW] Preview riêng cho từng nhóm ────────────────────────────────────
$preview8Excl = 0; $preview8Vat = 0;
$preview0Excl = 0;
foreach ($sells_vat_8pct as $s) {
    $preview8Excl += round($s['unit_price'] * $s['quantity'], 0);
    $preview8Vat  += round($s['unit_price'] * $s['quantity'] * $s['vat'] / 100, 0);
}
$preview8Total = $preview8Excl + $preview8Vat;

foreach ($sells_vat_0pct as $s) {
    $preview0Excl += round($s['unit_price'] * $s['quantity'], 0);
}
$preview0Total = $preview0Excl;

// Giữ biến cũ để không hỏng phần tabInfo / tabDraft / v.v.
$previewExcl  = $preview8Excl + $preview0Excl;
$previewVat   = $preview8Vat;
$previewTotal = $previewExcl + $previewVat;

$totalPob = 0;
foreach ($sells_pob as $s) { $totalPob += $s['total_amount']; }

$conn->close();

$error     = '';
$success   = '';
$rawResult = null;
$activeTab = $_GET['tab'] ?? 'issue';

// ============================================================
// XỬ LÝ POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----------------------------------------------------------
    // PHÁT HÀNH HÓA ĐƠN
    // ----------------------------------------------------------
    if ($action === 'issue') {
        if (empty($shipment['customer_tax'])) {
            $error = 'Khách hàng chưa có Mã số thuế!';
        } elseif (empty($sells_vat)) {
            $error = 'Không có khoản SELL nào cần xuất HĐ VAT!';
        } else {
            $invoiceDate   = trim($_POST['invoice_date']   ?? date('Y-m-d'));
            $buyerName     = trim($_POST['buyer_name']     ?? '');
            $paymentMethod = trim($_POST['payment_method'] ?? 'TM/CK');
            $extraNote     = trim($_POST['extra_note']     ?? '');

            // ── [NEW] Chọn nhóm hàng theo nút bấm ───────────────────────
            $invoiceGroup = trim($_POST['invoice_group'] ?? 'all');
            if ($invoiceGroup === 'vat8') {
                $sellsToIssue = $sells_vat_8pct;
                $groupLabel   = 'VAT > 0%';
            } elseif ($invoiceGroup === 'vat0') {
                $sellsToIssue = $sells_vat_0pct;
                $groupLabel   = 'VAT 0%';
                $extraNote = rtrim($extraNote) === ''
                    ? '0'
                    : $extraNote . ' | 0';
            } else {
                $sellsToIssue = $sells_vat;
                $groupLabel   = 'Tất cả';
            }

            if (empty($sellsToIssue)) {
                $error = "Không có dòng phí nào trong nhóm {$groupLabel}!";
            } else {
                try {
                    if (!defined('EHOADON_PARTNER_GUID') || empty(EHOADON_PARTNER_GUID)) {
                        throw new \Exception('Cấu hình eHoaDon chưa được load.');
                    }

                    $client         = new EHoaDonClient();
                    $invoicePayload = $client->buildInvoicePayload(
                        $shipment,
                        $sellsToIssue,
                        $invoiceDate, $buyerName, $paymentMethod, $extraNote,
                        $invoiceGroup
                    );

                    $result    = $client->createInvoice($invoicePayload);
                    $rawResult = $result;

                    if ($client->isSuccess($result)) {
                        $guid     = (string)($result['InvoiceGUID']   ?? '');
                        $invNo    = (string)($result['InvoiceNo']     ?? '');
                        $serial   = (string)($result['InvoiceSerial'] ?? '');
                        $form     = (string)($result['InvoiceForm']   ?? '');
                        $mtc      = (string)($result['MTC']           ?? '');
                        $issuedAt = date('Y-m-d H:i:s');
                        $userId   = intval($_SESSION['user_id']);

                        $conn2 = getDBConnection();

                        // ── [NEW] Lưu vào đúng cột tùy nhóm ─────────────
                        if ($invoiceGroup === 'vat0') {
                            // HĐ 0%: lưu vào cột riêng _0pct
                            $stmt2 = $conn2->prepare("UPDATE shipments SET
                                vat_invoice_guid_0pct   = ?,
                                vat_invoice_no_0pct     = ?,
                                vat_invoice_serial_0pct = ?,
                                vat_invoice_form_0pct   = ?,
                                vat_invoice_mtc_0pct    = ?,
                                vat_invoice_status_0pct = 'issued'
                                WHERE id = ?");
                            if (!$stmt2) throw new \Exception('DB error: ' . $conn2->error);
                            $stmt2->bind_param("sssssi",
                                $guid, $invNo, $serial, $form, $mtc, $id);
                        } else {
                            // HĐ có VAT% hoặc all: lưu vào cột chính
                            $stmt2 = $conn2->prepare("UPDATE shipments SET
                                vat_invoice_guid   = ?,
                                vat_invoice_no     = ?,
                                vat_invoice_serial = ?,
                                vat_invoice_form   = ?,
                                vat_invoice_mtc    = ?,
                                vat_invoice_status = 'issued',
                                vat_issued_at      = ?,
                                vat_issued_by      = ?
                                WHERE id = ?");
                            if (!$stmt2) {
                                $stmt2 = $conn2->prepare("UPDATE shipments SET
    vat_invoice_guid   = ?,
    vat_invoice_no     = ?,
    vat_invoice_serial = ?,
    vat_invoice_form   = ?,
    vat_invoice_mtc    = ?,
    vat_invoice_status = 'issued',
    vat_issued_at      = ?,
    vat_issued_by      = ?
    WHERE id = ?");
                                if (!$stmt2) throw new \Exception('DB error: ' . $conn2->error);
                                $stmt2->bind_param("ssssii",
                                    $guid, $invNo, $serial, $issuedAt, $userId, $id);
                            } else {
                                $stmt2->bind_param("ssssssii",
                                    $guid, $invNo, $serial, $form, $mtc, $issuedAt, $userId, $id);
                            }
                        }

                        $stmt2->execute();
                        if ($stmt2->error) throw new \Exception('DB execute error: ' . $stmt2->error);
                        $stmt2->close();
                        $conn2->close();

                        // ── [NEW] Cập nhật $shipment trong memory đúng cột ──
                        if ($invoiceGroup === 'vat0') {
                            $shipment['vat_invoice_guid_0pct']   = $guid;
                            $shipment['vat_invoice_no_0pct']     = $invNo;
                            $shipment['vat_invoice_serial_0pct'] = $serial;
                            $shipment['vat_invoice_form_0pct']   = $form;
                            $shipment['vat_invoice_mtc_0pct']    = $mtc;
                            $shipment['vat_invoice_status_0pct'] = 'issued';
                        } else {
                            $shipment['vat_invoice_guid']   = $guid;
                            $shipment['vat_invoice_no']     = $invNo;
                            $shipment['vat_invoice_serial'] = $serial;
                            $shipment['vat_invoice_form']   = $form;
                            $shipment['vat_invoice_mtc']    = $mtc;
                            $shipment['vat_invoice_status'] = 'issued';
                            $shipment['vat_issued_at']      = $issuedAt;
                        }

                        $displayNo = ($invNo !== '' && $invNo !== '0')
                            ? ' Số HĐ: <strong>' . htmlspecialchars($invNo) . '</strong>'
                            : ' <span class="badge bg-warning text-dark ms-1"><i class="bi bi-hourglass-split"></i> Chờ CQT cấp số</span>';
                        $displayMtc  = $mtc ? ' | MTC: <strong class="text-success">' . htmlspecialchars($mtc) . '</strong>' : '';
                        $displayGuid = ' | GUID: <code class="small">' . htmlspecialchars(substr($guid, 0, 8)) . '...</code>';

                        $success   = '✅ Phát hành hóa đơn VAT <strong>' . $groupLabel . '</strong> thành công!'
                                   . $displayNo . $displayMtc . $displayGuid;
                        $activeTab = 'info';

                    } else {
                        $error = '❌ eHoaDon lỗi: ' . htmlspecialchars($client->getErrorMessage($result));
                    }

                } catch (\Exception $e) {
                    $error = '❌ ' . $e->getMessage();
                }
            }
        }
    }

    // ----------------------------------------------------------
    // LÀM MỚI TỪ eHoaDon
    // ----------------------------------------------------------
    if ($action === 'refresh') {
        try {
            $client = new EHoaDonClient();
            // Thử lấy HĐ có VAT% trước (PartnerInvoiceID = shipment id gốc)
            // Nếu không tìm thấy thì thôi, không crash
            $result = $client->getInvoice($id);
            $rawResult = $result;

            if ($client->isSuccess($result)) {
                $guid   = (string)($result['InvoiceGUID']   ?? $shipment['vat_invoice_guid'] ?? '');
                $invNo  = (string)($result['InvoiceNo']     ?? $shipment['vat_invoice_no']   ?? '');
                $serial = (string)($result['InvoiceSerial'] ?? $shipment['vat_invoice_serial'] ?? '');
                $mtc    = (string)($result['MTC']           ?? $shipment['vat_invoice_mtc']  ?? '');

                $conn2 = getDBConnection();
                $stmt2 = $conn2->prepare("UPDATE shipments SET
                    vat_invoice_guid   = ?,
                    vat_invoice_no     = ?,
                    vat_invoice_serial = ?,
                    vat_invoice_mtc    = ?
                    WHERE id = ?");
                $stmt2->bind_param("ssssi", $guid, $invNo, $serial, $mtc, $id);
                $stmt2->execute();
                $stmt2->close();
                $conn2->close();

                $shipment['vat_invoice_guid']   = $guid;
                $shipment['vat_invoice_no']     = $invNo;
                $shipment['vat_invoice_serial'] = $serial;
                $shipment['vat_invoice_mtc']    = $mtc;

                $refreshNo = ($invNo !== '' && $invNo !== '0')
                    ? ' — Số HĐ: <strong>' . htmlspecialchars($invNo) . '</strong>'
                    : ' — Chưa được CQT cấp số';
                $success = '✅ Đã làm mới thông tin hóa đơn!' . $refreshNo;
            } else {
                $messLog = trim($result['MessLog'] ?? '');
                $error   = ($messLog === '' || str_contains($messLog, 'object is null'))
                    ? '⚠️ Chưa có hóa đơn nào trên eHoaDon cho lô này.'
                    : '⚠️ eHoaDon: ' . htmlspecialchars($messLog);
            }
            $activeTab = 'info';
        } catch (\Exception $e) {
            $error     = '❌ ' . $e->getMessage();
            $activeTab = 'info';
        }
    }

    // ----------------------------------------------------------
    // HỦY HÓA ĐƠN
    // ----------------------------------------------------------
    if ($action === 'cancel') {
        if ($_SESSION['role'] !== 'admin') {
            $error = 'Chỉ Admin mới có thể hủy hóa đơn!';
        } else {
            $reason = trim($_POST['cancel_reason'] ?? '');
            if (empty($reason)) {
                $error = 'Vui lòng nhập lý do hủy!';
            } else {
                try {
                    $client    = new EHoaDonClient();
                    $result    = $client->cancelInvoice($id, $reason);
                    $rawResult = $result;

                    if ($client->isSuccess($result)) {
                        $cancelledAt = date('Y-m-d H:i:s');
                        $conn2 = getDBConnection();
                        $stmt2 = $conn2->prepare("UPDATE shipments SET
                            vat_invoice_status = 'cancelled',
                            vat_cancelled_at   = ?,
                            vat_cancel_reason  = ?
                            WHERE id = ?");
                        $stmt2->bind_param("ssi", $cancelledAt, $reason, $id);
                        $stmt2->execute();
                        $stmt2->close();
                        $conn2->close();

                        $shipment['vat_invoice_status'] = 'cancelled';
                        $shipment['vat_cancelled_at']   = $cancelledAt;
                        $shipment['vat_cancel_reason']  = $reason;
                        $success = '✅ Hóa đơn đã được hủy thành công!';
                    } else {
                        $error = '❌ Hủy thất bại: ' . htmlspecialchars($client->getErrorMessage($result));
                    }
                    $activeTab = 'cancel';
                } catch (\Exception $e) {
                    $error     = '❌ ' . $e->getMessage();
                    $activeTab = 'cancel';
                }
            }
        }
    }
}

// ============================================================
// Chuẩn bị biến HTML
// ============================================================
$statusInfo = [
    'issued'    => ['bg-success', 'Đã phát hành', 'check-circle-fill'],
    'cancelled' => ['bg-danger',  'Đã hủy',        'x-circle-fill'],
    'draft'     => ['bg-warning', 'Nháp',           'pencil'],
];
$vatStatus = $shipment['vat_invoice_status'] ?? '';
$sBadge    = $statusInfo[$vatStatus] ?? ['bg-secondary', 'Chưa xuất', 'dash-circle'];

$hasValidGuid = !empty($shipment['vat_invoice_guid'])
    && $shipment['vat_invoice_guid'] !== '00000000-0000-0000-0000-000000000000';

// ── [NEW] Biến cho HĐ 0% ─────────────────────────────────────────────────
$hasValid0PctGuid = !empty($shipment['vat_invoice_guid_0pct'])
    && $shipment['vat_invoice_guid_0pct'] !== '00000000-0000-0000-0000-000000000000';
$invNo0Saved  = (string)($shipment['vat_invoice_no_0pct'] ?? '');
// ─────────────────────────────────────────────────────────────────────────

$invNoSaved  = (string)($shipment['vat_invoice_no'] ?? '');
$btnDisabled = (empty($shipment['customer_tax']) || empty($sells_vat)) ? 'disabled' : '';
$confirmCancel = 'XÁC NHẬN HỦY HÓA ĐƠN?\n\nThao tác này không thể hoàn tác!';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hóa đơn VAT - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .nav-tabs .nav-link.active { font-weight:600; }
        .info-row { display:flex; padding:6px 0; border-bottom:1px dashed #f0f0f0; }
        .info-label { min-width:170px; font-size:.82rem; color:#6c757d; font-weight:600; }
        .info-value { font-size:.88rem; flex:1; }
        .amount-display { font-size:1.4rem; font-weight:700; }
        .draft-watermark { position:relative; }
        .draft-watermark::after {
            content:'DRAFT'; position:absolute; top:50%; left:50%;
            transform:translate(-50%,-50%) rotate(-30deg);
            font-size:5rem; font-weight:900; color:rgba(200,0,0,.08);
            pointer-events:none; white-space:nowrap; z-index:0;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../customers/index.php">Khách hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a></li>
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

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Lô hàng</a></li>
            <li class="breadcrumb-item">
                <a href="view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($shipment['job_no']); ?></a>
            </li>
            <li class="breadcrumb-item active">Hóa đơn VAT</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-receipt-cutoff text-danger"></i>
                Hóa đơn VAT —
                <strong class="text-primary"><?php echo htmlspecialchars($shipment['job_no']); ?></strong>
            </h4>
            <span class="badge bg-<?php echo $sBadge[0]; ?> fs-6">
                <i class="bi bi-<?php echo $sBadge[2]; ?>"></i> <?php echo $sBadge[1]; ?>
            </span>
            <?php if ($invNoSaved !== '' && $invNoSaved !== '0'): ?>
            <span class="badge bg-dark fs-6 ms-1">
                <i class="bi bi-hash"></i> <?php echo htmlspecialchars($invNoSaved); ?>
            </span>
            <?php endif; ?>
            <?php if ($hasValidGuid): ?>
            <span class="badge bg-info text-dark fs-6 ms-1"
                  title="<?php echo htmlspecialchars($shipment['vat_invoice_guid']); ?>">
                <i class="bi bi-check2-circle"></i> Đã gửi CQT
            </span>
            <?php endif; ?>
            <?php if ($hasValid0PctGuid): ?>
            <span class="badge bg-info text-dark fs-6 ms-1"
                  title="<?php echo htmlspecialchars($shipment['vat_invoice_guid_0pct']); ?>">
                <i class="bi bi-check2-circle"></i> HĐ 0% đã gửi CQT
            </span>
            <?php endif; ?>
        </div>
        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Quay lại
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
        <?php if ($rawResult): ?>
        <hr>
        <details>
            <summary class="small">Raw response từ eHoaDon</summary>
            <pre class="small mt-1 mb-0"><?php
                $display = array_filter($rawResult, fn($k) => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY);
                echo htmlspecialchars(json_encode($display, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            ?></pre>
        </details>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
        <?php if ($rawResult): ?>
        <hr>
        <details>
            <summary class="small">Raw response</summary>
            <pre class="small mt-1 mb-0"><?php
                $display = array_filter($rawResult, fn($k) => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY);
                echo htmlspecialchars(json_encode($display, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            ?></pre>
        </details>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($sells_pob)): ?>
    <div class="alert alert-warning py-2 d-flex align-items-center gap-2">
        <i class="bi bi-arrow-left-right fs-5"></i>
        <div>
            <strong>Lưu ý Chi hộ (POB):</strong>
            Có <strong><?php echo count($sells_pob); ?> khoản</strong>
            (tổng <strong><?php echo number_format($totalPob, 0, ',', '.'); ?> VND</strong>)
            sẽ <strong class="text-danger">KHÔNG xuất trên HĐ VAT</strong>.
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- LEFT: TABS -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <ul class="nav nav-tabs px-3 pt-2">
                        <li class="nav-item">
                            <button class="nav-link <?php echo $activeTab==='issue'?'active':''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#tabIssue">
                                <i class="bi bi-send-fill text-primary"></i> Phát hành HĐ
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?php echo $activeTab==='draft'?'active':''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#tabDraft">
                                <i class="bi bi-eye text-secondary"></i> Xem nháp
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?php echo $activeTab==='info'?'active':''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#tabInfo">
                                <i class="bi bi-info-circle text-info"></i> Thông tin HĐ
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?php echo $activeTab==='download'?'active':''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#tabDownload">
                                <i class="bi bi-download text-success"></i> Tải về
                            </button>
                        </li>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li class="nav-item">
                            <button class="nav-link <?php echo $activeTab==='cancel'?'active':''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#tabCancel">
                                <i class="bi bi-x-circle text-danger"></i> Hủy HĐ
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <div class="tab-content p-3">

                        <!-- ============================================================ -->
                        <!-- TAB PHÁT HÀNH                                                 -->
                        <!-- ============================================================ -->
                        <div class="tab-pane fade <?php echo $activeTab==='issue'?'show active':''; ?>" id="tabIssue">
                            <?php if ($vatStatus === 'issued'): ?>
                            <div class="alert alert-warning py-2 mb-3">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Lô này <strong>đã có HĐ VAT</strong>
                                (GUID: <code><?php echo htmlspecialchars(substr($shipment['vat_invoice_guid']??'',0,8)); ?>...</code>).
                                Phát hành lại sẽ tạo hóa đơn <strong>MỚI</strong>.
                            </div>
                            <?php endif; ?>
                            <?php if (empty($shipment['customer_tax'])): ?>
                            <div class="alert alert-danger py-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Khách hàng chưa có <strong>Mã số thuế</strong>.
                                Vui lòng <a href="../customers/edit.php?id=<?php echo $shipment['customer_id']; ?>">cập nhật MST</a> trước.
                            </div>
                            <?php endif; ?>

                            <form method="POST" id="issueForm">
                                <input type="hidden" name="action" value="issue">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Ngày xuất HĐ <span class="text-danger">*</span></label>
                                        <input type="date" name="invoice_date" class="form-control"
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Hình thức TT</label>
                                        <select name="payment_method" class="form-select">
                                            <option value="TM/CK" selected>TM/CK</option>
                                            <option value="CK">Chuyển khoản</option>
                                            <option value="TM">Tiền mặt</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Tên người mua</label>
                                        <input type="text" name="buyer_name" class="form-control"
                                               value="<?php echo htmlspecialchars($shipment['customer_contact'] ?? $shipment['company_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Ghi chú thêm <small class="text-muted fw-normal">(tùy chọn)</small></label>
                                        <input type="text" name="extra_note" class="form-control" placeholder="VD: Tờ khai số 123456...">
                                        <div class="form-text">
                                            Tự động: <code>Phi DV: <?php echo htmlspecialchars($shipment['job_no']); ?>
                                            | So van don: <?php echo htmlspecialchars($shipment['hawb']??''); ?>
                                            | So to khai: <?php echo htmlspecialchars($shipment['customs_declaration_no']??''); ?></code>
                                        </div>
                                    </div>
                                </div>

                                <!-- ══ BẢNG 1: VAT > 0% ══ -->
                                <?php if (!empty($sells_vat_8pct)): ?>
                                <div class="card border-danger mb-3">
                                    <div class="card-header bg-danger text-white py-2 d-flex align-items-center justify-content-between">
                                        <span>
                                            <i class="bi bi-receipt-cutoff"></i>
                                            <strong>HĐ VAT 1 — Dịch vụ có VAT</strong>
                                            <span class="badge bg-light text-danger ms-1"><?php echo count($sells_vat_8pct); ?> dòng</span>
                                        </span>
                                        <span class="badge bg-white text-danger fs-6">
                                            Tổng: <?php echo number_format($preview8Total,0,',','.'); ?> VND
                                        </span>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered mb-0">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>#</th><th>Nội dung</th>
                                                        <th class="text-center">ĐVT</th>
                                                        <th class="text-center">SL</th>
                                                        <th class="text-end">Đơn giá</th>
                                                        <th class="text-center">VAT%</th>
                                                        <th class="text-end">Tiền VAT</th>
                                                        <th class="text-end">Thành tiền</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php $rowNum=1; foreach ($sells_vat_8pct as $s):
                                                    $amt    = round($s['unit_price'] * $s['quantity'], 0);
                                                    $vatAmt = round($amt * $s['vat'] / 100, 0);
                                                    $dname  = $s['description'];
                                                    if (stripos($s['code']??'','TRUCK') !== false && !empty(trim($s['notes']??''))) {
                                                        $dname = $s['description'] . ' (' . trim($s['notes']) . ')';
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo $rowNum++; ?></td>
                                                    <td><?php echo htmlspecialchars($dname); ?></td>
                                                    <td class="text-center">Lô</td>
                                                    <td class="text-center"><?php echo number_format($s['quantity'],2); ?></td>
                                                    <td class="text-end"><?php echo number_format($s['unit_price'],0,',','.'); ?></td>
                                                    <td class="text-center text-danger fw-bold"><?php echo $s['vat']; ?>%</td>
                                                    <td class="text-end text-warning"><?php echo number_format($vatAmt,0,',','.'); ?></td>
                                                    <td class="text-end fw-bold text-success"><?php echo number_format($s['total_amount'],0,',','.'); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                                <tfoot class="table-secondary fw-bold">
                                                    <tr>
                                                        <td colspan="6" class="text-end">Tổng trước VAT:</td>
                                                        <td colspan="2" class="text-end"><?php echo number_format($preview8Excl,0,',','.'); ?> VND</td>
                                                    </tr>
                                                    <tr>
                                                        <td colspan="6" class="text-end">Tổng VAT:</td>
                                                        <td colspan="2" class="text-end text-warning"><?php echo number_format($preview8Vat,0,',','.'); ?> VND</td>
                                                    </tr>
                                                    <tr class="table-dark">
                                                        <td colspan="6" class="text-end">TỔNG THANH TOÁN:</td>
                                                        <td colspan="2" class="text-end text-warning fs-6"><?php echo number_format($preview8Total,0,',','.'); ?> VND</td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="card-footer text-end">
                                        <button type="submit" name="invoice_group" value="vat8"
                                                class="btn btn-danger px-4" <?php echo $btnDisabled; ?>
                                                onclick="return confirm('Phát hành HĐ VAT (có VAT%) cho <?php echo addslashes($shipment['job_no']); ?>?\n\nHĐ sẽ gửi lên CQT, không thể xóa!')">
                                            <i class="bi bi-send-fill"></i>
                                            Phát hành HĐ VAT 1 (có VAT%) — <?php echo count($sells_vat_8pct); ?> dòng
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- ══ BẢNG 2: VAT = 0% ══ -->
                                <?php if (!empty($sells_vat_0pct)): ?>
                                <div class="card border-info mb-3">
                                    <div class="card-header bg-info text-white py-2 d-flex align-items-center justify-content-between">
                                        <span>
                                            <i class="bi bi-receipt"></i>
                                            <strong>HĐ VAT 2 — Dịch vụ VAT 0%</strong>
                                            <span class="badge bg-light text-info ms-1"><?php echo count($sells_vat_0pct); ?> dòng</span>
                                        </span>
                                        <span class="badge bg-white text-info fs-6">
                                            Tổng: <?php echo number_format($preview0Total,0,',','.'); ?> VND
                                        </span>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered mb-0">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>#</th><th>Nội dung</th>
                                                        <th class="text-center">ĐVT</th>
                                                        <th class="text-center">SL</th>
                                                        <th class="text-end">Đơn giá</th>
                                                        <th class="text-center">VAT%</th>
                                                        <th class="text-end">Thành tiền</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php $rowNum=1; foreach ($sells_vat_0pct as $s):
                                                    $dname = $s['description'];
                                                    if (stripos($s['code']??'','TRUCK') !== false && !empty(trim($s['notes']??''))) {
                                                        $dname = $s['description'] . ' (' . trim($s['notes']) . ')';
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo $rowNum++; ?></td>
                                                    <td><?php echo htmlspecialchars($dname); ?></td>
                                                    <td class="text-center">Lô</td>
                                                    <td class="text-center"><?php echo number_format($s['quantity'],2); ?></td>
                                                    <td class="text-end"><?php echo number_format($s['unit_price'],0,',','.'); ?></td>
                                                    <td class="text-center text-muted">0%</td>
                                                    <td class="text-end fw-bold text-success"><?php echo number_format($s['total_amount'],0,',','.'); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                                <tfoot class="table-dark fw-bold">
                                                    <tr>
                                                        <td colspan="5" class="text-end">TỔNG THANH TOÁN (0% VAT):</td>
                                                        <td colspan="2" class="text-end text-warning fs-6"><?php echo number_format($preview0Total,0,',','.'); ?> VND</td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="card-footer text-end">
                                        <button type="submit" name="invoice_group" value="vat0"
                                                class="btn btn-info text-white px-4" <?php echo $btnDisabled; ?>
                                                onclick="return confirm('Phát hành HĐ VAT 0% cho <?php echo addslashes($shipment['job_no']); ?>?\n\nHĐ sẽ gửi lên CQT, không thể xóa!')">
                                            <i class="bi bi-send-fill"></i>
                                            Phát hành HĐ VAT 2 (0%) — <?php echo count($sells_vat_0pct); ?> dòng
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (empty($sells_vat_8pct) && empty($sells_vat_0pct)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size:3rem;color:#ccc;"></i>
                                    <p class="text-muted mt-2">Không có khoản SELL nào để xuất HĐ</p>
                                </div>
                                <?php endif; ?>

                                <!-- POB list -->
                                <?php if (!empty($sells_pob)): ?>
                                <div class="mb-3">
                                    <p class="small text-muted mb-1">
                                        <i class="bi bi-arrow-left-right text-warning"></i>
                                        <strong>Chi hộ (không xuất HĐ):</strong>
                                    </p>
                                    <table class="table table-sm mb-0" style="background:#fffbeb;opacity:.75;">
                                        <thead><tr style="background:#fef3c7;">
                                            <th style="color:#92400e;">#</th>
                                            <th style="color:#92400e;">Nội dung</th>
                                            <th class="text-end" style="color:#92400e;">Thành tiền</th>
                                            <th style="color:#92400e;">Ghi chú</th>
                                        </tr></thead>
                                        <tbody>
                                        <?php $pi=1; foreach ($sells_pob as $ps): ?>
                                        <tr>
                                            <td><?php echo $pi++; ?></td>
                                            <td><?php echo htmlspecialchars($ps['description']); ?></td>
                                            <td class="text-end"><?php echo number_format($ps['total_amount'],0,',','.'); ?></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($ps['notes']??''); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>

                            </form>
                        </div><!-- /tabIssue -->

                        <!-- ============================================================ -->
                        <!-- TAB XEM NHÁP                                                  -->
                        <!-- ============================================================ -->
                        <div class="tab-pane fade <?php echo $activeTab==='draft'?'show active':''; ?>" id="tabDraft">
                            <div class="draft-watermark">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-muted mb-0">
                                        <i class="bi bi-eye"></i> Nội dung HĐ VAT
                                        <span class="badge bg-secondary ms-1"><?php echo count($sells_vat); ?> dòng</span>
                                        <?php if (!empty($sells_pob)): ?>
                                        <span class="badge bg-warning text-dark ms-1"><?php echo count($sells_pob); ?> chi hộ đã loại</span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>#</th>
                                                <th>Nội dung</th>
                                                <th class="text-center">ĐVT</th>
                                                <th class="text-center">SL</th>
                                                <th class="text-end">Đơn giá</th>
                                                <th class="text-center">VAT%</th>
                                                <th class="text-end">Tiền VAT</th>
                                                <th class="text-end">Thành tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php $n=1; foreach ($sells_vat as $s):
                                            $amt    = round($s['unit_price'] * $s['quantity'], 0);
                                            $vatAmt = round($amt * $s['vat'] / 100, 0);
                                            $dname  = $s['description'];
                                            if (stripos($s['code']??'','TRUCK') !== false && !empty(trim($s['notes']??''))) {
                                                $dname = $s['description'] . ' (' . trim($s['notes']) . ')';
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo $n++; ?></td>
                                            <td><?php echo htmlspecialchars($dname); ?></td>
                                            <td class="text-center">Lô</td>
                                            <td class="text-center"><?php echo number_format($s['quantity'],2); ?></td>
                                            <td class="text-end"><?php echo number_format($s['unit_price'],0,',','.'); ?></td>
                                            <td class="text-center <?php echo floatval($s['vat'])>0?'text-danger fw-bold':'text-muted'; ?>">
                                                <?php echo $s['vat']; ?>%
                                            </td>
                                            <td class="text-end text-warning"><?php echo $vatAmt > 0 ? number_format($vatAmt,0,',','.') : '0'; ?></td>
                                            <td class="text-end fw-bold text-success"><?php echo number_format($s['total_amount'],0,',','.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-secondary fw-bold">
                                            <tr>
                                                <td colspan="7" class="text-end">Tổng trước VAT:</td>
                                                <td class="text-end"><?php echo number_format($previewExcl,0,',','.'); ?> VND</td>
                                            </tr>
                                            <tr>
                                                <td colspan="7" class="text-end">Tổng VAT:</td>
                                                <td class="text-end text-warning"><?php echo number_format($previewVat,0,',','.'); ?> VND</td>
                                            </tr>
                                            <tr class="table-dark">
                                                <td colspan="7" class="text-end">TỔNG THANH TOÁN:</td>
                                                <td class="text-end text-warning"><?php echo number_format($previewTotal,0,',','.'); ?> VND</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div><!-- /tabDraft -->

                        <!-- ============================================================ -->
                        <!-- TAB THÔNG TIN HĐ                                             -->
                        <!-- ============================================================ -->
                        <div class="tab-pane fade <?php echo $activeTab==='info'?'show active':''; ?>" id="tabInfo">
                            <?php if ($vatStatus === 'issued' || $vatStatus === 'cancelled'): ?>

                            <!-- ── HĐ có VAT% ── -->
                            <h6 class="text-danger mb-2"><i class="bi bi-receipt-cutoff"></i> HĐ VAT 1 (có VAT%)</h6>
                            <div class="info-row">
                                <div class="info-label">Trạng thái HĐ</div>
                                <div class="info-value">
                                    <span class="badge bg-<?php echo $sBadge[0]; ?>">
                                        <i class="bi bi-<?php echo $sBadge[2]; ?>"></i> <?php echo $sBadge[1]; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Số HĐ</div>
                                <div class="info-value">
                                    <?php if ($invNoSaved !== '' && $invNoSaved !== '0'): ?>
                                        <strong class="fs-5"><?php echo htmlspecialchars($invNoSaved); ?></strong>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-hourglass-split"></i> Chờ CQT cấp số
                                        </span>
                                        <button type="button" class="btn btn-outline-info btn-sm ms-2"
                                                onclick="document.getElementById('refreshForm').submit()">
                                            <i class="bi bi-arrow-clockwise"></i> Làm mới
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Serial / Ký hiệu</div>
                                <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_serial']??'—'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Mẫu số</div>
                                <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_form']??'—'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">MTC</div>
                                <div class="info-value">
                                    <?php if (!empty($shipment['vat_invoice_mtc'])): ?>
                                        <code class="text-success"><?php echo htmlspecialchars($shipment['vat_invoice_mtc']); ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">GUID</div>
                                <div class="info-value">
                                    <code class="small"><?php echo htmlspecialchars($shipment['vat_invoice_guid']??'—'); ?></code>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Ngày phát hành</div>
                                <div class="info-value">
                                    <?php echo !empty($shipment['vat_issued_at'])
                                        ? date('d/m/Y H:i', strtotime($shipment['vat_issued_at']))
                                        : '—'; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Người phát hành</div>
                                <div class="info-value"><?php echo htmlspecialchars($shipment['issued_by_name']??'—'); ?></div>
                            </div>
                            <?php if ($vatStatus === 'cancelled'): ?>
                            <div class="info-row">
                                <div class="info-label">Ngày hủy</div>
                                <div class="info-value text-danger">
                                    <?php echo !empty($shipment['vat_cancelled_at'])
                                        ? date('d/m/Y H:i', strtotime($shipment['vat_cancelled_at']))
                                        : '—'; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Lý do hủy</div>
                                <div class="info-value text-danger"><?php echo htmlspecialchars($shipment['vat_cancel_reason']??'—'); ?></div>
                            </div>
                            <?php endif; ?>

                            <!-- ── [NEW] HĐ 0% ── -->
                            <?php if ($hasValid0PctGuid || !empty($shipment['vat_invoice_status_0pct'])): ?>
                            <hr>
                            <h6 class="text-info mb-2"><i class="bi bi-receipt"></i> HĐ VAT 2 (0%)</h6>
                            <div class="info-row">
                                <div class="info-label">Trạng thái HĐ 0%</div>
                                <div class="info-value">
                                    <span class="badge bg-<?php echo ($shipment['vat_invoice_status_0pct']??'')==='issued'?'success':'secondary'; ?>">
                                        <?php echo ($shipment['vat_invoice_status_0pct']??'')==='issued'?'Đã phát hành':'Chưa phát hành'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Số HĐ (0%)</div>
                                <div class="info-value">
                                    <?php if ($invNo0Saved !== '' && $invNo0Saved !== '0'): ?>
                                        <strong class="fs-5"><?php echo htmlspecialchars($invNo0Saved); ?></strong>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-hourglass-split"></i> Chờ CQT cấp số
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Serial (0%)</div>
                                <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_serial_0pct']??'—'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Mẫu số (0%)</div>
                                <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_form_0pct']??'—'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">MTC (0%)</div>
                                <div class="info-value">
                                    <?php if (!empty($shipment['vat_invoice_mtc_0pct'])): ?>
                                        <code class="text-success"><?php echo htmlspecialchars($shipment['vat_invoice_mtc_0pct']); ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">GUID (0%)</div>
                                <div class="info-value">
                                    <code class="small"><?php echo htmlspecialchars($shipment['vat_invoice_guid_0pct']??'—'); ?></code>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <form method="POST" id="refreshForm">
                                    <input type="hidden" name="action" value="refresh">
                                    <button type="submit" class="btn btn-outline-info btn-sm">
                                        <i class="bi bi-arrow-clockwise"></i> Làm mới từ eHoaDon
                                    </button>
                                </form>
                            </div>

                            <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-receipt" style="font-size:3rem;opacity:.3;"></i>
                                <p class="mt-2">Chưa có hóa đơn nào được phát hành cho lô này.</p>
                                <button class="btn btn-primary"
                                        onclick="document.querySelector('[data-bs-target=\'#tabIssue\']').click()">
                                    <i class="bi bi-send-fill"></i> Phát hành ngay
                                </button>
                            </div>
                            <?php endif; ?>
                        </div><!-- /tabInfo -->

                        <!-- ============================================================ -->
                        <!-- TAB TẢI VỀ                                                   -->
                        <!-- ============================================================ -->
                        <div class="tab-pane fade <?php echo $activeTab==='download'?'show active':''; ?>" id="tabDownload">
                            <?php if ($hasValidGuid): ?>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-info-circle text-info"></i>
                                Tải bản PDF/XML từ hệ thống eHoaDon.
                                Số HĐ: <strong><?php echo htmlspecialchars($invNoSaved ?: 'Chờ CQT cấp'); ?></strong>
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="download_invoice.php?id=<?php echo $id; ?>&type=pdf"
                                   class="btn btn-danger" target="_blank">
                                    <i class="bi bi-file-earmark-pdf-fill"></i> Tải PDF (HĐ VAT%)
                                </a>
                                <a href="download_invoice.php?id=<?php echo $id; ?>&type=xml"
                                   class="btn btn-warning" target="_blank">
                                    <i class="bi bi-file-earmark-code-fill"></i> Tải XML (HĐ VAT%)
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasValid0PctGuid): ?>
                            <p class="text-muted small mb-3 mt-3">
                                <i class="bi bi-info-circle text-info"></i>
                                HĐ VAT 0% — Số HĐ: <strong><?php echo htmlspecialchars($invNo0Saved ?: 'Chờ CQT cấp'); ?></strong>
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="download_invoice.php?id=<?php echo $id; ?>&type=pdf&group=vat0"
                                   class="btn btn-outline-danger" target="_blank">
                                    <i class="bi bi-file-earmark-pdf-fill"></i> Tải PDF (HĐ 0%)
                                </a>
                                <a href="download_invoice.php?id=<?php echo $id; ?>&type=xml&group=vat0"
                                   class="btn btn-outline-warning" target="_blank">
                                    <i class="bi bi-file-earmark-code-fill"></i> Tải XML (HĐ 0%)
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if (!$hasValidGuid && !$hasValid0PctGuid): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-download" style="font-size:3rem;opacity:.3;"></i>
                                <p class="mt-2">Chưa có hóa đơn. Vui lòng phát hành trước.</p>
                            </div>
                            <?php endif; ?>
                        </div><!-- /tabDownload -->

                        <!-- ============================================================ -->
                        <!-- TAB HỦY HĐ                                                   -->
                        <!-- ============================================================ -->
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <div class="tab-pane fade <?php echo $activeTab==='cancel'?'show active':''; ?>" id="tabCancel">
                            <?php if ($vatStatus === 'cancelled'): ?>
                            <div class="alert alert-danger py-2">
                                <i class="bi bi-x-circle-fill"></i>
                                Hóa đơn này <strong>đã bị hủy</strong>
                                <?php if (!empty($shipment['vat_cancelled_at'])): ?>
                                vào ngày <strong><?php echo date('d/m/Y H:i', strtotime($shipment['vat_cancelled_at'])); ?></strong>
                                <?php endif; ?>.
                                <br>Lý do: <?php echo htmlspecialchars($shipment['vat_cancel_reason']??'—'); ?>
                            </div>
                            <?php elseif (!$hasValidGuid): ?>
                            <div class="alert alert-warning py-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Chưa có hóa đơn để hủy.
                            </div>
                            <?php else: ?>
                            <div class="alert alert-danger py-2 mb-3">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Cảnh báo:</strong> Hủy hóa đơn là thao tác <strong>KHÔNG THỂ HOÀN TÁC</strong>.
                                Hóa đơn sẽ bị hủy trên hệ thống CQT.
                            </div>
                            <form method="POST" onsubmit="return confirm('<?php echo $confirmCancel; ?>')">
                                <input type="hidden" name="action" value="cancel">
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-danger">
                                        Lý do hủy <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="cancel_reason" class="form-control border-danger"
                                           placeholder="VD: Sai thông tin khách hàng..." required>
                                </div>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-x-circle-fill"></i> Xác nhận hủy hóa đơn
                                </button>
                            </form>
                            <?php endif; ?>
                        </div><!-- /tabCancel -->
                        <?php endif; ?>

                    </div><!-- /tab-content -->
                </div><!-- /card-body -->
            </div><!-- /card -->
        </div><!-- /col-lg-8 -->

        <!-- RIGHT: SUMMARY CARD -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-dark text-white py-2">
                    <i class="bi bi-calculator"></i> Tóm tắt
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">Khách hàng</div>
                        <div class="info-value fw-bold"><?php echo htmlspecialchars($shipment['company_name']??'—'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">MST</div>
                        <div class="info-value">
                            <?php if (!empty($shipment['customer_tax'])): ?>
                                <code><?php echo htmlspecialchars($shipment['customer_tax']); ?></code>
                            <?php else: ?>
                                <span class="text-danger fw-bold">⚠ Chưa có MST</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Địa chỉ</div>
                        <div class="info-value small"><?php echo htmlspecialchars($shipment['customer_address']??'—'); ?></div>
                    </div>
                    <hr>
                    <div class="info-row">
                        <div class="info-label">Job No</div>
                        <div class="info-value fw-bold text-primary"><?php echo htmlspecialchars($shipment['job_no']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">HAWB</div>
                        <div class="info-value"><?php echo htmlspecialchars($shipment['hawb']??'—'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Số tờ khai</div>
                        <div class="info-value"><?php echo htmlspecialchars($shipment['customs_declaration_no']??'—'); ?></div>
                    </div>
                    <hr>
                    <?php if (!empty($sells_vat_8pct)): ?>
                    <div class="info-row">
                        <div class="info-label">HĐ 1 (có VAT%)</div>
                        <div class="info-value fw-bold text-danger"><?php echo number_format($preview8Total,0,',','.'); ?> VND</div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($sells_vat_0pct)): ?>
                    <div class="info-row">
                        <div class="info-label">HĐ 2 (VAT 0%)</div>
                        <div class="info-value fw-bold text-info"><?php echo number_format($preview0Total,0,',','.'); ?> VND</div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Tổng trước VAT</div>
                        <div class="info-value"><?php echo number_format($previewExcl,0,',','.'); ?> VND</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tổng VAT</div>
                        <div class="info-value text-warning fw-bold"><?php echo number_format($previewVat,0,',','.'); ?> VND</div>
                    </div>
                    <div class="bg-success text-white rounded p-2 mt-2 text-center">
                        <div class="small">TỔNG THANH TOÁN (có VAT)</div>
                        <div class="amount-display"><?php echo number_format($previewTotal,0,',','.'); ?> VND</div>
                    </div>
                    <?php if ($totalPob > 0): ?>
                    <div class="bg-warning rounded p-2 mt-2 text-center">
                        <div class="small text-dark">Chi hộ (không xuất HĐ)</div>
                        <div class="fw-bold text-dark"><?php echo number_format($totalPob,0,',','.'); ?> VND</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Thông tin HĐ đã phát hành -->
            <?php if ($hasValidGuid): ?>
            <div class="card shadow-sm border-success mb-3">
                <div class="card-header bg-success text-white py-2">
                    <i class="bi bi-check-circle-fill"></i> HĐ VAT 1 đã phát hành
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">Số HĐ</div>
                        <div class="info-value fw-bold fs-5">
                            <?php echo $invNoSaved !== '' && $invNoSaved !== '0'
                                ? htmlspecialchars($invNoSaved)
                                : '<span class="badge bg-warning text-dark">Chờ CQT</span>'; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Serial</div>
                        <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_serial']??'—'); ?></div>
                    </div>
                    <?php if (!empty($shipment['vat_invoice_mtc'])): ?>
                    <div class="info-row">
                        <div class="info-label">MTC</div>
                        <div class="info-value"><code class="text-success"><?php echo htmlspecialchars($shipment['vat_invoice_mtc']); ?></code></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Phát hành lúc</div>
                        <div class="info-value small">
                            <?php echo !empty($shipment['vat_issued_at'])
                                ? date('d/m/Y H:i', strtotime($shipment['vat_issued_at']))
                                : '—'; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <a href="download_invoice.php?id=<?php echo $id; ?>&type=pdf"
                           class="btn btn-danger btn-sm" target="_blank">
                            <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                        </a>
                        <a href="download_invoice.php?id=<?php echo $id; ?>&type=xml"
                           class="btn btn-warning btn-sm" target="_blank">
                            <i class="bi bi-file-earmark-code-fill"></i> XML
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── [NEW] Card HĐ 0% đã phát hành ── -->
            <?php if ($hasValid0PctGuid): ?>
            <div class="card shadow-sm border-info">
                <div class="card-header bg-info text-white py-2">
                    <i class="bi bi-check-circle-fill"></i> HĐ VAT 2 (0%) đã phát hành
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">Số HĐ (0%)</div>
                        <div class="info-value fw-bold fs-5">
                            <?php echo $invNo0Saved !== '' && $invNo0Saved !== '0'
                                ? htmlspecialchars($invNo0Saved)
                                : '<span class="badge bg-warning text-dark">Chờ CQT</span>'; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Serial (0%)</div>
                        <div class="info-value"><?php echo htmlspecialchars($shipment['vat_invoice_serial_0pct']??'—'); ?></div>
                    </div>
                    <?php if (!empty($shipment['vat_invoice_mtc_0pct'])): ?>
                    <div class="info-row">
                        <div class="info-label">MTC (0%)</div>
                        <div class="info-value"><code class="text-success"><?php echo htmlspecialchars($shipment['vat_invoice_mtc_0pct']); ?></code></div>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <a href="download_invoice.php?id=<?php echo $id; ?>&type=pdf&group=vat0"
                           class="btn btn-outline-danger btn-sm" target="_blank">
                            <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                        </a>
                        <a href="download_invoice.php?id=<?php echo $id; ?>&type=xml&group=vat0"
                           class="btn btn-outline-warning btn-sm" target="_blank">
                            <i class="bi bi-file-earmark-code-fill"></i> XML
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /col-lg-4 -->

    </div><!-- /row -->
</div><!-- /container -->

<footer class="bg-white text-center py-2 border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const activeTabId = '<?php echo $activeTab; ?>';
    const tabBtn = document.querySelector('[data-bs-target="#tab' + activeTabId.charAt(0).toUpperCase() + activeTabId.slice(1) + '"]');
    if (tabBtn) {
        const tab = new bootstrap.Tab(tabBtn);
        tab.show();
    }
});
</script>
</body>
</html>