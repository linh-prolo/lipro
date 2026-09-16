<?php
/**
 * api/get_arrival_cost_code.php
 * Lookup mã Arrival Notice, xử lý đặc biệt FREIGHT
 */
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$code        = isset($_GET['code'])        ? strtoupper(trim($_GET['code']))  : '';
$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id'])     : 0;

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu mã']);
    exit;
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT * FROM arrival_cost_codes WHERE code = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('s', $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy mã: ' . $code]);
    exit;
}

$description = $row['description'];

// ── Xử lý đặc biệt: FREIGHT ──────────────────────────────────────
if ($code === 'FREIGHT' && $shipment_id > 0) {
    $s = $conn->query("SELECT pol, pod, vessel_flight FROM shipments WHERE id = $shipment_id LIMIT 1")->fetch_assoc();
    if ($s) {
        $pol = trim($s['pol']           ?? '');
        $pod = trim($s['pod']           ?? '');
        $vsl = trim($s['vessel_flight'] ?? '');

        $parts = [];
        if ($pol || $pod) {
            $parts[] = ($pol ?: '?') . ' - ' . ($pod ?: '?');
        }
        if ($vsl) {
            $parts[] = 'Chuyến tàu/bay: ' . $vsl;
        }

        if (!empty($parts)) {
            $description = 'Cước vận chuyển quốc tế ' . implode(', ', $parts);
        }
    }
}

$conn->close();

echo json_encode([
    'success'           => true,
    'code'              => $row['code'],
    'description'       => $description,
    'notes'             => $row['notes']             ?? '',
    'default_currency'  => $row['default_currency']  ?? 'USD',
    'default_unit_price'=> floatval($row['default_unit_price'] ?? 0),
]);