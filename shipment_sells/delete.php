<?php
require_once '../config/database.php';
checkLogin();

$id          = isset($_GET['id'])          ? intval($_GET['id'])          : 0;
$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;

if ($id == 0 || $shipment_id == 0) {
    header("Location: ../shipments/index.php");
    exit();
}

$conn = getDBConnection();

// Kiểm tra lô hàng có bị khóa không
$stmt_lock = $conn->prepare("SELECT is_locked FROM shipments WHERE id = ?");
$stmt_lock->bind_param("i", $shipment_id);
$stmt_lock->execute();
$shipment = $stmt_lock->get_result()->fetch_assoc();
$stmt_lock->close();

if (!$shipment) {
    header("Location: ../shipments/index.php");
    exit();
}

if ($shipment['is_locked'] == 'yes' && $_SESSION['role'] != 'admin') {
    header("Location: manage.php?shipment_id=$shipment_id&error=locked");
    exit();
}

// Kiểm tra sell tồn tại và thuộc đúng shipment — KHÔNG JOIN để tránh lỗi cost_code_id = NULL
$stmt_chk = $conn->prepare("SELECT id FROM shipment_sells WHERE id = ? AND shipment_id = ?");
$stmt_chk->bind_param("ii", $id, $shipment_id);
$stmt_chk->execute();
if (!$stmt_chk->get_result()->fetch_assoc()) {
    header("Location: manage.php?shipment_id=$shipment_id&error=not_found");
    exit();
}
$stmt_chk->close();

// Xóa
$stmt = $conn->prepare("DELETE FROM shipment_sells WHERE id = ? AND shipment_id = ?");
$stmt->bind_param("ii", $id, $shipment_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    header("Location: manage.php?shipment_id=$shipment_id&success=deleted");
} else {
    header("Location: manage.php?shipment_id=$shipment_id&error=delete_failed");
}

$stmt->close();
$conn->close();
exit();