<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;

if ($id == 0 || $shipment_id == 0) {
    header("Location: ../shipments/index.php");
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("DELETE FROM shipment_costs WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: manage.php?shipment_id=$shipment_id&success=deleted");
} else {
    header("Location: manage.php?shipment_id=$shipment_id&error=delete_failed");
}

$stmt->close();
$conn->close();
exit();
?>