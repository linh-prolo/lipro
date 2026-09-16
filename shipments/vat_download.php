<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
require_once '../config/EHoaDonClient.php';
checkLogin();

$id   = isset($_GET['id'])   ? intval($_GET['id'])        : 0;
$type = isset($_GET['type']) ? strtolower($_GET['type'])  : 'pdf';

if ($id == 0 || !in_array($type, ['pdf', 'xml'])) {
    header("Location: index.php");
    exit();
}

// Chỉ cho phép pdf hoặc xml
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, job_no, hawb,
                               vat_invoice_guid,
                               vat_invoice_no,
                               vat_invoice_serial,
                               vat_invoice_status
                        FROM shipments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$conn->close();

if (!$shipment) {
    header("Location: index.php");
    exit();
}

// Kiểm tra đã phát hành chưa
if (empty($shipment['vat_invoice_guid']) || $shipment['vat_invoice_status'] !== 'issued') {
    header("Location: vat_invoice.php?id={$id}&error=not_issued");
    exit();
}

// ============================================================
// GỌI eHoaDon
// ============================================================
try {
    $client = new EHoaDonClient();

    if ($type === 'pdf') {
        $result = $client->downloadPdf($id);
    } else {
        $result = $client->downloadXml($id);
    }

    // -------------------------------------------------------
    // Parse kết quả — Bkav trả về field tên khác nhau
    // PDF: FileContent hoặc PdfContent hoặc Content
    // XML: XmlContent hoặc Content hoặc FileContent
    // -------------------------------------------------------
    $fileContent = $result['FileContent']
                ?? $result['PdfContent']
                ?? $result['XmlContent']
                ?? $result['Content']
                ?? null;

    if (empty($fileContent)) {
        // Không có nội dung file
        $errMsg = $result['Description']  ??
                  $result['Message']      ??
                  $result['ErrorMessage'] ??
                  json_encode($result, JSON_UNESCAPED_UNICODE);
        throw new \Exception('eHoaDon không trả về file: ' . $errMsg);
    }

    // Bkav trả về base64 — decode
    $fileData = base64_decode($fileContent);
    if ($fileData === false || strlen($fileData) < 10) {
        throw new \Exception('File dữ liệu không hợp lệ (base64 decode thất bại)');
    }

    // -------------------------------------------------------
    // Tên file download
    // -------------------------------------------------------
    $jobSlug   = preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no']);
    $invNo     = preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['vat_invoice_no'] ?? 'HDDT');
    $serial    = preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['vat_invoice_serial'] ?? '');
    $dateStamp = date('Ymd');

    if ($type === 'pdf') {
        $filename    = "HoaDon_{$serial}_{$invNo}_{$jobSlug}_{$dateStamp}.pdf";
        $contentType = 'application/pdf';
    } else {
        $filename    = "HoaDon_{$serial}_{$invNo}_{$jobSlug}_{$dateStamp}.xml";
        $contentType = 'application/xml';
    }

    // -------------------------------------------------------
    // Output file
    // -------------------------------------------------------
    header('Content-Type: '        . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: '      . strlen($fileData));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo $fileData;
    exit();

} catch (\Exception $e) {
    // Lỗi → redirect về trang VAT kèm thông báo
    $errEncoded = urlencode('❌ Tải ' . strtoupper($type) . ' thất bại: ' . $e->getMessage());
    header("Location: vat_invoice.php?id={$id}&tab=download&dl_error={$errEncoded}");
    exit();
}
?>