<?php
require_once '../config/database.php';
require_once '../config/mail.php';
require_once '../config/helpers.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
                        c.company_name, c.short_name AS customer_short,
                        c.address AS customer_address,
                        c.email AS customer_email,
                        c.tax_code AS customer_tax,
                        c.phone AS customer_phone,
                        c.contact_person AS customer_contact
                        FROM shipments s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

// ✅ Đổi JOIN → LEFT JOIN
$stmt_sell = $conn->prepare(
    "SELECT ss.*,
            COALESCE(cc.code, '(Chua co ma)') AS code,
            COALESCE(NULLIF(TRIM(ss.description), ''), cc.description, ss.notes) AS description
     FROM shipment_sells ss
     LEFT JOIN cost_codes cc ON ss.cost_code_id = cc.id
     WHERE ss.shipment_id = ?
     ORDER BY ss.id"
);
$stmt_sell->bind_param("i", $id);
$stmt_sell->execute();
$sells = $stmt_sell->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy danh sách file đính kèm đã lưu
$stmt_att = $conn->prepare("SELECT * FROM shipment_attachments WHERE shipment_id = ? ORDER BY uploaded_at ASC");
$stmt_att->bind_param("i", $id);
$stmt_att->execute();
$saved_attachments = $stmt_att->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_att->close();

$total_sell = 0;
foreach ($sells as $s) { $total_sell += $s['total_amount']; }

$hawb         = $shipment['hawb'] ?? '';
$cd_no        = $shipment['customs_declaration_no'] ?? '';
$cus_name     = $shipment['company_name'] ?? '';
$auto_subject = 'DEBIT // LIPRO // ' . $hawb . (!empty($cd_no) ? ' // ' . $cd_no : '');

$conn->close();

// ============================================================
// HÀM TẠO FILE EXCEL (giữ nguyên)
// ============================================================
function buildExcelFile($shipment, $sells) {
    $C_DARK_BLUE  = '1B3A6B';
    $C_MID_BLUE   = '2E75B6';
    $C_LIGHT_BLUE = 'DEEAF1';
    $C_ACCENT     = 'C00000';
    $C_GOLD       = 'F4B942';
    $C_GREEN      = '375623';
    $C_GRAY_BG    = 'F2F2F2';
    $C_BORDER     = 'BDD7EE';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Debit Note');

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.7)->setBottom(0.7)->setLeft(0.6)->setRight(0.6);
    $sheet->getPageSetup()->setHorizontalCentered(true);
    $sheet->setShowGridlines(false);

    $sheet->getColumnDimension('A')->setWidth(1.5);
    $sheet->getColumnDimension('B')->setWidth(6);
    $sheet->getColumnDimension('C')->setWidth(32);
    $sheet->getColumnDimension('D')->setWidth(9);
    $sheet->getColumnDimension('E')->setWidth(16);
    $sheet->getColumnDimension('F')->setWidth(9);
    $sheet->getColumnDimension('G')->setWidth(18);
    $sheet->getColumnDimension('H')->setWidth(20);
    $sheet->getColumnDimension('I')->setWidth(1.5);

    $setCell = function($cell, $value) use ($sheet) { $sheet->setCellValue($cell, $value); };
    $setStr  = function($cell, $value) use ($sheet) { $sheet->setCellValueExplicit($cell, (string)$value, DataType::TYPE_STRING); };
    $sStyle  = function($range, $styleArr) use ($sheet) { $sheet->getStyle($range)->applyFromArray($styleArr); };
    $rowH    = function($row, $height) use ($sheet) { $sheet->getRowDimension($row)->setRowHeight($height); };
    $merge   = function($range) use ($sheet) { $sheet->mergeCells($range); };
    $borderBox = function($range, $color, $weight = Border::BORDER_THIN) use ($sheet) {
        $sheet->getStyle($range)->applyFromArray(['borders' => ['outline' => ['borderStyle' => $weight, 'color' => ['rgb' => $color]]]]);
    };

    $r = 1;

    $rowH($r, 8); $r++;
    $rowH($r, 18);
    $merge("B{$r}:C7");
    $logoPath = '../assets/images/logo.png';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo')->setPath($logoPath)->setCoordinates("B{$r}")
                ->setWidth(110)->setHeight(88)->setOffsetX(5)->setOffsetY(3)->setWorksheet($sheet);
    }

    $merge("D{$r}:H{$r}");
    $setCell("D{$r}", 'LIPRO LOGISTICS CO.,LTD');
    $sStyle("D{$r}", ['font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $C_ACCENT], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
    $rowH($r, 28); $r++;

    $merge("D{$r}:H{$r}"); $setCell("D{$r}", 'FREIGHT FORWARDING & CUSTOMS CLEARANCE SERVICES');
    $sStyle("D{$r}", ['font' => ['size' => 9, 'color' => ['rgb' => '666666'], 'italic' => true, 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]); $rowH($r, 14); $r++;

    $merge("D{$r}:H{$r}"); $setCell("D{$r}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi, Vietnam');
    $sStyle("D{$r}", ['font' => ['size' => 9, 'color' => ['rgb' => '444444'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]); $rowH($r, 14); $r++;

    $merge("D{$r}:H{$r}"); $setCell("D{$r}", 'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com');
    $sStyle("D{$r}", ['font' => ['size' => 9, 'color' => ['rgb' => '444444'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]); $rowH($r, 14); $r++;

    $merge("D{$r}:H{$r}"); $setCell("D{$r}", 'MST / Tax Code: 0110453612');
    $sStyle("D{$r}", ['font' => ['size' => 9, 'color' => ['rgb' => '666666'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]); $rowH($r, 14); $r++;

    $rowH($r, 8); $r++;
    $merge("B{$r}:H{$r}"); $rowH($r, 3); $sStyle("B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]]]); $r++;
    $merge("B{$r}:H{$r}"); $rowH($r, 2); $sStyle("B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_GOLD]]]); $r++;

    $rowH($r, 36); $merge("B{$r}:H{$r}");
    $setCell("B{$r}", 'DEBIT NOTE / INVOICE');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]); $r++;

    $merge("B{$r}:H{$r}"); $rowH($r, 3); $sStyle("B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_GOLD]]]); $r++;
    $rowH($r, 10); $r++;

    $labelStyle = ['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1B3A6B'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_LIGHT_BLUE]], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]];
    $valueStyle = ['font' => ['size' => 10, 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]];

    $rowH($r, 22); $merge("B{$r}:H{$r}");
    $setCell("B{$r}", '  SHIPMENT INFORMATION  /  THONG TIN LO HANG');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_MID_BLUE]], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
    $infoSectionHeaderRow = $r; $r++;

    $rowH($r, 20);
    $setCell("B{$r}", 'BILL TO:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setCell("C{$r}", strtoupper($shipment['company_name'] ?? ''));
    $sStyle("C{$r}", ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => $C_ACCENT], 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
    $setCell("F{$r}", 'JOB NO:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", $shipment['job_no'] ?? '');
    $sStyle("G{$r}", ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0070C0'], 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER]]);
    $setCell("H{$r}", ''); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'TAX ID:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setStr("C{$r}", $shipment['customer_tax'] ?? ''); $sStyle("C{$r}", $valueStyle);
    $setCell("F{$r}", 'DATE:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", date('d/m/Y')); $sStyle("G{$r}", ['font' => ['size' => 10, 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'ADDRESS:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setCell("C{$r}", $shipment['customer_address'] ?? ''); $sStyle("C{$r}", $valueStyle);
    $setCell("F{$r}", 'CONTACT:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", $shipment['customer_contact'] ?? ''); $sStyle("G{$r}", $valueStyle); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'PHONE:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setCell("C{$r}", $shipment['customer_phone'] ?? ''); $sStyle("C{$r}", $valueStyle);
    $setCell("F{$r}", 'EMAIL:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", $shipment['customer_email'] ?? ''); $sStyle("G{$r}", $valueStyle); $r++;

    $rowH($r, 6); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'MAWB / MBL:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setStr("C{$r}", $shipment['mawb'] ?? ''); $sStyle("C{$r}", ['font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
    $setCell("F{$r}", 'VESSEL / FLIGHT:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", $shipment['vessel_flight'] ?? ''); $sStyle("G{$r}", $valueStyle); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'HAWB / HBL:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setStr("C{$r}", $shipment['hawb'] ?? ''); $sStyle("C{$r}", ['font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
    $setCell("F{$r}", 'POL -> POD:'); $sStyle("F{$r}", $labelStyle);
    $polpod = trim(($shipment['pol'] ?? '') . ' -> ' . ($shipment['pod'] ?? ''), ' ->');
    $setCell("G{$r}", $polpod); $sStyle("G{$r}", $valueStyle); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'CUSTOMS DEC.:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $setStr("C{$r}", $shipment['customs_declaration_no'] ?? ''); $sStyle("C{$r}", $valueStyle);
    $setCell("F{$r}", 'ARRIVAL DATE:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '');
    $sStyle("G{$r}", ['font' => ['size' => 10, 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]); $r++;

    $rowH($r, 18); $setCell("B{$r}", 'PKG / GW / CW:'); $sStyle("B{$r}", $labelStyle); $merge("C{$r}:E{$r}");
    $pkg_str = ($shipment['packages'] ?? 0) . ' PKGS  |  GW: ' . number_format($shipment['gw'] ?? 0, 2, ',', '.') . ' KGS  |  CW/CBM: ' . number_format($shipment['cw'] ?? 0, 2, ',', '.');
    $setCell("C{$r}", $pkg_str); $sStyle("C{$r}", $valueStyle);
    $setCell("F{$r}", 'SHIPPER:'); $sStyle("F{$r}", $labelStyle);
    $setCell("G{$r}", $shipment['shipper'] ?? ''); $sStyle("G{$r}", $valueStyle); $r++;

    $infoEndRow = $r - 1;
    for ($row = $infoSectionHeaderRow; $row <= $infoEndRow; $row++) {
        $sheet->getStyle("B{$row}:H{$row}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]]);
    }
    $borderBox("B{$infoSectionHeaderRow}:H{$infoEndRow}", $C_BORDER, Border::BORDER_MEDIUM);
    $rowH($r, 12); $r++;

    $rowH($r, 22); $merge("B{$r}:H{$r}");
    $setCell("B{$r}", '  DESCRIPTION OF CHARGES  /  CHI TIET PHI DICH VU');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_MID_BLUE]], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]); $r++;

    $rowH($r, 24);
    foreach ([
        'B' => ['STT', Alignment::HORIZONTAL_CENTER],
        'C' => ['MO TA CHI PHI / DESCRIPTION', Alignment::HORIZONTAL_LEFT],
        'D' => ['SO LUONG', Alignment::HORIZONTAL_CENTER],
        'E' => ['DON GIA (VND)', Alignment::HORIZONTAL_RIGHT],
        'F' => ['VAT %', Alignment::HORIZONTAL_CENTER],
        'G' => ['THANH TIEN (VND)', Alignment::HORIZONTAL_RIGHT],
        'H' => ['GHI CHU / NOTES', Alignment::HORIZONTAL_LEFT],
    ] as $col => $info) {
        $setCell("{$col}{$r}", $info[0]);
        $sStyle("{$col}{$r}", ['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'alignment' => ['horizontal' => $info[1], 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '4472C4']]]]);
    }
    $tableHeaderRow = $r; $r++;

    $num = 1; $subtotal_before_vat = 0; $subtotal_total = 0;
    foreach ($sells as $sell) {
        $rowH($r, 20);
        $amount = $sell['unit_price'] * $sell['quantity'];
        $sum    = $sell['total_amount'];
        $subtotal_before_vat += $amount; $subtotal_total += $sum;
        $rowBg = ($num % 2 === 0) ? 'EBF3FB' : 'FFFFFF';
        $cellSt = function($align, $bold = false, $color = '333333') use ($rowBg) {
            return ['font' => ['size' => 10, 'bold' => $bold, 'color' => ['rgb' => $color], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rowBg]], 'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'BDD7EE']]]];
        };
        $setCell("B{$r}", $num); $sStyle("B{$r}", $cellSt(Alignment::HORIZONTAL_CENTER));
        $setCell("C{$r}", $sell['description']); $sStyle("C{$r}", $cellSt(Alignment::HORIZONTAL_LEFT));
        $setStr("D{$r}", number_format($sell['quantity'], 2, ',', '.')); $sStyle("D{$r}", $cellSt(Alignment::HORIZONTAL_CENTER));
        $setStr("E{$r}", number_format($sell['unit_price'], 0, ',', '.')); $sStyle("E{$r}", $cellSt(Alignment::HORIZONTAL_RIGHT));
        $setCell("F{$r}", number_format($sell['vat'], 2, ',', '.') . '%'); $sStyle("F{$r}", $cellSt(Alignment::HORIZONTAL_CENTER));
        $setStr("G{$r}", number_format($sum, 0, ',', '.')); $sStyle("G{$r}", $cellSt(Alignment::HORIZONTAL_RIGHT, true, $C_GREEN));
        $setCell("H{$r}", $sell['notes'] ?? ''); $sStyle("H{$r}", $cellSt(Alignment::HORIZONTAL_LEFT));
        $num++; $r++;
    }

    if (empty($sells)) {
        $rowH($r, 20); $merge("B{$r}:H{$r}"); $setCell("B{$r}", 'Chua co du lieu / No data available');
        $sStyle("B{$r}", ['font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '999999'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9F9F9']], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]]); $r++;
    }

    $dataRowEnd = $r - 1;
    $borderBox("B{$tableHeaderRow}:H{$dataRowEnd}", $C_DARK_BLUE, Border::BORDER_MEDIUM);

    $rowH($r, 18); $merge("B{$r}:F{$r}");
    $setCell("B{$r}", 'Tong truoc VAT / Sub-total (before VAT):');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_GRAY_BG]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]]);
    $setStr("G{$r}", number_format($subtotal_before_vat, 0, ',', '.'));
    $sStyle("G{$r}", ['font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_GRAY_BG]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]]);
    $setCell("H{$r}", ''); $sStyle("H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_GRAY_BG]], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]]); $r++;

    $rowH($r, 28); $merge("B{$r}:F{$r}");
    $setCell("B{$r}", 'TONG CONG (DA BAO GOM VAT) / GRAND TOTAL (VAT INCLUDED):');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $C_DARK_BLUE]]]]);
    $setStr("G{$r}", number_format($subtotal_total, 0, ',', '.') . ' VND');
    $sStyle("G{$r}", ['font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $C_DARK_BLUE]]]]);
    $setCell("H{$r}", ''); $sStyle("H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $C_DARK_BLUE]]]]); $r++;
    $rowH($r, 12); $r++;

    $rowH($r, 22); $merge("B{$r}:H{$r}");
    $setCell("B{$r}", '  PAYMENT INFORMATION  /  THONG TIN THANH TOAN');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_MID_BLUE]], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
    $borderBox("B{$r}:H{$r}", $C_MID_BLUE); $r++;

    $rowH($r, 18); $merge("B{$r}:H{$r}");
    $setCell("B{$r}", '  Terms: Payment due within 30 days from invoice date.');
    $sStyle("B{$r}", ['font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9E6']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDDDDD']]]]); $r++;
    $rowH($r, 6); $r++;

    $bankStartRow = $r;
    $rowH($r, 20); $merge("B{$r}:D{$r}");
    $setCell("B{$r}", 'TAI KHOAN THANH TOAN DICH VU');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
    $merge("F{$r}:H{$r}");
    $setCell("F{$r}", 'TAI KHOAN THANH TOAN CHI HO (POB)');
    $sStyle("F{$r}", ['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A237E']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
    $setCell("E{$r}", ''); $r++;

    $bankRows = [
        ['Chu TK:', 'CONG TY TNHH LIPRO LOGISTICS', 'VU THUY LINH'],
        ['So TK:', '9039998888', '19032342305016'],
        ['Ngan hang:', 'MB Bank (Quan doi)', 'Techcombank'],
        ['Chi nhanh:', 'Ha Noi', 'Ha Noi'],
        ['Noi dung CK:', 'LIPRO - ' . ($shipment['job_no'] ?? '') . ' - ' . ($shipment['hawb'] ?? ''), 'POB - ' . ($shipment['job_no'] ?? '')],
    ];

    foreach ($bankRows as $br) {
        $rowH($r, 18);
        $setCell("B{$r}", $br[0]); $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '1B5E20'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C8E6C9']]]]);
        $sheet->mergeCells("C{$r}:D{$r}"); $setStr("C{$r}", $br[1]); $sStyle("C{$r}", ['font' => ['size' => 9, 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F8F1']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C8E6C9']]]]);
        $setCell("E{$r}", '');
        $setCell("F{$r}", $br[0]); $sStyle("F{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '1A237E'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EAF6']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C5CAE9']]]]);
        $sheet->mergeCells("G{$r}:H{$r}"); $setStr("G{$r}", $br[2]); $sStyle("G{$r}", ['font' => ['size' => 9, 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF0FB']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C5CAE9']]]]);
        $r++;
    }

    $borderBox("B{$bankStartRow}:D" . ($r - 1), '1B5E20', Border::BORDER_MEDIUM);
    $borderBox("F{$bankStartRow}:H" . ($r - 1), '1A237E', Border::BORDER_MEDIUM);
    $rowH($r, 10); $r++;

    $rowH($r, 16); $merge("B{$r}:D{$r}");
    $setCell("B{$r}", 'Nguoi lap / Prepared by:');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    $merge("F{$r}:H{$r}"); $setCell("F{$r}", 'Xac nhan / Confirmed by:');
    $sStyle("F{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]); $r++;
    for ($i = 0; $i < 3; $i++) { $rowH($r, 16); $r++; }

    $rowH($r, 16); $merge("B{$r}:D{$r}"); $setCell("B{$r}", 'LIPRO LOGISTICS CO.,LTD');
    $sStyle("B{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $C_ACCENT], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]]]);
    $merge("F{$r}:H{$r}"); $setCell("F{$r}", strtoupper($shipment['company_name'] ?? ''));
    $sStyle("F{$r}", ['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '333333'], 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]]]); $r++;

    $rowH($r, 8); $r++;
    $merge("B{$r}:H{$r}"); $rowH($r, 14);
    $setCell("B{$r}", 'Thank you for your business! - Cam on Quy khach da su dung dich vu cua chung toi.');
    $sStyle("B{$r}", ['font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $C_DARK_BLUE]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]); $r++;

    $sheet->getPageSetup()->setPrintArea("A1:I{$r}");
    return $spreadsheet;
}

// ============================================================
// MAIL BODY (giữ nguyên)
// ============================================================
function buildMailBody($shipment, $sells, $total_sell, $extra = '') {
    $hawb = $shipment['hawb'] ?? ''; $cd_no = $shipment['customs_declaration_no'] ?? ''; $cus_name = $shipment['company_name'] ?? '';
    $rows = ''; $i = 1;
    foreach ($sells as $s) {
        $amount = number_format($s['unit_price'] * $s['quantity'], 0, ',', '.'); $vat_amount = number_format($s['unit_price'] * $s['quantity'] * $s['vat'] / 100, 0, ',', '.'); $sum = number_format($s['total_amount'], 0, ',', '.'); $bg = $i % 2 === 0 ? '#f8f9fa' : '#ffffff';
        $rows .= "<tr style='background:{$bg};'><td style='padding:7px 10px;border:1px solid #dee2e6;text-align:center;'>{$i}</td><td style='padding:7px 10px;border:1px solid #dee2e6;'>" . htmlspecialchars($s['description']) . "</td><td style='padding:7px 10px;border:1px solid #dee2e6;text-align:right;'>{$amount}</td><td style='padding:7px 10px;border:1px solid #dee2e6;text-align:right;'>{$vat_amount}</td><td style='padding:7px 10px;border:1px solid #dee2e6;text-align:right;font-weight:bold;color:#dc3545;'>{$sum}</td></tr>"; $i++;
    }
    $extra_html = !empty($extra) ? "<div style='margin:15px 0;padding:12px 15px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;font-size:13px;'>" . nl2br(htmlspecialchars($extra)) . "</div>" : '';
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,sans-serif;font-size:14px;color:#333;'><div style='max-width:680px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,.1);'><div style='background:linear-gradient(135deg,#1B3A6B 0%,#2E75B6 100%);padding:25px 30px;text-align:center;'><h1 style='color:#fff;margin:0 0 5px;font-size:22px;'>LIPRO LOGISTICS CO.,LTD</h1><p style='color:#a8c6e8;margin:0;font-size:12px;'>No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi, Vietnam<br>Tel: (+84) 366 666 322 | Email: lipro.logistics@gmail.com</p></div><div style='background:#C00000;padding:10px 30px;text-align:center;'><h2 style='color:#fff;margin:0;font-size:16px;letter-spacing:3px;'>DEBIT NOTE / INVOICE</h2></div><div style='padding:25px 30px;'><div style='background:#fff8e1;border-left:4px solid #F4B942;border-radius:4px;padding:12px 15px;margin-bottom:20px;'><p style='margin:0;font-size:14px;'><strong>Thông báo DEBIT của lô hàng:</strong><span style='color:#C00000;font-weight:bold;font-size:15px;'>" . ($hawb ? " HAWB: {$hawb}" : '') . ($cd_no ? " &nbsp;|&nbsp; Tờ khai: {$cd_no}" : '') . "</span></p></div><p style='margin:0 0 5px;font-size:15px;'><strong>Kính chào:</strong><span style='color:#1B3A6B;font-weight:bold;'> " . htmlspecialchars($cus_name) . "</span></p><p style='margin:0 0 20px;font-size:13px;color:#555;line-height:1.8;'>Đây là Email thông báo quan trọng về việc thông báo chi phí cho lô hàng trên, Bạn vui lòng kiểm tra và phản hồi lại chúng tôi nếu có sai sót.</p><table style='width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;'><tr style='background:#1B3A6B;color:#fff;'><th style='padding:8px 12px;text-align:left;' colspan='2'>Thông tin lô hàng</th></tr><tr style='background:#f8f9fa;'><td style='padding:7px 12px;font-weight:bold;width:35%;border-bottom:1px solid #dee2e6;'>Khách hàng:</td><td style='padding:7px 12px;border-bottom:1px solid #dee2e6;'><strong>" . htmlspecialchars($cus_name) . "</strong></td></tr><tr><td style='padding:7px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>Job ID #:</td><td style='padding:7px 12px;border-bottom:1px solid #dee2e6;'><strong style='color:#0070C0;font-size:15px;'>" . htmlspecialchars($shipment['job_no']) . "</strong></td></tr><tr style='background:#f8f9fa;'><td style='padding:7px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>HAWB:</td><td style='padding:7px 12px;border-bottom:1px solid #dee2e6;'><strong>" . htmlspecialchars($hawb) . "</strong></td></tr><tr><td style='padding:7px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>MBL:</td><td style='padding:7px 12px;border-bottom:1px solid #dee2e6;'>" . htmlspecialchars($shipment['mawb'] ?? '—') . "</td></tr><tr style='background:#f8f9fa;'><td style='padding:7px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>Số tờ khai:</td><td style='padding:7px 12px;border-bottom:1px solid #dee2e6;'><strong>" . htmlspecialchars($cd_no ?: '—') . "</strong></td></tr><tr><td style='padding:7px 12px;font-weight:bold;'>Ngày:</td><td style='padding:7px 12px;'>" . date('d/m/Y') . "</td></tr></table><h3 style='color:#1B3A6B;font-size:14px;margin:0 0 8px;padding-bottom:5px;border-bottom:2px solid #1B3A6B;'>Chi tiết phí dịch vụ</h3><table style='width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;'><thead><tr style='background:#1B3A6B;color:#fff;'><th style='padding:8px 10px;border:1px solid #1B3A6B;text-align:center;width:35px;'>STT</th><th style='padding:8px 10px;border:1px solid #1B3A6B;text-align:left;'>Nội dung</th><th style='padding:8px 10px;border:1px solid #1B3A6B;text-align:right;'>Số tiền</th><th style='padding:8px 10px;border:1px solid #1B3A6B;text-align:right;'>VAT</th><th style='padding:8px 10px;border:1px solid #1B3A6B;text-align:right;'>Thành tiền</th></tr></thead><tbody>{$rows}<tr style='background:#fff3cd;font-weight:bold;'><td colspan='4' style='padding:9px 10px;border:1px solid #dee2e6;text-align:right;'>TỔNG CỘNG (ĐÃ BAO GỒM VAT):</td><td style='padding:9px 10px;border:1px solid #dee2e6;text-align:right;color:#C00000;font-size:16px;'>" . number_format($total_sell, 0, ',', '.') . " VND</td></tr></tbody></table><div style='background:#1B3A6B;color:#fff;padding:15px 20px;border-radius:8px;margin-bottom:20px;'><table style='width:100%;'><tr><td style='font-size:13px;'>Tổng số tiền thanh toán (đã bao gồm VAT):</td><td style='text-align:right;font-size:22px;font-weight:bold;color:#90ee90;'>" . number_format($total_sell, 0, ',', '.') . " VND</td></tr></table></div>{$extra_html}<div style='background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:15px 20px;margin-bottom:20px;'><h4 style='color:#1B5E20;margin:0 0 10px;font-size:14px;border-bottom:1px solid #dee2e6;padding-bottom:6px;'>Thông tin tài khoản thanh toán:</h4><table style='width:100%;font-size:13px;'><tr><td style='padding:3px 0;font-weight:bold;width:35%;'>Số tài khoản:</td><td style='padding:3px 0;font-weight:bold;color:#1B5E20;'>9039998888 (VND)</td></tr><tr><td style='padding:3px 0;font-weight:bold;'>Ngân hàng:</td><td style='padding:3px 0;'>MB Bank (Quân đội)</td></tr><tr><td style='padding:3px 0;font-weight:bold;'>Tên thụ hưởng:</td><td style='padding:3px 0;font-weight:bold;'>CONG TY TNHH LIPRO LOGISTICS</td></tr></table></div><p style='margin:0 0 20px;font-size:12px;color:#888;font-style:italic;'>Terms: Payment due within 30 days from invoice date.</p><div style='border-top:1px solid #dee2e6;padding-top:15px;'><p style='margin:0 0 5px;font-size:13px;color:#555;'>Trân trọng,</p><p style='margin:0;'><strong style='color:#1B3A6B;font-size:14px;'>LIPRO LOGISTICS CO.,LTD</strong><br><span style='color:#888;font-size:12px;'>📞 (+84) 366 666 322 &nbsp;|&nbsp; ✉️ lipro.logistics@gmail.com</span></p></div></div><div style='background:#f8f9fa;padding:12px 30px;text-align:center;border-top:1px solid #dee2e6;'><p style='margin:0;color:#aaa;font-size:11px;'>Email này được tạo tự động từ hệ thống Forwarder System.</p></div></div></body></html>";
}

function buildMailBodyText($shipment, $total_sell) {
    return "LIPRO LOGISTICS CO.,LTD\n============================================================\nDEBIT NOTE / INVOICE\nLo hang: HAWB: " . ($shipment['hawb'] ?? '') . (($shipment['customs_declaration_no'] ?? '') ? " | To khai: " . $shipment['customs_declaration_no'] : '') . "\nKinh chao: " . ($shipment['company_name'] ?? '') . "\n\nDay la Email thong bao quan trong ve chi phi lo hang tren.\nBan vui long kiem tra va phan hoi neu co sai sot.\n\nJob ID: " . $shipment['job_no'] . "\nTong tien (da bao gom VAT): " . number_format($total_sell, 0, ',', '.') . " VND\n------------------------------------------------------------\nTK: 9039998888 (VND) - MB Bank - CONG TY TNHH LIPRO LOGISTICS\n";
}

// ============================================================
// XỬ LÝ GỬI MAIL
// ============================================================
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_mail'])) {
    $to_raw     = trim($_POST['to_email'] ?? '');
    $to_emails  = splitEmails($to_raw);
    $to_email   = $to_emails[0] ?? '';
    $auto_cc    = array_slice($to_emails, 1);

    $to_name    = trim($_POST['to_name']    ?? '');
    $cc_str     = trim($_POST['cc_email']   ?? '');
    $bcc_str    = trim($_POST['bcc_email']  ?? '');
    $subject    = trim($_POST['subject']    ?? $auto_subject);
    $body_extra = trim($_POST['body_extra'] ?? '');

    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email người nhận (To) không hợp lệ! Ít nhất một email hợp lệ phải được nhập.';
    } else {
        $tmpFile = '';
        try {
            $spreadsheet = buildExcelFile($shipment, $sells);
            $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                     . 'debit_' . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no'])
                     . '_' . time() . '.xlsx';
            (new Xlsx($spreadsheet))->save($tmpFile);
            if (!file_exists($tmpFile)) throw new \Exception('Không tạo được file Excel!');

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($to_email, $to_name);
            // Thêm auto CC từ to field
            foreach ($auto_cc as $acc) {
                $mail->addCC($acc);
            }

            // BCC cố định
            $mail->addBCC('dung@dnaexpress.vn', 'Dung');

            // ✅ CC - tách bằng ; hoặc ,
            foreach (splitEmails($cc_str) as $cc) {
                $mail->addCC($cc);
            }

            // ✅ BCC thêm - tách bằng ; hoặc ,
            foreach (splitEmails($bcc_str) as $bcc) {
                $mail->addBCC($bcc);
            }

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = buildMailBody($shipment, $sells, $total_sell, $body_extra);
            $mail->AltBody = buildMailBodyText($shipment, $total_sell);

            // Đính kèm file Debit Note Excel
            $attachName = 'DebitNote_' . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no']) . '_' . date('Ymd') . '.xlsx';
            $mail->addStringAttachment(file_get_contents($tmpFile), $attachName, 'base64', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            // ✅ Đính kèm các file đã lưu
            $baseDir = dirname(__DIR__) . '/';
            foreach ($saved_attachments as $att) {
                $fullPath = $baseDir . $att['file_path'];
                if (file_exists($fullPath)) {
                    $mail->addAttachment($fullPath, $att['file_name']);
                }
            }

            $mail->send();
            if (file_exists($tmpFile)) unlink($tmpFile);

            $conn2 = getDBConnection();
            $sent_at = date('Y-m-d H:i:s');
            $stmt_upd = $conn2->prepare("UPDATE shipments SET email_sent='yes', email_sent_at=?, email_sent_by=? WHERE id=?");
            $stmt_upd->bind_param("sii", $sent_at, $_SESSION['user_id'], $id);
            $stmt_upd->execute();
            $conn2->close();

            $shipment['email_sent']    = 'yes';
            $shipment['email_sent_at'] = $sent_at;

            // Reload attachments
            $conn3 = getDBConnection();
            $stmt_att2 = $conn3->prepare("SELECT * FROM shipment_attachments WHERE shipment_id = ? ORDER BY uploaded_at ASC");
            $stmt_att2->bind_param("i", $id);
            $stmt_att2->execute();
            $saved_attachments = $stmt_att2->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_att2->close();
            $conn3->close();

            $attCount = count($saved_attachments);
            $success = '✅ Đã gửi email thành công đến <strong>' . htmlspecialchars($to_email) . '</strong>!'
                     . ($attCount > 0 ? " (kèm {$attCount} file đính kèm)" : '');

        } catch (Exception $e) {
            if (!empty($tmpFile) && file_exists($tmpFile)) unlink($tmpFile);
            $error = '❌ Lỗi gửi mail: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage());
        } catch (\Throwable $e) {
            if (!empty($tmpFile) && file_exists($tmpFile)) unlink($tmpFile);
            $error = '❌ Lỗi hệ thống: ' . $e->getMessage() . ' <small>(line ' . $e->getLine() . ')</small>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi Debit Note - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .preview-frame { width:100%; height:520px; border:none; }
        .info-chip { background:#e8f4fd; border:1px solid #bee5eb; border-radius:20px; padding:3px 12px; font-size:.82rem; color:#0c5460; display:inline-block; }
        .email-sent-banner { background:linear-gradient(135deg,#d4edda,#c3e6cb); border:1px solid #28a745; border-radius:8px; padding:12px 18px; margin-bottom:16px; }
        .att-item { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:8px 12px; display:flex; align-items:center; gap:10px; }
        .att-item:hover { border-color:#adb5bd; }
        .drop-zone { border:2px dashed #0d6efd; border-radius:8px; padding:20px; text-align:center; cursor:pointer; transition:background .2s; }
        .drop-zone.dragover { background:#e8f4fd; }
        .drop-zone input[type=file] { display:none; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link" href="view.php?id=<?php echo $id; ?>">← <?php echo htmlspecialchars($shipment['job_no']); ?></a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../logout.php">Đăng xuất</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3 pb-5">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Lô hàng</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($shipment['job_no']); ?></a></li>
            <li class="breadcrumb-item active">Gửi Debit Note</li>
        </ol>
    </nav>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
        <div class="mt-2 d-flex gap-2">
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-success btn-sm"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <a href="send_debit.php?id=<?php echo $id; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-envelope"></i> Gửi lại</a>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- FORM -->
        <div class="col-xl-5 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white py-2">
                    <h5 class="mb-0"><i class="bi bi-envelope-fill"></i> Gửi Debit Note - <?php echo htmlspecialchars($shipment['job_no']); ?></h5>
                </div>
                <div class="card-body">

                    <?php if (!empty($shipment['email_sent']) && $shipment['email_sent'] == 'yes'): ?>
                    <div class="email-sent-banner">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-envelope-check-fill text-success fs-4"></i>
                            <div>
                                <strong class="text-success">Đã gửi email trước đó!</strong>
                                <?php if (!empty($shipment['email_sent_at'])): ?>
                                <br><small class="text-muted"><i class="bi bi-clock"></i> Lần gửi gần nhất: <strong><?php echo date('d/m/Y H:i', strtotime($shipment['email_sent_at'])); ?></strong></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2"><small class="text-muted"><i class="bi bi-exclamation-triangle text-warning"></i> Bạn có thể gửi lại nếu cần.</small></div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="info-chip"><i class="bi bi-box text-primary"></i> <?php echo htmlspecialchars($shipment['job_no']); ?></span>
                        <span class="info-chip"><i class="bi bi-people text-info"></i> <?php echo htmlspecialchars($shipment['customer_short'] ?? ''); ?></span>
                        <?php if ($hawb): ?><span class="info-chip"><i class="bi bi-file-text text-warning"></i> HAWB: <?php echo htmlspecialchars($hawb); ?></span><?php endif; ?>
                        <?php if ($cd_no): ?><span class="info-chip"><i class="bi bi-card-text text-success"></i> TK: <?php echo htmlspecialchars($cd_no); ?></span><?php endif; ?>
                        <span class="info-chip"><i class="bi bi-currency-dollar text-success"></i> <?php echo number_format($total_sell, 0, ',', '.'); ?> VND</span>
                    </div>

                    <form method="POST" action="send_debit.php?id=<?php echo $id; ?>" id="sendForm">
                        <input type="hidden" name="send_mail" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-envelope text-primary"></i> Gửi đến (To) — email đầu tiên là người nhận chính, các email sau tự động thành CC <span class="text-danger">*</span></label>
                            <input type="text" name="to_email" id="toEmail" class="form-control" required
                                   placeholder="email1@company.com; email2@another.com" oninput="updatePreviewInfo()"
                                   value="<?php echo htmlspecialchars($_POST['to_email'] ?? ($shipment['customer_email'] ?? '')); ?>">
                            <small class="text-muted">Email đầu tiên là To, các email tiếp theo tự động vào CC</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person text-primary"></i> Tên người nhận</label>
                            <input type="text" name="to_name" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['to_name'] ?? ($shipment['customer_contact'] ?? $shipment['company_name'])); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-people text-primary"></i> CC
                                <small class="text-muted fw-normal">— nhiều email cách nhau dấu <kbd>;</kbd></small>
                            </label>
                            <input type="text" name="cc_email" class="form-control"
                                   placeholder="email1@abc.com; email2@xyz.com"
                                   value="<?php echo htmlspecialchars($_POST['cc_email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-eye-slash text-primary"></i> BCC
                                <small class="text-muted fw-normal">— nhiều email cách nhau dấu <kbd>;</kbd></small>
                            </label>
                            <input type="text" name="bcc_email" class="form-control"
                                   placeholder="bcc1@abc.com; bcc2@xyz.com"
                                   value="<?php echo htmlspecialchars($_POST['bcc_email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-chat-left-text text-primary"></i> Tiêu đề <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="mailSubject" class="form-control" required
                                   oninput="updatePreviewInfo()"
                                   value="<?php echo htmlspecialchars($_POST['subject'] ?? $auto_subject); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-text-paragraph text-primary"></i> Nội dung thêm <small class="text-muted fw-normal">(tùy chọn)</small></label>
                            <textarea name="body_extra" class="form-control" rows="3"
                                      placeholder="Nhập ghi chú bổ sung..."><?php echo htmlspecialchars($_POST['body_extra'] ?? ''); ?></textarea>
                        </div>

                        <!-- FILE ĐÍNH KÈM -->
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-paperclip text-primary"></i> File đính kèm</label>

                            <!-- Debit Note Excel - luôn có -->
                            <div class="att-item mb-2">
                                <i class="bi bi-file-earmark-excel text-success fs-5"></i>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small">DebitNote_<?php echo $shipment['job_no']; ?>_<?php echo date('Ymd'); ?>.xlsx</div>
                                    <small class="text-muted">Debit Note Excel — tự động tạo</small>
                                </div>
                                <span class="badge bg-success">Auto</span>
                            </div>

                            <!-- File đã lưu -->
                            <div id="attList">
                            <?php foreach ($saved_attachments as $att): ?>
                            <div class="att-item mb-2" id="att-<?php echo $att['id']; ?>">
                                <i class="bi <?php echo fileIcon($att['file_type'] ?? ''); ?> fs-5"></i>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small"><?php echo htmlspecialchars($att['file_name']); ?></div>
                                    <small class="text-muted"><?php echo fmtSize(intval($att['file_size'])); ?> · <?php echo date('d/m/Y H:i', strtotime($att['uploaded_at'])); ?></small>
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="deleteAtt(<?php echo $att['id']; ?>)" title="Xóa file này">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                            </div>

                            <!-- Upload zone -->
                            <div class="drop-zone mt-2" id="dropZone" onclick="document.getElementById('fileInput').click()">
                                <input type="file" id="fileInput" multiple
                                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar">
                                <i class="bi bi-cloud-upload fs-3 text-primary"></i>
                                <div class="mt-1 small text-muted">Kéo thả hoặc click để thêm file đính kèm</div>
                                <div class="small text-muted">PDF, Word, Excel, Ảnh, ZIP · Tối đa 20MB/file</div>
                            </div>
                            <div id="uploadProgress" class="mt-2"></div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                            <?php if (!empty($shipment['email_sent']) && $shipment['email_sent'] == 'yes'): ?>
                            <button type="submit" class="btn btn-warning btn-lg px-4" id="sendBtn"
                                    onclick="return confirm('Lô hàng này đã được gửi email!\nBạn có chắc muốn GỬI LẠI không?')">
                                <i class="bi bi-send-fill"></i> Gửi lại Email
                            </button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-primary btn-lg px-4" id="sendBtn">
                                <i class="bi bi-send-fill"></i> Gửi Email
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- PREVIEW -->
        <div class="col-xl-7 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-eye"></i> Preview Email</span>
                    <span class="badge bg-success" id="previewTo"><?php echo htmlspecialchars($shipment['customer_email'] ?? 'Chưa có email'); ?></span>
                </div>
                <ul class="nav nav-tabs px-3 pt-2 bg-light border-bottom">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPreview"><i class="bi bi-eye-fill"></i> Nội dung email</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSummary"><i class="bi bi-info-circle"></i> Tóm tắt</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabPreview">
                        <iframe class="preview-frame" id="previewFrame"
                                srcdoc="<?php echo htmlspecialchars(buildMailBody($shipment, $sells, $total_sell)); ?>">
                        </iframe>
                    </div>
                    <div class="tab-pane fade" id="tabSummary">
                        <div class="p-3">
                            <table class="table table-sm table-bordered">
                                <tr><th class="bg-light" width="30%">Từ:</th><td><strong><?php echo MAIL_FROM_NAME; ?></strong> &lt;<?php echo MAIL_FROM; ?>&gt;</td></tr>
                                <tr><th class="bg-light">Đến:</th><td id="summaryTo"><?php echo htmlspecialchars($shipment['customer_email'] ?? '—'); ?></td></tr>
                                <tr><th class="bg-light">Tiêu đề:</th><td id="summarySubject"><?php echo htmlspecialchars($auto_subject); ?></td></tr>
                                <tr><th class="bg-light">Đính kèm:</th>
                                    <td>
                                        <div><i class="bi bi-file-earmark-excel text-success"></i> DebitNote_<?php echo $shipment['job_no']; ?>_<?php echo date('Ymd'); ?>.xlsx <span class="badge bg-success">Auto</span></div>
                                        <?php foreach ($saved_attachments as $att): ?>
                                        <div><i class="bi <?php echo fileIcon($att['file_type'] ?? ''); ?>"></i> <?php echo htmlspecialchars($att['file_name']); ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <tr><th class="bg-light">Job No:</th><td><strong class="text-primary"><?php echo htmlspecialchars($shipment['job_no']); ?></strong></td></tr>
                                <tr><th class="bg-light">HAWB:</th><td><?php echo htmlspecialchars($hawb ?: '—'); ?></td></tr>
                                <tr><th class="bg-light">Số tờ khai:</th><td><?php echo htmlspecialchars($cd_no ?: '—'); ?></td></tr>
                                <tr><th class="bg-light">Tổng tiền:</th><td><strong class="text-success fs-5"><?php echo number_format($total_sell, 0, ',', '.'); ?> VND</strong></td></tr>
                                <tr><th class="bg-light">Số dòng phí:</th><td><?php echo count($sells); ?> khoản</td></tr>
                                <tr><th class="bg-light">File đính kèm:</th><td><?php echo 1 + count($saved_attachments); ?> file (gồm Debit Note + <?php echo count($saved_attachments); ?> file thêm)</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white text-center py-2 border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SHIPMENT_ID = <?php echo $id; ?>;

function updatePreviewInfo() {
    const email = document.getElementById('toEmail').value;
    const subj  = document.getElementById('mailSubject').value;
    document.getElementById('previewTo').textContent      = email || 'Chưa nhập';
    document.getElementById('summaryTo').textContent      = email || '—';
    document.getElementById('summarySubject').textContent = subj  || '—';
}

document.getElementById('sendForm').addEventListener('submit', function(e) {
    const email = document.getElementById('toEmail').value;
    if (!email) { e.preventDefault(); alert('Vui lòng nhập email người nhận!'); return; }
    const btn = document.getElementById('sendBtn');
    setTimeout(function() {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang gửi...';
    }, 200);
});

// -------------------------------------------------------
// UPLOAD FILE
// -------------------------------------------------------
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('dragover',  function(e) { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', function()  { dropZone.classList.remove('dragover'); });
dropZone.addEventListener('drop',      function(e) {
    e.preventDefault(); dropZone.classList.remove('dragover');
    uploadFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', function() { uploadFiles(this.files); this.value = ''; });

function uploadFiles(files) {
    Array.from(files).forEach(function(file) {
        const formData = new FormData();
        formData.append('shipment_id', SHIPMENT_ID);
        formData.append('file', file);

        const prog = document.createElement('div');
        prog.className = 'alert alert-info py-1 px-2 mb-1 small';
        prog.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang tải: <strong>' + file.name + '</strong>';
        document.getElementById('uploadProgress').appendChild(prog);

        fetch('../api/upload_attachment.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                prog.remove();
                if (data.success) {
                    addAttItem(data);
                } else {
                    alert('Lỗi upload: ' + data.message);
                }
            })
            .catch(function() { prog.remove(); alert('Lỗi kết nối khi upload!'); });
    });
}

function addAttItem(att) {
    const icons = {
        'pdf': 'bi-file-earmark-pdf text-danger',
        'excel': 'bi-file-earmark-excel text-success',
        'word':  'bi-file-earmark-word text-primary',
        'image': 'bi-file-earmark-image text-info',
    };
    let icon = 'bi-file-earmark text-secondary';
    if (att.file_type) {
        if (att.file_type.includes('pdf'))   icon = 'bi-file-earmark-pdf text-danger';
        else if (att.file_type.includes('excel') || att.file_type.includes('spreadsheet')) icon = 'bi-file-earmark-excel text-success';
        else if (att.file_type.includes('word')  || att.file_type.includes('document'))    icon = 'bi-file-earmark-word text-primary';
        else if (att.file_type.includes('image')) icon = 'bi-file-earmark-image text-info';
        else if (att.file_type.includes('zip') || att.file_type.includes('rar')) icon = 'bi-file-earmark-zip text-warning';
    }
    const size = att.file_size < 1048576
        ? Math.round(att.file_size / 1024 * 10) / 10 + ' KB'
        : Math.round(att.file_size / 1048576 * 10) / 10 + ' MB';

    const div = document.createElement('div');
    div.className = 'att-item mb-2';
    div.id = 'att-' + att.id;
    div.innerHTML = '<i class="bi ' + icon + ' fs-5"></i>'
        + '<div class="flex-grow-1"><div class="fw-bold small">' + att.file_name + '</div>'
        + '<small class="text-muted">' + size + ' · Vừa tải lên</small></div>'
        + '<button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteAtt(' + att.id + ')" title="Xóa">'
        + '<i class="bi bi-trash"></i></button>';
    document.getElementById('attList').appendChild(div);
}

function deleteAtt(id) {
    if (!confirm('Xóa file đính kèm này khỏi hệ thống?')) return;
    const formData = new FormData();
    formData.append('id', id);

    fetch('../api/delete_attachment.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                const el = document.getElementById('att-' + id);
                if (el) el.remove();
            } else {
                alert('Lỗi xóa: ' + data.message);
            }
        })
        .catch(function() { alert('Lỗi kết nối!'); });
}

updatePreviewInfo();
</script>
</body>
</html>