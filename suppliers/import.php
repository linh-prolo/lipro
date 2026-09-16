<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['xls', 'xlsx'])) {
        $error = 'Chỉ chấp nhận file Excel (.xls, .xlsx)!';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Lỗi upload file! Mã lỗi: ' . $file['error'];
    } elseif ($file['size'] == 0) {
        $error = 'File rỗng!';
    } else {
        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $worksheet   = $spreadsheet->getActiveSheet();
            $rows        = $worksheet->toArray(null, true, true, false);

            // Bỏ dòng 1 (header A1)
            array_shift($rows);

            if (empty($rows)) {
                $error = 'File Excel không có dữ liệu từ dòng 2!';
            } else {
                $conn     = getDBConnection();
                $imported = 0;
                $updated  = 0;
                $skipped  = 0;
                $errors   = [];

                foreach ($rows as $index => $row) {
                    $row_number     = $index + 2;
                    $supplier_name  = isset($row[0]) ? trim($row[0]) : '';
                    $short_name     = isset($row[1]) ? strtoupper(trim($row[1])) : '';
                    $tax_code       = isset($row[2]) ? trim($row[2]) : '';
                    $address        = isset($row[3]) ? trim($row[3]) : '';
                    $phone          = isset($row[4]) ? trim($row[4]) : '';
                    $email          = isset($row[5]) ? trim($row[5]) : '';
                    $contact_person = isset($row[6]) ? trim($row[6]) : '';
                    $bank_name      = isset($row[7]) ? trim($row[7]) : '';
                    $bank_account   = isset($row[8]) ? trim($row[8]) : '';
                    $status         = isset($row[9]) ? strtolower(trim($row[9])) : 'active';

                    // Bỏ qua dòng trống
                    if (empty($supplier_name) && empty($short_name)) {
                        $skipped++;
                        continue;
                    }

                    // Validate bắt buộc
                    if (empty($supplier_name)) {
                        $errors[] = "Dòng $row_number: Tên nhà cung cấp không được để trống";
                        continue;
                    }
                    if (empty($short_name)) {
                        $errors[] = "Dòng $row_number: Tên viết tắt không được để trống";
                        continue;
                    }

                    // Kiểm tra status
                    if (!in_array($status, ['active', 'inactive'])) {
                        $status = 'active';
                    }

                    // Kiểm tra theo short_name
                    $stmt_check = $conn->prepare("SELECT id FROM suppliers WHERE short_name = ?");
                    $stmt_check->bind_param("s", $short_name);
                    $stmt_check->execute();
                    $existing = $stmt_check->get_result()->fetch_assoc();
                    $stmt_check->close();

                    // Thử tìm theo tax_code
                    if (!$existing && !empty($tax_code)) {
                        $stmt_check2 = $conn->prepare("SELECT id FROM suppliers WHERE tax_code = ? AND tax_code != ''");
                        $stmt_check2->bind_param("s", $tax_code);
                        $stmt_check2->execute();
                        $existing = $stmt_check2->get_result()->fetch_assoc();
                        $stmt_check2->close();
                    }

                    if ($existing) {
                        // UPDATE
                        $stmt = $conn->prepare("UPDATE suppliers SET supplier_name=?, short_name=?, tax_code=?, address=?, phone=?, email=?, contact_person=?, bank_name=?, bank_account=?, status=? WHERE id=?");
                        $stmt->bind_param("ssssssssssi", $supplier_name, $short_name, $tax_code, $address, $phone, $email, $contact_person, $bank_name, $bank_account, $status, $existing['id']);
                        if ($stmt->execute()) {
                            $updated++;
                        } else {
                            $errors[] = "Dòng $row_number: Lỗi cập nhật - " . $conn->error;
                        }
                        $stmt->close();
                    } else {
                        // INSERT
                        $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, short_name, tax_code, address, phone, email, contact_person, bank_name, bank_account, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssssssssi", $supplier_name, $short_name, $tax_code, $address, $phone, $email, $contact_person, $bank_name, $bank_account, $status, $_SESSION['user_id']);
                        if ($stmt->execute()) {
                            $imported++;
                        } else {
                            $errors[] = "Dòng $row_number: Lỗi thêm mới - " . $conn->error;
                        }
                        $stmt->close();
                    }
                }

                $conn->close();

                if ($imported > 0 || $updated > 0) {
                    $success = "Import thành công! <strong>Thêm mới: $imported</strong> | <strong>Cập nhật: $updated</strong>" . ($skipped > 0 ? " | Bỏ qua dòng trống: $skipped" : "");
                } elseif (empty($errors)) {
                    $error = 'Không có dữ liệu nào được import!';
                }

                if (!empty($errors)) {
                    $error_list = implode("<br>", array_slice($errors, 0, 10));
                    if (count($errors) > 10) {
                        $error_list .= "<br>... và " . (count($errors) - 10) . " lỗi khác";
                    }
                    $error = "Có <strong>" . count($errors) . " lỗi</strong>:<br>" . $error_list;
                }
            }
        } catch (Exception $e) {
            $error = 'Lỗi đọc file Excel: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Nhà cung cấp - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
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
                    <li class="nav-item"><a class="nav-link" href="../shipments/index.php">Lô hàng</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php">Nhà cung cấp</a></li>
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

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-excel"></i> Import Nhà cung cấp từ Excel</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                                <div class="mt-2">
                                    <a href="index.php" class="btn btn-success btn-sm">
                                        <i class="bi bi-list"></i> Xem danh sách
                                    </a>
                                    <a href="import.php" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-upload"></i> Import tiếp
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Hướng dẫn -->
                        <div class="alert alert-info mb-4">
                            <h6><i class="bi bi-info-circle"></i> Hướng dẫn:</h6>
                            <ul class="mb-2">
                                <li>File định dạng <strong>.xls</strong> hoặc <strong>.xlsx</strong></li>
                                <li>Dòng 1 là tiêu đề, dữ liệu bắt đầu từ <strong>dòng 2 (A2)</strong></li>
                                <li>Nếu <strong>Tên viết tắt</strong> hoặc <strong>MST</strong> đã tồn tại → Hệ thống sẽ <strong>CẬP NHẬT</strong></li>
                            </ul>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0 bg-white">
                                    <thead class="table-warning">
                                        <tr>
                                            <th>A</th><th>B</th><th>C</th><th>D</th><th>E</th>
                                            <th>F</th><th>G</th><th>H</th><th>I</th><th>J</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="small">
                                            <td>Tên NCC <span class="text-danger">*</span></td>
                                            <td>Tên viết tắt <span class="text-danger">*</span></td>
                                            <td>MST</td>
                                            <td>Địa chỉ</td>
                                            <td>Điện thoại</td>
                                            <td>Email</td>
                                            <td>Người liên hệ</td>
                                            <td>Tên ngân hàng</td>
                                            <td>Số tài khoản</td>
                                            <td>Trạng thái<br><small class="text-muted">active/inactive</small></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Nút tải file mẫu -->
                        <div class="mb-4">
                            <a href="download_template.php" class="btn btn-outline-warning">
                                <i class="bi bi-download"></i> Tải file Excel mẫu
                            </a>
                        </div>

                        <!-- Form upload -->
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Chọn file Excel <span class="text-danger">*</span></label>
                                <input type="file" name="excel_file" class="form-control" accept=".xls,.xlsx" required id="fileInput">
                                <div class="form-text">Chỉ chấp nhận .xls hoặc .xlsx, tối đa 10MB</div>
                            </div>

                            <div id="filePreview" class="alert alert-secondary d-none mb-3">
                                <i class="bi bi-file-earmark-excel text-success"></i>
                                <span id="fileName"></span>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-warning" id="submitBtn">
                                    <i class="bi bi-upload"></i> Import dữ liệu
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Ví dụ -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-table"></i> Ví dụ cấu trúc file Excel:</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead class="table-warning text-center">
                                    <tr>
                                        <th>A<br><small>Tên NCC</small></th>
                                        <th>B<br><small>Tên viết tắt</small></th>
                                        <th>C<br><small>MST</small></th>
                                        <th>D<br><small>Địa chỉ</small></th>
                                        <th>E<br><small>Điện thoại</small></th>
                                        <th>F<br><small>Email</small></th>
                                        <th>G<br><small>Người LH</small></th>
                                        <th>H<br><small>Ngân hàng</small></th>
                                        <th>I<br><small>Số TK</small></th>
                                        <th>J<br><small>Trạng thái</small></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="table-warning text-center">
                                        <td colspan="10"><em>Dòng 1: Tiêu đề (bỏ qua khi import)</em></td>
                                    </tr>
                                    <tr>
                                        <td>Công ty Vận tải ABC</td>
                                        <td><strong>ABC</strong></td>
                                        <td>0123456789</td>
                                        <td>123 Nguyễn Văn A, Q1</td>
                                        <td>028-1234567</td>
                                        <td>abc@gmail.com</td>
                                        <td>Nguyễn Văn A</td>
                                        <td>Vietcombank</td>
                                        <td>0123456789</td>
                                        <td>active</td>
                                    </tr>
                                    <tr>
                                        <td>Công ty Logistics XYZ</td>
                                        <td><strong>XYZ</strong></td>
                                        <td>0987654321</td>
                                        <td>456 Trần Hưng Đạo, Q5</td>
                                        <td>028-7654321</td>
                                        <td>xyz@gmail.com</td>
                                        <td>Trần Văn B</td>
                                        <td>Techcombank</td>
                                        <td>9876543210</td>
                                        <td>active</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-light text-center py-3 mt-5">
        <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('fileInput').addEventListener('change', function() {
            const fileName = this.files[0]?.name;
            if (fileName) {
                document.getElementById('fileName').textContent = ' ' + fileName;
                document.getElementById('filePreview').classList.remove('d-none');
            }
        });

        document.getElementById('importForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang xử lý...';
        });
    </script>
</body>
</html>