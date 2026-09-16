<?php
require_once '../config/database.php';
checkLogin();

$error = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Lấy thông tin khách hàng
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}

$customer = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = trim($_POST['company_name']);
    $tax_code = trim($_POST['tax_code']);
    $address = trim($_POST['address']);
    $email = trim($_POST['email']);
    $short_name = trim($_POST['short_name']);
    $phone = trim($_POST['phone']);
    $contact_person = trim($_POST['contact_person']);
    $status = $_POST['status'];
    
    if (empty($company_name) || empty($tax_code) || empty($short_name)) {
        $error = 'Vui lòng nhập đầy đủ thông tin bắt buộc!';
    } else {
        // Validate email nếu có nhập
        if (!empty($email)) {
            $emails = array_filter(array_map('trim', explode(';', $email)));
            $emailRegex = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
            $invalidEmails = [];
            foreach ($emails as $e) {
                if (!preg_match($emailRegex, $e)) {
                    $invalidEmails[] = $e;
                }
            }
            if (!empty($invalidEmails)) {
                $error = 'Email không hợp lệ: ' . implode(', ', $invalidEmails);
            }
        }

        if (empty($error)) {
            // Kiểm tra mã số thuế trùng (trừ chính nó)
            $stmt = $conn->prepare("SELECT id FROM customers WHERE tax_code = ? AND id != ?");
            $stmt->bind_param("si", $tax_code, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Mã số thuế đã tồn tại!';
            } else {
                // Cập nhật
                $stmt = $conn->prepare("UPDATE customers SET company_name=?, tax_code=?, address=?, email=?, short_name=?, phone=?, contact_person=?, status=? WHERE id=?");
                $stmt->bind_param("ssssssssi", $company_name, $tax_code, $address, $email, $short_name, $phone, $contact_person, $status, $id);
                
                if ($stmt->execute()) {
                    header("Location: index.php?success=updated");
                    exit();
                } else {
                    $error = 'Có lỗi xảy ra: ' . $conn->error;
                }
            }
            $stmt->close();
            $conn->close();
        }
    }
} else {
    // Load dữ liệu cũ vào POST để hiển thị
    $_POST = $customer;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sửa Khách hàng - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Khách hàng</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../shipments/index.php">Lô hàng</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a>
                    </li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../accounts/index.php">Tài khoản</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['full_name']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../logout.php">Đăng xuất</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Sửa thông tin Khách hàng</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST" id="customerForm">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Tên công ty <span class="text-danger">*</span></label>
                                    <input type="text" name="company_name" class="form-control" required
                                        value="<?php echo htmlspecialchars($_POST['company_name']); ?>">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tên viết tắt <span class="text-danger">*</span></label>
                                    <input type="text" name="short_name" class="form-control" required
                                        value="<?php echo htmlspecialchars($_POST['short_name']); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mã số thuế <span class="text-danger">*</span></label>
                                    <input type="text" name="tax_code" class="form-control" required
                                        value="<?php echo htmlspecialchars($_POST['tax_code']); ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="text"
                                           name="email"
                                           id="emailInput"
                                           class="form-control"
                                           placeholder="vd: email1@example.com; email2@example.com"
                                           value="<?php echo htmlspecialchars($_POST['email']); ?>">
                                    <div class="form-text text-muted">
                                        <i class="bi bi-info-circle"></i> Có thể nhập nhiều email, ngăn cách bằng dấu <strong>;</strong>
                                    </div>
                                    <div class="invalid-feedback" id="emailError"></div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Địa chỉ</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['address']); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="text" name="phone" class="form-control"
                                        value="<?php echo htmlspecialchars($_POST['phone']); ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Người liên hệ</label>
                                    <input type="text" name="contact_person" class="form-control"
                                        value="<?php echo htmlspecialchars($_POST['contact_person']); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo $_POST['status'] == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                    <option value="inactive" <?php echo $_POST['status'] == 'inactive' ? 'selected' : ''; ?>>Ngưng hoạt động</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-save"></i> Cập nhật
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const emailInput = document.getElementById('emailInput');
        const emailError = document.getElementById('emailError');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        function validateEmails() {
            const value = emailInput.value.trim();
            if (value === '') {
                emailInput.classList.remove('is-invalid', 'is-valid');
                return true;
            }
            const emails = value.split(';').map(e => e.trim()).filter(e => e !== '');
            const invalidEmails = emails.filter(e => !emailRegex.test(e));
            if (invalidEmails.length > 0) {
                emailInput.classList.add('is-invalid');
                emailInput.classList.remove('is-valid');
                emailError.textContent = 'Email không hợp lệ: ' + invalidEmails.join(', ');
                return false;
            } else {
                emailInput.classList.remove('is-invalid');
                emailInput.classList.add('is-valid');
                return true;
            }
        }

        emailInput.addEventListener('input', validateEmails);

        document.getElementById('customerForm').addEventListener('submit', function(e) {
            if (!validateEmails()) {
                e.preventDefault();
                emailInput.focus();
            }
        });
    </script>
</body>
</html>