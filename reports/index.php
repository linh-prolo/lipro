<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Bộ lọc năm/tháng
$filter_year  = intval($_GET['year']  ?? date('Y'));
$filter_month = intval($_GET['month'] ?? 0); // 0 = tất cả

// ─── Xuất CSV ───────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-' . $filter_year . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Tháng', 'Số lô hàng', 'Tổng chi phí (VND)', 'Tổng doanh thu (VND)', 'Lợi nhuận (VND)']);

    $stmt2 = $conn->prepare("
        SELECT
            DATE_FORMAT(s.created_at, '%Y-%m') as month_year,
            COUNT(DISTINCT s.id) as shipment_count,
            COALESCE(SUM(c_sub.total_cost),0) as total_cost,
            COALESCE(SUM(s_sub.total_sell),0) as total_sell
        FROM shipments s
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_cost FROM shipment_costs GROUP BY shipment_id) c_sub ON c_sub.shipment_id = s.id
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_sell FROM shipment_sells GROUP BY shipment_id) s_sub ON s_sub.shipment_id = s.id
        WHERE s.deleted_at IS NULL AND YEAR(s.created_at) = ?
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
        ORDER BY month_year ASC");
    $stmt2->bind_param("i", $filter_year);
    $stmt2->execute();
    $rows2 = $stmt2->get_result();
    while ($r = $rows2->fetch_assoc()) {
        fputcsv($out, [
            $r['month_year'], $r['shipment_count'],
            $r['total_cost'], $r['total_sell'],
            $r['total_sell'] - $r['total_cost'],
        ]);
    }
    $stmt2->close();
    fclose($out);
    $conn->close();
    exit();
}

// ─── Tab 1: Doanh thu / Chi phí theo tháng ─────────────
// Dùng subquery để tránh Cartesian product khi JOIN 2 bảng cùng lúc
$sql_monthly = "
    SELECT
        DATE_FORMAT(s.created_at, '%Y-%m') as month_year,
        COUNT(DISTINCT s.id) as shipment_count,
        COALESCE(SUM(c_sub.total_cost),0) as total_cost,
        COALESCE(SUM(s_sub.total_sell),0) as total_sell
    FROM shipments s
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_cost FROM shipment_costs GROUP BY shipment_id) c_sub ON c_sub.shipment_id = s.id
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_sell FROM shipment_sells GROUP BY shipment_id) s_sub ON s_sub.shipment_id = s.id
    WHERE s.deleted_at IS NULL AND YEAR(s.created_at) = ?";
if ($filter_month > 0) {
    $sql_monthly .= " AND MONTH(s.created_at) = ?";
}
$sql_monthly .= " GROUP BY DATE_FORMAT(s.created_at, '%Y-%m') ORDER BY month_year ASC";

$stmt = $conn->prepare($sql_monthly);
if ($filter_month > 0) {
    $stmt->bind_param("ii", $filter_year, $filter_month);
} else {
    $stmt->bind_param("i", $filter_year);
}
$stmt->execute();
$monthly_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Tab 2: Theo khách hàng ─────────────────────────────
$stmt = $conn->prepare("
    SELECT
        c.short_name, c.company_name,
        COUNT(DISTINCT s.id) as shipment_count,
        COALESCE(SUM(c_sub.total_cost),0) as total_cost,
        COALESCE(SUM(s_sub.total_sell),0) as total_sell
    FROM customers c
    LEFT JOIN shipments s ON s.customer_id = c.id AND s.deleted_at IS NULL AND YEAR(s.created_at) = ?
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_cost FROM shipment_costs GROUP BY shipment_id) c_sub ON c_sub.shipment_id = s.id
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_sell FROM shipment_sells GROUP BY shipment_id) s_sub ON s_sub.shipment_id = s.id
    GROUP BY c.id, c.short_name, c.company_name
    HAVING shipment_count > 0
    ORDER BY total_sell DESC LIMIT 50");
$stmt->bind_param("i", $filter_year);
$stmt->execute();
$customer_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Tab 3: Theo tuyến hàng (POL → POD) ────────────────
$stmt = $conn->prepare("
    SELECT
        COALESCE(NULLIF(s.pol,''),'?') as origin,
        COALESCE(NULLIF(s.pod,''),'?') as destination,
        COUNT(DISTINCT s.id) as shipment_count,
        COALESCE(SUM(c_sub.total_cost),0) as total_cost,
        COALESCE(SUM(s_sub.total_sell),0) as total_sell
    FROM shipments s
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_cost FROM shipment_costs GROUP BY shipment_id) c_sub ON c_sub.shipment_id = s.id
    LEFT JOIN (SELECT shipment_id, SUM(total_amount) as total_sell FROM shipment_sells GROUP BY shipment_id) s_sub ON s_sub.shipment_id = s.id
    WHERE s.deleted_at IS NULL AND YEAR(s.created_at) = ?
    GROUP BY s.pol, s.pod
    ORDER BY shipment_count DESC LIMIT 30");
$stmt->bind_param("i", $filter_year);
$stmt->execute();
$route_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Tab 4: Theo trạng thái ─────────────────────────────
$status_result = $conn->query("SELECT status, COUNT(*) as cnt FROM shipments WHERE deleted_at IS NULL GROUP BY status ORDER BY cnt DESC");
$status_data   = $status_result ? $status_result->fetch_all(MYSQLI_ASSOC) : [];
$total_count   = array_sum(array_column($status_data, 'cnt'));

$conn->close();

// Chuẩn bị dữ liệu Chart.js
$chart_months = json_encode(array_column($monthly_data, 'month_year'));
$chart_costs  = json_encode(array_map(fn($r) => floatval($r['total_cost']), $monthly_data));
$chart_sells  = json_encode(array_map(fn($r) => floatval($r['total_sell']), $monthly_data));

$years = range(date('Y') - 3, date('Y') + 1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php
    $conn = getDBConnection();
    include '../partials/navbar.php';
    ?>

    <div class="container-fluid mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <h4><i class="bi bi-bar-chart-line"></i> Báo cáo thống kê</h4>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-2">
                    <select name="year" class="form-select form-select-sm" style="width:100px;">
                        <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $filter_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="month" class="form-select form-select-sm" style="width:130px;">
                        <option value="0" <?php echo $filter_month == 0 ? 'selected' : ''; ?>>Tất cả tháng</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $filter_month ? 'selected' : ''; ?>>Tháng <?php echo $m; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Lọc</button>
                    <a href="?year=<?php echo $filter_year; ?>&month=<?php echo $filter_month; ?>&export=csv" class="btn btn-success btn-sm">
                        <i class="bi bi-download"></i> Xuất CSV
                    </a>
                </form>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" id="reportTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-monthly"><i class="bi bi-calendar-month"></i> Doanh thu / Chi phí</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-customer"><i class="bi bi-people"></i> Theo khách hàng</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-route"><i class="bi bi-geo-alt"></i> Theo tuyến hàng</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-status"><i class="bi bi-pie-chart"></i> Theo trạng thái</a></li>
        </ul>

        <div class="tab-content">
            <!-- Tab 1 -->
            <div class="tab-pane fade show active" id="tab-monthly">
                <div class="card shadow-sm mb-4">
                    <div class="card-body"><canvas id="chartMonthly" height="80"></canvas></div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tháng</th><th>Số lô hàng</th>
                                        <th>Tổng chi phí</th><th>Tổng doanh thu</th>
                                        <th>Lợi nhuận</th><th>Tỷ lệ LN</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($monthly_data)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                                <?php else:
                                $sum_cost = $sum_sell = 0;
                                foreach ($monthly_data as $r):
                                    $profit = $r['total_sell'] - $r['total_cost'];
                                    $margin = $r['total_sell'] > 0 ? round($profit / $r['total_sell'] * 100, 1) : 0;
                                    $sum_cost += $r['total_cost'];
                                    $sum_sell += $r['total_sell'];
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo $r['month_year']; ?></td>
                                    <td><?php echo number_format($r['shipment_count']); ?></td>
                                    <td class="text-danger"><?php echo number_format($r['total_cost'], 0, ',', '.'); ?> VND</td>
                                    <td class="text-success"><?php echo number_format($r['total_sell'], 0, ',', '.'); ?> VND</td>
                                    <td class="<?php echo $profit >= 0 ? 'text-primary fw-semibold' : 'text-danger fw-semibold'; ?>">
                                        <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?> VND
                                    </td>
                                    <td><span class="badge <?php echo $margin >= 0 ? 'bg-success' : 'bg-danger'; ?>"><?php echo $margin; ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Tổng cộng</td><td>—</td>
                                    <td><?php echo number_format($sum_cost, 0, ',', '.'); ?> VND</td>
                                    <td><?php echo number_format($sum_sell, 0, ',', '.'); ?> VND</td>
                                    <td class="<?php echo ($sum_sell-$sum_cost)>=0?'text-primary':'text-danger'; ?>">
                                        <?php echo ($sum_sell-$sum_cost>=0?'+':'').number_format($sum_sell-$sum_cost, 0, ',', '.'); ?> VND
                                    </td>
                                    <td>—</td>
                                </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2 -->
            <div class="tab-pane fade" id="tab-customer">
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr><th>#</th><th>Khách hàng</th><th>Số lô</th><th>Tổng chi phí</th><th>Tổng doanh thu</th><th>Lợi nhuận</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($customer_data)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                                <?php else: ?>
                                <?php foreach ($customer_data as $i => $r):
                                    $profit = $r['total_sell'] - $r['total_cost'];
                                ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($r['short_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($r['company_name'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo number_format($r['shipment_count']); ?></td>
                                    <td><?php echo number_format($r['total_cost'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($r['total_sell'], 0, ',', '.'); ?></td>
                                    <td class="<?php echo $profit>=0?'text-primary fw-semibold':'text-danger fw-semibold'; ?>">
                                        <?php echo ($profit>=0?'+':'').number_format($profit,0,',','.'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3 -->
            <div class="tab-pane fade" id="tab-route">
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr><th>#</th><th>Tuyến hàng (POL → POD)</th><th>Số lô</th><th>Tổng chi phí</th><th>Tổng doanh thu</th><th>Lợi nhuận</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($route_data)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                                <?php else: ?>
                                <?php foreach ($route_data as $i => $r):
                                    $profit = $r['total_sell'] - $r['total_cost'];
                                ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td>
                                        <span class="badge bg-info text-dark me-1"><?php echo htmlspecialchars($r['origin']); ?></span>
                                        <i class="bi bi-arrow-right text-muted"></i>
                                        <span class="badge bg-primary ms-1"><?php echo htmlspecialchars($r['destination']); ?></span>
                                    </td>
                                    <td><?php echo number_format($r['shipment_count']); ?></td>
                                    <td><?php echo number_format($r['total_cost'],0,',','.'); ?></td>
                                    <td><?php echo number_format($r['total_sell'],0,',','.'); ?></td>
                                    <td class="<?php echo $profit>=0?'text-primary fw-semibold':'text-danger fw-semibold'; ?>">
                                        <?php echo ($profit>=0?'+':'').number_format($profit,0,',','.'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 4 -->
            <div class="tab-pane fade" id="tab-status">
                <div class="row">
                    <div class="col-md-5">
                        <div class="card shadow-sm"><div class="card-body"><canvas id="chartStatus" style="max-height:300px;"></canvas></div></div>
                    </div>
                    <div class="col-md-7">
                        <div class="card shadow-sm">
                            <div class="card-body p-0">
                                <table class="table table-hover mb-0">
                                    <thead><tr><th>Trạng thái</th><th>Số lô</th><th>Tỷ lệ</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($status_data as $r):
                                        $pct = $total_count > 0 ? round($r['cnt']/$total_count*100,1) : 0;
                                    ?>
                                    <tr>
                                        <td><span class="badge badge-status-<?php echo $r['status']; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                                        <td><?php echo number_format($r['cnt']); ?></td>
                                        <td>
                                            <div class="progress" style="height:8px;width:100px;">
                                                <div class="progress-bar" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $pct; ?>%</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const ctxMonthly = document.getElementById('chartMonthly');
    if (ctxMonthly) {
        new Chart(ctxMonthly, {
            type: 'bar',
            data: {
                labels: <?php echo $chart_months; ?>,
                datasets: [
                    { label: 'Doanh thu', data: <?php echo $chart_sells; ?>, backgroundColor: 'rgba(25,135,84,0.7)', borderColor:'#198754', borderWidth:1 },
                    { label: 'Chi phí',   data: <?php echo $chart_costs; ?>,  backgroundColor: 'rgba(220,53,69,0.7)',  borderColor:'#dc3545', borderWidth:1 }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });
    }
    const ctxStatus = document.getElementById('chartStatus');
    if (ctxStatus) {
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($status_data, 'status')); ?>,
                datasets: [{ data: <?php echo json_encode(array_column($status_data, 'cnt')); ?>,
                    backgroundColor: ['#ffc107','#0d6efd','#6f42c1','#fd7e14','#198754','#dc3545'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'right' } } }
        });
    }
    </script>
</body>
</html>
