<?php
require_once '../config/database.php';
checkLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id          = intval($_POST['id'] ?? 0);
$shipment_id = intval($_POST['shipment_id'] ?? 0);

if ($id === 0) {
    echo json_encode(['success' => false, 'message' => 'Thiếu id']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("DELETE FROM arrival_notice_charges WHERE id=? AND shipment_id=?");
$stmt->bind_param("ii", $id, $shipment_id);
$stmt->execute();

// Xóa dòng sell tương ứng nếu có
$conn->query("DELETE FROM shipment_sells WHERE shipment_id=$shipment_id AND notes='[ARRIVAL_DOM_{$id}]'");

$conn->close();
echo json_encode(['success' => true, 'message' => 'Đã xóa']);