<?php
require_once '../config/database.php';
checkLogin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = trim($_POST['company_name']);
    $tax_code = trim($_POST['tax_code']);
    $address = trim($_POST['address']);
    $email = trim($_POST['email']);
    $short_name = trim($_POST['short_name']);
    $phone = trim($_POST['phone']);
    $contact_person = trim($_POST['contact_person']);
    $status = $_POST['status'];
    
    // Validate
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
            $conn = getDBConnection();
            
            // Kiểm tra mã số thuế trùng
            $stmt = $conn->prepare("SELECT id FROM customers WHERE tax_code = ?");
            $stmt->bind_param("s", $tax_code);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Mã số thuế đã tồn tại!';
            } else {
                // Thêm khách hàng
                $stmt = $conn->prepare("INSERT INTO customers (company_name, tax_code, address, email, short_name, phone, contact_person, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssi", $company_name, $tax_code, $address, $email, $short_name, $phone, $contact_person, $status, $_SESSION['user_id']);
                
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
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Khách hàng - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php
    $conn = $conn ?? getDBConnection();
    $page_title = 'Thêm Khách hàng';
    include '../partials/sidebar.php';
    ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

        <div class="container-fluid px-4 py-3">
            <div class="row">
                <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Khách hàng mới</h5>
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
                                        value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tên viết tắt <span class="text-danger">*</span></label>
                                    <input type="text" name="short_name" class="form-control" required
                                        value="<?php echo isset($_POST['short_name']) ? htmlspecialchars($_POST['short_name']) : ''; ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mã số thuế <span class="text-danger">*</span></label>
                                    <input type="text" name="tax_code" class="form-control" required
                                        value="<?php echo isset($_POST['tax_code']) ? htmlspecialchars($_POST['tax_code']) : ''; ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="text"
                                           name="email"
                                           id="emailInput"
                                           class="form-control"
                                           placeholder="vd: email1@example.com; email2@example.com"
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                                    <div class="form-text text-muted">
                                        <i class="bi bi-info-circle"></i> Có thể nhập nhiều email, ngăn cách bằng dấu <strong>;</strong>
                                    </div>
                                    <div class="invalid-feedback" id="emailError"></div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Địa chỉ</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="text" name="phone" class="form-control"
                                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Người liên hệ</label>
                                    <input type="text" name="contact_person" class="form-control"
                                        value="<?php echo isset($_POST['contact_person']) ? htmlspecialchars($_POST['contact_person']) : ''; ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active">Hoạt động</option>
                                    <option value="inactive">Ngưng hoạt động</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Lưu thông tin
                                </button>
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