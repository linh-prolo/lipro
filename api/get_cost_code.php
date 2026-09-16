<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$code = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';

if (empty($code)) {
    echo json_encode(['success' => false]);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, code, description, default_currency, default_unit_price FROM cost_codes WHERE code = ? AND status = 'active'");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'success'               => true,
        'id'                    => $row['id'],
        'description'           => $row['description'],
        // ✅ Bổ sung thêm — dùng cho arrival_notice.php tự điền tiền tệ và đơn giá mặc định
        'default_currency'      => $row['default_currency']   ?? 'USD',
        'default_unit_price'    => $row['default_unit_price'] ?? 0,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy mã chi phí: ' . $code]);
}

$stmt->close();
$conn->close();
?>