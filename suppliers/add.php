<?php
require_once '../config/database.php';
checkLogin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $supplier_name = trim($_POST['supplier_name']);
    $tax_code = trim($_POST['tax_code']);
    $address = trim($_POST['address']);
    $email = trim($_POST['email']);
    $short_name = trim($_POST['short_name']);
    $phone = trim($_POST['phone']);
    $contact_person = trim($_POST['contact_person']);
    $status = $_POST['status'];
    
    if (empty($supplier_name) || empty($tax_code) || empty($short_name)) {
        $error = 'Vui lòng nhập đầy đủ thông tin bắt buộc!';
    } else {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT id FROM suppliers WHERE tax_code = ?");
        $stmt->bind_param("s", $tax_code);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Mã số thuế đã tồn tại!';
        } else {
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, tax_code, address, email, short_name, phone, contact_person, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssi", $supplier_name, $tax_code, $address, $email, $short_name, $phone, $contact_person, $status, $_SESSION['user_id']);
            
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
    <title>Thêm Nhà cung cấp - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php
    $conn = $conn ?? getDBConnection();
    $page_title = 'Thêm Nhà cung cấp';
    include '../partials/sidebar.php';
    ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

    <div class="container-fluid px-4 py-3">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Nhà cung cấp mới</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Tên nhà cung cấp <span class="text-danger">*</span></label>
                                    <input type="text" name="supplier_name" class="form-control" required value="<?php echo isset($_POST['supplier_name']) ? htmlspecialchars($_POST['supplier_name']) : ''; ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tên viết tắt <span class="text-danger">*</span></label>
                                    <input type="text" name="short_name" class="form-control" required value="<?php echo isset($_POST['short_name']) ? htmlspecialchars($_POST['short_name']) : ''; ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mã số thuế <span class="text-danger">*</span></label>
                                    <input type="text" name="tax_code" class="form-control" required value="<?php echo isset($_POST['tax_code']) ? htmlspecialchars($_POST['tax_code']) : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Địa chỉ</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Người liên hệ</label>
                                    <input type="text" name="contact_person" class="form-control" value="<?php echo isset($_POST['contact_person']) ? htmlspecialchars($_POST['contact_person']) : ''; ?>">
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
                                <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Lưu thông tin</button>
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