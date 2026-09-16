<?php
require_once '../config/database.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
                        c.company_name, c.short_name AS customer_short,
                        c.address AS customer_address,
                        c.tax_code AS customer_tax,
                        c.email AS customer_email,
                        c.phone AS customer_phone,
                        c.contact_person AS customer_contact
                        FROM shipments s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

// ✅ Đổi JOIN → LEFT JOIN để lấy cả dòng cost_code_id = NULL
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

// Tách sells: dịch vụ thường vs chi hộ (is_pob = 1)
$sells_service = [];
$sells_pob     = [];
foreach ($sells as $s) {
    if (!empty($s['is_pob'])) { $sells_pob[] = $s; }
    else                       { $sells_service[] = $s; }
}
$grand_total_service = array_sum(array_column($sells_service, 'total_amount'));
$grand_total_pob     = array_sum(array_column($sells_pob,     'total_amount'));
$grand_total         = $grand_total_service + $grand_total_pob;

$conn->close();

// ============================================================
// BẢNG MÀU — giảm độ đậm, nhẹ nhàng hơn
// ============================================================
define('C_DARK_BLUE',  '2F5496');   // xanh navy nhạt hơn (cũ: 1B3A6B)
define('C_MID_BLUE',   '5B9BD5');   // xanh vừa nhạt hơn (cũ: 2E75B6)
define('C_LIGHT_BLUE', 'EBF3FB');   // nền label rất nhạt (cũ: DEEAF1)
define('C_ACCENT',     'C0504D');   // đỏ mềm hơn        (cũ: C00000)
define('C_GOLD',       'F9C457');   // vàng nhạt hơn      (cũ: F4B942)
define('C_GREEN',      '4E7F3E');   // xanh lá nhạt hơn   (cũ: 375623)
define('C_GRAY_BG',    'F5F5F5');   // xám rất nhạt
define('C_BORDER',     'D0E4F4');   // viền xanh rất nhạt
define('C_NAVY',       '3949AB');   // navy nhạt hơn      (cũ: 1A237E)
define('C_NAVY_LIGHT', 'EEF0FB');   // nền navy nhạt

// Header section: thay vì màu đậm dùng màu vừa
define('C_SECTION_BG', '6FA8DC');   // header section nhạt (cũ: C_MID_BLUE đậm)
define('C_TBL_HEADER', '4472C4');   // header cột bảng nhạt hơn (cũ: C_DARK_BLUE)
define('C_TOTAL_SVC',  '2F5496');   // tổng dịch vụ
define('C_TOTAL_POB',  '3949AB');   // tổng chi hộ
define('C_GRAND',      'C0504D');   // grand total

// Màu tài khoản ngân hàng — nhạt hơn
define('C_BANK_SVC_HDR',   '538135');   // header TK dịch vụ (cũ: 1B5E20)
define('C_BANK_SVC_LBL',   'EBF4E8');   // nền nhãn trái
define('C_BANK_SVC_VAL',   'F6FBF4');   // nền giá trị trái
define('C_BANK_SVC_BORDER','B8D8A8');   // viền trái
define('C_BANK_POB_HDR',   '3949AB');   // header TK POB (cũ: 1A237E)
define('C_BANK_POB_LBL',   'EEF0FB');   // nền nhãn phải
define('C_BANK_POB_VAL',   'F7F8FE');   // nền giá trị phải
define('C_BANK_POB_BORDER','C5CAE9');   // viền phải

// ============================================================
// KHỞI TẠO SPREADSHEET
// ============================================================
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

// ── Độ rộng cột ───────────────────────────────────────────
$sheet->getColumnDimension('A')->setWidth(1.5);
$sheet->getColumnDimension('B')->setWidth(13);
$sheet->getColumnDimension('C')->setWidth(32);
$sheet->getColumnDimension('D')->setWidth(9);
$sheet->getColumnDimension('E')->setWidth(16);
$sheet->getColumnDimension('F')->setWidth(14.5);
$sheet->getColumnDimension('G')->setWidth(18);
$sheet->getColumnDimension('H')->setWidth(25.5);
$sheet->getColumnDimension('I')->setWidth(1.5);

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function setCell($sheet, $cell, $value)          { $sheet->setCellValue($cell, $value); }
function styleRange($sheet, $range, $styleArr)   { $sheet->getStyle($range)->applyFromArray($styleArr); }
function rowH($sheet, $row, $h)                  { $sheet->getRowDimension($row)->setRowHeight($h); }
function merge($sheet, $range)                   { $sheet->mergeCells($range); }
function borderBox($sheet, $range, $color = '000000', $weight = Border::BORDER_THIN) {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => ['outline' => ['borderStyle' => $weight, 'color' => ['rgb' => $color]]]
    ]);
}

// ============================================================
// ROW TRACKER
// ============================================================
$r = 1;

// ============================================================
// PHẦN 1: LOGO TEXT + THÔNG TIN CÔNG TY
// ============================================================
rowH($sheet, $r, 6); $r++;   // row 1 — padding trên

// ── LOGO TEXT "LIPRO" (B2:C7) ─────────────────────────────
// Tạo logo bằng cell style: chữ LIPRO to, đậm, màu nổi, nền xanh nhạt
// Dùng merge B2:C7 để chiếm cùng vùng với ảnh logo cũ
rowH($sheet, $r, 22);
merge($sheet, "B{$r}:C7");

// Nếu có file logo thật → dùng ảnh
$logoPath = '../assets/images/logo.png';
if (file_exists($logoPath)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logoPath);
    $drawing->setCoordinates("B{$r}");
    $drawing->setWidth(110);
    $drawing->setHeight(88);
    $drawing->setOffsetX(4);
    $drawing->setOffsetY(4);
    $drawing->setWorksheet($sheet);
} else {
    // Fallback: logo chữ tạo bằng cell style
    // Dòng chữ "LIPRO" lớn
    setCell($sheet, "B{$r}", 'LIPRO');
    styleRange($sheet, "B{$r}", [
        'font' => [
            'bold'  => true,
            'size'  => 26,
            'color' => ['rgb' => C_DARK_BLUE],
            'name'  => 'Calibri',
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'EBF3FB'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_BOTTOM,
        ],
    ]);
}

// Thêm dòng sub-text "LOGISTICS" ngay dưới chữ LIPRO
// (Sẽ đặt vào row $r+1 trong phạm vi merge B:C)
// Vì B2:C7 đã merge, ta đặt sub-text vào B3 nhưng thực ra B2 đã merge hết
// → Giải pháp: vẽ logo text 2 dòng bằng Rich Text
if (!file_exists($logoPath)) {
    // Dùng Rich Text để có 2 dòng trong 1 cell
    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
    $run1 = $richText->createTextRun('LIPRO');
    $run1->getFont()->setBold(true)->setSize(28)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . C_DARK_BLUE))->setName('Calibri');
    $richText->createText("\n");
    $run2 = $richText->createTextRun('LOGISTICS');
    $run2->getFont()->setBold(false)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . C_MID_BLUE))->setName('Calibri');
    $richText->createText("\n");
    $run3 = $richText->createTextRun('━━━━━━━');
    $run3->getFont()->setBold(false)->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . C_GOLD))->setName('Calibri');

    $sheet->setCellValue("B{$r}", $richText);
    styleRange($sheet, "B{$r}", [
        'font' => ['name' => 'Calibri'],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'EBF3FB'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => C_BORDER]],
        ],
    ]);
}

// ── THÔNG TIN CÔNG TY (D2:H7) ─────────────────────────────

// Tên công ty (row 2)
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'LIPRO LOGISTICS CO.,LTD');
styleRange($sheet, "D{$r}", [
    'font'      => ['bold' => true, 'size' => 17, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
rowH($sheet, $r, 26); $r++;

// Tagline (row 3)
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'FREIGHT FORWARDING & CUSTOMS CLEARANCE SERVICES');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '888888'], 'italic' => true, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Address (row 4)
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi, Vietnam');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '666666'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Phone & Email (row 5)
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '666666'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Tax code (row 6)
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'MST / Tax Code: 0110453612');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '888888'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Padding dưới header công ty (row 7)
rowH($sheet, $r, 6); $r++;

// ── Đường kẻ phân cách mỏng ──────────────────────────────
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 2);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]]]);
$r++;

merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 2);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GOLD]]]);
$r++;

// ── Tiêu đề DEBIT NOTE ────────────────────────────────────
rowH($sheet, $r, 32);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", 'DEBIT NOTE / INVOICE');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 2);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GOLD]]]);
$r++;

rowH($sheet, $r, 8); $r++;

// ============================================================
// PHẦN 2: THÔNG TIN LÔ HÀNG
// ============================================================
$labelStyle = [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => C_DARK_BLUE], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_LIGHT_BLUE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
];
$valueStyle = [
    'font'      => ['size' => 9, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
];

// Section header — nhạt hơn
rowH($sheet, $r, 20);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  SHIPMENT INFORMATION  /  THONG TIN LO HANG');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_SECTION_BG]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$infoSectionHeaderRow = $r;
$r++;

// Hàm vẽ 1 dòng thông tin lô hàng (label trái | value trái | label phải | value phải)
function infoRow($sheet, &$r, $lbl1, $val1, $lbl2, $val2, $labelStyle, $valueStyle, $mergeVal1 = true) {
    rowH($sheet, $r, 17);
    setCell($sheet, "B{$r}", $lbl1);
    styleRange($sheet, "B{$r}", $labelStyle);
    if ($mergeVal1) { $sheet->mergeCells("C{$r}:E{$r}"); }
    setCell($sheet, "C{$r}", $val1);
    styleRange($sheet, "C{$r}", $valueStyle);
    setCell($sheet, "F{$r}", $lbl2);
    styleRange($sheet, "F{$r}", $labelStyle);
    setCell($sheet, "G{$r}", $val2);
    styleRange($sheet, "G{$r}", $valueStyle);
    // Hairline bottom
    $sheet->getStyle("B{$r}:H{$r}")->applyFromArray([
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]
    ]);
    $r++;
}

// BILL TO + JOB NO — merge G:H
rowH($sheet, $r, 20);
setCell($sheet, "B{$r}", 'BILL TO:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", strtoupper($shipment['company_name'] ?? ''));
styleRange($sheet, "C{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
setCell($sheet, "F{$r}", 'JOB NO:');
styleRange($sheet, "F{$r}", $labelStyle);
merge($sheet, "G{$r}:H{$r}");
setCell($sheet, "G{$r}", $shipment['job_no'] ?? '');
styleRange($sheet, "G{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => C_DARK_BLUE], 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray([
    'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]
]);
$r++;

// TAX ID + DATE
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'TAX ID:');     styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_tax'] ?? '');   styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'DATE:');       styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", date('d/m/Y')); styleRange($sheet, "G{$r}", array_merge($valueStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]));
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// ADDRESS + CONTACT
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'ADDRESS:');   styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_address'] ?? '');  styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'CONTACT:');  styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['customer_contact'] ?? ''); styleRange($sheet, "G{$r}", $valueStyle);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// PHONE + EMAIL
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'PHONE:');    styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_phone'] ?? '');    styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'EMAIL:');    styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['customer_email'] ?? '');   styleRange($sheet, "G{$r}", $valueStyle);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

rowH($sheet, $r, 4); $r++;  // spacer nhỏ

// MAWB + VESSEL/FLIGHT
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'MAWB / MBL:');   styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['mawb'] ?? '');
styleRange($sheet, "C{$r}", array_merge($valueStyle, ['font' => ['bold' => true, 'size' => 9, 'name' => 'Calibri', 'color' => ['rgb' => '333333']]]));
setCell($sheet, "F{$r}", 'VESSEL / FLIGHT:'); styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['vessel_flight'] ?? ''); styleRange($sheet, "G{$r}", $valueStyle);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// HAWB + POL→POD — merge G:H
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'HAWB / HBL:');  styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['hawb'] ?? '');
styleRange($sheet, "C{$r}", array_merge($valueStyle, ['font' => ['bold' => true, 'size' => 9, 'name' => 'Calibri', 'color' => ['rgb' => '333333']]]));
setCell($sheet, "F{$r}", 'POL -> POD:'); styleRange($sheet, "F{$r}", $labelStyle);
$polpod = trim(($shipment['pol'] ?? '') . ' -> ' . ($shipment['pod'] ?? ''), ' ->');
merge($sheet, "G{$r}:H{$r}");
setCell($sheet, "G{$r}", $polpod); styleRange($sheet, "G{$r}", $valueStyle);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// CUSTOMS DEC. + ARRIVAL DATE
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'CUSTOMS DEC.:'); styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customs_declaration_no'] ?? ''); styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'ARRIVAL DATE:'); styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '');
styleRange($sheet, "G{$r}", array_merge($valueStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]));
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// PKG/GW/CW + SHIPPER
rowH($sheet, $r, 17);
setCell($sheet, "B{$r}", 'PKG / GW / CW:'); styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
$pkg_str = ($shipment['packages'] ?? 0) . ' PKGS  |  GW: ' . number_format($shipment['gw'] ?? 0, 2, ',', '.') . ' KGS  |  CW/CBM: ' . number_format($shipment['cw'] ?? 0, 2, ',', '.');
setCell($sheet, "C{$r}", $pkg_str); styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'SHIPPER:'); styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['shipper'] ?? ''); styleRange($sheet, "G{$r}", $valueStyle);
$sheet->getStyle("B{$r}:H{$r}")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]]]);
$r++;

// Khung viền nhẹ cho phần info
$infoEndRow = $r - 1;
borderBox($sheet, "B{$infoSectionHeaderRow}:H{$infoEndRow}", C_BORDER, Border::BORDER_THIN);

rowH($sheet, $r, 10); $r++;

// ============================================================
// HELPER: Bảng chi tiết phí
// ============================================================
function drawChargesTable($sheet, &$r, $data, $sectionTitle, $headerBg, $totalLabel, $totalBg) {
    // Section header
    rowH($sheet, $r, 20);
    merge($sheet, "B{$r}:H{$r}");
    setCell($sheet, "B{$r}", '  ' . $sectionTitle);
    styleRange($sheet, "B{$r}", [
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerBg]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $r++;

    // Header cột bảng
    rowH($sheet, $r, 22);
    $cols = [
        'B' => ['STT',                        Alignment::HORIZONTAL_CENTER],
        'C' => ['MO TA / DESCRIPTION',        Alignment::HORIZONTAL_LEFT],
        'D' => ['SL',                          Alignment::HORIZONTAL_CENTER],
        'E' => ['DON GIA (VND)',               Alignment::HORIZONTAL_RIGHT],
        'F' => ['VAT %',                       Alignment::HORIZONTAL_CENTER],
        'G' => ['THANH TIEN (VND)',            Alignment::HORIZONTAL_RIGHT],
        'H' => ['GHI CHU / NOTES',             Alignment::HORIZONTAL_LEFT],
    ];
    foreach ($cols as $col => $info) {
        setCell($sheet, "{$col}{$r}", $info[0]);
        styleRange($sheet, "{$col}{$r}", [
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_TBL_HEADER]],
            'alignment' => ['horizontal' => $info[1], 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'A8C4E0']]],
        ]);
    }
    $tableHeaderRow = $r; $r++;

    $num = 1; $sub_pre = 0; $sub_total = 0;

    if (empty($data)) {
        rowH($sheet, $r, 18);
        merge($sheet, "B{$r}:H{$r}");
        setCell($sheet, "B{$r}", 'Chua co du lieu / No data available');
        styleRange($sheet, "B{$r}", [
            'font'      => ['size' => 9, 'italic' => true, 'color' => ['rgb' => 'AAAAAA'], 'name' => 'Calibri'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FAFAFA']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E0E0']]],
        ]);
        $r++;
    } else {
        foreach ($data as $sell) {
            rowH($sheet, $r, 18);
            $amount   = $sell['unit_price'] * $sell['quantity'];
            $sum      = $sell['total_amount'];
            $sub_pre  += $amount;
            $sub_total += $sum;
            $rowBg = ($num % 2 === 0) ? 'F0F6FC' : 'FFFFFF';  // sọc xanh rất nhạt

            $cs = function($align, $bold = false, $colorRgb = '444444') use ($rowBg) {
                return [
                    'font'      => ['size' => 9, 'bold' => $bold, 'color' => ['rgb' => $colorRgb], 'name' => 'Calibri'],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rowBg]],
                    'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDEAF5']]],
                ];
            };

            setCell($sheet, "B{$r}", $num);                     styleRange($sheet, "B{$r}", $cs(Alignment::HORIZONTAL_CENTER));
            setCell($sheet, "C{$r}", $sell['description']);      styleRange($sheet, "C{$r}", $cs(Alignment::HORIZONTAL_LEFT));
            $sheet->setCellValueExplicit("D{$r}", number_format($sell['quantity'], 2, ',', '.'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            styleRange($sheet, "D{$r}", $cs(Alignment::HORIZONTAL_CENTER));
            $sheet->setCellValueExplicit("E{$r}", number_format($sell['unit_price'], 0, ',', '.'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            styleRange($sheet, "E{$r}", $cs(Alignment::HORIZONTAL_RIGHT));
            setCell($sheet, "F{$r}", number_format($sell['vat'], 2, ',', '.') . '%');
            styleRange($sheet, "F{$r}", $cs(Alignment::HORIZONTAL_CENTER));
            $sheet->setCellValueExplicit("G{$r}", number_format($sum, 0, ',', '.'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            styleRange($sheet, "G{$r}", $cs(Alignment::HORIZONTAL_RIGHT, true, C_GREEN));
            setCell($sheet, "H{$r}", $sell['notes'] ?? '');      styleRange($sheet, "H{$r}", $cs(Alignment::HORIZONTAL_LEFT));

            $num++; $r++;
        }
    }

    $dataRowEnd = $r - 1;
    borderBox($sheet, "B{$tableHeaderRow}:H{$dataRowEnd}", 'C5D9F1', Border::BORDER_THIN);

    // Sub-total trước VAT
    rowH($sheet, $r, 16);
    merge($sheet, "B{$r}:F{$r}");
    setCell($sheet, "B{$r}", 'Tong truoc VAT / Sub-total (before VAT):');
    styleRange($sheet, "B{$r}", [
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '666666'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDDDDD']]],
    ]);
    $sheet->setCellValueExplicit("G{$r}", number_format($sub_pre, 0, ',', '.'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    styleRange($sheet, "G{$r}", [
        'font'      => ['bold' => true, 'size' => 9, 'name' => 'Calibri', 'color' => ['rgb' => '444444']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDDDDD']]],
    ]);
    setCell($sheet, "H{$r}", '');
    styleRange($sheet, "H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDDDDD']]]]);
    $r++;

    // Tổng cộng (VAT included)
    rowH($sheet, $r, 24);
    merge($sheet, "B{$r}:F{$r}");
    setCell($sheet, "B{$r}", strtoupper($totalLabel) . ':');
    styleRange($sheet, "B{$r}", [
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $totalBg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->setCellValueExplicit("G{$r}", number_format($sub_total, 0, ',', '.') . ' VND', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    styleRange($sheet, "G{$r}", [
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $totalBg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    setCell($sheet, "H{$r}", '');
    styleRange($sheet, "H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $totalBg]]]);
    borderBox($sheet, "B{$r}:H{$r}", $totalBg, Border::BORDER_THIN);
    $r++;

    return $sub_total;
}

// ============================================================
// PHẦN 3A: BẢNG DỊCH VỤ
// ============================================================
drawChargesTable($sheet, $r, $sells_service,
    'DESCRIPTION OF CHARGES  /  CHI TIET PHI DICH VU',
    C_SECTION_BG, 'TONG DICH VU / SERVICE TOTAL (VAT INCL.)', C_TOTAL_SVC);

rowH($sheet, $r, 10); $r++;

// ============================================================
// PHẦN 3B: BẢNG CHI HỘ — chỉ hiển thị khi có dữ liệu
// ============================================================
if (!empty($sells_pob)) {
    drawChargesTable($sheet, $r, $sells_pob,
        'PAID ON BEHALF (CHI HO / POB)  /  CHI TIET KHOAN CHI HO',
        C_NAVY, 'TONG CHI HO / POB TOTAL', C_TOTAL_POB);
    rowH($sheet, $r, 10); $r++;
}

// ============================================================
// GRAND TOTAL
// ============================================================
rowH($sheet, $r, 28);
merge($sheet, "B{$r}:F{$r}");
setCell($sheet, "B{$r}", 'TONG CONG / GRAND TOTAL (SERVICE + POB, VAT INCLUDED):');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAND]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->setCellValueExplicit("G{$r}", number_format($grand_total, 0, ',', '.') . ' VND', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
styleRange($sheet, "G{$r}", [
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAND]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
setCell($sheet, "H{$r}", '');
styleRange($sheet, "H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAND]]]);
borderBox($sheet, "B{$r}:H{$r}", C_GRAND, Border::BORDER_THIN);
$r++;

rowH($sheet, $r, 10); $r++;

// ============================================================
// PHẦN 4: THÔNG TIN THANH TOÁN — 2 bên song song
// ============================================================

// Section header
rowH($sheet, $r, 20);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  PAYMENT INFORMATION  /  THONG TIN THANH TOAN');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_SECTION_BG]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// Terms
rowH($sheet, $r, 16);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  Terms: Payment due within 30 days from invoice date. / Thanh toan trong vong 30 ngay ke tu ngay xuat hoa don.');
styleRange($sheet, "B{$r}", [
    'font'      => ['size' => 8, 'italic' => true, 'color' => ['rgb' => '777777'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFDE7']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E8E8E8']]],
]);
$r++;

rowH($sheet, $r, 5); $r++;

// ── Tiêu đề 2 block tài khoản ─────────────────────────────
$bankStartRow = $r;
rowH($sheet, $r, 18);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'TAI KHOAN THANH TOAN DICH VU');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_SVC_HDR]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
setCell($sheet, "E{$r}", '');
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", 'TAI KHOAN THANH TOAN CHI HO (POB)');
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_POB_HDR]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// Helper dòng tài khoản song song
function bankRow($sheet, &$r, $label, $valueLeft, $valueRight) {
    rowH($sheet, $r, 17);
    setCell($sheet, "B{$r}", $label);
    styleRange($sheet, "B{$r}", [
        'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => '3A6A2A'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_SVC_LBL]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => C_BANK_SVC_BORDER]]],
    ]);
    $sheet->mergeCells("C{$r}:D{$r}");
    setCell($sheet, "C{$r}", $valueLeft);
    styleRange($sheet, "C{$r}", [
        'font'      => ['size' => 8, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_SVC_VAL]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => C_BANK_SVC_BORDER]]],
    ]);
    setCell($sheet, "E{$r}", '');
    setCell($sheet, "F{$r}", $label);
    styleRange($sheet, "F{$r}", [
        'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => C_NAVY], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_POB_LBL]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => C_BANK_POB_BORDER]]],
    ]);
    $sheet->mergeCells("G{$r}:H{$r}");
    setCell($sheet, "G{$r}", $valueRight);
    styleRange($sheet, "G{$r}", [
        'font'      => ['size' => 8, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_BANK_POB_VAL]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => C_BANK_POB_BORDER]]],
    ]);
    $r++;
}

bankRow($sheet, $r, 'Chu TK:',      'CONG TY TNHH LIPRO LOGISTICS',  'VU THUY LINH');
bankRow($sheet, $r, 'So TK:',       '9039998888',                      '19032342305016');
bankRow($sheet, $r, 'Ngan hang:',   'MB Bank (Quan doi)',               'Techcombank');
bankRow($sheet, $r, 'Chi nhanh:',   'Ha Noi',                           'Ha Noi');
bankRow($sheet, $r, 'Noi dung CK:',
    'LIPRO - ' . ($shipment['job_no'] ?? '') . ' - ' . ($shipment['hawb'] ?? ''),
    'POB - '   . ($shipment['job_no'] ?? ''));

$bankEndRow = $r - 1;
borderBox($sheet, "B{$bankStartRow}:D{$bankEndRow}", C_BANK_SVC_HDR, Border::BORDER_THIN);
borderBox($sheet, "F{$bankStartRow}:H{$bankEndRow}", C_BANK_POB_HDR, Border::BORDER_THIN);

rowH($sheet, $r, 8); $r++;

// ============================================================
// FOOTER — CHỮ KÝ
// ============================================================
rowH($sheet, $r, 14);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'Nguoi lap / Prepared by:');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => '777777'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", 'Xac nhan / Confirmed by:');
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => '777777'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$r++;

for ($i = 0; $i < 3; $i++) { rowH($sheet, $r, 14); $r++; }

rowH($sheet, $r, 14);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'LIPRO LOGISTICS CO.,LTD');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BBBBBB']]],
]);
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", strtoupper($shipment['company_name'] ?? ''));
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 8, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BBBBBB']]],
]);
$r++;

rowH($sheet, $r, 6); $r++;

// Footer cuối
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 13);
setCell($sheet, "B{$r}", 'Thank you for your business! — Cam on Quy khach da su dung dich vu cua chung toi.');
styleRange($sheet, "B{$r}", [
    'font'      => ['size' => 8, 'italic' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// ============================================================
// PRINT AREA & OUTPUT
// ============================================================
$sheet->getPageSetup()->setPrintArea("A1:I{$r}");

$filename = 'DebitNote_' . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no'])
          . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>