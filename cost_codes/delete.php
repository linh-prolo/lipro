<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Kiểm tra xem mã có đang được sử dụng không
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipment_costs WHERE cost_code_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result1 = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipment_sells WHERE cost_code_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result2 = $stmt->get_result()->fetch_assoc();

if ($result1['count'] > 0 || $result2['count'] > 0) {
    header("Location: index.php?error=in_use");
    exit();
}

// Xóa mã
$stmt = $conn->prepare("DELETE FROM cost_codes WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: index.php?success=deleted");
} else {
    header("Location: index.php?error=delete_failed");
}

$stmt->close();
$conn->close();
exit();
?>