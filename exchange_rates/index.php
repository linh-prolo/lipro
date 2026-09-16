<?php
/*
-- Tạo bảng exchange_rates (chạy 1 lần):
-- CREATE TABLE exchange_rates (
--   id INT AUTO_INCREMENT PRIMARY KEY,
--   currency_code VARCHAR(10) NOT NULL,
--   currency_name VARCHAR(100),
--   rate_to_vnd DECIMAL(15,2) NOT NULL,
--   effective_date DATE,
--   updated_by INT,
--   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--   updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-- );
*/
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Tự tạo bảng nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS exchange_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    currency_code VARCHAR(10) NOT NULL,
    currency_name VARCHAR(100),
    rate_to_vnd DECIMAL(15,2) NOT NULL,
    effective_date DATE,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Dữ liệu mặc định khi bảng rỗng
$check = $conn->query("SELECT COUNT(*) c FROM exchange_rates")->fetch_assoc();
if (intval($check['c']) === 0) {
    $defaults = [
        ['USD', 'Đô la Mỹ',        25450.00, date('Y-m-d')],
        ['EUR', 'Euro',             27800.00, date('Y-m-d')],
        ['CNY', 'Nhân dân tệ',      3520.00,  date('Y-m-d')],
        ['JPY', 'Yên Nhật',           165.00,  date('Y-m-d')],
        ['SGD', 'Đô la Singapore',  19100.00, date('Y-m-d')],
    ];
    $stmt_ins = $conn->prepare("INSERT INTO exchange_rates (currency_code, currency_name, rate_to_vnd, effective_date, updated_by) VALUES (?,?,?,?,?)");
    foreach ($defaults as $d) {
        $uid = $_SESSION['user_id'] ?? null;
        $stmt_ins->bind_param("ssdsi", $d[0], $d[1], $d[2], $d[3], $uid);
        $stmt_ins->execute();
    }
    $stmt_ins->close();
}

// Xóa tỷ giá
if (isset($_GET['delete']) && isAdmin()) {
    $del_id = intval($_GET['delete']);
    $del_stmt = $conn->prepare("DELETE FROM exchange_rates WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: index.php?success=deleted");
    exit();
}

$rates = $conn->query("SELECT er.*, a.full_name as updated_by_name FROM exchange_rates er LEFT JOIN accounts a ON a.id = er.updated_by ORDER BY currency_code")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$page_title = 'Tỷ giá ngoại tệ';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tỷ giá - LIPRO LOGISTICS</title>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="bi bi-currency-exchange"></i> Tỷ giá ngoại tệ</h4>
            <?php if (isAdmin()): ?>
            <a href="add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Thêm tỷ giá
            </a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $_GET['success'] === 'added' ? 'Thêm tỷ giá thành công!' : ($_GET['success'] === 'updated' ? 'Cập nhật thành công!' : 'Xóa thành công!'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row mb-4">
            <?php foreach ($rates as $r): ?>
            <div class="col-md-4 col-lg-3 mb-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="display-6 fw-bold text-primary"><?php echo htmlspecialchars($r['currency_code']); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($r['currency_name']); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="fs-5 fw-semibold"><?php echo number_format($r['rate_to_vnd'], 0, ',', '.'); ?></div>
                                <div class="text-muted" style="font-size:.75rem;">VND</div>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <?php echo $r['effective_date'] ? date('d/m/Y', strtotime($r['effective_date'])) : '—'; ?>
                            </small>
                            <?php if (isAdmin()): ?>
                            <div class="d-flex gap-1">
                                <a href="edit.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="index.php?delete=<?php echo $r['id']; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Xóa tỷ giá này?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Mã tiền tệ</th>
                                <th>Tên</th>
                                <th>Tỷ giá (VND)</th>
                                <th>Ngày hiệu lực</th>
                                <th>Cập nhật bởi</th>
                                <th>Lần cuối cập nhật</th>
                                <?php if (isAdmin()): ?><th>Thao tác</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rates as $r): ?>
                        <tr>
                            <td><span class="badge bg-primary fs-6"><?php echo htmlspecialchars($r['currency_code']); ?></span></td>
                            <td><?php echo htmlspecialchars($r['currency_name']); ?></td>
                            <td class="fw-semibold"><?php echo number_format($r['rate_to_vnd'], 2, ',', '.'); ?></td>
                            <td><?php echo $r['effective_date'] ? date('d/m/Y', strtotime($r['effective_date'])) : '—'; ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($r['updated_by_name'] ?? '—'); ?></td>
                            <td class="text-muted small"><?php echo date('d/m/Y H:i', strtotime($r['updated_at'])); ?></td>
                            <?php if (isAdmin()): ?>
                            <td>
                                <a href="edit.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="index.php?delete=<?php echo $r['id']; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Xóa tỷ giá này?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
