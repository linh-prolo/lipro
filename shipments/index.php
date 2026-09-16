<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Xử lý tìm kiếm & lọc
$search         = isset($_GET['search'])     ? trim($_GET['search'])     : '';
$status_filter  = isset($_GET['status'])     ? $_GET['status']           : '';
$locked_filter  = isset($_GET['locked'])     ? $_GET['locked']           : 'no';
$customer_filter= isset($_GET['customer'])   ? intval($_GET['customer']) : 0;
$date_from      = isset($_GET['date_from'])  ? trim($_GET['date_from'])  : '';
$date_to        = isset($_GET['date_to'])    ? trim($_GET['date_to'])    : '';
$email_filter   = isset($_GET['email_sent']) ? $_GET['email_sent']       : '';
$sort_by        = isset($_GET['sort_by'])    ? $_GET['sort_by']          : 'created_at';
$sort_dir       = isset($_GET['sort_dir'])   ? $_GET['sort_dir']         : 'DESC';

// Whitelist sort
$allowed_sort = ['created_at', 'invoice_no', 'arrival_date', 'job_no'];
if (!in_array($sort_by, $allowed_sort)) $sort_by = 'created_at';
$sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';

$where = [];

if ($search) {
    $s = $conn->real_escape_string($search);
    $where[] = "(s.job_no LIKE '%$s%' OR s.mawb LIKE '%$s%' OR s.hawb LIKE '%$s%'
                 OR s.shipper LIKE '%$s%' OR s.cnee LIKE '%$s%'
                 OR s.customs_declaration_no LIKE '%$s%'
                 OR s.invoice_no LIKE '%$s%'
                 OR c.short_name LIKE '%$s%')";
}
if ($status_filter)   $where[] = "s.status = '$status_filter'";
if ($locked_filter)   $where[] = "s.is_locked = '$locked_filter'";
if ($customer_filter) $where[] = "s.customer_id = $customer_filter";

// ✅ LỌC THEO NGÀY XUẤT HOÁ ĐƠN (invoice_date) thay vì created_at
if ($date_from) $where[] = "DATE(s.invoice_date) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to)   $where[] = "DATE(s.invoice_date) <= '" . $conn->real_escape_string($date_to)   . "'";

if ($email_filter)    $where[] = "s.email_sent = '$email_filter'";

$whereClause = count($where) > 0
    ? 'WHERE s.deleted_at IS NULL AND ' . implode(' AND ', $where)
    : 'WHERE s.deleted_at IS NULL';

// Sắp xếp invoice_no NULL xuống cuối
$orderClause = $sort_by === 'invoice_no'
    ? "ORDER BY (s.invoice_no IS NULL OR s.invoice_no = '') ASC, s.invoice_no $sort_dir"
    : "ORDER BY s.$sort_by $sort_dir";

// Phân trang
$per_page    = 25;
$page        = max(1, intval($_GET['page'] ?? 1));
$count_sql   = "SELECT COUNT(*) c FROM shipments s
                LEFT JOIN customers c ON s.customer_id = c.id
                $whereClause";
$count_res   = $conn->query($count_sql);
$total_count = intval($count_res->fetch_assoc()['c'] ?? 0);
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;
$offset      = ($page - 1) * $per_page;

$sql = "SELECT s.*,
               c.company_name, c.short_name AS customer_short,
               COALESCE(sc.total_cost, 0) AS total_cost,
               COALESCE(ss.total_sell, 0) AS total_sell,
               COALESCE(ss.total_sell, 0) - COALESCE(sc.total_cost, 0) AS profit
        FROM shipments s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) AS total_cost FROM shipment_costs GROUP BY shipment_id) sc ON sc.shipment_id = s.id
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) AS total_sell FROM shipment_sells GROUP BY shipment_id) ss ON ss.shipment_id = s.id
        $whereClause
        $orderClause
        LIMIT $per_page OFFSET $offset";

$result = $conn->query($sql);

$stats = [
    'total'      => $conn->query("SELECT COUNT(*) c FROM shipments WHERE deleted_at IS NULL")->fetch_assoc()['c'],
    'pending'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='pending' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'in_transit' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='in_transit' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'arrived'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='arrived' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'cleared'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='cleared' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'delivered'  => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='delivered' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'locked'     => $conn->query("SELECT COUNT(*) c FROM shipments WHERE is_locked='yes' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'email_sent' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE email_sent='yes' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'vat_issued' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE vat_invoice_status='issued' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'trash'      => $conn->query("SELECT COUNT(*) c FROM shipments WHERE deleted_at IS NOT NULL")->fetch_assoc()['c'],
];

$customers = $conn->query("SELECT id, short_name, company_name FROM customers WHERE status='active' ORDER BY short_name");

$conn->close();

$statusBadge = [
    'pending'    => ['color' => 'warning',  'text' => 'Chờ xử lý',       'icon' => 'hourglass-split'],
    'in_transit' => ['color' => 'primary',  'text' => 'Đang vận chuyển', 'icon' => 'truck'],
    'arrived'    => ['color' => 'info',     'text' => 'Đã đến',          'icon' => 'geo-alt'],
    'cleared'    => ['color' => 'success',  'text' => 'Đã thông quan',   'icon' => 'check-circle'],
    'delivered'  => ['color' => 'dark',     'text' => 'Đã giao',         'icon' => 'box-seam'],
    'cancelled'  => ['color' => 'danger',   'text' => 'Đã hủy',          'icon' => 'x-circle'],
];

// Helper tạo URL sort
function sortUrl($col) {
    $params = $_GET;
    $params['sort_by']  = $col;
    $params['sort_dir'] = (isset($_GET['sort_by']) && $_GET['sort_by'] === $col && strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC') ? 'DESC' : 'ASC';
    return 'index.php?' . http_build_query($params);
}
function sortIcon($col) {
    global $sort_by, $sort_dir;
    if ($sort_by !== $col) return '<i class="bi bi-arrow-down-up text-secondary ms-1" style="opacity:.4"></i>';
    return $sort_dir === 'ASC'
        ? '<i class="bi bi-sort-alpha-down text-warning ms-1"></i>'
        : '<i class="bi bi-sort-alpha-up text-warning ms-1"></i>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Lô hàng - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .shipment-row {
            cursor: pointer;
            transition: background-color 0.15s, transform 0.1s;
        }
        .shipment-row:hover {
            background-color: #e8f4fd !important;
            transform: scale(1.001);
        }
        .shipment-row td { vertical-align: middle; }

        .stat-card {
            border-radius: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
        }
        .stat-card .stat-num  { font-size: 1.9rem; font-weight: 700; }
        .stat-card .stat-icon { font-size: 2rem; opacity: .6; }

        .job-no { font-weight: 700; color: #0d6efd; font-size: .9rem; letter-spacing: .4px; }
        .profit-pos { color: #198754; font-weight: 600; }
        .profit-neg { color: #dc3545; font-weight: 600; }
        .locked-badge { font-size: .65rem; padding: 2px 6px; border-radius: 4px; }

        .action-btn { opacity: 0.8; transition: opacity .2s; }
        .shipment-row:hover .action-btn { opacity: 1; }

        .filter-card { border-left: 4px solid #0d6efd; }

        .table thead th {
            background: #343a40;
            color: white;
            font-size: .78rem;
            white-space: nowrap;
            padding: 8px 6px;
        }
        .table thead th a {
            color: white;
            text-decoration: none;
        }
        .table thead th a:hover { color: #ffc107; }
        .table tbody td { font-size: .82rem; padding: 6px; }

        .cd-badge {
            font-size: .72rem;
            background: #e8f5e9;
            color: #1b5e20;
            border: 1px solid #a5d6a7;
            border-radius: 4px;
            padding: 1px 5px;
            display: inline-block;
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .invoice-badge {
            font-size: .72rem;
            background: #fff3cd;
            color: #664d03;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 2px 6px;
            display: inline-block;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 600;
        }
        .invoice-badge.none {
            background: #f3f4f6;
            color: #9ca3af;
            border-color: #d1d5db;
            font-weight: normal;
        }

        .shipper-cell {
            font-size: 0.55rem;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .email-sent-badge {
            font-size: .72rem;
            padding: 3px 7px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
        }
        .email-sent-badge.sent {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .email-sent-badge.not-sent {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        .email-sent-time {
            font-size: .68rem;
            color: #6b7280;
            display: block;
            margin-top: 2px;
        }

        .vat-badge {
            font-size: .72rem;
            padding: 3px 7px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
            text-decoration: none;
        }
        .vat-badge:hover { opacity: .85; }
        .vat-badge.issued {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .vat-badge.cancelled {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .vat-badge.pending {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
        }
        .vat-badge.none {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }

        th.sort-active { background: #1a2535 !important; }

        .btn-lock-on  { font-size: .75rem; padding: 2px 6px; }
        .btn-lock-off { font-size: .75rem; padding: 2px 6px; }

        /* ✅ Highlight bộ lọc ngày đang active */
        .date-filter-active .form-control {
            border-color: #0d6efd;
            background-color: #eff6ff;
        }
        .date-filter-label-active {
            color: #0d6efd;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-light">

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../customers/index.php"><i class="bi bi-people"></i> Khách hàng</a></li>
                    <li class="nav-item"><a class="nav-link" href="../quotations/index.php">Báo Giá</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php"><i class="bi bi-box"></i> Lô hàng</a></li>
                    <li class="nav-item"><a class="nav-link" href="../debt/index.php">Công Nợ</a></li>
                    <li class="nav-item"><a class="nav-link" href="../suppliers/index.php"><i class="bi bi-truck"></i> Nhà cung cấp</a></li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i> Quản trị
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../accounts/index.php"><i class="bi bi-person-badge"></i> Tài khoản</a></li>
                            <li><a class="dropdown-item" href="../cost_codes/index.php"><i class="bi bi-tag"></i> Mã chi phí</a></li>
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
                            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Đăng xuất</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3 pb-5">

        <!-- HEADER -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-box text-primary"></i> Quản lý Lô hàng</h4>
            <div class="d-flex gap-2">
                <a href="trash.php" class="btn btn-outline-secondary">
                    <i class="bi bi-trash3"></i> Thùng rác
                    <?php if ($stats['trash'] > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $stats['trash']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Thêm lô hàng mới
                </a>
            </div>
        </div>

        <!-- THỐNG KÊ TRẠNG THÁI -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-primary text-white" onclick="filterByStatus('')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['total']; ?></div>
                                <div class="small">Tất cả</div>
                            </div>
                            <i class="bi bi-boxes stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-warning" onclick="filterByStatus('pending')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['pending']; ?></div>
                                <div class="small">Chờ xử lý</div>
                            </div>
                            <i class="bi bi-hourglass-split stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-primary text-white" onclick="filterByStatus('in_transit')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['in_transit']; ?></div>
                                <div class="small">Đang vận chuyển</div>
                            </div>
                            <i class="bi bi-truck stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-info text-white" onclick="filterByStatus('arrived')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['arrived']; ?></div>
                                <div class="small">Đã đến</div>
                            </div>
                            <i class="bi bi-geo-alt stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-success text-white" onclick="filterByStatus('cleared')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['cleared']; ?></div>
                                <div class="small">Đã thông quan</div>
                            </div>
                            <i class="bi bi-check-circle stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-dark text-white" onclick="filterByStatus('delivered')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['delivered']; ?></div>
                                <div class="small">Đã giao</div>
                            </div>
                            <i class="bi bi-box-seam stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STAT EMAIL + VAT -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #10b981 !important; cursor:pointer"
                     onclick="filterByEmail('yes')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-envelope-check-fill text-success fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-success"><?php echo $stats['email_sent']; ?></div>
                            <small class="text-muted">Đã gửi Debit Note</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #6b7280 !important; cursor:pointer"
                     onclick="filterByEmail('no')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-envelope-x-fill text-secondary fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-secondary"><?php echo $stats['total'] - $stats['email_sent']; ?></div>
                            <small class="text-muted">Chưa gửi Debit Note</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #166534 !important; cursor:pointer"
                     onclick="filterByVat('issued')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-receipt-cutoff text-success fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-success"><?php echo $stats['vat_issued']; ?></div>
                            <small class="text-muted">Đã xuất HĐ VAT</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #dc2626 !important; cursor:pointer"
                     onclick="filterByVat('none')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-receipt text-danger fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-danger"><?php echo $stats['total'] - $stats['vat_issued']; ?></div>
                            <small class="text-muted">Chưa xuất HĐ VAT</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BỘ LỌC -->
        <div class="card filter-card shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" id="filterForm" class="row g-2 align-items-end">
                    <!-- Giữ lại sort khi filter -->
                    <input type="hidden" name="sort_by"  value="<?php echo htmlspecialchars($sort_by); ?>">
                    <input type="hidden" name="sort_dir" value="<?php echo htmlspecialchars($sort_dir); ?>">

                    <div class="col-md-3">
                        <label class="form-label small mb-1"><i class="bi bi-search"></i> Tìm kiếm</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Job No, MAWB, HAWB, Tờ khai, Shipper, Số HĐ, KH..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1"><i class="bi bi-people"></i> Khách hàng</label>
                        <select name="customer" class="form-select form-select-sm">
                            <option value="">-- Tất cả --</option>
                            <?php while ($c = $customers->fetch_assoc()): ?>
                                <option value="<?php echo $c['id']; ?>"
                                    <?php echo $customer_filter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['short_name'] . ' - ' . $c['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-flag"></i> Trạng thái</label>
                        <select name="status" class="form-select form-select-sm" id="statusSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="pending"    <?php echo $status_filter=='pending'    ?'selected':''; ?>>Chờ xử lý</option>
                            <option value="in_transit" <?php echo $status_filter=='in_transit' ?'selected':''; ?>>Đang vận chuyển</option>
                            <option value="arrived"    <?php echo $status_filter=='arrived'    ?'selected':''; ?>>Đã đến</option>
                            <option value="cleared"    <?php echo $status_filter=='cleared'    ?'selected':''; ?>>Đã thông quan</option>
                            <option value="delivered"  <?php echo $status_filter=='delivered'  ?'selected':''; ?>>Đã giao</option>
                            <option value="cancelled"  <?php echo $status_filter=='cancelled'  ?'selected':''; ?>>Đã hủy</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-lock"></i> Khóa</label>
                        <select name="locked" class="form-select form-select-sm">
                            <option value="">-- Tất cả --</option>
                            <option value="no"  <?php echo $locked_filter=='no'  ?'selected':''; ?>>Chưa khóa</option>
                            <option value="yes" <?php echo $locked_filter=='yes' ?'selected':''; ?>>Đã khóa</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-envelope"></i> Email</label>
                        <select name="email_sent" class="form-select form-select-sm" id="emailSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="yes" <?php echo $email_filter=='yes' ?'selected':''; ?>>Đã gửi</option>
                            <option value="no"  <?php echo $email_filter=='no'  ?'selected':''; ?>>Chưa gửi</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-receipt-cutoff"></i> HĐ VAT</label>
                        <select name="vat_status" class="form-select form-select-sm" id="vatSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="issued"    <?php echo (($_GET['vat_status'] ?? '') == 'issued')    ? 'selected' : ''; ?>>Đã xuất</option>
                            <option value="cancelled" <?php echo (($_GET['vat_status'] ?? '') == 'cancelled') ? 'selected' : ''; ?>>Đã hủy</option>
                            <option value="none"      <?php echo (($_GET['vat_status'] ?? '') == 'none')      ? 'selected' : ''; ?>>Chưa xuất</option>
                        </select>
                    </div>

                    <!-- ✅ NGÀY XUẤT HOÁ ĐƠN (invoice_date) -->
                    <div class="col-md-1 <?php echo $date_from ? 'date-filter-active' : ''; ?>">
                        <label class="form-label small mb-1 <?php echo $date_from ? 'date-filter-label-active' : ''; ?>">
                            <i class="bi bi-calendar-event"></i>
                            Từ ngày HĐ
                        </label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?php echo $date_from; ?>"
                               title="Lọc theo ngày xuất hoá đơn (invoice_date)">
                    </div>
                    <div class="col-md-1 <?php echo $date_to ? 'date-filter-active' : ''; ?>">
                        <label class="form-label small mb-1 <?php echo $date_to ? 'date-filter-label-active' : ''; ?>">
                            <i class="bi bi-calendar-event"></i>
                            Đến ngày HĐ
                        </label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?php echo $date_to; ?>"
                               title="Lọc theo ngày xuất hoá đơn (invoice_date)">
                    </div>

                    <div class="col-md-auto d-flex gap-1 flex-wrap">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i> Lọc
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x"></i> Xóa
                        </a>
                        <a href="export_statement.php?<?php echo htmlspecialchars(http_build_query([
                            'search'    => $search,
                            'status'    => $status_filter,
                            'locked'    => $locked_filter,
                            'customer'  => $customer_filter ?: '',
                            'date_from' => $date_from,
                            'date_to'   => $date_to,
                        ])); ?>" class="btn btn-success btn-sm" title="Xuất Statement of Account">
                            <i class="bi bi-file-earmark-excel-fill"></i> Xuất SOA
                        </a>
						<!-- Nút Xuất Excel Phí Chi Hộ (MỚI) -->
						<a href="export_pob_fees.php?<?php echo htmlspecialchars(http_build_query([
						'search'    => $search,
						'status'    => $status_filter,
						'locked'    => $locked_filter,
						'customer'  => $customer_filter ?: '',
						'date_from' => $date_from,
						'date_to'   => $date_to,
						])); ?>" class="btn btn-warning btn-sm" title="Xuất danh sách phí chi hộ được lọc">
						<i class="bi bi-arrow-left-right"></i> Xuất Phí Chi Hộ
						</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- THÔNG BÁO SUCCESS -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2">
            <i class="bi bi-check-circle"></i>
            <?php
                if ($_GET['success'] == 'added')    echo 'Thêm lô hàng thành công!';
                if ($_GET['success'] == 'updated')  echo 'Cập nhật lô hàng thành công!';
                if ($_GET['success'] == 'deleted')  echo 'Xóa lô hàng thành công!';
                if ($_GET['success'] == 'locked')   echo '<i class="bi bi-lock-fill"></i> Đã khoá lô hàng thành công!';
                if ($_GET['success'] == 'unlocked') echo '<i class="bi bi-unlock-fill"></i> Đã mở khoá lô hàng thành công!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- THÔNG BÁO ERROR -->
        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2">
            <i class="bi bi-exclamation-triangle"></i>
            <?php
                if ($_GET['error'] == 'shipment_locked') echo 'Lô hàng đã khóa, không thể sửa!';
                if ($_GET['error'] == 'delete_failed')   echo 'Xóa lô hàng thất bại!';
                if ($_GET['error'] == 'no_permission')   echo 'Bạn không có quyền thực hiện thao tác này!';
                if ($_GET['error'] == 'not_found')       echo 'Không tìm thấy lô hàng!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- BẢNG DANH SÁCH -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
                <span>
                    <i class="bi bi-table"></i> Danh sách lô hàng
                    <span class="badge bg-light text-dark ms-2"><?php echo $result->num_rows; ?> lô hàng</span>
                    <?php if ($search || $status_filter || $customer_filter || $date_from || $date_to || $locked_filter || $email_filter || !empty($_GET['vat_status'])): ?>
                        <span class="badge bg-warning text-dark ms-1">
                            <i class="bi bi-funnel"></i> Đang lọc
                        </span>
                    <?php endif; ?>
                    <?php if ($date_from || $date_to): ?>
                        <span class="badge bg-info text-dark ms-1">
                            <i class="bi bi-calendar-event"></i>
                            Ngày HĐ:
                            <?php echo $date_from ? date('d/m/Y', strtotime($date_from)) : '...'; ?>
                            —
                            <?php echo $date_to   ? date('d/m/Y', strtotime($date_to))   : '...'; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($sort_by !== 'created_at'): ?>
                        <span class="badge bg-info text-dark ms-1">
                            <i class="bi bi-sort-down"></i>
                            Sắp xếp: <?php echo $sort_by === 'invoice_no' ? 'Số HĐ' : ucfirst(str_replace('_', ' ', $sort_by)); ?>
                            (<?php echo $sort_dir; ?>)
                        </span>
                    <?php endif; ?>
                </span>
                <small class="text-white-50">
                    <i class="bi bi-hand-index"></i> Click vào dòng để xem chi tiết |
                    <i class="bi bi-arrow-down-up"></i> Click tiêu đề cột để sắp xếp
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th style="width:32px">#</th>
                                <th>
                                    <a href="<?php echo sortUrl('job_no'); ?>">
                                        Job No <?php echo sortIcon('job_no'); ?>
                                    </a>
                                </th>
                                <th>Khách hàng</th>
                                <th>MAWB / HAWB</th>
                                <th>Số tờ khai</th>
                                <th class="<?php echo $sort_by === 'invoice_no' ? 'sort-active' : ''; ?>">
                                    <a href="<?php echo sortUrl('invoice_no'); ?>">
                                        Số HĐ <?php echo sortIcon('invoice_no'); ?>
                                    </a>
                                </th>
                                <th style="max-width:110px;">Shipper / CNEE</th>
                                <th>Kiện / GW / CW</th>
                                <th>
                                    <a href="<?php echo sortUrl('arrival_date'); ?>">
                                        Ngày đến <?php echo sortIcon('arrival_date'); ?>
                                    </a>
                                </th>
                                <th>COST</th>
                                <th>SELL</th>
                                <th>Lợi nhuận</th>
                                <th>Debit Note</th>
                                <th>HĐ VAT</th>
                                <th>Trạng thái</th>
                                <th style="width:100px">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php $stt = 1; while ($row = $result->fetch_assoc()):
                                    $badge         = $statusBadge[$row['status']] ?? ['color'=>'secondary','text'=>$row['status'],'icon'=>'circle'];
                                    $profit        = floatval($row['profit']);
                                    $email_sent    = $row['email_sent']    ?? 'no';
                                    $email_sent_at = $row['email_sent_at'] ?? null;
                                    $vat_status    = $row['vat_invoice_status'] ?? '';
                                    $vat_no        = $row['vat_invoice_no']     ?? '';
                                    $is_locked     = $row['is_locked'] ?? 'no';

                                    // Lọc VAT phía PHP
                                    $vat_filter_val = $_GET['vat_status'] ?? '';
                                    if ($vat_filter_val === 'issued'    && $vat_status !== 'issued')    continue;
                                    if ($vat_filter_val === 'cancelled' && $vat_status !== 'cancelled') continue;
                                    if ($vat_filter_val === 'none'      && !empty($vat_status))         continue;

                                    $current_url = 'index.php?' . http_build_query(array_filter([
                                        'search'     => $search,
                                        'status'     => $status_filter,
                                        'locked'     => $locked_filter,
                                        'customer'   => $customer_filter ?: '',
                                        'date_from'  => $date_from,
                                        'date_to'    => $date_to,
                                        'email_sent' => $email_filter,
                                        'vat_status' => $_GET['vat_status'] ?? '',
                                        'sort_by'    => $sort_by,
                                        'sort_dir'   => $sort_dir,
                                    ]));
                                ?>
                                <tr class="shipment-row"
                                    onclick="goToDetail(<?php echo $row['id']; ?>, event)"
                                    data-id="<?php echo $row['id']; ?>">

                                    <td class="text-center text-muted"><?php echo $stt++; ?></td>

                                    <!-- Job No -->
                                    <td>
                                        <span class="job-no"><?php echo htmlspecialchars($row['job_no']); ?></span>
                                        <?php if ($is_locked === 'yes'): ?>
                                            <br><span class="badge bg-danger locked-badge">
                                                <i class="bi bi-lock-fill"></i> Đã khóa
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Khách hàng -->
                                    <td>
                                        <span class="badge bg-info text-dark">
                                            <?php echo htmlspecialchars($row['customer_short']); ?>
                                        </span>
                                        <br><small class="text-muted">
                                            <?php echo htmlspecialchars($row['company_name']); ?>
                                        </small>
                                    </td>

                                    <!-- MAWB / HAWB -->
                                    <td>
                                        <small>
                                            <span class="text-muted">M:</span>
                                            <strong><?php echo htmlspecialchars($row['mawb']); ?></strong><br>
                                            <span class="text-muted">H:</span>
                                            <?php echo htmlspecialchars($row['hawb']); ?>
                                        </small>
                                    </td>

                                    <!-- Số tờ khai -->
                                    <td>
                                        <?php if (!empty($row['customs_declaration_no'])): ?>
                                            <span class="cd-badge"
                                                  title="<?php echo htmlspecialchars($row['customs_declaration_no']); ?>">
                                                <i class="bi bi-file-earmark-text"></i>
                                                <?php echo htmlspecialchars($row['customs_declaration_no']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Số hoá đơn -->
                                    <td class="text-center">
                                        <?php if (!empty($row['invoice_no'])): ?>
                                            <span class="invoice-badge"
                                                  title="Số HĐ: <?php echo htmlspecialchars($row['invoice_no']); ?>">
                                                <i class="bi bi-receipt"></i>
                                                <?php echo htmlspecialchars($row['invoice_no']); ?>
                                            </span>
                                            <?php if (!empty($row['invoice_date']) && $row['invoice_date'] !== '0000-00-00'): ?>
                                                <br><small class="text-muted" style="font-size:.68rem;">
                                                    <?php echo date('d/m/Y', strtotime($row['invoice_date'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="invoice-badge none">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Shipper/CNEE -->
                                    <td style="max-width:110px;">
                                        <small>
                                            <?php if ($row['shipper']): ?>
                                                <div class="shipper-cell text-primary"
                                                     title="<?php echo htmlspecialchars($row['shipper']); ?>">
                                                    <i class="bi bi-box-arrow-up"></i>
                                                    <?php echo htmlspecialchars($row['shipper']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($row['cnee']): ?>
                                                <div class="shipper-cell text-success"
                                                     title="<?php echo htmlspecialchars($row['cnee']); ?>">
                                                    <i class="bi bi-box-arrow-down"></i>
                                                    <?php echo htmlspecialchars($row['cnee']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$row['shipper'] && !$row['cnee']): ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <!-- Kiện / GW / CW -->
                                    <td>
                                        <small>
                                            <?php if ($row['packages']): ?>
                                                <i class="bi bi-stack"></i>
                                                <?php echo number_format($row['packages']); ?> kiện<br>
                                            <?php endif; ?>
                                            <?php if ($row['gw']): ?>
                                                GW: <?php echo number_format($row['gw'], 1); ?> kg<br>
                                            <?php endif; ?>
                                            <?php if ($row['cw']): ?>
                                                CW: <?php echo number_format($row['cw'], 1); ?>
                                            <?php endif; ?>
                                            <?php if (!$row['packages'] && !$row['gw'] && !$row['cw']): ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <!-- Ngày đến -->
                                    <td>
                                        <small>
                                            <?php if ($row['arrival_date']): ?>
                                                <i class="bi bi-calendar-check text-success"></i>
                                                <?php echo date('d/m/Y', strtotime($row['arrival_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo date('d/m/Y', strtotime($row['created_at'])); ?>
                                        </small>
                                    </td>

                                    <!-- COST -->
                                    <td class="text-end">
                                        <small class="text-danger fw-bold">
                                            <?php echo $row['total_cost'] > 0
                                                ? number_format($row['total_cost'], 0, ',', '.')
                                                : '—'; ?>
                                        </small>
                                    </td>

                                    <!-- SELL -->
                                    <td class="text-end">
                                        <small class="text-success fw-bold">
                                            <?php echo $row['total_sell'] > 0
                                                ? number_format($row['total_sell'], 0, ',', '.')
                                                : '—'; ?>
                                        </small>
                                    </td>

                                    <!-- Lợi nhuận -->
                                    <td class="text-end">
                                        <?php if ($row['total_cost'] > 0 || $row['total_sell'] > 0): ?>
                                            <small class="<?php echo $profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                                                <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>

                                    <!-- DEBIT NOTE / EMAIL -->
                                    <td class="text-center">
                                        <?php if ($email_sent === 'yes'): ?>
                                            <span class="email-sent-badge sent">
                                                <i class="bi bi-envelope-check-fill"></i> Đã gửi
                                            </span>
                                            <?php if ($email_sent_at): ?>
                                                <span class="email-sent-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($email_sent_at)); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="email-sent-badge not-sent">
                                                <i class="bi bi-envelope-x"></i> Chưa gửi
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- HĐ VAT -->
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($vat_status === 'issued'): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>&tab=info"
                                               class="vat-badge issued"
                                               title="Số HĐ: <?php echo htmlspecialchars($vat_no); ?>">
                                                <i class="bi bi-receipt-cutoff"></i>
                                                <?php echo htmlspecialchars($vat_no) ?: 'Đã xuất'; ?>
                                            </a>
                                        <?php elseif ($vat_status === 'cancelled'): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>&tab=info"
                                               class="vat-badge cancelled">
                                                <i class="bi bi-x-circle"></i> Đã hủy
                                            </a>
                                        <?php elseif (!empty($vat_status)): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>"
                                               class="vat-badge pending">
                                                <i class="bi bi-hourglass-split"></i>
                                                <?php echo htmlspecialchars($vat_status); ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>"
                                               class="vat-badge none"
                                               title="Chưa xuất hóa đơn VAT — Click để phát hành">
                                                <i class="bi bi-plus-circle"></i> Xuất HĐ
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Trạng thái -->
                                    <td>
                                        <span class="badge bg-<?php echo $badge['color']; ?>">
                                            <i class="bi bi-<?php echo $badge['icon']; ?>"></i>
                                            <?php echo $badge['text']; ?>
                                        </span>
                                    </td>

                                    <!-- THAO TÁC -->
                                    <td onclick="event.stopPropagation()">
                                        <div class="d-flex flex-column gap-1 align-items-center action-btn">
                                            <div class="d-flex gap-1">
                                                <!-- Sửa -->
                                                <a href="edit.php?id=<?php echo $row['id']; ?>"
                                                   class="btn btn-warning btn-sm btn-lock-off"
                                                   title="Sửa lô hàng">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <!-- Khoá / Mở khoá -->
                                                <?php if ($is_locked === 'yes'): ?>
                                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <a href="toggle_lock.php?id=<?php echo $row['id']; ?>&redirect=<?php echo urlencode($current_url); ?>"
                                                       class="btn btn-danger btn-sm btn-lock-on"
                                                       title="Đang khoá — Click để mở khoá"
                                                       onclick="return confirm('Mở khoá lô hàng <?php echo htmlspecialchars(addslashes($row['job_no'])); ?>?')">
                                                        <i class="bi bi-lock-fill"></i>
                                                    </a>
                                                    <?php else: ?>
                                                    <span class="btn btn-danger btn-sm btn-lock-on disabled"
                                                          title="Đã khoá (chỉ Admin mới mở được)">
                                                        <i class="bi bi-lock-fill"></i>
                                                    </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <a href="toggle_lock.php?id=<?php echo $row['id']; ?>&redirect=<?php echo urlencode($current_url); ?>"
                                                       class="btn btn-outline-secondary btn-sm btn-lock-off"
                                                       title="Chưa khoá — Click để khoá"
                                                       onclick="return confirm('Khoá lô hàng <?php echo htmlspecialchars(addslashes($row['job_no'])); ?>?')">
                                                        <i class="bi bi-unlock"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Xóa (chỉ admin) -->
                                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                                <a href="delete.php?id=<?php echo $row['id']; ?>"
                                                   class="btn btn-danger btn-sm btn-lock-off"
                                                   title="Xóa lô hàng"
                                                   onclick="return confirm('Xóa lô hàng <?php echo htmlspecialchars(addslashes($row['job_no'])); ?>?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center py-5">
                                        <i class="bi bi-inbox" style="font-size:3rem;color:#ccc"></i>
                                        <p class="text-muted mt-2 mb-2">Không tìm thấy lô hàng nào</p>
                                        <?php if ($search || $status_filter || $customer_filter || $date_from || $date_to || $email_filter || !empty($_GET['vat_status'])): ?>
                                            <a href="index.php" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-x"></i> Xóa bộ lọc
                                            </a>
                                        <?php else: ?>
                                            <a href="add.php" class="btn btn-sm btn-primary">
                                                <i class="bi bi-plus-circle"></i> Thêm lô hàng đầu tiên
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Phân trang" class="mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Hiển thị <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_count); ?> trong tổng <?php echo $total_count; ?> kết quả
                </small>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹</a></li>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">›</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
        <?php endif; ?>

    </div>

    <footer class="bg-white text-center py-2 border-top">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function goToDetail(id, event) {
            if (event.target.closest('.action-btn')) return;
            window.location.href = 'view.php?id=' + id;
        }

        function filterByStatus(status) {
            document.getElementById('statusSelect').value = status;
            document.getElementById('filterForm').submit();
        }

        function filterByEmail(val) {
            document.getElementById('emailSelect').value = val;
            document.getElementById('filterForm').submit();
        }

        function filterByVat(val) {
            document.getElementById('vatSelect').value = val;
            document.getElementById('filterForm').submit();
        }

        document.querySelectorAll('#filterForm select').forEach(sel => {
            sel.addEventListener('change', () => {
                document.getElementById('filterForm').submit();
            });
        });
    </script>
</body>
</html>