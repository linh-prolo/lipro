<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
checkLogin();

$conn = getDBConnection();

// ============================================================
// XỬ LÝ POST - Cập nhật thanh toán
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sid    = intval($_POST['shipment_id'] ?? 0);

    if ($sid > 0) {
        if ($action === 'update_customer') {
            $paid_amount = floatval($_POST['customer_paid_amount'] ?? 0);
            $paid_at     = trim($_POST['customer_paid_at']     ?? '');
            $paid_note   = trim($_POST['customer_paid_note']   ?? '');
            $stmt = $conn->prepare("UPDATE shipments SET customer_paid_amount=?, customer_paid_at=?, customer_paid_note=? WHERE id=?");
            $paid_at_val = $paid_at ?: null;
            $stmt->bind_param("dssi", $paid_amount, $paid_at_val, $paid_note, $sid);
            $stmt->execute(); $stmt->close();
        } elseif ($action === 'update_supplier') {
            $paid_amount = floatval($_POST['supplier_paid_amount'] ?? 0);
            $paid_at     = trim($_POST['supplier_paid_at']     ?? '');
            $paid_note   = trim($_POST['supplier_paid_note']   ?? '');
            $stmt = $conn->prepare("UPDATE shipments SET supplier_paid_amount=?, supplier_paid_at=?, supplier_paid_note=? WHERE id=?");
            $paid_at_val = $paid_at ?: null;
            $stmt->bind_param("dssi", $paid_amount, $paid_at_val, $paid_note, $sid);
            $stmt->execute(); $stmt->close();
        }
    }
    header("Location: debt.php?" . http_build_query(array_filter([
        'search'       => $_POST['search']       ?? '',
        'search_email' => $_POST['search_email'] ?? '',
        'status_kh'    => $_POST['status_kh']    ?? '',
        'status_ncc'   => $_POST['status_ncc']   ?? '',
        'month'        => $_POST['month']        ?? '',
        'customer_id'  => $_POST['customer_id']  ?? '',
        'is_locked'    => $_POST['is_locked']    ?? '',
        'view'         => $_POST['view']         ?? '',
    ])));
    exit();
}

// ============================================================
// FILTER
// ============================================================
$search       = trim($_GET['search']       ?? '');
$search_email = trim($_GET['search_email'] ?? '');
$status_kh    = trim($_GET['status_kh']    ?? '');
$status_ncc   = trim($_GET['status_ncc']   ?? '');
$month        = trim($_GET['month']        ?? '');
$customer_id  = trim($_GET['customer_id']  ?? '');
$is_locked    = trim($_GET['is_locked']    ?? 'yes');
$view         = trim($_GET['view']         ?? 'shipment');
$export_soa   = isset($_GET['export_soa']) && $_GET['export_soa'] === '1';
$soa_customer = intval($_GET['soa_customer'] ?? 0);
$export_excel = isset($_GET['export_excel']) && $_GET['export_excel'] === '1';

$where  = ["s.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(s.job_no LIKE ? OR s.hawb LIKE ? OR s.mawb LIKE ? OR c.company_name LIKE ? OR s.customs_declaration_no LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}
if ($search_email !== '') {
    $like_email = '%' . $search_email . '%';
    $where[]    = 'c.email LIKE ?';
    $params[]   = $like_email;
    $types     .= 's';
}
if ($month !== '') {
    $where[]  = 'DATE_FORMAT(s.arrival_date, "%Y-%m") = ?';
    $params[] = $month;
    $types   .= 's';
}
if ($customer_id !== '' && intval($customer_id) > 0) {
    $where[]  = 's.customer_id = ?';
    $params[] = intval($customer_id);
    $types   .= 'i';
}
if ($is_locked !== '') {
    $where[]  = 's.is_locked = ?';
    $params[] = $is_locked;
    $types   .= 's';
}

$sql = "SELECT s.id, s.job_no, s.hawb, s.mawb, s.customs_declaration_no,
    s.arrival_date, s.status, s.is_locked,
    s.invoice_no, s.invoice_date,
    c.id AS cust_id, c.company_name, c.short_name, c.email AS cust_email,
    COALESCE((SELECT SUM(sc.total_amount) FROM shipment_costs sc WHERE sc.shipment_id = s.id), 0) AS total_cost,
    COALESCE((SELECT SUM(ss.total_amount) FROM shipment_sells ss WHERE ss.shipment_id = s.id), 0) AS total_sell,
    COALESCE(s.customer_paid_amount, 0) AS customer_paid_amount,
    s.customer_paid_at,
    s.customer_paid_note,
    COALESCE(s.supplier_paid_amount, 0) AS supplier_paid_amount,
    s.supplier_paid_at,
    s.supplier_paid_note
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.arrival_date DESC, s.id DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Filter status sau khi lấy data
$data = [];
foreach ($rows as $row) {
    $sell       = floatval($row['total_sell']);
    $cost       = floatval($row['total_cost']);
    $kh_paid    = floatval($row['customer_paid_amount']);
    $ncc_paid   = floatval($row['supplier_paid_amount']);
    $kh_remain  = $sell - $kh_paid;
    $ncc_remain = $cost - $ncc_paid;

    if ($status_kh === 'paid'    && $kh_paid  < $sell)  continue;
    if ($status_kh === 'unpaid'  && $kh_paid  >= $sell) continue;
    if ($status_kh === 'partial' && ($kh_paid <= 0 || $kh_paid >= $sell)) continue;

    if ($status_ncc === 'paid'    && $ncc_paid  < $cost)  continue;
    if ($status_ncc === 'unpaid'  && $ncc_paid  >= $cost) continue;
    if ($status_ncc === 'partial' && ($ncc_paid <= 0 || $ncc_paid >= $cost)) continue;

    $row['total_sell']  = $sell;
    $row['total_cost']  = $cost;
    $row['kh_remain']   = $kh_remain;
    $row['ncc_remain']  = $ncc_remain;
    $data[] = $row;
}

// ============================================================
// XUẤT EXCEL (CSV)
// ============================================================
if ($export_excel) {
    $filename = 'cong_no_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 để Excel đọc đúng tiếng Việt
    fputs($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, [
        'STT', 'Job No', 'Khách hàng', 'Email KH', 'HAWB', 'MAWB', 'Tờ khai',
        'Số HĐ', 'Ngày HĐ', 'Ngày đến', 'Khoá',
        'Total Cost', 'Total Sell', 'Lợi nhuận',
        'KH đã trả', 'Ngày trả KH', 'KH còn nợ', 'TT KH',
        'NCC đã trả', 'NCC còn nợ', 'TT NCC',
        'Ghi chú KH', 'Ghi chú NCC',
    ]);

    $stt = 1;
    foreach ($data as $row) {
        $profit = $row['total_sell'] - $row['total_cost'];

        // Trạng thái KH
        if ($row['total_sell'] <= 0)                                      $tt_kh = 'N/A';
        elseif ($row['customer_paid_amount'] >= $row['total_sell'])       $tt_kh = 'Đã thu';
        elseif ($row['customer_paid_amount'] > 0)                         $tt_kh = 'Một phần';
        else                                                              $tt_kh = 'Chưa thu';

        // Trạng thái NCC
        if ($row['total_cost'] <= 0)                                      $tt_ncc = 'N/A';
        elseif ($row['supplier_paid_amount'] >= $row['total_cost'])       $tt_ncc = 'Đã trả';
        elseif ($row['supplier_paid_amount'] > 0)                         $tt_ncc = 'Một phần';
        else                                                              $tt_ncc = 'Chưa trả';

        fputcsv($out, [
            $stt++,
            $row['job_no'],
            $row['company_name'] ?? '',
            $row['cust_email']   ?? '',
            $row['hawb']         ?? '',
            $row['mawb']         ?? '',
            $row['customs_declaration_no'] ?? '',
            $row['invoice_no']   ?? '',
            ($row['invoice_date'] && $row['invoice_date'] !== '0000-00-00')
                ? date('d/m/Y', strtotime($row['invoice_date'])) : '',
            ($row['arrival_date'] && $row['arrival_date'] !== '0000-00-00')
                ? date('d/m/Y', strtotime($row['arrival_date'])) : '',
            $row['is_locked'] === 'yes' ? 'Đã khoá' : 'Chưa khoá',
            number_format($row['total_cost'], 2, '.', ''),
            number_format($row['total_sell'], 2, '.', ''),
            number_format($profit, 2, '.', ''),
            number_format($row['customer_paid_amount'], 2, '.', ''),
            ($row['customer_paid_at'] && $row['customer_paid_at'] !== '0000-00-00')
                ? date('d/m/Y', strtotime($row['customer_paid_at'])) : '',
            number_format($row['kh_remain'], 2, '.', ''),
            $tt_kh,
            number_format($row['supplier_paid_amount'], 2, '.', ''),
            number_format($row['ncc_remain'], 2, '.', ''),
            $tt_ncc,
            $row['customer_paid_note'] ?? '',
            $row['supplier_paid_note'] ?? '',
        ]);
    }

    // Dòng tổng cộng
    $sum_sell       = array_sum(array_column($data, 'total_sell'));
    $sum_cost       = array_sum(array_column($data, 'total_cost'));
    $sum_kh_paid    = array_sum(array_column($data, 'customer_paid_amount'));
    $sum_kh_remain  = array_sum(array_column($data, 'kh_remain'));
    $sum_ncc_paid   = array_sum(array_column($data, 'supplier_paid_amount'));
    $sum_ncc_remain = array_sum(array_column($data, 'ncc_remain'));
    $sum_profit     = $sum_sell - $sum_cost;

    fputcsv($out, [
        '', 'TỔNG CỘNG', '', '', '', '', '', '', '', '', '',
        number_format($sum_cost, 2, '.', ''),
        number_format($sum_sell, 2, '.', ''),
        number_format($sum_profit, 2, '.', ''),
        number_format($sum_kh_paid, 2, '.', ''),
        '',
        number_format($sum_kh_remain, 2, '.', ''),
        '',
        number_format($sum_ncc_paid, 2, '.', ''),
        number_format($sum_ncc_remain, 2, '.', ''),
        '', '', '',
    ]);

    fclose($out);
    exit();
}

// Tổng hợp toàn bộ
$sum_sell       = array_sum(array_column($data, 'total_sell'));
$sum_cost       = array_sum(array_column($data, 'total_cost'));
$sum_kh_paid    = array_sum(array_column($data, 'customer_paid_amount'));
$sum_kh_remain  = array_sum(array_column($data, 'kh_remain'));
$sum_ncc_paid   = array_sum(array_column($data, 'supplier_paid_amount'));
$sum_ncc_remain = array_sum(array_column($data, 'ncc_remain'));
$sum_profit     = $sum_sell - $sum_cost;

// Nhóm theo khách hàng
$customer_groups = [];
foreach ($data as $row) {
    $cid = $row['cust_id'] ?? 0;
    if (!isset($customer_groups[$cid])) {
        $customer_groups[$cid] = [
            'cust_id'      => $cid,
            'company_name' => $row['company_name'] ?? '(Không rõ)',
            'short_name'   => $row['short_name'] ?? '',
            'cust_email'   => $row['cust_email'] ?? '',
            'shipments'    => [],
            'total_sell'   => 0, 'total_cost'   => 0,
            'kh_paid'      => 0, 'kh_remain'    => 0,
            'ncc_paid'     => 0, 'ncc_remain'   => 0,
            'count_paid'   => 0, 'count_unpaid' => 0, 'count_partial' => 0,
        ];
    }
    $g = &$customer_groups[$cid];
    $g['shipments'][]  = $row;
    $g['total_sell']  += $row['total_sell'];
    $g['total_cost']  += $row['total_cost'];
    $g['kh_paid']     += floatval($row['customer_paid_amount']);
    $g['kh_remain']   += $row['kh_remain'];
    $g['ncc_paid']    += floatval($row['supplier_paid_amount']);
    $g['ncc_remain']  += $row['ncc_remain'];
    if ($row['kh_remain'] <= 0 && $row['total_sell'] > 0)  $g['count_paid']++;
    elseif (floatval($row['customer_paid_amount']) > 0)     $g['count_partial']++;
    else                                                    $g['count_unpaid']++;
    unset($g);
}

// Lấy danh sách khách hàng cho dropdown
$cust_list = $conn->query("SELECT id, company_name, short_name FROM customers WHERE status='active' ORDER BY company_name ASC")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// XUẤT SOA
// ============================================================
if ($export_soa && $soa_customer > 0) {
    $soa_data = isset($customer_groups[$soa_customer]) ? $customer_groups[$soa_customer] : null;
    if ($soa_data) {
        header('Content-Type: text/html; charset=utf-8');
        $now = date('d/m/Y');
        echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
        <title>SOA - ' . htmlspecialchars($soa_data['company_name']) . '</title>
        <style>
            body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}
            h2{color:#003366;}
            table{width:100%;border-collapse:collapse;margin-top:10px;}
            th,td{border:1px solid #ccc;padding:5px 7px;font-size:11px;}
            th{background:#003366;color:#fff;text-align:center;}
            .num{text-align:right;}
            .total-row{background:#f0f0f0;font-weight:bold;}
            .unpaid{color:#dc3545;}
            .paid{color:#198754;}
            @media print{.no-print{display:none;}}
        </style></head><body>
        <div class="no-print" style="margin-bottom:15px;">
            <button onclick="window.print()" style="padding:6px 16px;background:#003366;color:#fff;border:none;border-radius:4px;cursor:pointer;">🖨️ In / Xuất PDF</button>
            <button onclick="window.close()" style="padding:6px 16px;margin-left:8px;background:#888;color:#fff;border:none;border-radius:4px;cursor:pointer;">✕ Đóng</button>
        </div>
        <table style="border:none;margin-bottom:10px;"><tr>
            <td style="border:none;width:50%;">
                <strong>LIPRO FORWARDER</strong><br>
                Ngày xuất: ' . $now . '
            </td>
            <td style="border:none;text-align:right;">
                <h2 style="margin:0;">STATEMENT OF ACCOUNT</h2>
            </td>
        </tr></table>
        <p><strong>Khách hàng:</strong> ' . htmlspecialchars($soa_data['company_name']) . '</p>';
        if (!empty($soa_data['cust_email'])) {
            echo '<p><strong>Email:</strong> ' . htmlspecialchars($soa_data['cust_email']) . '</p>';
        }
        echo '<table>
            <thead><tr>
                <th>#</th><th>Job No</th><th>HAWB</th><th>Tờ khai</th>
                <th>Ngày đến</th><th>Số HĐ</th><th>Ngày HĐ</th>
                <th class="num">Tổng Sell</th>
                <th class="num">Đã thanh toán</th>
                <th class="num">Còn nợ</th>
                <th>Trạng thái</th>
            </tr></thead><tbody>';
        $i = 1;
        foreach ($soa_data['shipments'] as $r) {
            $remain = $r['kh_remain'];
            $status_txt = $remain <= 0
                ? '<span class="paid">Đã thanh toán</span>'
                : ($r['customer_paid_amount'] > 0
                    ? '<span style="color:orange;">Một phần</span>'
                    : '<span class="unpaid">Chưa thanh toán</span>');
            echo '<tr>
                <td style="text-align:center;">' . $i++ . '</td>
                <td>' . htmlspecialchars($r['job_no']) . '</td>
                <td>' . htmlspecialchars($r['hawb'] ?? '') . '</td>
                <td>' . htmlspecialchars($r['customs_declaration_no'] ?? '') . '</td>
                <td style="text-align:center;">' . ($r['arrival_date'] && $r['arrival_date'] !== '0000-00-00' ? date('d/m/Y', strtotime($r['arrival_date'])) : '—') . '</td>
                <td>' . htmlspecialchars($r['invoice_no'] ?? '') . '</td>
                <td style="text-align:center;">' . ($r['invoice_date'] && $r['invoice_date'] !== '0000-00-00' ? date('d/m/Y', strtotime($r['invoice_date'])) : '—') . '</td>
                <td class="num">' . number_format($r['total_sell'], 2, ',', '.') . '</td>
                <td class="num paid">' . number_format(floatval($r['customer_paid_amount']), 2, ',', '.') . '</td>
                <td class="num ' . ($remain > 0 ? 'unpaid' : 'paid') . '">' . number_format($remain, 2, ',', '.') . '</td>
                <td style="text-align:center;">' . $status_txt . '</td>
            </tr>';
        }
        echo '<tr class="total-row">
            <td colspan="7" style="text-align:right;">TỔNG CỘNG:</td>
            <td class="num">' . number_format($soa_data['total_sell'], 2, ',', '.') . '</td>
            <td class="num paid">' . number_format($soa_data['kh_paid'], 2, ',', '.') . '</td>
            <td class="num unpaid">' . number_format($soa_data['kh_remain'], 2, ',', '.') . '</td>
            <td></td>
        </tr></tbody></table>
        <p style="margin-top:20px;font-size:11px;color:#666;">
            Vui lòng thanh toán số tiền còn lại. Nếu có thắc mắc, xin liên hệ với chúng tôi.<br>
            Xin cảm ơn!
        </p>
        </body></html>';
        exit();
    }
}

$conn->close();

// Helpers
function fmt($n) { return number_format(floatval($n), 2, ',', '.'); }
function statusKH($paid, $sell) {
    if ($sell <= 0)     return ['bg-secondary', 'N/A'];
    if ($paid >= $sell) return ['bg-success',   'Đã thu'];
    if ($paid > 0)      return ['bg-warning text-dark', 'Một phần'];
    return                     ['bg-danger',    'Chưa thu'];
}
function statusNCC($paid, $cost) {
    if ($cost <= 0)     return ['bg-secondary', 'N/A'];
    if ($paid >= $cost) return ['bg-success',   'Đã trả'];
    if ($paid > 0)      return ['bg-warning text-dark', 'Một phần'];
    return                     ['bg-danger',    'Chưa trả'];
}

// URL xuất Excel giữ nguyên filter hiện tại
$excel_url = 'debt.php?' . http_build_query(array_merge(
    array_filter([
        'search'       => $search,
        'search_email' => $search_email,
        'status_kh'    => $status_kh,
        'status_ncc'   => $status_ncc,
        'month'        => $month,
        'customer_id'  => $customer_id,
        'is_locked'    => $is_locked,
        'view'         => $view,
    ]),
    ['export_excel' => '1']
));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Công Nợ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-size: .875rem; }
        .table th { font-size: .76rem; white-space: nowrap; }
        .table td { font-size: .79rem; vertical-align: middle; }
        .sticky-col { position: sticky; left: 0; background: #fff; z-index: 1; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .profit-pos { color: #198754; font-weight: 700; }
        .profit-neg { color: #dc3545; font-weight: 700; }
        .summary-card { border-left: 4px solid; }
        tfoot td { font-weight: 700; background: #f8f9fa; }
        .customer-header { background: #e8f0fe; cursor: pointer; user-select: none; }
        .customer-header:hover { background: #d0e1fd; }
        .badge-count { font-size: .7rem; }
    </style>
</head>
<body class="bg-light">

<!-- NAVBAR -->
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
                <li class="nav-item"><a class="nav-link" href="../shipments/index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="debt.php">Công Nợ</a></li>
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

        <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-cash-coin text-success"></i> Quản lý Công Nợ
            <span class="badge bg-secondary ms-1"><?php echo count($data); ?> lô</span>
        </h4>

        <?php
        // URL giữ filter
        $filter_params = array_filter([
            'search'       => $search,
            'search_email' => $search_email,
            'status_kh'    => $status_kh,
            'status_ncc'   => $status_ncc,
            'month'        => $month,
            'customer_id'  => $customer_id,
            'is_locked'    => $is_locked,
            'view'         => $view,
        ]);

        $excel_url  = 'debt.php?'                . http_build_query(array_merge($filter_params, ['export_excel' => '1']));
        $report_url = 'export_debt_report.php?' . http_build_query($filter_params);
        ?>

        <div class="d-flex gap-2">
            <!-- Nút xuất CSV -->
            <a href="<?php echo htmlspecialchars($excel_url); ?>"
               class="btn btn-success btn-sm"
               title="Xuất danh sách đang lọc ra file CSV">
                <i class="bi bi-file-earmark-excel-fill"></i> Xuất Excel
                <span class="badge bg-light text-success ms-1"><?php echo count($data); ?></span>
            </a>

            <!-- Nút xuất XLSX Report đẹp -->
            <a href="<?php echo htmlspecialchars($report_url); ?>"
               class="btn btn-primary btn-sm"
               title="Xuất báo cáo công nợ định dạng đẹp (.xlsx)">
                <i class="bi bi-file-earmark-bar-graph-fill"></i> Xuất Report
                <span class="badge bg-light text-primary ms-1"><?php echo count($data); ?></span>
            </a>

            <!-- View Toggle -->
            <div class="btn-group btn-group-sm">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'shipment'])); ?>"
                   class="btn <?php echo $view === 'shipment' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                    <i class="bi bi-list-ul"></i> Theo Lô
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'customer'])); ?>"
                   class="btn <?php echo $view === 'customer' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                    <i class="bi bi-people-fill"></i> Theo KH
                </a>
            </div>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#0d6efd;">
                <div class="small text-muted">Tổng Sell</div>
                <div class="fw-bold text-primary"><?php echo fmt($sum_sell); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#dc3545;">
                <div class="small text-muted">Tổng Cost</div>
                <div class="fw-bold text-danger"><?php echo fmt($sum_cost); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#198754;">
                <div class="small text-muted">Lợi nhuận</div>
                <div class="fw-bold <?php echo $sum_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo fmt($sum_profit); ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#ffc107;">
                <div class="small text-muted">KH còn nợ</div>
                <div class="fw-bold text-warning"><?php echo fmt($sum_kh_remain); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#fd7e14;">
                <div class="small text-muted">NCC còn nợ</div>
                <div class="fw-bold" style="color:#fd7e14;"><?php echo fmt($sum_ncc_remain); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#6f42c1;">
                <div class="small text-muted">Đã thu KH</div>
                <div class="fw-bold" style="color:#6f42c1;"><?php echo fmt($sum_kh_paid); ?></div>
            </div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end" id="filterForm">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="🔍 Job No, HAWB, Khách hàng..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="search_email" class="form-control form-control-sm"
                           placeholder="✉️ Email công ty..."
                           value="<?php echo htmlspecialchars($search_email); ?>">
                </div>
                <div class="col-md-2">
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="">— Tất cả khách hàng —</option>
                        <?php foreach ($cust_list as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $customer_id == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['short_name'] ?: $c['company_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <input type="month" name="month" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($month); ?>" title="Lọc theo tháng arrival">
                </div>
                <div class="col-md-1">
                    <select name="status_kh" class="form-select form-select-sm">
                        <option value="">— Công nợ KH —</option>
                        <option value="unpaid"  <?php echo $status_kh === 'unpaid'  ? 'selected' : ''; ?>>Chưa thu</option>
                        <option value="partial" <?php echo $status_kh === 'partial' ? 'selected' : ''; ?>>Một phần</option>
                        <option value="paid"    <?php echo $status_kh === 'paid'    ? 'selected' : ''; ?>>Đã thu</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="status_ncc" class="form-select form-select-sm">
                        <option value="">— Công nợ NCC —</option>
                        <option value="unpaid"  <?php echo $status_ncc === 'unpaid'  ? 'selected' : ''; ?>>Chưa trả</option>
                        <option value="partial" <?php echo $status_ncc === 'partial' ? 'selected' : ''; ?>>Một phần</option>
                        <option value="paid"    <?php echo $status_ncc === 'paid'    ? 'selected' : ''; ?>>Đã trả</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="is_locked" class="form-select form-select-sm">
                        <option value="">— Khoá —</option>
                        <option value="yes" <?php echo $is_locked === 'yes' ? 'selected' : ''; ?>>Đã khoá</option>
                        <option value="no"  <?php echo $is_locked === 'no'  ? 'selected' : ''; ?>>Chưa khoá</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel"></i> Lọc
                    </button>
                    <a href="debt.php?view=<?php echo $view; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Xóa lọc
                    </a>
                    <?php
                    $hide_paid_url = array_merge($_GET, ['status_kh' => ($status_kh === '' ? 'unpaid' : ''), 'view' => $view]);
                    $hiding_paid   = ($status_kh === 'unpaid');
                    ?>
                    <a href="debt.php?<?php echo http_build_query($hide_paid_url); ?>"
                       class="btn btn-sm <?php echo $hiding_paid ? 'btn-warning' : 'btn-outline-warning'; ?>"
                       title="<?php echo $hiding_paid ? 'Đang ẩn lô đã thu — Nhấn để hiện tất cả' : 'Ẩn lô đã thu KH'; ?>">
                        <i class="bi bi-eye-slash"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($view === 'customer'): ?>
    <!-- ============================================================ -->
    <!-- VIEW: THEO KHÁCH HÀNG -->
    <!-- ============================================================ -->
    <?php foreach ($customer_groups as $cid => $grp): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header customer-header d-flex justify-content-between align-items-center py-2"
             data-bs-toggle="collapse" data-bs-target="#cust_<?php echo $cid; ?>">
            <div>
                <strong><?php echo htmlspecialchars($grp['company_name']); ?></strong>
                <?php if ($grp['short_name']): ?>
                    <span class="badge bg-secondary badge-count ms-1"><?php echo htmlspecialchars($grp['short_name']); ?></span>
                <?php endif; ?>
                <?php if ($grp['cust_email']): ?>
                    <span class="text-muted small ms-2"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($grp['cust_email']); ?></span>
                <?php endif; ?>
                <span class="badge bg-primary badge-count ms-2"><?php echo count($grp['shipments']); ?> lô</span>
                <?php if ($grp['count_paid'] > 0): ?>
                    <span class="badge bg-success badge-count"><?php echo $grp['count_paid']; ?> đã thu</span>
                <?php endif; ?>
                <?php if ($grp['count_partial'] > 0): ?>
                    <span class="badge bg-warning text-dark badge-count"><?php echo $grp['count_partial']; ?> 1 phần</span>
                <?php endif; ?>
                <?php if ($grp['count_unpaid'] > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $grp['count_unpaid']; ?> chưa thu</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <span class="small text-muted">
                    Sell: <strong class="text-primary"><?php echo fmt($grp['total_sell']); ?></strong> |
                    Còn nợ: <strong class="<?php echo $grp['kh_remain'] > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo fmt($grp['kh_remain']); ?></strong>
                </span>
                <a href="debt.php?<?php echo http_build_query(array_merge($_GET, ['export_soa' => '1', 'soa_customer' => $cid])); ?>"
                   target="_blank" class="btn btn-sm btn-outline-info" title="Xuất SOA cho khách hàng này"
                   onclick="event.stopPropagation();">
                    <i class="bi bi-file-earmark-text"></i> SOA
                </a>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>
        <div class="collapse show" id="cust_<?php echo $cid; ?>">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="min-width:110px;">Job No</th>
                            <th style="min-width:90px;">HAWB</th>
                            <th style="min-width:90px;">Tờ Khai</th>
                            <th style="min-width:85px;">Ngày đến</th>
                            <th style="min-width:90px;">Số HĐ</th>
                            <th style="min-width:85px;">Ngày HĐ</th>
                            <th style="min-width:60px;">Khoá</th>
                            <th class="num" style="min-width:105px;">Sell</th>
                            <th class="num" style="min-width:105px;">KH Trả</th>
                            <th class="num" style="min-width:95px;">Còn Nợ</th>
                            <th style="min-width:80px;">T.Thái</th>
                            <th style="min-width:70px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($grp['shipments'] as $row):
                        $skh = statusKH($row['customer_paid_amount'], $row['total_sell']);
                    ?>
                        <tr>
                            <td>
                                <a href="../shipments/view.php?id=<?php echo $row['id']; ?>"
                                   class="fw-bold text-primary text-decoration-none">
                                    <?php echo htmlspecialchars($row['job_no']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($row['hawb'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['customs_declaration_no'] ?? '—'); ?></td>
                            <td>
                                <?php echo ($row['arrival_date'] && $row['arrival_date'] !== '0000-00-00')
                                    ? date('d/m/Y', strtotime($row['arrival_date'])) : '—'; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['invoice_no'] ?? '—'); ?></td>
                            <td>
                                <?php echo ($row['invoice_date'] && $row['invoice_date'] !== '0000-00-00')
                                    ? date('d/m/Y', strtotime($row['invoice_date'])) : '—'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $row['is_locked'] === 'yes'
                                    ? '<i class="bi bi-lock-fill text-danger" title="Đã khoá"></i>'
                                    : '<i class="bi bi-unlock text-secondary" title="Chưa khoá"></i>'; ?>
                            </td>
                            <td class="num text-primary"><?php echo fmt($row['total_sell']); ?></td>
                            <td class="num text-success fw-bold"><?php echo fmt($row['customer_paid_amount']); ?></td>
                            <td class="num <?php echo $row['kh_remain'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo fmt($row['kh_remain']); ?>
                            </td>
                            <td><span class="badge <?php echo $skh[0]; ?>"><?php echo $skh[1]; ?></span></td>
                            <td>
                                <button class="btn btn-outline-success btn-sm"
                                        onclick="openModal(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                        title="Cập nhật thanh toán">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="text-end">Tổng:</td>
                            <td class="num text-primary"><?php echo fmt($grp['total_sell']); ?></td>
                            <td class="num text-success"><?php echo fmt($grp['kh_paid']); ?></td>
                            <td class="num <?php echo $grp['kh_remain'] > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo fmt($grp['kh_remain']); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- ============================================================ -->
    <!-- VIEW: THEO LÔ HÀNG -->
    <!-- ============================================================ -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="sticky-col" style="min-width:110px;">Job No</th>
                            <th style="min-width:130px;">Khách hàng</th>
                            <th style="min-width:90px;">HAWB</th>
                            <th style="min-width:90px;">MAWB</th>
                            <th style="min-width:100px;">Tờ Khai</th>
                            <th style="min-width:85px;">Số HĐ</th>
                            <th style="min-width:85px;">Ngày HĐ</th>
                            <th style="min-width:60px;">Khoá</th>
                            <th class="num" style="min-width:105px;">Cost</th>
                            <th class="num" style="min-width:105px;">Sell</th>
                            <th class="num" style="min-width:90px;">Lợi Nhuận</th>
                            <th class="num text-warning" style="min-width:105px;">KH Trả</th>
                            <th style="min-width:90px;">Ngày Trả KH</th>
                            <th class="num text-danger" style="min-width:90px;">KH Còn Nợ</th>
                            <th style="min-width:80px;">TT KH</th>
                            <th class="num text-info" style="min-width:105px;">Đã Trả NCC</th>
                            <th class="num" style="min-width:90px;color:#fd7e14;">NCC Còn Nợ</th>
                            <th style="min-width:80px;">TT NCC</th>
                            <th style="min-width:70px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="19" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-4"></i><br>Không có dữ liệu
                            </td>
                        </tr>
                    <?php else: foreach ($data as $row):
                        $profit = $row['total_sell'] - $row['total_cost'];
                        $skh    = statusKH($row['customer_paid_amount'], $row['total_sell']);
                        $sncc   = statusNCC($row['supplier_paid_amount'], $row['total_cost']);
                    ?>
                        <tr>
                            <td class="sticky-col">
                                <a href="../shipments/view.php?id=<?php echo $row['id']; ?>"
                                   class="fw-bold text-primary text-decoration-none">
                                    <?php echo htmlspecialchars($row['job_no']); ?>
                                </a>
                            </td>
                            <td>
                                <a href="debt.php?<?php echo http_build_query(array_merge($_GET, ['customer_id' => $row['cust_id'], 'view' => 'customer'])); ?>"
                                   class="text-decoration-none text-dark" title="Xem theo KH">
                                    <?php echo htmlspecialchars($row['short_name'] ?: ($row['company_name'] ?? '')); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($row['hawb'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['mawb'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['customs_declaration_no'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['invoice_no'] ?? '—'); ?></td>
                            <td>
                                <?php echo ($row['invoice_date'] && $row['invoice_date'] !== '0000-00-00')
                                    ? date('d/m/Y', strtotime($row['invoice_date'])) : '—'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $row['is_locked'] === 'yes'
                                    ? '<i class="bi bi-lock-fill text-danger" title="Đã khoá"></i>'
                                    : '<i class="bi bi-unlock text-secondary" title="Chưa khoá"></i>'; ?>
                            </td>
                            <td class="num text-danger"><?php echo fmt($row['total_cost']); ?></td>
                            <td class="num text-primary"><?php echo fmt($row['total_sell']); ?></td>
                            <td class="num <?php echo $profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                                <?php echo fmt($profit); ?>
                            </td>
                            <td class="num text-success fw-bold"><?php echo fmt($row['customer_paid_amount']); ?></td>
                            <td>
                                <?php echo $row['customer_paid_at']
                                    ? date('d/m/Y', strtotime($row['customer_paid_at']))
                                    : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="num <?php echo $row['kh_remain'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo fmt($row['kh_remain']); ?>
                            </td>
                            <td><span class="badge <?php echo $skh[0]; ?>"><?php echo $skh[1]; ?></span></td>
                            <td class="num text-info fw-bold"><?php echo fmt($row['supplier_paid_amount']); ?></td>
                            <td class="num <?php echo $row['ncc_remain'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo fmt($row['ncc_remain']); ?>
                            </td>
                            <td><span class="badge <?php echo $sncc[0]; ?>"><?php echo $sncc[1]; ?></span></td>
                            <td>
                                <button class="btn btn-outline-success btn-sm"
                                        onclick="openModal(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                        title="Cập nhật thanh toán">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($data)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="8" class="text-end">TỔNG CỘNG:</td>
                            <td class="num text-danger"><?php echo fmt($sum_cost); ?></td>
                            <td class="num text-primary"><?php echo fmt($sum_sell); ?></td>
                            <td class="num <?php echo $sum_profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>"><?php echo fmt($sum_profit); ?></td>
                            <td class="num text-success"><?php echo fmt($sum_kh_paid); ?></td>
                            <td></td>
                            <td class="num text-danger"><?php echo fmt($sum_kh_remain); ?></td>
                            <td></td>
                            <td class="num text-info"><?php echo fmt($sum_ncc_paid); ?></td>
                            <td class="num text-danger"><?php echo fmt($sum_ncc_remain); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- MODAL CẬP NHẬT THANH TOÁN -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title">
                    <i class="bi bi-pencil-fill"></i>
                    Cập nhật thanh toán — <span id="modalJobNo" class="fw-bold"></span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <!-- KH -->
                    <div class="col-md-6">
                        <div class="card border-success h-100">
                            <div class="card-header bg-success text-white py-2">
                                <i class="bi bi-person-fill"></i> Thu tiền Khách hàng
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formKH">
                                    <input type="hidden" name="action" value="update_customer">
                                    <input type="hidden" name="shipment_id"   id="kh_shipment_id">
                                    <input type="hidden" name="search"        value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="search_email"  value="<?php echo htmlspecialchars($search_email); ?>">
                                    <input type="hidden" name="status_kh"     value="<?php echo htmlspecialchars($status_kh); ?>">
                                    <input type="hidden" name="status_ncc"    value="<?php echo htmlspecialchars($status_ncc); ?>">
                                    <input type="hidden" name="month"         value="<?php echo htmlspecialchars($month); ?>">
                                    <input type="hidden" name="customer_id"   value="<?php echo htmlspecialchars($customer_id); ?>">
                                    <input type="hidden" name="is_locked"     value="<?php echo htmlspecialchars($is_locked); ?>">
                                    <input type="hidden" name="view"          value="<?php echo htmlspecialchars($view); ?>">
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Tổng Sell (chỉ đọc)</label>
                                        <input type="text" class="form-control form-control-sm bg-light" id="kh_sell_display" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Số tiền KH đã trả</label>
                                        <input type="number" name="customer_paid_amount" id="kh_paid_amount"
                                               class="form-control form-control-sm" min="0" step="0.01" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Ngày trả</label>
                                        <input type="date" name="customer_paid_at" id="kh_paid_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Ghi chú</label>
                                        <input type="text" name="customer_paid_note" id="kh_paid_note"
                                               class="form-control form-control-sm" placeholder="VD: CK ngân hàng, số ref...">
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-save"></i> Lưu
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="setFullPaid('kh')">
                                            <i class="bi bi-check-all"></i> Đã trả đủ
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- NCC -->
                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-header bg-warning text-dark py-2">
                                <i class="bi bi-truck"></i> Trả tiền Nhà cung cấp
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formNCC">
                                    <input type="hidden" name="action" value="update_supplier">
                                    <input type="hidden" name="shipment_id"   id="ncc_shipment_id">
                                    <input type="hidden" name="search"        value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="search_email"  value="<?php echo htmlspecialchars($search_email); ?>">
                                    <input type="hidden" name="status_kh"     value="<?php echo htmlspecialchars($status_kh); ?>">
                                    <input type="hidden" name="status_ncc"    value="<?php echo htmlspecialchars($status_ncc); ?>">
                                    <input type="hidden" name="month"         value="<?php echo htmlspecialchars($month); ?>">
                                    <input type="hidden" name="customer_id"   value="<?php echo htmlspecialchars($customer_id); ?>">
                                    <input type="hidden" name="is_locked"     value="<?php echo htmlspecialchars($is_locked); ?>">
                                    <input type="hidden" name="view"          value="<?php echo htmlspecialchars($view); ?>">
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Tổng Cost (chỉ đọc)</label>
                                        <input type="text" class="form-control form-control-sm bg-light" id="ncc_cost_display" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Số tiền đã trả NCC</label>
                                        <input type="number" name="supplier_paid_amount" id="ncc_paid_amount"
                                               class="form-control form-control-sm" min="0" step="0.01" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Ngày trả</label>
                                        <input type="date" name="supplier_paid_at" id="ncc_paid_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Ghi chú</label>
                                        <input type="text" name="supplier_paid_note" id="ncc_paid_note"
                                               class="form-control form-control-sm" placeholder="VD: Trả tháng 3/2026...">
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="bi bi-save"></i> Lưu
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="setFullPaid('ncc')">
                                            <i class="bi bi-check-all"></i> Đã trả đủ
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white text-center py-2 border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentRow = null;

function openModal(row) {
    currentRow = row;
    document.getElementById('modalJobNo').textContent  = row.job_no;
    document.getElementById('kh_shipment_id').value   = row.id;
    document.getElementById('ncc_shipment_id').value  = row.id;

    document.getElementById('kh_sell_display').value  = new Intl.NumberFormat('vi-VN', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(row.total_sell);
    document.getElementById('kh_paid_amount').value   = row.customer_paid_amount || 0;
    document.getElementById('kh_paid_at').value       = row.customer_paid_at || '';
    document.getElementById('kh_paid_note').value     = row.customer_paid_note || '';

    document.getElementById('ncc_cost_display').value = new Intl.NumberFormat('vi-VN', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(row.total_cost);
    document.getElementById('ncc_paid_amount').value  = row.supplier_paid_amount || 0;
    document.getElementById('ncc_paid_at').value      = row.supplier_paid_at || '';
    document.getElementById('ncc_paid_note').value    = row.supplier_paid_note || '';

    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function setFullPaid(type) {
    if (!currentRow) return;
    if (type === 'kh') {
        document.getElementById('kh_paid_amount').value = currentRow.total_sell;
        if (!document.getElementById('kh_paid_at').value)
            document.getElementById('kh_paid_at').value = new Date().toISOString().split('T')[0];
    } else {
        document.getElementById('ncc_paid_amount').value = currentRow.total_cost;
        if (!document.getElementById('ncc_paid_at').value)
            document.getElementById('ncc_paid_at').value = new Date().toISOString().split('T')[0];
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('filterForm');

    form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', function () { form.submit(); });
    });

    const monthInput = form.querySelector('input[name="month"]');
    if (monthInput) {
        monthInput.addEventListener('change', function () { form.submit(); });
    }

    ['search', 'search_email'].forEach(function (name) {
        const input = form.querySelector('input[name="' + name + '"]');
        if (input) {
            let timer;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { form.submit(); }, 500);
            });
        }
    });
});
</script>
</body>
</html>