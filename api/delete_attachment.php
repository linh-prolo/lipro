<?php
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$att_id = intval($_POST['id'] ?? 0);
if ($att_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM shipment_attachments WHERE id = ?");
$stmt->bind_param("i", $att_id);
$stmt->execute();
$att = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$att) {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy file']);
    $conn->close();
    exit;
}

$fullPath = dirname(__DIR__) . '/' . $att['file_path'];
if (file_exists($fullPath)) unlink($fullPath);

$stmt2 = $conn->prepare("DELETE FROM shipment_attachments WHERE id = ?");
$stmt2->bind_param("i", $att_id);
$stmt2->execute();
$stmt2->close();
$conn->close();

echo json_encode(['success' => true]);