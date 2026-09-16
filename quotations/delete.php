<?php
require_once '../config/database.php';
checkLogin();

if (!isAdmin()) {
    header("Location: index.php?error=no_permission");
    exit();
}

$conn = getDBConnection();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Kiểm tra tồn tại
$stmt = $conn->prepare("SELECT id, quotation_no FROM quotations WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quot) {
    header("Location: index.php");
    exit();
}

// Xóa (cascade xóa quotation_items)
$stmt = $conn->prepare("DELETE FROM quotations WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

$conn->close();

header("Location: index.php?success=deleted");
exit();
