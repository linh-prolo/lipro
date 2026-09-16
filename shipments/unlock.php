<?php
require_once '../config/database.php';
checkLogin();
checkAdmin(); // Chỉ admin mới mở khóa được

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Mở khóa lô hàng
$stmt = $conn->prepare("UPDATE shipments SET is_locked='no', locked_at=NULL, locked_by=NULL WHERE id=?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: index.php?success=unlocked");
} else {
    header("Location: index.php?error=unlock_failed");
}

$stmt->close();
$conn->close();
exit();
?>