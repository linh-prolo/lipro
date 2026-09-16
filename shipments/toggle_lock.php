<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

$id       = isset($_GET['id'])       ? intval($_GET['id'])    : 0;
$redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : 'index.php';

// Bảo mật redirect
if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?\=\&\%]+$/', $redirect)) {
    $redirect = 'index.php';
}

if (!$id) {
    header('Location: index.php?error=not_found');
    exit;
}

// Lấy trạng thái khoá hiện tại
$stmt = $conn->prepare("SELECT id, job_no, is_locked FROM shipments WHERE id = ? AND deleted_at IS NULL");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    header('Location: index.php?error=not_found');
    exit;
}

$current_locked = $row['is_locked'] ?? 'no';

// Nếu đang khoá → muốn mở khoá → chỉ admin được phép
if ($current_locked === 'yes' && $_SESSION['role'] !== 'admin') {
    $sep = strpos($redirect, '?') !== false ? '&' : '?';
    header('Location: ' . $redirect . $sep . 'error=no_permission');
    exit;
}

// Xác định action rồi redirect sang lock.php để xử lý
$action = ($current_locked === 'yes') ? 'unlock' : 'lock';

header('Location: lock.php?id=' . $id . '&action=' . $action . '&redirect=' . urlencode($redirect));
exit;