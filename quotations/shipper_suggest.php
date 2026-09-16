<?php
require_once '../config/database.php';
checkLogin();
$q = trim($_GET['q'] ?? '');
if ($q === '') { echo '[]'; exit; }
$conn = getDBConnection();
$like = "%$q%";
$stmt = $conn->prepare(
    "SELECT DISTINCT shipper FROM quotations
     WHERE shipper IS NOT NULL AND shipper != '' AND shipper LIKE ?
     ORDER BY shipper ASC LIMIT 10"
);
$stmt->bind_param("s", $like);
$stmt->execute();
$res = $stmt->get_result();
$list = [];
while ($row = $res->fetch_row()) $list[] = $row[0];
$stmt->close();
$conn->close();
header('Content-Type: application/json');
echo json_encode($list, JSON_UNESCAPED_UNICODE);