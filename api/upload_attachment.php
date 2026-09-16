<?php
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$shipment_id = intval($_POST['shipment_id'] ?? 0);
if ($shipment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid shipment_id']);
    exit;
}

if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file      = $_FILES['file'];
$allowed   = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','rar'];
$ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$maxSize   = 20 * 1024 * 1024; // 20MB

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Loại file không được phép: ' . $ext]);
    exit;
}
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File quá lớn (tối đa 20MB)']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/attachments/' . $shipment_id . '/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeName  = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
$fileName  = date('YmdHis') . '_' . $safeName;
$filePath  = $uploadDir . $fileName;
$dbPath    = 'uploads/attachments/' . $shipment_id . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Không thể lưu file']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("INSERT INTO shipment_attachments (shipment_id, file_name, file_path, file_size, file_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("issisi", $shipment_id, $file['name'], $dbPath, $file['size'], $file['type'], $_SESSION['user_id']);
$stmt->execute();
$newId = $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode([
    'success'   => true,
    'id'        => $newId,
    'file_name' => $file['name'],
    'file_path' => $dbPath,
    'file_size' => $file['size'],
    'file_type' => $file['type'],
]);