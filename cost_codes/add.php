<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = strtoupper(trim($_POST['code']));
    $description = trim($_POST['description']);
    $notes = trim($_POST['notes']);
    $status = $_POST['status'];
    
    if (empty($code) || empty($description)) {
        $error = 'Vui lòng nhập đầy đủ Mã và Nội dung!';
    } else {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT id FROM cost_codes WHERE code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Mã này đã tồn tại!';
        } else {
            $stmt = $conn->prepare("INSERT INTO cost_codes (code, description, notes, status, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $code, $description, $notes, $status, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                header("Location: index.php?success=added");
                exit();
            } else {
                $error = 'Có lỗi xảy ra: ' . $conn->error;
            }
        }
        
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Mã chi phí - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php
    $conn = $conn ?? getDBConnection();
    $page_title = 'Thêm Mã chi phí';
    include '../partials/sidebar.php';
    ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

    <div class="container-fluid px-4 py-3">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Mã chi phí</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Mã <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control text-uppercase" required maxlength="20" placeholder="VD: THC, DOC" value="<?php echo isset($_POST['code']) ? htmlspecialchars($_POST['code']) : ''; ?>">
                                <small class="text-muted">Nhập chữ IN HOA, không dấu, không khoảng trắng</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nội dung <span class="text-danger">*</span></label>
                                <input type="text" name="description" class="form-control" required placeholder="VD: Terminal Handling Charge" value="<?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ghi chú</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Ghi chú thêm..."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active">Hoạt động</option>
                                    <option value="inactive">Ngưng hoạt động</option>
                                </select>
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
    </div><!-- /container-fluid -->
    </div><!-- /main-content -->

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>