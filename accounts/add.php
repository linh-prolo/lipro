<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Yêu cầu không hợp lệ. Vui lòng thử lại.';
    } else {
        $username         = trim($_POST['username']);
        $password         = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $full_name        = trim($_POST['full_name']);
        $email            = trim($_POST['email']);
        $role             = $_POST['role'];
        $status           = $_POST['status'];

        if (empty($username) || empty($password) || empty($full_name)) {
            $error = 'Vui lòng nhập đầy đủ thông tin bắt buộc!';
        } elseif ($password !== $confirm_password) {
            $error = 'Mật khẩu xác nhận không khớp!';
        } elseif (strlen($password) < 6) {
            $error = 'Mật khẩu phải có ít nhất 6 ký tự!';
        } else {
            $conn = getDBConnection();

            // Kiểm tra username trùng
            $stmt = $conn->prepare("SELECT id FROM accounts WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Tên đăng nhập đã tồn tại!';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pv     = 'bcrypt';
                $stmt   = $conn->prepare("INSERT INTO accounts (username, password, password_version, full_name, email, role, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssi", $username, $hashed, $pv, $full_name, $email, $role, $status, $_SESSION['user_id']);

                if ($stmt->execute()) {
                    logActivity($conn, 'create', 'accounts', "Tạo tài khoản: $username");
                    $stmt->close();
                    $conn->close();
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
}

$conn = getDBConnection();
$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Tài khoản - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php include '../partials/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="d-flex align-items-center mb-3 gap-2">
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Tài khoản mới</h5>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tên đăng nhập <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" required
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                                <small class="text-muted">Chỉ dùng chữ cái, số và dấu gạch dưới</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mật khẩu <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="password" id="passwordInput"
                                           class="form-control" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
                                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Tối thiểu 6 ký tự</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confirmPasswordInput"
                                           class="form-control" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword" tabindex="-1">
                                        <i class="bi bi-eye" id="toggleConfirmPasswordIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Quyền</label>
                                <select name="role" class="form-select">
                                    <option value="user" <?php echo (($_POST['role'] ?? '') === 'user') ? 'selected' : ''; ?>>User (Nhân viên)</option>
                                    <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin (Quản trị viên)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo (($_POST['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Hoạt động</option>
                                    <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Khóa</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Tạo tài khoản
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePw(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
        document.getElementById('togglePassword').addEventListener('click', () => togglePw('passwordInput', 'togglePasswordIcon'));
        document.getElementById('toggleConfirmPassword').addEventListener('click', () => togglePw('confirmPasswordInput', 'toggleConfirmPasswordIcon'));
    </script>
</body>
</html>
