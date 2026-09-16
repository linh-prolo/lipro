<?php
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /forwarder/shipments/index.php?error=no_permission");
    exit();
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare(
    "SELECT q.*, c.company_name, c.short_name, c.tax_code, c.address, c.email, c.phone
     FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$quot) { header("Location: index.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

foreach ($items as &$it) {
    $it['_amt'] = floatval($it['amount'] ?? 0);
    if ($it['_amt'] == 0)
        $it['_amt'] = floatval($it['unit_price']) * floatval($it['quantity']);
}
unset($it);

$totals = [];
foreach ($items as $it) {
    $c = $it['currency'];
    $totals[$c] = ($totals[$c] ?? 0) + $it['_amt'];
}

$pol      = $quot['pol']      ?? '';
$pod      = $quot['pod']      ?? '';
$shipper  = $quot['shipper']  ?? '';
$packages = $quot['packages'] ?? '';
$gw       = $quot['gw']       ?? '';
$cw       = $quot['cw']       ?? '';

// ── Helpers ──────────────────────────────────────────────────────────
function vn($v, $d = 2): string {
    $v = floatval($v);
    if ($v == 0) return '-';
    $s = number_format($v, $d, ',', '.');
    if (strpos($s, ',') !== false) {
        $s = rtrim($s, '0');
        $s = rtrim($s, ',');
    }
    return $s;
}

function vnFull($v, $d = 2): string {
    $v = floatval($v);
    return number_format($v, $d, ',', '.');
}

function S($sheet, $range, array $arr) {
    $sheet->getStyle($range)->applyFromArray($arr);
}

// ── Colors ───────────────────────────────────────────────────────────
$C_RED   = 'C00000';
$C_BLUE  = '2F5496';
$C_GRN   = '538135';
$C_DKBL  = '1F3864';
$C_GOLD  = 'F4B942';
$C_LGRAY = 'F2F2F2';
$C_WHITE = 'FFFFFF';
$C_DARK  = '404040';
$C_LBLUE = 'F0F6FC';

// ══════════════════════════════════════════════════════════════════════
$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Quotation');
$sheet->setShowGridlines(false);

$ps = $sheet->getPageSetup();
$ps->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
$ps->setPaperSize(PageSetup::PAPERSIZE_A4);
$ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);

$colWidths = ['A'=>1,'B'=>28,'C'=>10,'D'=>14,'E'=>10,'F'=>16,'G'=>12,'H'=>8,'I'=>18,'J'=>18,'K'=>1];
foreach ($colWidths as $col => $w)
    $sheet->getColumnDimension($col)->setWidth($w);

$r = 1;

// ══════════════════════════════════════════════════════════════════════
// SECTION 1 — HEADER CÔNG TY
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

$sheet->mergeCells("B{$r}:C" . ($r + 3));
$logo = dirname(__DIR__) . '/assets/images/logo.png';
if (file_exists($logo)) {
    $img = new Drawing();
    $img->setName('Logo')->setPath($logo)
        ->setCoordinates("B{$r}")->setOffsetX(4)->setOffsetY(4)
        ->setWidth(80)->setHeight(65)->setWorksheet($sheet);
}

$sheet->getRowDimension($r)->setRowHeight(22);
$sheet->mergeCells("D{$r}:J{$r}");
$sheet->setCellValue("D{$r}", 'LIPRO LOGISTICS CO., LTD');
S($sheet, "D{$r}", [
    'font'      => ['bold'=>true,'size'=>16,'color'=>['rgb'=>$C_RED],'name'=>'Times New Roman'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$compLines = [
    'No. 6 Lane 1002 Lang Street, Lang Ha Ward, Dong Da District, Hanoi City, Vietnam',
    'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com',
    'MST / Tax Code: 0110453612',
];
foreach ($compLines as $line) {
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->mergeCells("D{$r}:J{$r}");
    $sheet->setCellValue("D{$r}", $line);
    S($sheet, "D{$r}", [
        'font'      => ['size'=>8,'color'=>['rgb'=>'666666']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
    $r++;
}

$sheet->getRowDimension($r)->setRowHeight(2);
$sheet->mergeCells("B{$r}:J{$r}");
S($sheet, "B{$r}:J{$r}", ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]]]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(2);
$sheet->mergeCells("B{$r}:J{$r}");
S($sheet, "B{$r}:J{$r}", ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_GOLD]]]);
$r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 2 — TIÊU ĐỀ
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(26);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'BÁO GIÁ / QUOTATION');
S($sheet, "B{$r}", [
    'font'      => ['bold'=>true,'size'=>15,'name'=>'Times New Roman'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:E{$r}");
$sheet->setCellValue("B{$r}", 'Số/No: ' . ($quot['quotation_no'] ?? ''));
S($sheet, "B{$r}", ['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$sheet->mergeCells("F{$r}:G{$r}");
$sheet->setCellValue("F{$r}", 'NGÀY / DATE:');
S($sheet, "F{$r}", ['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$sheet->mergeCells("H{$r}:J{$r}");
$sheet->setCellValue("H{$r}", $quot['issue_date'] ? date('d/m/Y', strtotime($quot['issue_date'])) : date('d/m/Y'));
S($sheet, "H{$r}", ['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 3 — THÔNG TIN KHÁCH HÀNG
// ═══════════════════════════════════════════════════════════���══════════
$sheet->getRowDimension($r)->setRowHeight(13);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'Kính gửi / To:');
S($sheet, "B{$r}", ['font'=>['italic'=>true,'size'=>9]]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(18);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", strtoupper($quot['company_name'] ?? ''));
S($sheet, "B{$r}", ['font'=>['bold'=>true,'size'=>11,'name'=>'Times New Roman']]);
$r++;

if (!empty($quot['address'])) {
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", $quot['address']);
    S($sheet, "B{$r}", ['font'=>['size'=>9]]);
    $r++;
}

$sheet->getRowDimension($r)->setRowHeight(13);
$sheet->setCellValue("B{$r}", 'MST:');
S($sheet, "B{$r}", ['font'=>['bold'=>true,'size'=>9]]);
$sheet->mergeCells("C{$r}:J{$r}");
$sheet->setCellValue("C{$r}", $quot['tax_code'] ?? '');
S($sheet, "C{$r}", ['font'=>['size'=>9]]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 4 — THÔNG TIN LÔ HÀNG (layout 2 cột, shipper bên phải)
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(18);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", '  THÔNG TIN LÔ HÀNG / SHIPMENT INFORMATION');
S($sheet, "B{$r}", [
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_DKBL]],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$lbl = ['font'=>['bold'=>true,'size'=>9],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]];
$val = ['font'=>['size'=>9,'color'=>['rgb'=>'0070C0']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]];

// Cột trái
$shipLeft = [];
if (!empty($pol))      $shipLeft[] = ['POL (Cảng đi):',            $pol];
if (!empty($pod))      $shipLeft[] = ['POD (Cảng đến):',           $pod];
if (!empty($packages)) $shipLeft[] = ['Số kiện (Qty):',            vn($packages, 0) . ' kiện'];
if (!empty($gw))       $shipLeft[] = ['GW:',                       vnFull(floatval($gw), 2) . ' KGS'];
if (!empty($cw))       $shipLeft[] = ['CW:',                       vnFull(floatval($cw), 2) . ' KGS'];
$shipLeft[] = ['Ngày lập (Date):',      $quot['issue_date'] ? date('d/m/Y', strtotime($quot['issue_date'])) : ''];
if (!empty($quot['valid_until']))
    $shipLeft[] = ['Hiệu lực đến (Valid until):', date('d/m/Y', strtotime($quot['valid_until']))];

// Cột phải — shipper ở dòng đầu tiên
$shipRight = [];
if (!empty($shipper))
    $shipRight[0] = ['Shipper (Người gửi):', $shipper];

$maxRows = max(count($shipLeft), count($shipRight) > 0 ? max(array_keys($shipRight)) + 1 : 0);

for ($si = 0; $si < $maxRows; $si++) {
    $sheet->getRowDimension($r)->setRowHeight(15);

    // Cột trái: B label, C:E value
    if (isset($shipLeft[$si])) {
        [$ll, $lv] = $shipLeft[$si];
        $sheet->setCellValue("B{$r}", $ll);
        S($sheet, "B{$r}", $lbl);
        $sheet->mergeCells("C{$r}:E{$r}");
        $sheet->setCellValue("C{$r}", $lv);
        S($sheet, "C{$r}", $val);
    }

    // Cột phải: F:G label, H:J value
    if (isset($shipRight[$si])) {
        [$rl, $rv] = $shipRight[$si];
        $sheet->mergeCells("F{$r}:G{$r}");
        $sheet->setCellValue("F{$r}", $rl);
        S($sheet, "F{$r}", $lbl);
        $sheet->mergeCells("H{$r}:J{$r}");
        $sheet->setCellValue("H{$r}", $rv);
        S($sheet, "H{$r}", $val);
    }

    $r++;
}
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 5 — BẢNG CHI PHÍ
// ══════════════════════════════════════════════════════════════════════
$tblCols  = ['B','C','D','E','F','G','H','I','J'];
$tblHdrs  = ['Diễn giải / Description','Tiền tệ','Đơn giá','SL','Thành tiền','Tỷ giá','VAT%','Tổng VND','Ghi chú'];
$tblAligns = [
    Alignment::HORIZONTAL_LEFT,
    Alignment::HORIZONTAL_CENTER,
    Alignment::HORIZONTAL_RIGHT,
    Alignment::HORIZONTAL_CENTER,
    Alignment::HORIZONTAL_RIGHT,
    Alignment::HORIZONTAL_RIGHT,
    Alignment::HORIZONTAL_CENTER,
    Alignment::HORIZONTAL_RIGHT,
    Alignment::HORIZONTAL_LEFT,
];

$sheet->getRowDimension($r)->setRowHeight(18);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", '  CHI TIẾT BÁO GIÁ / QUOTATION DETAILS');
S($sheet, "B{$r}", [
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_BLUE]],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(20);
foreach ($tblCols as $ci => $col) {
    $sheet->setCellValue("{$col}{$r}", $tblHdrs[$ci]);
    S($sheet, "{$col}{$r}", [
        'font'      => ['bold'=>true,'size'=>8,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_DARK]],
        'alignment' => ['horizontal'=>$tblAligns[$ci],'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'888888']]],
    ]);
}
$tblTop = $r;
$r++;

foreach ($items as $idx => $item) {
    $bg  = ($idx % 2 === 0) ? $C_WHITE : $C_LBLUE;
    $amt = $item['_amt'];

    $sheet->getRowDimension($r)->setRowHeight(14);

    $cs = function($align, $bold = false, $color = '000000') use ($bg) {
        return [
            'font'      => ['size'=>8,'name'=>'Arial','bold'=>$bold,'color'=>['rgb'=>$color]],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
            'alignment' => ['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
            'borders'   => ['bottom'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'DDDDDD']]],
        ];
    };

    $sheet->setCellValue("B{$r}", $item['description'] ?? '');
    S($sheet, "B{$r}", $cs(Alignment::HORIZONTAL_LEFT));

    $sheet->setCellValue("C{$r}", $item['currency'] ?? 'USD');
    S($sheet, "C{$r}", $cs(Alignment::HORIZONTAL_CENTER));

    $sheet->setCellValue("D{$r}", $item['unit_price'] != 0 ? vnFull(floatval($item['unit_price']), 2) : '-');
    S($sheet, "D{$r}", $cs(Alignment::HORIZONTAL_RIGHT));

    $sheet->setCellValue("E{$r}", vnFull(floatval($item['quantity']), 2));
    S($sheet, "E{$r}", $cs(Alignment::HORIZONTAL_CENTER));

    $sheet->setCellValue("F{$r}", $amt != 0 ? vnFull($amt, 2) : '-');
    S($sheet, "F{$r}", $cs(Alignment::HORIZONTAL_RIGHT));

    $sheet->setCellValue("G{$r}", '—');
    S($sheet, "G{$r}", $cs(Alignment::HORIZONTAL_CENTER, false, '999999'));

    $sheet->setCellValue("H{$r}", '—');
    S($sheet, "H{$r}", $cs(Alignment::HORIZONTAL_CENTER, false, '999999'));

    $sheet->setCellValue("I{$r}", $amt != 0 ? vnFull($amt, 2) : '-');
    S($sheet, "I{$r}", [
        'font'      => ['bold'=>true,'size'=>8,'name'=>'Arial','color'=>['rgb'=>'375623']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['bottom'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'DDDDDD']]],
    ]);

    $sheet->setCellValue("J{$r}", $item['notes'] ?? '');
    S($sheet, "J{$r}", $cs(Alignment::HORIZONTAL_LEFT, false, '555555'));

    $r++;
}

S($sheet, "B{$tblTop}:J" . ($r - 1), [
    'borders' => ['outline'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>$C_BLUE]]],
]);

foreach ($totals as $cur => $total) {
    $sheet->getRowDimension($r)->setRowHeight(16);
    $sheet->mergeCells("B{$r}:H{$r}");
    $sheet->setCellValue("B{$r}", 'TỔNG ' . $cur);
    S($sheet, "B{$r}", [
        'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8F0FE']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]],
    ]);
    $sheet->setCellValue("I{$r}", vnFull($total, 2));
    S($sheet, "I{$r}", [
        'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8F0FE']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]],
    ]);
    $sheet->setCellValue("J{$r}", '');
    S($sheet, "J{$r}", ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8F0FE']]]);
    $r++;
}
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 6 — GHI CHÚ
// ══════════════════════════════════════════════════════════════════════
if (!empty($quot['notes'])) {
    $sheet->getRowDimension($r)->setRowHeight(14);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", $quot['notes']);
    S($sheet, "B{$r}", [
        'font'      => ['size'=>9,'italic'=>true],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
    ]);
    $r++;
}

$noteLines = [
    'Above quotation exclude VAT and inspection fee if any.',
    'If you have any questions concerning this quotation, please contact: Linh at 0985572699',
];
foreach ($noteLines as $note) {
    $sheet->getRowDimension($r)->setRowHeight(12);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", $note);
    S($sheet, "B{$r}", [
        'font'      => ['size'=>8,'color'=>['rgb'=>'666666']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $r++;
}
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 7 — CHỮ KÝ
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("G{$r}:J{$r}");
$sheet->setCellValue("G{$r}", 'Người lập / Prepared by:');
S($sheet, "G{$r}", [
    'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

for ($i = 0; $i < 3; $i++) {
    $sheet->getRowDimension($r)->setRowHeight(16);
    $r++;
}

$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("G{$r}:J{$r}");
$sheet->setCellValue("G{$r}", 'LIPRO LOGISTICS CO., LTD');
S($sheet, "G{$r}", [
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED],'name'=>'Arial'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 8 — FOOTER
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'CẢM ƠN BẠN ĐÃ GIAO DỊCH VỚI CHÚNG TÔI! / Thank you for your business!');
S($sheet, "B{$r}", [
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getPageSetup()->setPrintArea("A1:K{$r}");

// ══════════════════════════════════════════════════════════════════════
// OUTPUT
// ══════════════════════════════════════════════════════════════════════
$filename = 'Quotation_'
    . preg_replace('/[^A-Za-z0-9_\-]/', '_', $quot['quotation_no'] ?? $id)
    . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit();