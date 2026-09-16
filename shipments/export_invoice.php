<?php
require_once '../config/database.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
                        c.company_name,
                        c.address        AS customer_address,
                        c.tax_code       AS customer_tax,
                        c.email          AS customer_email,
                        c.phone          AS customer_phone,
                        c.contact_person AS customer_contact
                        FROM shipments s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

$stmt_sell = $conn->prepare("SELECT ss.*, cc.code, cc.description
                              FROM shipment_sells ss
                              JOIN cost_codes cc ON ss.cost_code_id = cc.id
                              WHERE ss.shipment_id = ?
                              ORDER BY ss.id");
$stmt_sell->bind_param("i", $id);
$stmt_sell->execute();
$sells = $stmt_sell->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ============================================================
// THÔNG TIN DÙNG CHUNG
// ============================================================
$hawb        = $shipment['hawb']                   ?? '';
$cd_no       = $shipment['customs_declaration_no'] ?? '';
$today       = date('d/m/Y');
$cus_name    = $shipment['company_name']            ?? '';
$cus_tax     = $shipment['customer_tax']            ?? '';
$cus_address = $shipment['customer_address']        ?? '';
$cus_contact = $shipment['customer_contact']        ?? '';
$cus_email   = $shipment['customer_email']          ?? '';
$co_email    = !empty(trim($cus_email)) ? 'CÓ' : 'KHÔNG';

// ============================================================
// KHỞI TẠO SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Hoa Don');
$sheet->setShowGridlines(true);

$colWidths = [
    'A' =>  4,   'B' =>  4,   'C' => 36,  'D' => 14,
    'E' =>  9,   'F' => 14,   'G' => 14,  'H' => 10,
    'I' => 14,   'J' => 14,   'K' => 22,  'L' => 32,
    'M' => 16,   'N' => 40,   'O' => 18,  'P' => 12,
    'Q' => 14,   'R' => 28,
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ============================================================
// HELPERS
// ============================================================
$setStr = function($cell, $val) use ($sheet) {
    $sheet->setCellValueExplicit($cell, (string)$val, DataType::TYPE_STRING);
};
$rowH = function($row, $h) use ($sheet) {
    $sheet->getRowDimension($row)->setRowHeight($h);
};

// Style chung cho cell dữ liệu
$baseStyle = [
    'font'      => ['size' => 10, 'name' => 'Arial'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
];
$centerStyle = array_merge_recursive($baseStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$rightStyle = array_merge_recursive($baseStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
]);

$applyRowStyle = function($row, $colStyles) use ($sheet, $baseStyle, $centerStyle, $rightStyle) {
    foreach ($colStyles as $col => $type) {
        $style = match($type) {
            'center' => $centerStyle,
            'right'  => $rightStyle,
            default  => $baseStyle,
        };
        $sheet->getStyle("{$col}{$row}")->applyFromArray($style);
    }
};

// ============================================================
// DÒNG 1 - HEADER CỘT
// ============================================================
$headers = [
    'A' => 'STT',
    'B' => 'MaHangHoaDichVu',
    'C' => 'TenHangHoaDichVu',
    'D' => 'DonViTinh_ChietKhau',
    'E' => 'SoLuong',
    'F' => 'DonGia',
    'G' => 'ThanhTien',
    'H' => 'ThueSuat',
    'I' => 'TienThueGTGT',
    'J' => 'NgayThangNamHD',
    'K' => 'HoTenNguoiMua',
    'L' => 'TenDonVi',
    'M' => 'MaSoThue',
    'N' => 'DiaChi',
    'O' => 'SoTaiKhoan',
    'P' => 'HinhThucTT',
    'Q' => 'CoBangEmail',
    'R' => 'DSEmail',
];

$rowH(1, 20);
foreach ($headers as $col => $label) {
    $sheet->setCellValue("{$col}1", $label);
    $sheet->getStyle("{$col}1")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 10, 'name' => 'Arial'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9DC3E6']]],
    ]);
}

// ============================================================
// HELPER: t�� style toàn bộ cột A-R cho 1 dòng
// ============================================================
$styleAllCols = function($r, $overrides = []) use ($sheet, $baseStyle, $centerStyle, $rightStyle) {
    $defaultMap = [
        'A' => 'center', 'B' => 'base',   'C' => 'base',   'D' => 'center',
        'E' => 'center', 'F' => 'right',  'G' => 'right',  'H' => 'center',
        'I' => 'right',  'J' => 'center', 'K' => 'base',   'L' => 'base',
        'M' => 'center', 'N' => 'base',   'O' => 'base',   'P' => 'center',
        'Q' => 'center', 'R' => 'base',
    ];
    $map = array_merge($defaultMap, $overrides);
    foreach ($map as $col => $type) {
        $style = match($type) {
            'center' => $centerStyle,
            'right'  => $rightStyle,
            default  => $baseStyle,
        };
        $sheet->getStyle("{$col}{$r}")->applyFromArray($style);
    }
};

// ============================================================
// DÒNG 2 - Số văn đơn (HAWB)
// ✅ ThueSuat = 'khác' (cố định)
// ============================================================
$r = 2;
$rowH($r, 18);

$setStr("C{$r}", 'Số vận đơn (' . $hawb . ')');
$setStr("D{$r}", 'Diễn giải');
$setStr("H{$r}", 'khác');   // ✅ ThueSuat cố định = 'khác'

$styleAllCols($r);
$r++;

// ============================================================
// DÒNG 3 - Số tờ khai (Customs Declaration No)
// ✅ ThueSuat = 'khác' (cố định)
// ============================================================
$rowH($r, 18);

$setStr("C{$r}", 'Số tờ khai (' . $cd_no . ')');
$setStr("D{$r}", 'Diễn giải');
$setStr("H{$r}", 'khác');   // ✅ ThueSuat cố định = 'khác'

$styleAllCols($r);
$r++;

// ============================================================
// DÒNG 4+ - Chi tiết từng phí (shipment_sells)
// ✅ ThueSuat lấy từ cột vat của shipment_sells
// ============================================================
$stt = 1;
foreach ($sells as $sell) {
    $rowH($r, 18);

    $qty       = $sell['quantity'];
    $price     = $sell['unit_price'];
    $vat       = $sell['vat'];           // ví dụ: 8 (%)
    $amount    = $price * $qty;          // ThanhTien
    $vat_amt   = $amount * $vat / 100;  // TienThueGTGT

    // ✅ ThueSuat: lấy từ vat của shipment_sells
    // Nếu vat = 0  → hiển thị '0%'
    // Nếu vat = 8  → hiển thị '8%'
    // Nếu vat = 10 → hiển thị '10%'
    // Có thể chỉnh thành 'khác' nếu muốn - hiện tại dùng đúng giá trị vat
    $thue_suat = ($vat == 0) ? '0%' : $vat . '%';

    // A - STT
    $sheet->setCellValue("A{$r}", $stt);

    // B - MaHangHoaDichVu (bỏ trống)

    // C - TenHangHoaDichVu
    $setStr("C{$r}", $sell['description']);

    // D - DonViTinh (luôn LÔ)
    $setStr("D{$r}", 'LÔ');

    // E - SoLuong
    $setStr("E{$r}", number_format($qty, 2, ',', '.'));

    // F - DonGia
    $setStr("F{$r}", number_format($price, 0, ',', '.'));

    // G - ThanhTien = unit_price * quantity
    $setStr("G{$r}", number_format($amount, 0, ',', '.'));

    // H - ThueSuat ✅ lấy từ vat của shipment_sells
    $setStr("H{$r}", $thue_suat);

    // I - TienThueGTGT = unit_price * quantity * vat / 100
    $setStr("I{$r}", number_format($vat_amt, 0, ',', '.'));

    // J - NgayThangNamHD = ngày hiện tại
    $setStr("J{$r}", $today);

    // K - HoTenNguoiMua
    $setStr("K{$r}", $cus_contact);

    // L - TenDonVi
    $setStr("L{$r}", $cus_name);

    // M - MaSoThue
    $setStr("M{$r}", $cus_tax);

    // N - DiaChi
    $setStr("N{$r}", $cus_address);

    // O - SoTaiKhoan (bỏ trống)

    // P - HinhThucTT
    $setStr("P{$r}", 'TM/CK');

    // Q - CoBangEmail
    $setStr("Q{$r}", $co_email);

    // R - DSEmail
    $setStr("R{$r}", $cus_email);

    // Áp style toàn bộ cột
    $styleAllCols($r);

    $stt++;
    $r++;
}

// ============================================================
// FREEZE HEADER + AUTO FILTER
// ============================================================
$sheet->freezePane('A2');
$sheet->setAutoFilter("A1:R1");

// ============================================================
// PAGE SETUP
// ============================================================
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()
    ->setTop(0.5)->setBottom(0.5)
    ->setLeft(0.3)->setRight(0.3);

// ============================================================
// OUTPUT
// ============================================================
$filename = 'HoaDon_'
          . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no'])
          . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>