<?php
require_once 'config/database.php';
checkLogin();

$conn = getDBConnection();

// Thống kê tổng quan
$stats = [
    'total_shipments'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_pending'  => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='pending' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_in_transit' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='in_transit' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_arrived'  => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='arrived' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_cleared'  => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='cleared' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_delivered'=> $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='delivered' AND deleted_at IS NULL")->fetch_assoc()['c'],
    'shipments_locked'   => $conn->query("SELECT COUNT(*) c FROM shipments WHERE is_locked='yes'")->fetch_assoc()['c'],
    'total_customers'    => $conn->query("SELECT COUNT(*) c FROM customers")->fetch_assoc()['c'],
    'active_customers'   => $conn->query("SELECT COUNT(*) c FROM customers WHERE status='active'")->fetch_assoc()['c'],
    'total_suppliers'    => $conn->query("SELECT COUNT(*) c FROM suppliers")->fetch_assoc()['c'],
    'total_users'        => $conn->query("SELECT COUNT(*) c FROM accounts")->fetch_assoc()['c'],
    'active_users'       => $conn->query("SELECT COUNT(*) c FROM accounts WHERE status='active'")->fetch_assoc()['c'],
    'total_cost_codes'   => $conn->query("SELECT COUNT(*) c FROM cost_codes")->fetch_assoc()['c'],
];

// 5 lô hàng mới nhất
$recent_shipments = $conn->query("SELECT s.*, c.short_name as customer_short_name 
    FROM shipments s LEFT JOIN customers c ON s.customer_id = c.id 
    WHERE s.deleted_at IS NULL ORDER BY s.created_at DESC LIMIT 5");

// ─── Tài chính tổng quát (chỉ tính lô chưa bị xoá) ────────────────────────
// Dùng subquery để tránh nhân chéo khi JOIN nhiều bảng chi tiết
$total_cost = $conn->query("
    SELECT COALESCE(SUM(sc.total_amount), 0) AS t
    FROM shipment_costs sc
    INNER JOIN shipments s ON s.id = sc.shipment_id
    WHERE s.deleted_at IS NULL
")->fetch_assoc()['t'];

$total_sell = $conn->query("
    SELECT COALESCE(SUM(ss.total_amount), 0) AS t
    FROM shipment_sells ss
    INNER JOIN shipments s ON s.id = ss.shipment_id
    WHERE s.deleted_at IS NULL
")->fetch_assoc()['t'];

$total_profit = $total_sell - $total_cost;

// Thống kê thông báo chưa đọc
$unread_notif = getUnreadNotificationCount($conn);

// ─── Biểu đồ doanh thu 6 tháng gần nhất ───────────────────────────────────
// Dùng subquery riêng cho cost và sell để tránh Cartesian product
$chart_stmt = $conn->prepare("
    SELECT
        mo,
        COALESCE(cost, 0)  AS cost,
        COALESCE(sell, 0)  AS sell
    FROM (
        SELECT DATE_FORMAT(s.created_at, '%Y-%m') AS mo
        FROM shipments s
        WHERE s.deleted_at IS NULL
          AND s.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
    ) AS months
    LEFT JOIN (
        SELECT DATE_FORMAT(s.created_at, '%Y-%m') AS mo,
               SUM(sc.total_amount) AS cost
        FROM shipment_costs sc
        INNER JOIN shipments s ON s.id = sc.shipment_id
        WHERE s.deleted_at IS NULL
          AND s.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
    ) AS c USING (mo)
    LEFT JOIN (
        SELECT DATE_FORMAT(s.created_at, '%Y-%m') AS mo,
               SUM(ss.total_amount) AS sell
        FROM shipment_sells ss
        INNER JOIN shipments s ON s.id = ss.shipment_id
        WHERE s.deleted_at IS NULL
          AND s.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
    ) AS v USING (mo)
    ORDER BY mo ASC
");
$chart_stmt->execute();
$chart_rows = $chart_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$chart_stmt->close();

$chart_labels = json_encode(array_column($chart_rows, 'mo'));
$chart_costs  = json_encode(array_map(fn($r) => floatval($r['cost']), $chart_rows));
$chart_sells  = json_encode(array_map(fn($r) => floatval($r['sell']), $chart_rows));

// Dữ liệu cho biểu đồ Doughnut trạng thái
$status_rows = [
    ['label' => 'Chờ xử lý',      'count' => $stats['shipments_pending'],   'color' => '#ffc107'],
    ['label' => 'Đang vận chuyển', 'count' => $stats['shipments_in_transit'],'color' => '#0d6efd'],
    ['label' => 'Đã đến',          'count' => $stats['shipments_arrived'],   'color' => '#6f42c1'],
    ['label' => 'Đã thông quan',   'count' => $stats['shipments_cleared'],   'color' => '#fd7e14'],
    ['label' => 'Đã giao',         'count' => $stats['shipments_delivered'], 'color' => '#198754'],
];
$doughnut_labels = json_encode(array_column($status_rows, 'label'));
$doughnut_data   = json_encode(array_column($status_rows, 'count'));
$doughnut_colors = json_encode(array_column($status_rows, 'color'));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
    <style>
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.12)!important; }
        .stat-icon { font-size: 2.4rem; opacity: 0.85; }
    </style>
</head>
<body>
    <?php include 'partials/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold" style="color:var(--lipro-primary,#1e3a5f);">
                <i class="bi bi-speedometer2"></i> Dashboard
            </h2>
            <div class="text-muted small">
                <i class="bi bi-calendar3"></i> <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>

        <!-- ─── Stat Cards ─────────────────────────── -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-primary shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Tổng lô hàng</h6>
                                <h2 class="text-primary mb-0"><?php echo number_format($stats['total_shipments']); ?></h2>
                            </div>
                            <i class="bi bi-box stat-icon text-primary"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-primary text-white">
                        <a href="shipments/index.php" class="text-white text-decoration-none small">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card stat-card border-info shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Khách hàng</h6>
                                <h2 class="text-info mb-0"><?php echo number_format($stats['total_customers']); ?></h2>
                                <small class="text-muted"><?php echo $stats['active_customers']; ?> hoạt động</small>
                            </div>
                            <i class="bi bi-people stat-icon text-info"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-info text-white">
                        <a href="customers/index.php" class="text-white text-decoration-none small">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card stat-card border-success shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Nhà cung cấp</h6>
                                <h2 class="text-success mb-0"><?php echo number_format($stats['total_suppliers']); ?></h2>
                            </div>
                            <i class="bi bi-truck stat-icon text-success"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-success text-white">
                        <a href="suppliers/index.php" class="text-white text-decoration-none small">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isAdmin()): ?>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-warning shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Tài khoản</h6>
                                <h2 class="text-warning mb-0"><?php echo number_format($stats['total_users']); ?></h2>
                                <small class="text-muted"><?php echo $stats['active_users']; ?> hoạt động</small>
                            </div>
                            <i class="bi bi-person-badge stat-icon text-warning"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-warning text-dark">
                        <a href="accounts/index.php" class="text-dark text-decoration-none small">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ─── Tài chính + thông báo ──────────────── -->
        <div class="row mb-4">
            <!-- Tài chính -->
            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-cash-stack text-success"></i> Tổng quan tài chính
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4 border-end">
                                <div class="text-muted small">Tổng chi phí</div>
                                <div class="fs-4 fw-bold text-danger"><?php echo number_format($total_cost, 0, ',', '.'); ?></div>
                                <div class="text-muted small">VND</div>
                            </div>
                            <div class="col-4 border-end">
                                <div class="text-muted small">Tổng doanh thu</div>
                                <div class="fs-4 fw-bold text-success"><?php echo number_format($total_sell, 0, ',', '.'); ?></div>
                                <div class="text-muted small">VND</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">Lợi nhuận</div>
                                <div class="fs-4 fw-bold <?php echo $total_profit >= 0 ? 'text-primary' : 'text-warning'; ?>">
                                    <?php echo ($total_profit >= 0 ? '+' : '') . number_format($total_profit, 0, ',', '.'); ?>
                                </div>
                                <div class="small <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $total_profit >= 0 ? '▲ Lãi' : '▼ Lỗ'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <a href="reports/index.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-bar-chart-line"></i> Xem báo cáo chi tiết
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Thông báo -->
            <div class="col-lg-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-bell text-warning"></i> Thông báo
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <?php if ($unread_notif > 0): ?>
                        <div class="text-center">
                            <div class="display-4 fw-bold text-warning"><?php echo $unread_notif; ?></div>
                            <div class="text-muted">thông báo chưa đọc</div>
                            <a href="notifications/index.php" class="btn btn-warning btn-sm mt-2">
                                <i class="bi bi-bell-fill"></i> Xem ngay
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="bi bi-check-circle-fill text-success fs-1"></i>
                            <div class="mt-2">Không có thông báo mới</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Biểu đồ ────────────────────────────── -->
        <div class="row mb-4">
            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-bar-chart text-primary"></i> Doanh thu vs Chi phí (6 tháng gần nhất)
                    </div>
                    <div class="card-body">
                        <canvas id="chartRevenue" height="100"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-pie-chart text-info"></i> Phân bổ trạng thái lô hàng
                    </div>
                    <div class="card-body">
                        <canvas id="chartStatus" style="max-height:220px;"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Trạng thái lô hàng ──────────────────── -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-list-check text-info"></i> Trạng thái lô hàng
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($status_rows as $sr): ?>
                            <div class="col-6 col-md-2">
                                <div class="p-3 rounded text-center" style="background:<?php echo $sr['color']; ?>22; border:1px solid <?php echo $sr['color']; ?>44;">
                                    <div class="fw-bold fs-3" style="color:<?php echo $sr['color']; ?>;">
                                        <?php echo $sr['count']; ?>
                                    </div>
                                    <small class="text-muted"><?php echo $sr['label']; ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="col-6 col-md-2">
                                <div class="p-3 rounded text-center" style="background:#dc354522; border:1px solid #dc354544;">
                                    <div class="fw-bold fs-3 text-danger">
                                        <?php echo $stats['shipments_locked']; ?>
                                    </div>
                                    <small class="text-muted">Đã khóa</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Lô hàng mới nhất ──────────────────── -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> 5 Lô hàng mới nhất</h5>
                        <a href="shipments/index.php" class="btn btn-light btn-sm">Xem tất cả</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Job No</th>
                                        <th>Khách hàng</th>
                                        <th>HAWB/MAWB</th>
                                        <th>Trạng thái</th>
                                        <th>Ngày tạo</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $statusColors = [
                                    'pending' => 'warning', 'in_transit' => 'primary',
                                    'arrived' => 'info', 'cleared' => 'success',
                                    'delivered' => 'dark', 'cancelled' => 'danger'
                                ];
                                $statusTexts = [
                                    'pending' => 'Chờ xử lý', 'in_transit' => 'Đang vận chuyển',
                                    'arrived' => 'Đã đến', 'cleared' => 'Đã thông quan',
                                    'delivered' => 'Đã giao', 'cancelled' => 'Hủy'
                                ];
                                if ($recent_shipments->num_rows > 0):
                                    while ($row = $recent_shipments->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['job_no']); ?></strong></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row['customer_short_name'] ?? '—'); ?></span></td>
                                    <td><small><?php echo htmlspecialchars(($row['hawb'] ?? '') . ' / ' . ($row['mawb'] ?? '')); ?></small></td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusColors[$row['status']] ?? 'secondary'; ?>">
                                            <?php echo $statusTexts[$row['status']] ?? $row['status']; ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></small></td>
                                    <td>
                                        <a href="shipments/view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Chưa có lô hàng nào</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Thao tác nhanh ────────────────────── -->
        <div class="row mb-4">
            <div class="col-12 mb-3">
                <h5><i class="bi bi-lightning"></i> Thao tác nhanh</h5>
            </div>
            <div class="col-md-3">
                <a href="shipments/add.php" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-plus-circle"></i> Thêm lô hàng mới
                </a>
            </div>
            <div class="col-md-3">
                <a href="customers/add.php" class="btn btn-info w-100 mb-3">
                    <i class="bi bi-person-plus"></i> Thêm khách hàng
                </a>
            </div>
            <div class="col-md-3">
                <a href="suppliers/add.php" class="btn btn-success w-100 mb-3">
                    <i class="bi bi-building-add"></i> Thêm nhà cung cấp
                </a>
            </div>
            <?php if (isAdmin()): ?>
            <div class="col-md-3">
                <a href="cost_codes/add.php" class="btn btn-warning w-100 mb-3">
                    <i class="bi bi-tag"></i> Thêm mã chi phí
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'partials/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Biểu đồ cột doanh thu vs chi phí
    const ctxRev = document.getElementById('chartRevenue');
    if (ctxRev) {
        new Chart(ctxRev, {
            type: 'bar',
            data: {
                labels: <?php echo $chart_labels; ?>,
                datasets: [
                    {
                        label: 'Doanh thu',
                        data: <?php echo $chart_sells; ?>,
                        backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        borderColor: '#198754',
                        borderWidth: 1,
                    },
                    {
                        label: 'Chi phí',
                        data: <?php echo $chart_costs; ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 1,
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    // Biểu đồ Doughnut trạng thái
    const ctxSt = document.getElementById('chartStatus');
    if (ctxSt) {
        new Chart(ctxSt, {
            type: 'doughnut',
            data: {
                labels: <?php echo $doughnut_labels; ?>,
                datasets: [{
                    data: <?php echo $doughnut_data; ?>,
                    backgroundColor: <?php echo $doughnut_colors; ?>,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
            }
        });
    }
    </script>
</body>
</html>
