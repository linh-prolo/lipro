<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$conn = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Yêu cầu không hợp lệ.';
    } else {
        $code   = strtoupper(trim($_POST['currency_code']));
        $name   = trim($_POST['currency_name']);
        $rate   = floatval($_POST['rate_to_vnd']);
        $date   = trim($_POST['effective_date']);
        $uid    = intval($_SESSION['user_id']);

        if (empty($code) || $rate <= 0) {
            $error = 'Vui lòng nhập đầy đủ thông tin!';
        } else {
            $stmt = $conn->prepare("INSERT INTO exchange_rates (currency_code, currency_name, rate_to_vnd, effective_date, updated_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param("ssdsi", $code, $name, $rate, $date, $uid);
            if ($stmt->execute()) {
                logActivity($conn, 'create', 'exchange_rates', "Thêm tỷ giá: $code");
                $stmt->close();
                $conn->close();
                header("Location: index.php?success=added");
                exit();
            } else {
                $error = 'Có lỗi xảy ra: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

$csrf_token = generateCsrfToken();
$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Tỷ giá - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php
    $conn = getDBConnection();
    include '../partials/navbar.php';
    ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm tỷ giá mới</h5>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mã tiền tệ <span class="text-danger">*</span></label>
                                <input type="text" name="currency_code" class="form-control text-uppercase"
                                       placeholder="USD, EUR, CNY..." required maxlength="10"
                                       value="<?php echo htmlspecialchars($_POST['currency_code'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tên tiền tệ</label>
                                <input type="text" name="currency_name" class="form-control"
                                       placeholder="Đô la Mỹ..."
                                       value="<?php echo htmlspecialchars($_POST['currency_name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tỷ giá (VND) <span class="text-danger">*</span></label>
                                <input type="number" name="rate_to_vnd" class="form-control"
                                       min="0.01" step="0.01" required
                                       value="<?php echo htmlspecialchars($_POST['rate_to_vnd'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Ngày hiệu lực</label>
                                <input type="date" name="effective_date" class="form-control"
                                       value="<?php echo htmlspecialchars($_POST['effective_date'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Lưu</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
