<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Soft delete: đưa vào thùng rác thay vì xóa thật
$deleted_at = date('Y-m-d H:i:s');
$deleted_by = intval($_SESSION['user_id']);

$stmt = $conn->prepare("UPDATE shipments SET deleted_at = ?, deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
$stmt->bind_param("sii", $deleted_at, $deleted_by, $id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    header("Location: index.php?success=deleted");
} else {
    header("Location: index.php?error=delete_failed");
}

$stmt->close();
$conn->close();
exit();
?>