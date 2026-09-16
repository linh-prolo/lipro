<?php
/**
 * shipments/download_arrival.php
 * Xuất file Excel Arrival Notice để tải về
 */
require_once '../config/database.php';
checkLogin();
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT s.*, c.company_name, c.short_name AS customer_short,
    c.address AS customer_address, c.email AS customer_email,
    c.phone AS customer_phone, c.tax_code AS customer_tax,
    c.contact_person AS customer_contact
    FROM shipments s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { $conn->close(); header("Location: index.php"); exit(); }

$foreign_charges  = $conn->query("SELECT * FROM arrival_notice_charges WHERE shipment_id=$id AND charge_group='foreign' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$domestic_charges = $conn->query("SELECT * FROM arrival_notice_charges WHERE shipment_id=$id AND charge_group='local' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$usd_rate = floatval($shipment['an_exchange_usd'] ?? 25000);
$eur_rate = floatval($shipment['an_exchange_eur'] ?? 27000);
$conn->close();

// Format số kiểu VN: dấu . nghìn, dấu , thập phân
function anFmt($num, $dec = 0) {
    return number_format($num, $dec, ',', '.');
}

// ============================================================
// BUILD SPREADSHEET
// ============================================================
$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Arrival Notice');
$sheet->setShowGridlines(false);
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);
$sheet->getPageSetup()->setHorizontalCentered(true);

foreach (['A'=>1.5,'B'=>28,'C'=>10,'D'=>14,'E'=>10,'F'=>14,'G'=>16,'H'=>8,'I'=>18,'J'=>12,'K'=>1.5] as $col=>$w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$C_RED  = 'C00000';
$C_BLUE = '2F5496';
$C_GRN  = '538135';
$C_GOLD = 'F4B942';

$r = 1;
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// --- Logo + header công ty ---
$sheet->mergeCells("B{$r}:C" . ($r+3));
$logo = dirname(__DIR__).'/assets/images/logo.png';
if (file_exists($logo)) {
    $drawing = new Drawing();
    $drawing->setName('Logo')->setPath($logo)->setCoordinates("B{$r}")
        ->setWidth(90)->setHeight(72)->setOffsetX(4)->setOffsetY(4)->setWorksheet($sheet);
}
$sheet->getRowDimension($r)->setRowHeight(24);
$sheet->mergeCells("D{$r}:J{$r}");
$sheet->setCellValue("D{$r}", 'LIPRO LOGISTICS CO., LTD');
$sheet->getStyle("D{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>17,'color'=>['rgb'=>$C_RED],'name'=>'Times New Roman'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

foreach ([
    'No. 6 Lane 1002 Lang Street, Lang Ha Ward, Dong Da District, Hanoi City, Vietnam',
    'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com',
    'MST / Tax Code: 0110453612',
] as $line) {
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->mergeCells("D{$r}:J{$r}");
    $sheet->setCellValue("D{$r}", $line);
    $sheet->getStyle("D{$r}")->applyFromArray([
        'font'      => ['size'=>8,'color'=>['rgb'=>'666666'],'name'=>'Calibri'],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
    $r++;
}

// Đường kẻ đôi
foreach ([[$C_RED,2],[$C_GOLD,2]] as [$clr,$h]) {
    $sheet->getRowDimension($r)->setRowHeight($h);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->getStyle("B{$r}:J{$r}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$clr]]]);
    $r++;
}

// Tiêu đề
$sheet->getRowDimension($r)->setRowHeight(28);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'GIẤY BÁO HÀNG ĐẾN / ARRIVAL NOTICE');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>16,'color'=>['rgb'=>'FFFFFF'],'name'=>'Times New Roman'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_BLUE]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->mergeCells("B{$r}:J{$r}");
$sheet->getRowDimension($r)->setRowHeight(2);
$sheet->getStyle("B{$r}:J{$r}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_GOLD]]]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(8); $r++;

// --- Ngày ---
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("G{$r}:H{$r}");
$sheet->setCellValue("G{$r}", 'NGÀY / DATE:');
$sheet->getStyle("G{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'name'=>'Calibri'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$sheet->mergeCells("I{$r}:J{$r}");
$sheet->setCellValue("I{$r}", date('d/m/Y'));
$sheet->getStyle("I{$r}")->applyFromArray(['font'=>['size'=>9,'name'=>'Calibri']]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(6); $r++;

// --- Khách hàng ---
$lbl = ['font'=>['bold'=>true,'size'=>9,'name'=>'Calibri','color'=>['rgb'=>$C_BLUE]],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]];
$val = ['font'=>['size'=>9,'name'=>'Calibri','color'=>['rgb'=>'333333']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true]];

$sheet->getRowDimension($r)->setRowHeight(13);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'Kính gửi / To:');
$sheet->getStyle("B{$r}")->applyFromArray(['font'=>['italic'=>true,'size'=>9,'name'=>'Calibri']]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(20);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", strtoupper($shipment['company_name']??''));
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'=>['bold'=>true,'size'=>12,'name'=>'Times New Roman','color'=>['rgb'=>$C_RED]],
    'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(13);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", $shipment['customer_address']??'');
$sheet->getStyle("B{$r}")->applyFromArray(['font'=>['size'=>9,'name'=>'Calibri']]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(13);
$sheet->setCellValue("B{$r}", 'MST:');
$sheet->getStyle("B{$r}")->applyFromArray($lbl);
$sheet->mergeCells("C{$r}:J{$r}");
$sheet->setCellValue("C{$r}", $shipment['customer_tax']??'');
$sheet->getStyle("C{$r}")->applyFromArray($val);
$r++;
$sheet->getRowDimension($r)->setRowHeight(6); $r++;

// --- Section header thông tin lô hàng ---
$infoSecStart = $r;
$sheet->getRowDimension($r)->setRowHeight(18);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", '  THÔNG TIN LÔ HÀNG / SHIPMENT INFORMATION');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_BLUE]],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$arrival = !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '—';

$infoRows = [
    ['Người gửi (Shipper):',  $shipment['shipper']??'—',         null, null],
    ['Từ cảng (POL):',        $shipment['pol']??'—',             'Vận đơn chủ (MAWB):',  $shipment['mawb']??'—'],
    ['Đến cảng (POD):',       $shipment['pod']??'—',             'Vận đơn phụ (HAWB):',  $shipment['hawb']??'—'],
    ['Chuyến bay/Tàu:',       $shipment['vessel_flight']??'—',   'Hàng hóa:',            'as per bill'],
    ['Kho (Warehouse):',      $shipment['warehouse']??'—',       'Số kiện (Qty):',        ($shipment['packages']??0).' kiện'],
    ['Cont / Seal:',          $shipment['cont_seal']??'—',       'GW:',                  anFmt(floatval($shipment['gw']??0),2).' KGS'],
    ['Số tờ khai (Customs):', $shipment['customs_declaration_no']??'—', 'CW/CBM:', anFmt(floatval($shipment['cw']??0),2).' KGS'],
];

foreach ($infoRows as [$l1,$v1,$l2,$v2]) {
    $sheet->getRowDimension($r)->setRowHeight(15);
    if ($l1) { $sheet->setCellValue("B{$r}",$l1); $sheet->getStyle("B{$r}")->applyFromArray($lbl); }
    if ($v1!==null) { $sheet->mergeCells("C{$r}:E{$r}"); $sheet->setCellValue("C{$r}",$v1); $sheet->getStyle("C{$r}")->applyFromArray($val); }
    if ($l2) { $sheet->setCellValue("F{$r}",$l2); $sheet->getStyle("F{$r}")->applyFromArray($lbl); }
    if ($v2!==null) { $sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}",$v2); $sheet->getStyle("G{$r}")->applyFromArray($val); }
    $sheet->getStyle("B{$r}:J{$r}")->applyFromArray(['borders'=>['bottom'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'E0E0E0']]]]);
    $r++;
}

// ETA
$sheet->getRowDimension($r)->setRowHeight(15);
$sheet->setCellValue("B{$r}", 'Ngày đến (ETA):');
$sheet->getStyle("B{$r}")->applyFromArray($lbl);
$sheet->mergeCells("C{$r}:J{$r}");
$sheet->setCellValue("C{$r}", $arrival);
$sheet->getStyle("C{$r}")->applyFromArray([
    'font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED],'name'=>'Calibri'],
    'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getStyle("B{$infoSecStart}:J".($r-1))->applyFromArray([
    'borders'=>['outline'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'C5D9F1']]]
]);
$sheet->getRowDimension($r)->setRowHeight(6); $r++;

// --- Lưu ý ---
foreach ([
    '* Khi nhận lệnh, Quý khách vui lòng mang theo / Please bring the following documents:',
    '  - Giấy giới thiệu / Letter of recommendation.',
    '  - CMND/CCCD / ID card',
    '* Và thanh toán các khoản sau / And make payment for the following charges:',
] as $note) {
    $sheet->getRowDimension($r)->setRowHeight(12);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", $note);
    $sheet->getStyle("B{$r}")->applyFromArray([
        'font'=>['size'=>8.5,'bold'=>str_starts_with($note,'*'),'name'=>'Calibri'],
    ]);
    $r++;
}
$sheet->getRowDimension($r)->setRowHeight(6); $r++;

// --- Tỷ giá ---
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("E{$r}:F{$r}");
$sheet->setCellValue("E{$r}", 'TỶ GIÁ USD:');
$sheet->getStyle("E{$r}")->applyFromArray([
    'font'=>['bold'=>true,'size'=>8.5,'name'=>'Calibri'],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT],
]);
$sheet->setCellValueExplicit("G{$r}", anFmt($usd_rate,0), DataType::TYPE_STRING);
$sheet->getStyle("G{$r}")->applyFromArray([
    'font'=>['bold'=>true,'size'=>8.5,'color'=>['rgb'=>$C_RED],'name'=>'Calibri'],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT],
]);
$sheet->mergeCells("I{$r}:J{$r}");
$sheet->setCellValueExplicit("I{$r}", 'TỶ GIÁ EUR: '.anFmt($eur_rate,0), DataType::TYPE_STRING);
$sheet->getStyle("I{$r}")->applyFromArray([
    'font'=>['bold'=>true,'size'=>8.5,'color'=>['rgb'=>'0070C0'],'name'=>'Calibri'],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT],
]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(6); $r++;

// ============================================================
// HELPER: vẽ bảng phí
// ============================================================
$drawTable = function(string $title, string $colorHex, array $charges) use ($sheet, &$r, $C_GRN): void {
    $sheet->getRowDimension($r)->setRowHeight(18);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", '  '.$title);
    $sheet->getStyle("B{$r}")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$colorHex]],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $r++;

    // Header cột
    $sheet->getRowDimension($r)->setRowHeight(20);
    $cols = [
        'B' => ['Diễn giải / Description', Alignment::HORIZONTAL_LEFT],
        'C' => ['Tiền tệ',                  Alignment::HORIZONTAL_CENTER],
        'D' => ['Đơn giá',                  Alignment::HORIZONTAL_RIGHT],
        'E' => ['SL',                        Alignment::HORIZONTAL_CENTER],
        'F' => ['Thành tiền',                Alignment::HORIZONTAL_RIGHT],
        'G' => ['Tỷ giá',                    Alignment::HORIZONTAL_RIGHT],
        'H' => ['VAT%',                      Alignment::HORIZONTAL_CENTER],
        'I' => ['Tổng VND',                  Alignment::HORIZONTAL_RIGHT],
        'J' => ['Ghi chú',                   Alignment::HORIZONTAL_LEFT],
    ];
    foreach ($cols as $col => [$label, $align]) {
        $sheet->setCellValue("{$col}{$r}", $label);
        $sheet->getStyle("{$col}{$r}")->applyFromArray([
            'font'      => ['bold'=>true,'size'=>8,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'404040']],
            'alignment' => ['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'888888']]],
        ]);
    }
    $hdrRow = $r; $r++;

    if (empty($charges)) {
        $sheet->getRowDimension($r)->setRowHeight(14);
        $sheet->mergeCells("B{$r}:J{$r}");
        $sheet->setCellValue("B{$r}", 'Chưa có dữ liệu');
        $sheet->getStyle("B{$r}")->applyFromArray([
            'font'      => ['italic'=>true,'size'=>8,'color'=>['rgb'=>'AAAAAA'],'name'=>'Calibri'],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);
        $r++;
    } else {
        foreach ($charges as $i => $c) {
            $bg = ($i % 2 === 0) ? 'FFFFFF' : 'F0F6FC';
            $sheet->getRowDimension($r)->setRowHeight(14);

            $cs = fn($align, $bold=false, $color='444444') => [
                'font'      => ['size'=>8,'bold'=>$bold,'color'=>['rgb'=>$color],'name'=>'Calibri'],
                'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
                'alignment' => ['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
                'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'DDEAF5']]],
            ];

            $amount     = floatval($c['amount']     ?? 0);
            $amount_vnd = floatval($c['amount_vnd'] ?? 0);
            $total_vnd  = floatval($c['total_vnd']  ?? 0);
            $vat        = floatval($c['vat']        ?? 0);
            $unit_price = floatval($c['unit_price'] ?? 0);
            $quantity   = floatval($c['quantity']   ?? 1);
            $ex_rate    = floatval($c['exchange_rate'] ?? 1);

            $sheet->setCellValue("B{$r}", $c['description']??'');
            $sheet->getStyle("B{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_LEFT));

            $sheet->setCellValue("C{$r}", $c['currency']??'USD');
            $sheet->getStyle("C{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));

            $sheet->setCellValueExplicit("D{$r}", anFmt($unit_price, 2), DataType::TYPE_STRING);
            $sheet->getStyle("D{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));

            $sheet->setCellValueExplicit("E{$r}", anFmt($quantity, 2), DataType::TYPE_STRING);
            $sheet->getStyle("E{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));

            $sheet->setCellValueExplicit("F{$r}", $amount > 0 ? anFmt($amount, 2) : '—', DataType::TYPE_STRING);
            $sheet->getStyle("F{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));

            $sheet->setCellValueExplicit("G{$r}", anFmt($ex_rate, 0), DataType::TYPE_STRING);
            $sheet->getStyle("G{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));

            $sheet->setCellValue("H{$r}", $vat > 0 ? anFmt($vat, 0).'%' : '—');
            $sheet->getStyle("H{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));

            $sheet->setCellValueExplicit("I{$r}", $total_vnd > 0 ? anFmt($total_vnd, 0) : '—', DataType::TYPE_STRING);
            $sheet->getStyle("I{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT, true, $C_GRN));

            $sheet->setCellValue("J{$r}", $c['notes']??'');
            $sheet->getStyle("J{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_LEFT));

            $r++;
        }
    }

    // Viền ngoài bảng
    $sheet->getStyle("B{$hdrRow}:J".($r-1))->applyFromArray([
        'borders'=>['outline'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>$colorHex]]]
    ]);

    // Tổng
    $total = array_sum(array_column($charges, 'total_vnd'));
    $sheet->getRowDimension($r)->setRowHeight(18);
    $sheet->mergeCells("B{$r}:H{$r}");
    $sheet->setCellValue("B{$r}", 'TỔNG '.$title.' (VND)');
    $sheet->getStyle("B{$r}")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$colorHex]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]],
    ]);
    $sheet->setCellValueExplicit("I{$r}", $total > 0 ? anFmt($total, 0) : '—', DataType::TYPE_STRING);
    $sheet->getStyle("I{$r}")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>10,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$colorHex]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]],
    ]);
    $sheet->getStyle("J{$r}")->applyFromArray([
        'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$colorHex]]
    ]);
    $r++;
    $sheet->getRowDimension($r)->setRowHeight(8); $r++;
};

$drawTable('PHÍ NƯỚC NGOÀI (EXW + FREIGHT)', $C_BLUE, $foreign_charges);
$drawTable('PHÍ TẠI VIỆT NAM', $C_GRN, $domestic_charges);

// Grand total
$grandTotal = array_sum(array_column($foreign_charges,'total_vnd')) + array_sum(array_column($domestic_charges,'total_vnd'));
$sheet->getRowDimension($r)->setRowHeight(24);
$sheet->mergeCells("B{$r}:H{$r}");
$sheet->setCellValue("B{$r}", 'TỔNG THANH TOÁN / GRAND TOTAL');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$sheet->setCellValueExplicit("I{$r}", $grandTotal > 0 ? anFmt($grandTotal,0).' VND' : '—', DataType::TYPE_STRING);
$sheet->getStyle("I{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("J{$r}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]]]);
$sheet->getStyle("B{$r}:J{$r}")->applyFromArray([
    'borders'=>['outline'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>$C_RED]]]
]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(10); $r++;

// --- Thông tin chuyển khoản ---
$sheet->getRowDimension($r)->setRowHeight(18);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", '  THÔNG TIN CHUYỂN KHOẢN / PAYMENT INFORMATION');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'538135']],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

foreach ([
    ['Số tài khoản / Account No:', '9039998888'],
    ['Ngân hàng / Bank:',          'Military Commercial Joint Stock Bank (MB Bank)'],
    ['Người thụ hưởng / Beneficiary:', 'CONG TY TNHH LIPRO LOGISTICS'],
] as [$l, $v]) {
    $sheet->getRowDimension($r)->setRowHeight(16);
    $sheet->mergeCells("B{$r}:D{$r}");
    $sheet->setCellValue("B{$r}", $l);
    $sheet->getStyle("B{$r}")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>9,'name'=>'Calibri'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'EBF4E8']],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'B8D8A8']]],
    ]);
    $sheet->mergeCells("E{$r}:J{$r}");
    $sheet->setCellValue("E{$r}", $v);
    $sheet->getStyle("E{$r}")->applyFromArray([
        'font'      => ['size'=>9,'name'=>'Calibri'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F6FBF4']],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'B8D8A8']]],
    ]);
    $r++;
}
$sheet->getRowDimension($r)->setRowHeight(8); $r++;

// --- Chữ ký ---
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:E{$r}"); $sheet->setCellValue("B{$r}", 'Người lập / Prepared by:');
$sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'name'=>'Calibri'],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
$sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}", 'Người nhận / Acknowledged by:');
$sheet->getStyle("G{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'name'=>'Calibri'],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
$r += 4;

$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:E{$r}"); $sheet->setCellValue("B{$r}", 'LIPRO LOGISTICS CO., LTD');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'    => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED],'name'=>'Calibri'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'borders' => ['top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]],
]);
$sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}", strtoupper($shipment['company_name']??''));
$sheet->getStyle("G{$r}")->applyFromArray([
    'font'    => ['bold'=>true,'size'=>9,'name'=>'Calibri'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'borders' => ['top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]],
]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(6); $r++;
$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'CẢM ƠN BẠN ĐÃ GIAO DỊCH VỚI CHÚNG TÔI! / Thank you for your business!');
$sheet->getStyle("B{$r}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Calibri'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getPageSetup()->setPrintArea("A1:K{$r}");

$filename = 'ArrivalNotice_' . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no'] ?? $id) . '_' . date('Ymd') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit();
?>