<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Require database & vendors
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ========================================
// 1. LẤY FILTER PARAMS (giống export_statement.php)
// ========================================
$search          = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$status_filter   = isset($_GET['status'])    ? $_GET['status']          : '';
$locked_filter   = isset($_GET['locked'])    ? $_GET['locked']          : '';
$customer_filter = isset($_GET['customer'])  ? intval($_GET['customer']) : 0;
$date_from       = isset($_GET['date_from']) ? trim($_GET['date_from'])  : '';
$date_to         = isset($_GET['date_to'])   ? trim($_GET['date_to'])    : '';

$conn = getDBConnection();

// ========================================
// 2. BUILD WHERE (giống export_statement.php)
// ========================================
$where = [];
if ($search) {
    $s = $conn->real_escape_string($search);
    $where[] = "(s.job_no LIKE '%$s%' OR s.mawb LIKE '%$s%' OR s.hawb LIKE '%$s%'
                 OR s.shipper LIKE '%$s%' OR s.cnee LIKE '%$s%'
                 OR c.short_name LIKE '%$s%')";
}
if ($status_filter)   $where[] = "s.status = '"    . $conn->real_escape_string($status_filter) . "'";
if ($locked_filter)   $where[] = "s.is_locked = '" . $conn->real_escape_string($locked_filter) . "'";
if ($customer_filter) $where[] = "s.customer_id = " . intval($customer_filter);
if ($date_from)       $where[] = "DATE(s.invoice_date) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to)         $where[] = "DATE(s.invoice_date) <= '" . $conn->real_escape_string($date_to)   . "'";

$whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ========================================
// 3. LẤY SHIPMENTS VỚI CHỈ NHỮNG CÓ PHÍ CHI HỘ
// ========================================
$sql = "SELECT DISTINCT s.id, s.job_no, s.mawb, s.hawb, s.customs_declaration_no,
               s.invoice_date, s.customer_id,
               c.company_name, c.short_name AS customer_short
        FROM shipments s
        LEFT JOIN customers c ON s.customer_id = c.id
        INNER JOIN shipment_sells ss ON s.id = ss.shipment_id AND ss.is_pob = 1
        $whereClause
        ORDER BY s.invoice_date DESC, s.job_no ASC";

$result = $conn->query($sql);
if (!$result) {
    die("Query error: " . $conn->error);
}

$shipments = [];
while ($row = $result->fetch_assoc()) {
    $shipments[] = $row;
}

// ========================================
// 4. TỔNG HỢP DỮ LIỆU CHI HỘ CHO MỖI SHIPMENT
// ========================================
$all_fees = [];
foreach ($shipments as $ship) {
    $sid = intval($ship['id']);
    
    // Lấy tất cả phí chi hộ (is_pob = 1) của shipment này
    $rs = $conn->query("
        SELECT ss.*, 
               COALESCE(cc.code, '(Chưa có mã)') AS code,
               COALESCE(cc.description, ss.notes) AS full_desc
        FROM shipment_sells ss
        LEFT JOIN cost_codes cc ON ss.cost_code_id = cc.id
        WHERE ss.shipment_id = $sid AND ss.is_pob = 1
        ORDER BY ss.id
    ");
    
    while ($fee = $rs->fetch_assoc()) {
        $fee['shipment_info'] = $ship;
        $all_fees[] = $fee;
    }
}

$conn->close();

// ========================================
// 5. TẠO SPREADSHEET
// ========================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Phí Chi Hộ');

// Định dạng trang
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

$sheet->getPageMargins()->setLeft(0.5)->setRight(0.5)->setTop(0.75)->setBottom(0.75);

// ========================================
// 6. HEADER
// ========================================
$r = 1;
$sheet->mergeCells("A{$r}:K{$r}");
$sheet->setCellValue("A{$r}", 'DANH SÁCH PHÍ CHI HỘ (POB / B2B)');
$sheet->getStyle("A{$r}:K{$r}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFA500']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($r)->setRowHeight(25);
$r++;

$sheet->setCellValue("A{$r}", 'Ngày xuất: ' . date('d/m/Y H:i'));
$sheet->getStyle("A{$r}")->getFont()->setSize(10)->setItalic(true);
$r += 2;

// ========================================
// 7. CỘT HEADER
// ========================================
$cols = ['A' => 'STT', 'B' => 'Job No', 'C' => 'MAWB', 'D' => 'HAWB', 'E' => 'Tờ khai', 
         'F' => 'Mã CP', 'G' => 'Nội dung', 'H' => 'Số lượng', 'I' => 'Đơn giá', 
         'J' => 'VAT %', 'K' => 'Thành tiền'];

foreach ($cols as $col => $label) {
    $sheet->setCellValue("{$col}{$r}", $label);
    $sheet->getStyle("{$col}{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
    ]);
}
$sheet->getRowDimension($r)->setRowHeight(20);
$r++;

// ========================================
// 8. DỮ LIỆU
// ========================================
$total_amount = 0;
$idx = 1;

if (empty($all_fees)) {
    // Không có dữ liệu
    $sheet->mergeCells("A{$r}:K{$r}");
    $sheet->setCellValue("A{$r}", 'Không có phí chi hộ nào');
    $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(['rgb' => '999999']);
} else {
    foreach ($all_fees as $fee) {
        $ship = $fee['shipment_info'];
        
        $sheet->setCellValue("A{$r}", $idx);
        $sheet->setCellValue("B{$r}", $ship['job_no']);
        $sheet->setCellValue("C{$r}", $ship['mawb']);
        $sheet->setCellValue("D{$r}", $ship['hawb']);
        $sheet->setCellValue("E{$r}", $ship['customs_declaration_no'] ?? '');
        $sheet->setCellValue("F{$r}", $fee['code']);
        $sheet->setCellValue("G{$r}", $fee['full_desc']);
        $sheet->setCellValue("H{$r}", floatval($fee['quantity']));
        $sheet->setCellValue("I{$r}", floatval($fee['unit_price']));
        $sheet->setCellValue("J{$r}", floatval($fee['vat']));
        $sheet->setCellValue("K{$r}", floatval($fee['total_amount']));
        
        $total_amount += floatval($fee['total_amount']);
        
        // Format số tiền
        $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("J{$r}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("K{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        
        // Căn phải cho số tiền
        $sheet->getStyle("H{$r}:K{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        // Border
        $sheet->getStyle("A{$r}:K{$r}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC']
                ]
            ]
        ]);
        
        $r++;
        $idx++;
    }
    
    // ========================================
    // 9. TỔNG CỘNG
    // ========================================
    $sheet->mergeCells("A{$r}:J{$r}");
    $sheet->setCellValue("A{$r}", 'TỔNG TIỀN CHI HỘ');
    $sheet->setCellValue("K{$r}", $total_amount);
    
    $sheet->getStyle("A{$r}:K{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF6B35']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '000000']]],
    ]);
    
    $sheet->getStyle("K{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("K{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getRowDimension($r)->setRowHeight(22);
}

// ========================================
// 10. AUTO-WIDTH COLUMNS
// ========================================
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(12);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(10);
$sheet->getColumnDimension('G')->setWidth(20);
$sheet->getColumnDimension('H')->setWidth(10);
$sheet->getColumnDimension('I')->setWidth(12);
$sheet->getColumnDimension('J')->setWidth(8);
$sheet->getColumnDimension('K')->setWidth(14);

// ========================================
// 11. FREEZE HEADER
// ========================================
$sheet->freezePane('A4');

// ========================================
// 12. XUẤT FILE
// ========================================
$fileName = 'Phi_Chi_Ho_' . date('Ymd_Hi') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
