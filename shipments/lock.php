<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$id     = isset($_GET['id'])       ? intval($_GET['id'])   : 0;
$action = isset($_GET['action'])   ? trim($_GET['action']) : '';

// redirect sau khi xong — mặc định về view.php
$redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : 'view.php?id=' . $id;
if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?\=\&\%]+$/', $redirect)) {
    $redirect = 'view.php?id=' . $id;
}

if ($id == 0 || !in_array($action, ['lock', 'unlock'])) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*, c.company_name, c.short_name AS customer_short 
                         FROM shipments s 
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: index.php");
    exit();
}

// Tổng Cost & Sell
$total_cost = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_costs WHERE shipment_id=$id")->fetch_assoc()['t'];
$total_sell = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells WHERE shipment_id=$id")->fetch_assoc()['t'];
$profit     = $total_sell - $total_cost;

$error = '';

// Xử lý KHÓA
if ($action == 'lock' && $shipment['is_locked'] == 'no') {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $invoice_no   = trim($_POST['invoice_no']);
        $invoice_date = trim($_POST['invoice_date']);

        if (empty($invoice_no)) {
            $error = 'Vui lòng nhập số hóa đơn!';
        } elseif (empty($invoice_date)) {
            $error = 'Vui lòng chọn ngày xuất hóa đơn!';
        } else {
            $locked_at = date('Y-m-d H:i:s');
            $stmt_lock = $conn->prepare("UPDATE shipments SET 
                is_locked='yes', invoice_no=?, invoice_date=?, locked_at=?, locked_by=?
                WHERE id=?");
            $stmt_lock->bind_param("sssii", $invoice_no, $invoice_date, $locked_at, $_SESSION['user_id'], $id);
            if ($stmt_lock->execute()) {
                $conn->close();
                $sep = strpos($redirect, '?') !== false ? '&' : '?';
                header('Location: ' . $redirect . $sep . 'success=locked');
                exit();
            } else {
                $error = 'Lỗi khóa lô hàng: ' . $conn->error;
            }
        }
    }
}

// Xử lý MỞ KHÓA
if ($action == 'unlock' && $shipment['is_locked'] == 'yes') {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $stmt_unlock = $conn->prepare("UPDATE shipments SET 
            is_locked='no', invoice_no=NULL, invoice_date=NULL,
            locked_at=NULL, locked_by=NULL
            WHERE id=?");
        $stmt_unlock->bind_param("i", $id);
        if ($stmt_unlock->execute()) {
            $conn->close();
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'success=unlocked');
            exit();
        } else {
            $error = 'Lỗi mở khóa: ' . $conn->error;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $action == 'lock' ? 'Khóa' : 'Mở khóa'; ?> Lô hàng - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
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

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-6">

                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Lô hàng</a></li>
                        <li class="breadcrumb-item">
                            <a href="view.php?id=<?php echo $id; ?>">
                                <?php echo htmlspecialchars($shipment['job_no']); ?>
                            </a>
                        </li>
                        <li class="breadcrumb-item active">
                            <?php echo $action == 'lock' ? 'Khóa lô hàng' : 'Mở khóa lô hàng'; ?>
                        </li>
                    </ol>
                </nav>

                <!-- Thông tin lô hàng -->
                <div class="card shadow-sm mb-3 border-0">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1 text-primary fw-bold">
                                    <i class="bi bi-box"></i> <?php echo htmlspecialchars($shipment['job_no']); ?>
                                </h5>
                                <small class="text-muted d-block">
                                    <i class="bi bi-people"></i>
                                    <span class="badge bg-info text-dark">
                                        <?php echo htmlspecialchars($shipment['customer_short']); ?>
                                    </span>
                                    <?php echo htmlspecialchars($shipment['company_name']); ?>
                                </small>
                                <small class="text-muted">
                                    MAWB: <strong><?php echo htmlspecialchars($shipment['mawb']); ?></strong>
                                    &nbsp;|&nbsp;
                                    HAWB: <strong><?php echo htmlspecialchars($shipment['hawb']); ?></strong>
                                </small>
                            </div>
                            <span class="badge <?php echo $shipment['is_locked'] == 'yes' ? 'bg-danger' : 'bg-success'; ?> fs-6 p-2">
                                <i class="bi bi-<?php echo $shipment['is_locked'] == 'yes' ? 'lock-fill' : 'unlock'; ?>"></i>
                                <?php echo $shipment['is_locked'] == 'yes' ? 'Đã khóa' : 'Chưa khóa'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tóm tắt tài chính -->
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="card border-0 bg-danger text-white text-center p-2 rounded">
                            <small><i class="bi bi-cash-stack"></i> Tổng COST</small>
                            <strong class="d-block"><?php echo number_format($total_cost, 0, ',', '.'); ?></strong>
                            <small>VND</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 bg-success text-white text-center p-2 rounded">
                            <small><i class="bi bi-currency-dollar"></i> Tổng SELL</small>
                            <strong class="d-block"><?php echo number_format($total_sell, 0, ',', '.'); ?></strong>
                            <small>VND</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 <?php echo $profit >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white text-center p-2 rounded">
                            <small><i class="bi bi-graph-up"></i> Lợi nhuận</small>
                            <strong class="d-block">
                                <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?>
                            </strong>
                            <small><?php echo $profit >= 0 ? '✅ Lãi' : '❌ Lỗ'; ?></small>
                        </div>
                    </div>
                </div>

                <!-- FORM KHÓA -->
                <?php if ($action == 'lock' && $shipment['is_locked'] == 'no'): ?>
                <div class="card shadow border-danger">
                    <div class="card-header bg-danger text-white py-2">
                        <h5 class="mb-0"><i class="bi bi-lock-fill"></i> Khóa lô hàng</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger py-2">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-warning py-2 mb-3">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Lưu ý:</strong> Sau khi khóa:
                            <ul class="mb-0 mt-1">
                                <li>Lô hàng <strong>không thể sửa</strong> bởi User thường</li>
                                <li>Chỉ <strong>Admin</strong> mới có thể mở khóa hoặc chỉnh sửa</li>
                                <li>Thông tin hóa đơn sẽ được lưu lại</li>
                            </ul>
                        </div>

                        <form method="POST"
                              action="lock.php?id=<?php echo $id; ?>&action=lock&redirect=<?php echo urlencode($redirect); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-receipt"></i> Số hóa đơn
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="invoice_no" class="form-control form-control-lg"
                                       placeholder="VD: HD-2024-001"
                                       value="<?php echo isset($_POST['invoice_no']) ? htmlspecialchars($_POST['invoice_no']) : ''; ?>"
                                       required autofocus>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-calendar"></i> Ngày xuất hóa đơn
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="date" name="invoice_date" class="form-control form-control-lg"
                                       value="<?php echo isset($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d'); ?>"
                                       required>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-secondary btn-lg">
                                    <i class="bi bi-arrow-left"></i> Hủy
                                </a>
                                <button type="submit" class="btn btn-danger btn-lg"
                                        onclick="return confirm('Bạn có chắc muốn KHÓA lô hàng <?php echo htmlspecialchars(addslashes($shipment['job_no'])); ?>?\n\nSau khi khóa, User thường không thể sửa!')">
                                    <i class="bi bi-lock-fill"></i> Xác nhận Khóa
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- FORM MỞ KHÓA -->
                <?php elseif ($action == 'unlock' && $shipment['is_locked'] == 'yes'): ?>
                <div class="card shadow border-success">
                    <div class="card-header bg-success text-white py-2">
                        <h5 class="mb-0"><i class="bi bi-unlock-fill"></i> Mở khóa lô hàng</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger py-2">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-info py-2 mb-3">
                            <i class="bi bi-info-circle-fill"></i>
                            Lô hàng này đang bị khóa với thông tin sau:
                        </div>

                        <table class="table table-bordered mb-3">
                            <tr>
                                <th class="bg-light" width="45%">
                                    <i class="bi bi-receipt"></i> Số hóa đơn:
                                </th>
                                <td class="fw-bold text-danger fs-5">
                                    <?php echo htmlspecialchars($shipment['invoice_no'] ?? '—'); ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-light">
                                    <i class="bi bi-calendar"></i> Ngày xuất HĐ:
                                </th>
                                <td>
                                    <?php echo !empty($shipment['invoice_date']) && $shipment['invoice_date'] !== '0000-00-00'
                                        ? date('d/m/Y', strtotime($shipment['invoice_date']))
                                        : '—'; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-light">
                                    <i class="bi bi-clock"></i> Thời gian khóa:
                                </th>
                                <td>
                                    <?php echo !empty($shipment['locked_at'])
                                        ? date('d/m/Y H:i:s', strtotime($shipment['locked_at']))
                                        : '—'; ?>
                                </td>
                            </tr>
                        </table>

                        <div class="alert alert-warning py-2 mb-4">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Lưu ý:</strong> Sau khi mở khóa:
                            <ul class="mb-0 mt-1">
                                <li>Thông tin hóa đơn
                                    (<strong><?php echo htmlspecialchars($shipment['invoice_no'] ?? ''); ?></strong>)
                                    sẽ bị <strong>xóa</strong>
                                </li>
                                <li>Lô hàng có thể chỉnh sửa lại</li>
                            </ul>
                        </div>

                        <form method="POST"
                              action="lock.php?id=<?php echo $id; ?>&action=unlock&redirect=<?php echo urlencode($redirect); ?>">
                            <div class="d-flex justify-content-between">
                                <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-secondary btn-lg">
                                    <i class="bi bi-arrow-left"></i> Hủy
                                </a>
                                <button type="submit" class="btn btn-success btn-lg"
                                        onclick="return confirm('Bạn có chắc muốn MỞ KHÓA lô hàng <?php echo htmlspecialchars(addslashes($shipment['job_no'])); ?>?\n\nThông tin hóa đơn sẽ bị xóa!')">
                                    <i class="bi bi-unlock-fill"></i> Xác nhận Mở khóa
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LỖI: Trạng thái không hợp lệ -->
                <?php else: ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    Thao tác không hợp lệ!
                    <?php if ($action == 'lock' && $shipment['is_locked'] == 'yes'): ?>
                        Lô hàng này đã được khóa rồi.
                    <?php elseif ($action == 'unlock' && $shipment['is_locked'] == 'no'): ?>
                        Lô hàng này chưa bị khóa.
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($redirect); ?>" class="alert-link">Quay lại</a>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>