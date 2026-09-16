<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();
$uid  = intval($_SESSION['user_id']);

$error   = '';
$success = '';

// Lấy thông tin user
$stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Yêu cầu không hợp lệ. Vui lòng thử lại.';
    } else {
        $old_password     = $_POST['old_password'];
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Vui lòng điền đầy đủ thông tin!';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Mật khẩu mới xác nhận không khớp!';
        } elseif (strlen($new_password) < 6) {
            $error = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
        } else {
            // Xác thực mật khẩu cũ
            $pv  = $user['password_version'] ?? 'md5';
            $ok  = ($pv === 'bcrypt')
                 ? password_verify($old_password, $user['password'])
                 : ($user['password'] === md5($old_password));

            if (!$ok) {
                $error = 'Mật khẩu cũ không đúng!';
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pv_new = 'bcrypt';
                $stmt   = $conn->prepare("UPDATE accounts SET password=?, password_version=? WHERE id=?");
                $stmt->bind_param("ssi", $hashed, $pv_new, $uid);
                if ($stmt->execute()) {
                    logActivity($conn, 'change_password', 'profile', 'Đổi mật khẩu thành công');
                    $success = 'Đổi mật khẩu thành công!';
                    // Cập nhật lại $user
                    $user['password']         = $hashed;
                    $user['password_version'] = 'bcrypt';
                } else {
                    $error = 'Có lỗi xảy ra. Vui lòng thử lại.';
                }
                $stmt->close();
            }
        }
    }
}

$csrf_token = generateCsrfToken();
$page_title = 'Hồ sơ cá nhân';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ cá nhân - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php include '../partials/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <h5 class="mb-4"><i class="bi bi-person-gear"></i> Hồ sơ cá nhân</h5>

                <!-- Thông tin cá nhân -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-person-circle"></i> Thông tin tài khoản</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:56px;height:56px;font-size:1.4rem;">
                                <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div>
                                <div class="fw-bold fs-5"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></div>
                            </div>
                        </div>

                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted" style="width:140px;"><i class="bi bi-envelope"></i> Email</td>
                                <td><?php echo htmlspecialchars($user['email'] ?: '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><i class="bi bi-shield-check"></i> Quyền</td>
                                <td>
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <span class="badge bg-danger">Admin</span>
                                    <?php elseif ($user['role'] === 'supplier'): ?>
                                        <span class="badge bg-warning text-dark">Nhà cung cấp</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Staff</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted"><i class="bi bi-shield-lock"></i> Bảo mật</td>
                                <td>
                                    <?php if (($user['password_version'] ?? 'md5') === 'bcrypt'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Mã hóa bcrypt</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> MD5 (nên đổi mật khẩu)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Đổi mật khẩu -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-key"></i> Đổi mật khẩu</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success d-flex align-items-center gap-2">
                                <i class="bi bi-check-circle-fill"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mật khẩu hiện tại <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="old_password" id="oldPw" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePw('oldPw','oldPwIcon')" tabindex="-1">
                                        <i class="bi bi-eye" id="oldPwIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mật khẩu mới <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="newPw" class="form-control" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePw('newPw','newPwIcon')" tabindex="-1">
                                        <i class="bi bi-eye" id="newPwIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Tối thiểu 6 ký tự</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Xác nhận mật khẩu mới <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confirmPw" class="form-control" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePw('confirmPw','confirmPwIcon')" tabindex="-1">
                                        <i class="bi bi-eye" id="confirmPwIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-save"></i> Đổi mật khẩu
                            </button>
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
    </script>
</body>
</html>
