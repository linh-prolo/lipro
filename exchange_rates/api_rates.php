<?php
require_once '../config/database.php';
checkLogin();

header('Content-Type: application/json; charset=utf-8');

$conn  = getDBConnection();
$rows  = $conn->query("SELECT currency_code, rate_to_vnd FROM exchange_rates ORDER BY currency_code");
$rates = [];
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $rates[$r['currency_code']] = floatval($r['rate_to_vnd']);
    }
}
$conn->close();
echo json_encode($rates);
