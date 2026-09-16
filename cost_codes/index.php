<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$conn = getDBConnection();

// ================================================================
// XỬ LÝ POST — chỉ cho tab Arrival (tab Cost dùng add/edit/delete riêng)
// ================================================================
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'arrival_add') {
        $code      = strtoupper(trim($_POST['code']               ?? ''));
        $desc      = trim($_POST['description']                   ?? '');
        $notes     = trim($_POST['notes']                         ?? '');
        $def_curr  = in_array($_POST['default_currency'] ?? '', ['USD','EUR','VND'])
                     ? $_POST['default_currency'] : 'VND';
        $def_price = floatval($_POST['default_unit_price']        ?? 0);
        $status    = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        if (!$code || !$desc) {
            $err = 'Vui lòng nhập Mã và Diễn giải!';
        } else {
            $st = $conn->prepare("INSERT INTO arrival_cost_codes
                (code, description, notes, default_currency, default_unit_price, status)
                VALUES (?,?,?,?,?,?)");
            $st->bind_param('ssssds', $code, $desc, $notes, $def_curr, $def_price, $status);
            if ($st->execute()) {
                $msg = "Đã thêm mã Arrival <strong>" . htmlspecialchars($code) . "</strong>.";
            } else {
                $err = 'Lỗi (mã đã tồn tại?): ' . $conn->error;
            }
        }

    } elseif ($action === 'arrival_edit') {
        $id        = intval($_POST['id']);
        $code      = strtoupper(trim($_POST['code']               ?? ''));
        $desc      = trim($_POST['description']                   ?? '');
        $notes     = trim($_POST['notes']                         ?? '');
        $def_curr  = in_array($_POST['default_currency'] ?? '', ['USD','EUR','VND'])
                     ? $_POST['default_currency'] : 'VND';
        $def_price = floatval($_POST['default_unit_price']        ?? 0);
        $status    = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

                $st = $conn->prepare("UPDATE arrival_cost_codes
            SET code=?, description=?, notes=?, default_currency=?, default_unit_price=?, status=?
            WHERE id=?");
        $st->bind_param('ssssdsi', $code, $desc, $notes, $def_curr, $def_price, $status, $id);
        if ($st->execute()) {
            $msg = 'Đã cập nhật mã Arrival.';
        } else {
            $err = 'Lỗi: ' . $conn->error;
        }

    } elseif ($action === 'arrival_delete') {
        $id = intval($_POST['id']);
        // Không cho xóa mã FREIGHT
        $check = $conn->query("SELECT code FROM arrival_cost_codes WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($check && $check['code'] === 'FREIGHT') {
            $err = 'Không thể xóa mã đặc biệt FREIGHT!';
        } else {
            $conn->query("DELETE FROM arrival_cost_codes WHERE id=$id");
            $msg = 'Đã xóa mã Arrival.';
        }
    }
}

// ── Load dữ liệu ──────────────────────────────────────────────────
$cost_codes    = $conn->query("SELECT * FROM cost_codes ORDER BY code ASC");
$arrival_codes = $conn->query("SELECT * FROM arrival_cost_codes ORDER BY code ASC")->fetch_all(MYSQLI_ASSOC);

// ── Tab đang active ───────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'cost';
// Sau khi POST, ở lại tab arrival
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = 'arrival';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mã chi phí - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
    <style>
        .code-badge  { font-size: .85rem; letter-spacing: .5px; }
        .special-row { background: #fff8e1 !important; }
    </style>
</head>
<body class="bg-light">
    <?php
    $page_title = 'Mã chi phí';
    include '../partials/sidebar.php';
    ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

<div class="container-fluid px-4 py-3">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-tag text-primary"></i> Quản lý Mã chi phí</h4>
    </div>

    <!-- Alert messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php
            if ($_GET['success'] == 'added')   echo 'Thêm mã chi phí thành công!';
            if ($_GET['success'] == 'updated') echo 'Cập nhật mã chi phí thành công!';
            if ($_GET['success'] == 'deleted') echo 'Xóa mã chi phí thành công!';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php
            if ($_GET['error'] == 'in_use')        echo 'Không thể xóa mã đang được sử dụng!';
            if ($_GET['error'] == 'delete_failed') echo 'Xóa mã chi phí thất bại!';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- NAV TABS -->
    <ul class="nav nav-tabs mb-0" id="mainTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'cost' ? 'active fw-bold' : ''; ?>"
               href="?tab=cost">
                <i class="bi bi-cash-stack text-danger"></i> Mã Chi Phí SELL / COST
                <span class="badge bg-secondary ms-1"><?php echo $cost_codes->num_rows; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'arrival' ? 'active fw-bold' : ''; ?>"
               href="?tab=arrival">
                <i class="bi bi-file-earmark-text text-info"></i> Mã Arrival Notice
                <span class="badge bg-info text-white ms-1"><?php echo count($arrival_codes); ?></span>
            </a>
        </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom bg-white p-3 shadow-sm mb-4">

        <!-- ============================================================
             TAB 1: COST CODES — giữ nguyên giao diện + tính năng cũ
             ============================================================ -->
        <div class="<?php echo $active_tab === 'cost' ? '' : 'd-none'; ?>" id="paneCost">

            <div class="d-flex justify-content-between align-items-center mb-3 mt-1">
                <h6 class="mb-0 text-danger fw-bold">
                    <i class="bi bi-cash-stack"></i> Danh sách mã chi phí SELL / COST
                </h6>
                <!-- Giữ nguyên link add.php như cũ -->
                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Thêm mã chi phí
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>STT</th>
                            <th>Mã</th>
                            <th>Nội dung</th>
                            <th>Ghi chú</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($cost_codes->num_rows > 0):
                            $stt = 1;
                            while ($row = $cost_codes->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $stt++; ?></td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($row['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($row['notes'] ?? ''); ?></small></td>
                            <td>
                                <?php if ($row['status'] == 'active'): ?>
                                    <span class="badge bg-success">Hoạt động</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Ngưng</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Giữ nguyên link edit.php / delete.php như cũ -->
                                <a href="edit.php?id=<?php echo $row['id']; ?>"
                                   class="btn btn-sm btn-warning">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="delete.php?id=<?php echo $row['id']; ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Bạn có chắc muốn xóa mã này?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <i class="bi bi-inbox" style="font-size:3rem;color:#ccc"></i>
                                <p class="text-muted mt-2">Chưa có mã chi phí nào</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /paneCost -->

        <!-- ============================================================
             TAB 2: ARRIVAL COST CODES — CRUD inline
             ============================================================ -->
        <div class="<?php echo $active_tab === 'arrival' ? '' : 'd-none'; ?>" id="paneArrival">

            <div class="d-flex justify-content-between align-items-center mb-3 mt-1">
                <div>
                    <h6 class="mb-0 text-info fw-bold">
                        <i class="bi bi-file-earmark-text"></i> Danh sách mã Arrival Notice
                    </h6>
                    <small class="text-muted">
                        <i class="bi bi-star-fill text-warning"></i>
                        Mã <strong>FREIGHT</strong> là mã đặc biệt — tự động điền diễn giải
                        <em>POL → POD, Chuyến tàu/bay: VSL</em> từ lô hàng.
                    </small>
                </div>
                <button class="btn btn-info btn-sm text-white"
                        data-bs-toggle="modal" data-bs-target="#modalAddArrival">
                    <i class="bi bi-plus-circle"></i> Thêm mã Arrival
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-bordered table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:45px">STT</th>
                            <th style="width:120px">Mã</th>
                            <th>Diễn giải</th>
                            <th style="width:80px">Tiền tệ</th>
                            <th style="width:120px">Đơn giá mặc định</th>
                            <th>Ghi chú</th>
                            <th style="width:90px">Trạng thái</th>
                            <th style="width:90px">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($arrival_codes)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="bi bi-inbox" style="font-size:3rem;color:#ccc"></i>
                                <p class="text-muted mt-2">Chưa có mã Arrival nào. Hãy chạy file SQL tạo bảng trước.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($arrival_codes as $i => $c):
                            $is_special = ($c['code'] === 'FREIGHT');
                        ?>
                        <tr class="<?php echo $is_special ? 'special-row' : ''; ?>">
                            <td class="text-center text-muted"><?php echo $i + 1; ?></td>
                            <td>
                                <span class="badge <?php echo $is_special
                                    ? 'bg-warning text-dark'
                                    : 'bg-info text-white'; ?> code-badge">
                                    <?php if ($is_special): ?>
                                        <i class="bi bi-star-fill"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($c['code']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($c['description']); ?>
                                <?php if ($is_special): ?>
                                    <br><small class="text-muted fst-italic">
                                        Auto: "Cước vận chuyển quốc tế [POL] - [POD], Chuyến tàu/bay: [VSL]"
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($c['default_currency'] ?? 'VND'); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php $dp = floatval($c['default_unit_price'] ?? 0); ?>
                                <?php echo $dp > 0 ? number_format($dp, 2, ',', '.') : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($c['notes'] ?? '—'); ?></small></td>
                            <td class="text-center">
                                <?php if ($c['status'] === 'active'): ?>
                                    <span class="badge bg-success">Hoạt động</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Ngưng</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-warning btn-sm"
                                        onclick="editArrival(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES); ?>)"
                                        title="Sửa">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (!$is_special): ?>
                                <button class="btn btn-danger btn-sm"
                                        onclick="deleteArrival(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['code']); ?>')"
                                        title="Xóa">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-danger btn-sm" disabled title="Mã đặc biệt — không thể xóa">
                                    <i class="bi bi-lock"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /paneArrival -->

    </div><!-- /tab-content -->
</div><!-- /container -->

<!-- ================================================================
     MODAL: THÊM ARRIVAL COST CODE
     ================================================================ -->
<div class="modal fade" id="modalAddArrival" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?tab=arrival">
                <input type="hidden" name="action" value="arrival_add">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle"></i> Thêm Mã Arrival Notice
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Mã <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control text-uppercase" required
                               placeholder="VD: DO, STORAGE, CFS...">
                        <small class="text-muted">Tự động chuyển in hoa. Không được trùng mã đã có.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Diễn giải <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="form-control" required
                               placeholder="Mô tả ngắn gọn, rõ ràng">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Tiền tệ mặc định</label>
                            <select name="default_currency" class="form-select">
                                <option value="VND" selected>VND</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Đơn giá mặc định</label>
                            <input type="number" name="default_unit_price" class="form-control"
                                   value="0" min="0" step="0.0001">
                            <small class="text-muted">0 = không tự điền</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Ghi chú</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm (tùy chọn)"></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-bold">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Ngưng</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-info text-white">
                        <i class="bi bi-save"></i> Thêm
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: SỬA ARRIVAL COST CODE
     ================================================================ -->
<div class="modal fade" id="modalEditArrival" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?tab=arrival">
                <input type="hidden" name="action" value="arrival_edit">
                <input type="hidden" name="id" id="editArrivalId">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Sửa Mã Arrival Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Mã</label>
                        <input type="text" name="code" id="editArrivalCode"
                               class="form-control text-uppercase" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Diễn giải</label>
                        <input type="text" name="description" id="editArrivalDesc"
                               class="form-control" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Tiền tệ mặc định</label>
                            <select name="default_currency" id="editArrivalCurr" class="form-select">
                                <option value="VND">VND</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Đơn giá mặc định</label>
                            <input type="number" name="default_unit_price" id="editArrivalPrice"
                                   class="form-control" min="0" step="0.0001">
                            <small class="text-muted">0 = không tự điền</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Ghi chú</label>
                        <textarea name="notes" id="editArrivalNotes"
                                  class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-bold">Trạng thái</label>
                        <select name="status" id="editArrivalStatus" class="form-select">
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Ngưng</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save"></i> Lưu
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FORM XÓA ARRIVAL CODE (ẩn) -->
<form method="POST" action="?tab=arrival" id="formDeleteArrival" style="display:none">
    <input type="hidden" name="action" value="arrival_delete">
    <input type="hidden" name="id"     id="deleteArrivalId">
</form>

<footer class="bg-white text-center py-2 border-top mt-2">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Arrival Cost Codes ─────────────────────────────────────────────
function editArrival(c) {
    document.getElementById('editArrivalId').value     = c.id;
    document.getElementById('editArrivalCode').value   = c.code;
    document.getElementById('editArrivalDesc').value   = c.description;
    document.getElementById('editArrivalNotes').value  = c.notes  || '';
    document.getElementById('editArrivalCurr').value   = c.default_currency   || 'VND';
    document.getElementById('editArrivalPrice').value  = c.default_unit_price || 0;
    document.getElementById('editArrivalStatus').value = c.status;
    new bootstrap.Modal(document.getElementById('modalEditArrival')).show();
}

function deleteArrival(id, code) {
    if (!confirm('Xóa mã Arrival "' + code + '"?\nThao tác này không thể hoàn tác!')) return;
    document.getElementById('deleteArrivalId').value = id;
    document.getElementById('formDeleteArrival').submit();
}

// Auto uppercase khi gõ vào ô Mã
document.querySelectorAll('input[name="code"]').forEach(function(el) {
    el.addEventListener('input', function() {
        var pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
});
</script>
</div><!-- /container-fluid -->
</div><!-- /main-content -->

<?php include '../partials/footer.php'; ?>
</body>
</html>