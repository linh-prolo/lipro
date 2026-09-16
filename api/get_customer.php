<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$short_name = isset($_GET['short_name']) ? strtoupper(trim($_GET['short_name'])) : '';

if (empty($short_name)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu mã khách hàng']);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, company_name, short_name FROM customers WHERE short_name = ? AND status = 'active'");
$stmt->bind_param("s", $short_name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'success'      => true,
        'id'           => $row['id'],
        'company_name' => $row['company_name'],
        'short_name'   => $row['short_name']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy']);
}

$stmt->close();
$conn->close();
?>