<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/database.php';

// Nếu đã đăng nhập, chuyển đến dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// ─── Rate limiting ───────────────────────
if (!isset($_SESSION['login_attempts']))      $_SESSION['login_attempts']      = 0;
if (!isset($_SESSION['login_lockout_until'])) $_SESSION['login_lockout_until'] = 0;

$is_locked         = time() < $_SESSION['login_lockout_until'];
$remaining_seconds = max(0, $_SESSION['login_lockout_until'] - time());

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($is_locked) {
        $error = "Quá nhiều lần đăng nhập sai. Vui lòng thử lại sau " . ceil($remaining_seconds / 60) . " phút.";
    } elseif (!verifyCsrfToken()) {
        $error = "Yêu cầu không hợp lệ. Vui lòng tải lại trang.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $conn = getDBConnection();

        // Tìm user theo username (tương thích cả md5 và bcrypt)
        $stmt = $conn->prepare("SELECT id, username, full_name, role, status, password, password_version FROM accounts WHERE username = ? AND status = 'active'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        $login_ok = false;
        $user     = null;
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $pv   = $user['password_version'] ?? 'md5';

            if ($pv === 'bcrypt') {
                $login_ok = password_verify($password, $user['password']);
            } else {
                // Legacy MD5 - tương thích ngược
                $login_ok = ($user['password'] === md5($password));
                if ($login_ok) {
                    // Tự động nâng cấp lên bcrypt
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE accounts SET password=?, password_version='bcrypt' WHERE id=?");
                    $upd->bind_param("si", $new_hash, $user['id']);
                    $upd->execute();
                    $upd->close();
                }
            }
        }

        if ($login_ok) {
            // Reset rate limit
            $_SESSION['login_attempts']      = 0;
            $_SESSION['login_lockout_until'] = 0;

            // Regenerate session ID để tránh session fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            logActivity($conn, 'login', 'auth', 'Đăng nhập thành công');

            $stmt->close();
            $conn->close();

            header("Location: dashboard.php");
            exit();
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_lockout_until'] = time() + 15 * 60;
                $error = "Bạn đã nhập sai quá 5 lần. Tài khoản bị khóa tạm thời 15 phút.";
            } else {
                $remaining_tries = 5 - $_SESSION['login_attempts'];
                $error = "Tên đăng nhập hoặc mật khẩu không đúng! Còn " . $remaining_tries . " lần thử.";
            }
        }

        $stmt->close();
        $conn->close();
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --lipro-primary: #1e3a5f;
            --lipro-accent:  #2d6a9f;
        }
        body {
            background: linear-gradient(135deg, var(--lipro-primary) 0%, var(--lipro-accent) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-container { max-width: 420px; margin: 0 auto; }
        .card {
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            border: none;
        }
        .login-logo {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--lipro-primary), var(--lipro-accent));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 12px;
            font-size: 2rem; color: white;
        }
        .btn-login {
            background: linear-gradient(135deg, var(--lipro-primary), var(--lipro-accent));
            border: none; color: white;
        }
        .btn-login:hover { opacity: 0.9; color: white; }
        .input-group .btn-outline-secondary { border-color: #ced4da; }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="card">
                <div class="card-body p-5">
                    <div class="login-logo">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <h3 class="text-center fw-bold mb-1" style="color: var(--lipro-primary);">LIPRO LOGISTICS</h3>
                    <p class="text-center text-muted mb-4">Hệ thống quản lý Forwarder</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_locked && !$error): ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2">
                            <i class="bi bi-lock-fill"></i>
                            Tài khoản tạm khóa. Còn <?php echo ceil($remaining_seconds / 60); ?> phút.
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-person"></i> Tên đăng nhập
                            </label>
                            <input type="text" name="username" class="form-control form-control-lg"
                                   placeholder="Nhập tên đăng nhập" required autofocus
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-lock"></i> Mật khẩu
                            </label>
                            <div class="input-group">
                                <input type="password" name="password" id="passwordInput"
                                       class="form-control form-control-lg"
                                       placeholder="Nhập mật khẩu" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mb-3">
                            <a href="#" class="text-decoration-none small text-muted">
                                <i class="bi bi-question-circle"></i> Quên mật khẩu?
                            </a>
                        </div>

                        <button type="submit" class="btn btn-login btn-lg w-100" id="submitBtn"
                                <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <span id="submitNormal">
                                <i class="bi bi-box-arrow-in-right"></i> Đăng nhập
                            </span>
                            <span id="submitSpinner" class="d-none">
                                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                Đang xử lý...
                            </span>
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <small class="text-muted">
                            <i class="bi bi-shield-check text-success"></i>
                            LIPRO LOGISTICS &copy; <?php echo date('Y'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePassword').addEventListener('click', function () {
            const input = document.getElementById('passwordInput');
            const icon  = document.getElementById('togglePasswordIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function () {
            document.getElementById('submitNormal').classList.add('d-none');
            document.getElementById('submitSpinner').classList.remove('d-none');
            document.getElementById('submitBtn').disabled = true;
        });
    </script>
</body>
</html>
