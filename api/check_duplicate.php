<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$field = $_GET['field'] ?? '';
$value = trim($_GET['value'] ?? '');
$exclude_id = intval($_GET['exclude_id'] ?? 0);

if (empty($value) || !in_array($field, ['hawb', 'customs_declaration_no'])) {
    echo json_encode(['duplicate' => false]);
    exit;
}

$conn = getDBConnection();

$sql = "SELECT id, job_no, customer_id, arrival_date,
        (SELECT company_name FROM customers WHERE id = shipments.customer_id) AS company_name
        FROM shipments
        WHERE {$field} = ?";

$params = [$value];
$types  = 's';

if ($exclude_id > 0) {
    $sql   .= ' AND id != ?';
    $params[] = $exclude_id;
    $types .= 'i';
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

echo json_encode([
    'duplicate' => count($rows) > 0,
    'matches'   => $rows,
]);