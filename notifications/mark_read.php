<?php
require_once '../config/database.php';
checkLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$conn    = getDBConnection();
$user_id = intval($_SESSION['user_id']);

// Đánh dấu tất cả đã đọc
if (!empty($_POST['all'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit();
}

// Đánh dấu một thông báo đã đọc
$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit();
}

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $id, $user_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $ok]);
