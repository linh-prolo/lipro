<?php
require_once '../config/database.php';
checkLogin();

$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;

if ($shipment_id == 0) {
    header("Location: ../shipments/index.php");
    exit();
}

$conn = getDBConnection();

// Lấy thông tin lô hàng
$stmt = $conn->prepare("SELECT job_no FROM shipments WHERE id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: ../shipments/index.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cost_code_id = intval($_POST['cost_code_id']);
    $quantity = floatval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $vat = floatval($_POST['vat']);
    $supplier_id = intval($_POST['supplier_id']);
    $notes = trim($_POST['notes']);
    
    // Tính thành tiền: (số lượng * đơn giá) * (1 + VAT/100)
    $total_amount = $quantity * $unit_price * (1 + $vat / 100);
    
    if ($cost_code_id == 0) {
        $error = 'Vui lòng chọn mã chi phí!';
    } else {
        $stmt = $conn->prepare("INSERT INTO shipment_costs (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, supplier_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $supplier_id_param = $supplier_id > 0 ? $supplier_id : null;
        $stmt->bind_param("iidddissi", $shipment_id, $cost_code_id, $quantity, $unit_price, $vat, $total_amount, $supplier_id_param, $notes, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            header("Location: manage.php?shipment_id=$shipment_id&success=added");
            exit();
        } else {
            $error = 'Có lỗi xảy ra: ' . $conn->error;
        }
        
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thêm Chi phí - Forwarder System</title>
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
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Thêm Chi phí đầu vào (COST) - Job: <?php echo htmlspecialchars($shipment['job_no']); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" id="costForm">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Mã chi phí <span class="text-danger">*</span></label>
                                    <input type="text" id="costCode" class="form-control text-uppercase" placeholder="VD: THC" required>
                                    <input type="hidden" name="cost_code_id" id="costCodeId">
                                    <small class="text-muted">Nhập mã để tự động điền nội dung</small>
                                </div>

                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Nội dung <span class="text-muted">(Tự động)</span></label>
                                    <input type="text" id="costDescription" class="form-control bg-light" readonly>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Số lượng</label>
                                    <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" value="1" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Đơn giá (VND)</label>
                                    <input type="number" name="unit_price" id="unitPrice" class="form-control" step="0.01" value="0" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">VAT (%)</label>
                                    <input type="number" name="vat" id="vat" class="form-control" step="0.1" value="0" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Thành tiền <span class="text-muted">(Tự động tính)</span></label>
                                <input type="text" id="totalAmount" class="form-control bg-light fw-bold" readonly>
                            </div>

                            <hr>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tên viết tắt NCC</label>
                                    <input type="text" id="supplierShortName" class="form-control text-uppercase" placeholder="VD: ABC">
                                    <input type="hidden" name="supplier_id" id="supplierId" value="0">
                                    <small class="text-muted">Nhập để tự động điền tên NCC</small>
                                </div>

                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Nhà cung cấp <span class="text-muted">(Tự động)</span></label>
                                    <input type="text" id="supplierName" class="form-control bg-light" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ghi chú</label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="manage.php?shipment_id=<?php echo $shipment_id; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Lưu chi phí
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
        // Auto-fill cost code description
        document.getElementById('costCode').addEventListener('blur', function() {
            const code = this.value.toUpperCase();
            if (code) {
                fetch('../api/get_cost_code.php?code=' + code)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('costCodeId').value = data.id;
                            document.getElementById('costDescription').value = data.description;
                        } else {
                            alert('Không tìm thấy mã chi phí: ' + code);
                            this.value = '';
                            document.getElementById('costCodeId').value = '';
                            document.getElementById('costDescription').value = '';
                        }
                    });
            }
        });

        // Auto-fill supplier name
        document.getElementById('supplierShortName').addEventListener('blur', function() {
            const shortName = this.value.toUpperCase();
            if (shortName) {
                fetch('../api/get_supplier.php?short_name=' + shortName)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('supplierId').value = data.id;
                            document.getElementById('supplierName').value = data.supplier_name;
                        } else {
                            alert('Không tìm thấy nhà cung cấp: ' + shortName);
                            this.value = '';
                            document.getElementById('supplierId').value = '0';
                            document.getElementById('supplierName').value = '';
                        }
                    });
            } else {
                document.getElementById('supplierId').value = '0';
                document.getElementById('supplierName').value = '';
            }
        });

        // Auto-calculate total amount
        function calculateTotal() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
            const vat = parseFloat(document.getElementById('vat').value) || 0;
            
            const total = quantity * unitPrice * (1 + vat / 100);
            document.getElementById('totalAmount').value = total.toLocaleString('vi-VN') + ' VND';
        }

        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('unitPrice').addEventListener('input', calculateTotal);
        document.getElementById('vat').addEventListener('input', calculateTotal);

        // Validate form
        document.getElementById('costForm').addEventListener('submit', function(e) {
            if (!document.getElementById('costCodeId').value) {
                e.preventDefault();
                alert('Vui lòng chọn mã chi phí hợp lệ!');
            }
        });
    </script>
</body>
</html>