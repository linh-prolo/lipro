<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Kiểm tra xem khách hàng có lô hàng nào không
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipments WHERE customer_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result['count'] > 0) {
    // Không cho xóa nếu có lô hàng
    header("Location: index.php?error=has_shipments");
    exit();
}

// Xóa khách hàng
$stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
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