<?php
require_once '../config/database.php';
checkLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit();
}

$conn    = getDBConnection();
$results = [];
$like    = '%' . $q . '%';

// Tìm trong shipments
$stmt = $conn->prepare("SELECT id, job_no, hawb, mawb FROM shipments WHERE deleted_at IS NULL AND (job_no LIKE ? OR hawb LIKE ? OR mawb LIKE ?) LIMIT 4");
$stmt->bind_param("sss", $like, $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
while ($r = $rows->fetch_assoc()) {
    $label = $r['job_no'];
    if ($r['hawb']) $label .= ' / ' . $r['hawb'];
    $results[] = [
        'type'  => 'shipment',
        'id'    => $r['id'],
        'label' => $label,
        'url'   => '/lipro/shipments/view.php?id=' . intval($r['id']),
    ];
}
$stmt->close();

// Tìm trong customers
if (count($results) < 10) {
    $left = 10 - count($results);
    $stmt = $conn->prepare("SELECT id, short_name, full_name FROM customers WHERE (short_name LIKE ? OR full_name LIKE ?) LIMIT " . intval($left));
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($r = $rows->fetch_assoc()) {
        $results[] = [
            'type'  => 'customer',
            'id'    => $r['id'],
            'label' => $r['short_name'] . ' - ' . $r['full_name'],
            'url'   => '/lipro/customers/view.php?id=' . intval($r['id']),
        ];
    }
    $stmt->close();
}

// Tìm trong suppliers
if (count($results) < 10) {
    $left = 10 - count($results);
    $stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE name LIKE ? LIMIT " . intval($left));
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($r = $rows->fetch_assoc()) {
        $results[] = [
            'type'  => 'supplier',
            'id'    => $r['id'],
            'label' => $r['name'],
            'url'   => '/lipro/suppliers/view.php?id=' . intval($r['id']),
        ];
    }
    $stmt->close();
}

$conn->close();
echo json_encode($results);
