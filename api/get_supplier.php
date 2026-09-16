<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$short_name = isset($_GET['short_name']) ? strtoupper(trim($_GET['short_name'])) : '';

if (empty($short_name)) {
    echo json_encode(['success' => false]);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, supplier_name FROM suppliers WHERE short_name = ? AND status = 'active'");
$stmt->bind_param("s", $short_name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'id' => $row['id'],
        'supplier_name' => $row['supplier_name']
    ]);
} else {
    echo json_encode(['success' => false]);
}

$stmt->close();
$conn->close();
?>