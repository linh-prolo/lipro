<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0 || $id == $_SESSION['user_id']) {
    // Không cho xóa chính mình
    header("Location: index.php?error=cannot_delete_self");
    exit();
}

$conn = getDBConnection();

// Xóa tài khoản
$stmt = $conn->prepare("DELETE FROM accounts WHERE id = ?");
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