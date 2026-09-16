<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM cost_codes WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}

$cost_code = $result->fetch_assoc();
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = strtoupper(trim($_POST['code']));
    $description = trim($_POST['description']);
    $notes = trim($_POST['notes']);
    $status = $_POST['status'];
    
    if (empty($code) || empty($description)) {
        $error = 'Vui lòng nhập đầy đủ Mã và Nội dung!';
    } else {
        $stmt = $conn->prepare("SELECT id FROM cost_codes WHERE code = ? AND id != ?");
        $stmt->bind_param("si", $code, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Mã này đã tồn tại!';
        } else {
            $stmt = $conn->prepare("UPDATE cost_codes SET code=?, description=?, notes=?, status=? WHERE id=?");
            $stmt->bind_param("ssssi", $code, $description, $notes, $status, $id);
            
            if ($stmt->execute()) {
                header("Location: index.php?success=updated");
                exit();
            } else {
                $error = 'Có lỗi xảy ra: ' . $conn->error;
            }
        }
    }
} else {
    $_POST = $cost_code;
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Sửa Mã chi phí - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Sửa Mã chi phí</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Mã <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control text-uppercase" required maxlength="20" value="<?php echo htmlspecialchars($_POST['code']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nội dung <span class="text-danger">*</span></label>
                                <input type="text" name="description" class="form-control" required value="<?php echo htmlspecialchars($_POST['description']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ghi chú</label>
                                <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['notes']); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo $_POST['status'] == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                    <option value="inactive" <?php echo $_POST['status'] == 'inactive' ? 'selected' : ''; ?>>Ngưng hoạt động</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                                <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Cập nhật</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>