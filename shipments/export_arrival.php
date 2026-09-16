<?php
require_once '../config/database.php';
require_once '../config/mail.php';
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
    c.address AS customer_address, c.email AS customer_email,
    c.phone AS customer_phone, c.tax_code AS customer_tax,
    c.contact_person AS customer_contact
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

// Load charges
$foreign_charges  = $conn->query("SELECT * FROM arrival_notice_charges WHERE shipment_id=$id AND charge_group='foreign' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$domestic_charges = $conn->query("SELECT * FROM arrival_notice_charges WHERE shipment_id=$id AND charge_group='local' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

// Load saved attachments
$stmt_att = $conn->prepare("SELECT * FROM shipment_attachments WHERE shipment_id = ? ORDER BY uploaded_at ASC");
$stmt_att->bind_param("i", $id);
$stmt_att->execute();
$saved_attachments = $stmt_att->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_att->close();

// ✅ MỚI - lấy đúng cột an_exchange_usd/eur, fallback về arrival_usd_rate nếu chưa có
$usd_rate = floatval($shipment['an_exchange_usd'] > 0 ? $shipment['an_exchange_usd'] : ($shipment['arrival_usd_rate'] ?? 25000));
$eur_rate = floatval($shipment['an_exchange_eur'] > 0 ? $shipment['an_exchange_eur'] : ($shipment['arrival_eur_rate'] ?? 27000));

$hawb    = $shipment['hawb'] ?? '';
$cd_no   = $shipment['customs_declaration_no'] ?? '';
$auto_subject = 'ARRIVAL NOTICE // LIPRO // ' . $hawb . (!empty($cd_no) ? ' // ' . $cd_no : '');

$error = ''; $success = '';

// ============================================================
// HELPER: tách email
// ============================================================
function splitEmails(string $str): array {
    $parts = preg_split('/[;,]/', $str);
    $result = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) $result[] = $p;
    }
    return $result;
}

// ============================================================
// BUILD EXCEL - gọi export_arrival.php logic inline
// ============================================================
function buildArrivalXlsxContent(array $shipment, array $foreign_charges, array $domestic_charges, float $usd_rate, float $eur_rate): string {
    $ss    = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Arrival Notice');
    $sheet->setShowGridlines(false);

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);

    foreach(['A'=>1,'B'=>28,'C'=>10,'D'=>14,'E'=>10,'F'=>16.5,'G'=>16,'H'=>8,'I'=>18,'J'=>12,'K'=>1] as $col=>$w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    $C_RED  = 'C00000';
    $C_BLUE = '2F5496';
    $C_GRN  = '538135';

    $r = 1;
    $sheet->getRowDimension($r)->setRowHeight(5); $r++;

    // Logo + Header
    $sheet->mergeCells("B{$r}:C".($r+3));
    $logo = dirname(__DIR__).'/assets/images/logo.png';
    if (file_exists($logo)) {
        $drawing = new Drawing();
        $drawing->setName('Logo')->setPath($logo)->setCoordinates("B{$r}")
            ->setWidth(80)->setHeight(65)->setOffsetX(4)->setOffsetY(4)->setWorksheet($sheet);
    }
    $sheet->getRowDimension($r)->setRowHeight(22);
    $sheet->mergeCells("D{$r}:J{$r}");
    $sheet->setCellValue("D{$r}", 'LIPRO LOGISTICS CO., LTD');
    $sheet->getStyle("D{$r}")->applyFromArray([
        'font' => ['bold'=>true,'size'=>16,'color'=>['rgb'=>$C_RED],'name'=>'Times New Roman'],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $r++;

    foreach([
        'No. 6 Lane 1002 Lang Street, Lang Ha Ward, Dong Da District, Hanoi City, Vietnam',
        'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com',
        'MST / Tax Code: 0110453612',
    ] as $line) {
        $sheet->getRowDimension($r)->setRowHeight(13);
        $sheet->mergeCells("D{$r}:J{$r}");
        $sheet->setCellValue("D{$r}", $line);
        $sheet->getStyle("D{$r}")->applyFromArray(['font'=>['size'=>8,'color'=>['rgb'=>'666666']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
        $r++;
    }

    foreach([[$C_RED,2],['F4B942',2]] as [$clr,$h]) {
        $sheet->getRowDimension($r)->setRowHeight($h);
        $sheet->mergeCells("B{$r}:J{$r}");
        $sheet->getStyle("B{$r}:J{$r}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$clr]]]);
        $r++;
    }

    $sheet->getRowDimension($r)->setRowHeight(26);
    $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", 'GIẤY BÁO HÀNG ĐẾN / ARRIVAL NOTICE');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>15,'name'=>'Times New Roman'],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER]]);
    $r++;

    $sheet->getRowDimension($r)->setRowHeight(14);
    $sheet->mergeCells("G{$r}:H{$r}"); $sheet->setCellValue("G{$r}", 'NGÀY:');
    $sheet->getStyle("G{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT]]);
    $sheet->mergeCells("I{$r}:J{$r}"); $sheet->setCellValue("I{$r}", date('d/m/Y'));
    $r++; $r++;

    // Khách hàng
    $sheet->getRowDimension($r)->setRowHeight(13); $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", 'Kính gửi / To:');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['italic'=>true,'size'=>9]]); $r++;
    $sheet->getRowDimension($r)->setRowHeight(18); $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", strtoupper($shipment['company_name']??''));
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'name'=>'Times New Roman']]); $r++;
    $sheet->getRowDimension($r)->setRowHeight(13); $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", $shipment['customer_address']??'');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['size'=>9]]); $r++;
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->setCellValue("B{$r}", 'MST:'); $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9]]);
    $sheet->mergeCells("C{$r}:J{$r}"); $sheet->setCellValue("C{$r}", $shipment['customer_tax']??''); $r++;
    $r++;

    $lbl = ['font'=>['bold'=>true,'size'=>9],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]];
    $val = ['font'=>['size'=>9,'color'=>['rgb'=>'0070C0']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]];
    $arrival = !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '';

    $infoRows = [
        ['Người gửi (Shipper):', $shipment['shipper']??'', null, null],
        ['Từ cảng (From):', $shipment['pol']??'', 'Vận đơn chủ (MAWB):', $shipment['mawb']??''],
        ['Đến cảng (Terminal):', $shipment['pod']??'', 'Vận đơn phụ (HAWB):', $shipment['hawb']??''],
        ['Chuyến (Flight/Vessel):', $shipment['vessel_flight']??'', 'Hàng hóa (Description):', 'as per bill'],
        ['Kho (Warehouse):', $shipment['warehouse']??'-', 'Số lượng (Quantity):', ($shipment['packages']??'').' kiện'],
        ['Cont/Seal:', $shipment['cont_seal']??'-', 'Trọng lượng (GW):', ($shipment['gw']??0).' KGS'],
        [null, null, 'Trọng lượng tính cước (CW):', ($shipment['cw']??0).' KGS'],
    ];

    foreach ($infoRows as [$l1,$v1,$l2,$v2]) {
        $sheet->getRowDimension($r)->setRowHeight(15);
        if ($l1) { $sheet->setCellValue("B{$r}",$l1); $sheet->getStyle("B{$r}")->applyFromArray($lbl); }
        if ($v1!==null) { $sheet->mergeCells("C{$r}:E{$r}"); $sheet->setCellValue("C{$r}",$v1); $sheet->getStyle("C{$r}")->applyFromArray($val); }
        if ($l2) { $sheet->setCellValue("F{$r}",$l2); $sheet->getStyle("F{$r}")->applyFromArray($lbl); }
        if ($v2!==null) { $sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}",$v2); $sheet->getStyle("G{$r}")->applyFromArray($val); }
        $r++;
    }

    $sheet->getRowDimension($r)->setRowHeight(15);
    $sheet->setCellValue("B{$r}", 'Ngày đến (ETA):'); $sheet->getStyle("B{$r}")->applyFromArray($lbl);
    $sheet->mergeCells("C{$r}:E{$r}"); $sheet->setCellValue("C{$r}", $arrival);
    $sheet->getStyle("C{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED]]]); $r++;
    $r++;

    foreach([
        '* Khi nhận lệnh, Quý khách vui lòng mang theo / Please bring the following documents:',
        '  - Giấy giới thiệu / Letter of recommendation.',
        '  - CMND/CCCD / ID card',
        '* Và thanh toán các khoản sau / And make payment for the following charges:',
    ] as $note) {
        $sheet->getRowDimension($r)->setRowHeight(12); $sheet->mergeCells("B{$r}:J{$r}");
        $sheet->setCellValue("B{$r}", $note);
        $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['size'=>8.5,'bold'=>str_starts_with($note,'*')]]);
        $r++;
    }
    $r++;

    // Tỷ giá
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->mergeCells("F{$r}:G{$r}"); $sheet->setCellValue("F{$r}", 'TỶ GIÁ USD:');
    $sheet->getStyle("F{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>8],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT]]);
    $sheet->setCellValue("H{$r}", number_format($usd_rate,0,',','.'));
    $sheet->getStyle("H{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>8,'color'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT]]);
    $sheet->mergeCells("I{$r}:J{$r}"); $sheet->setCellValue("I{$r}", 'TỶ GIÁ EUR: '.number_format($eur_rate,0,',','.'));
    $sheet->getStyle("I{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>8,'color'=>['rgb'=>'0070C0']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT]]);
    $r++;

    // Helper vẽ bảng phí
    $drawChargeTable = function(string $title, string $colorHex, array $charges) use ($sheet, &$r): void {
        $sheet->getRowDimension($r)->setRowHeight(18); $sheet->mergeCells("B{$r}:J{$r}");
        $sheet->setCellValue("B{$r}", '  '.$title);
        $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$colorHex]],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]]);
        $r++;

        $sheet->getRowDimension($r)->setRowHeight(20);
        $cols = ['B'=>['Diễn giải',Alignment::HORIZONTAL_LEFT],'C'=>['Tiền tệ',Alignment::HORIZONTAL_CENTER],'D'=>['Đơn giá',Alignment::HORIZONTAL_RIGHT],'E'=>['SL',Alignment::HORIZONTAL_CENTER],'F'=>['Thành tiền',Alignment::HORIZONTAL_RIGHT],'G'=>['Quy đổi VND',Alignment::HORIZONTAL_RIGHT],'H'=>['VAT%',Alignment::HORIZONTAL_CENTER],'I'=>['Tổng VND',Alignment::HORIZONTAL_RIGHT],'J'=>['Ghi chú',Alignment::HORIZONTAL_LEFT]];
        foreach ($cols as $col=>[$label,$align]) {
            $sheet->setCellValue("{$col}{$r}", $label);
            $sheet->getStyle("{$col}{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>8,'color'=>['rgb'=>'FFFFFF'],'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'404040']],'alignment'=>['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'888888']]]]);
        }
        $hdrRow = $r; $r++;

        if (empty($charges)) {
            $sheet->getRowDimension($r)->setRowHeight(12); $sheet->mergeCells("B{$r}:J{$r}");
            $sheet->setCellValue("B{$r}", 'Chưa có dữ liệu');
            $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['italic'=>true,'size'=>8,'color'=>['rgb'=>'AAAAAA']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]); $r++;
        } else {
            foreach ($charges as $i=>$c) {
                $bg = ($i%2===0)?'FFFFFF':'F0F6FC';
                $sheet->getRowDimension($r)->setRowHeight(14);
                $cs = function($align) use ($bg) {
                    return ['font'=>['size'=>8,'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],'alignment'=>['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['bottom'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'DDDDDD']]]];
                };

                $amount     = floatval($c['amount']     ?? 0);
                $amount_vnd = floatval($c['amount_vnd'] ?? 0);
                $total_vnd  = floatval($c['total_vnd']  ?? 0);
                $vat        = floatval($c['vat']        ?? 0);

                $sheet->setCellValue("B{$r}", $c['description']??''); $sheet->getStyle("B{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_LEFT));
                $sheet->setCellValue("C{$r}", $c['currency']??'USD'); $sheet->getStyle("C{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));
                $sheet->setCellValue("D{$r}", number_format(floatval($c['unit_price']??0),2,',','.')); $sheet->getStyle("D{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));
                $sheet->setCellValue("E{$r}", number_format(floatval($c['quantity']??1),2,',','.')); $sheet->getStyle("E{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));
                $sheet->setCellValue("F{$r}", $amount>0?number_format($amount,2,',','.'): '-'); $sheet->getStyle("F{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));
                $sheet->setCellValue("G{$r}", $amount_vnd>0?number_format($amount_vnd,0,',','.'): '-'); $sheet->getStyle("G{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_RIGHT));
                $sheet->setCellValue("H{$r}", $vat>0?$vat.'%':'-'); $sheet->getStyle("H{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_CENTER));
                $sheet->setCellValue("I{$r}", $total_vnd>0?number_format($total_vnd,0,',','.'): '-');
                $sheet->getStyle("I{$r}")->applyFromArray(array_merge($cs(Alignment::HORIZONTAL_RIGHT),['font'=>['bold'=>true,'size'=>8,'name'=>'Arial','color'=>['rgb'=>'375623']]]));
                $sheet->setCellValue("J{$r}", $c['notes']??''); $sheet->getStyle("J{$r}")->applyFromArray($cs(Alignment::HORIZONTAL_LEFT));
                $r++;
            }
        }

        $sheet->getStyle("B{$hdrRow}:J".($r-1))->applyFromArray(['borders'=>['outline'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>$colorHex]]]]);

        $total = array_sum(array_column($charges,'total_vnd'));
        $sheet->getRowDimension($r)->setRowHeight(16); $sheet->mergeCells("B{$r}:H{$r}");
        $sheet->setCellValue("B{$r}", 'TỔNG '.$title.' (VND)');
        $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8F0FE']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]]]);
        $sheet->setCellValue("I{$r}", $total>0?number_format($total,0,',','.'): '-');
        $sheet->getStyle("I{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E8F0FE']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_HAIR,'color'=>['rgb'=>'CCCCCC']]]]);
        $r++; $r++;
    };

    $drawChargeTable('PHÍ NƯỚC NGOÀI (EXW + FREIGHT)', $C_BLUE, $foreign_charges);
    $drawChargeTable('PHÍ TẠI VIỆT NAM', $C_GRN, $domestic_charges);

    $grandTotal = array_sum(array_column($foreign_charges,'total_vnd')) + array_sum(array_column($domestic_charges,'total_vnd'));

    $sheet->getRowDimension($r)->setRowHeight(22); $sheet->mergeCells("B{$r}:H{$r}");
    $sheet->setCellValue("B{$r}", 'TỔNG THANH TOÁN');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF'],'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER]]);
    $sheet->setCellValue("I{$r}", $grandTotal>0?number_format($grandTotal,0,',','.').' VND': '-');
    $sheet->getStyle("I{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF'],'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER]]);
    $sheet->getStyle("J{$r}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]]]);
    $r++; $r++;

    // Thông tin chuyển khoản
    $sheet->getRowDimension($r)->setRowHeight(14); $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", 'Quý khách thanh toán bằng chuyển khoản / Our banking information:');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['size'=>9,'italic'=>true]]); $r++;
    $sheet->getRowDimension($r)->setRowHeight(4); $r++;

    foreach([
        ['Số tài khoản / Account No:','9039998888'],
        ['Ngân hàng / Bank:','Military Commercial Joint Stock Bank (MB Bank)'],
        ['Người thụ hưởng / Beneficiary:','CONG TY TNHH LIPRO LOGISTICS'],
    ] as [$l,$v]) {
        $sheet->getRowDimension($r)->setRowHeight(16);
        $sheet->mergeCells("B{$r}:D{$r}"); $sheet->setCellValue("B{$r}", $l);
        $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F2F2F2']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]]]);
        $sheet->mergeCells("E{$r}:J{$r}"); $sheet->setCellValue("E{$r}", $v);
        $sheet->getStyle("E{$r}")->applyFromArray(['font'=>['size'=>9],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'FFFFFF']],'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]]]);
        $r++;
    }
    $r++;

    // Chữ ký
    $sheet->getRowDimension($r)->setRowHeight(14);
    $sheet->mergeCells("B{$r}:E{$r}"); $sheet->setCellValue("B{$r}", 'Người lập / Prepared by:');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}", 'Người nhận / Acknowledged by:');
    $sheet->getStyle("G{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $r += 4;

    $sheet->getRowDimension($r)->setRowHeight(14);
    $sheet->mergeCells("B{$r}:E{$r}"); $sheet->setCellValue("B{$r}", 'LIPRO LOGISTICS CO., LTD');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]]]);
    $sheet->mergeCells("G{$r}:J{$r}"); $sheet->setCellValue("G{$r}", strtoupper($shipment['company_name']??''));
    $sheet->getStyle("G{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]]]);
    $r++;

    $sheet->getRowDimension($r)->setRowHeight(6); $r++;
    $sheet->getRowDimension($r)->setRowHeight(14); $sheet->mergeCells("B{$r}:J{$r}");
    $sheet->setCellValue("B{$r}", 'CẢM ƠN BẠN ĐÃ GIAO DỊCH VỚI CHÚNG TÔI! / Thank you for your business!');
    $sheet->getStyle("B{$r}")->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF'],'name'=>'Arial'],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER]]);
    $r++;

    $sheet->getPageSetup()->setPrintArea("A1:K{$r}");

    ob_start();
    (new Xlsx($ss))->save('php://output');
    return ob_get_clean();
}

// ============================================================
// BUILD MAIL BODY HTML
// ============================================================
function buildArrivalMailBody(array $shipment, array $foreign_charges, array $domestic_charges, float $usd_rate, float $eur_rate, string $extra = ''): string {
    $hawb    = $shipment['hawb'] ?? '';
    $cd_no   = $shipment['customs_declaration_no'] ?? '';
    $cus     = $shipment['company_name'] ?? '';
    $arrival = !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '—';

    $makeRows = function(array $charges, string $title) {
        if (empty($charges)) return '';
        $html = "<tr style='background:#1B3A6B;color:#fff;'><th colspan='7' style='padding:8px 12px;text-align:left;'>{$title}</th></tr>
        <tr style='background:#f0f0f0;'>
            <th style='padding:6px 8px;border:1px solid #ccc;'>Diễn giải</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:center;'>Tiền tệ</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:right;'>Đơn giá</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:center;'>SL</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:right;'>Thành tiền</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:right;'>Quy đổi VND</th>
            <th style='padding:6px 8px;border:1px solid #ccc;text-align:right;'>Tổng VND</th>
        </tr>";
        foreach ($charges as $i=>$c) {
            $bg = $i%2==0?'#fff':'#f8f9fa';
            $html .= "<tr style='background:{$bg};'>
                <td style='padding:6px 8px;border:1px solid #dee2e6;'>".htmlspecialchars($c['description']??'')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:center;'>".htmlspecialchars($c['currency']??'')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:right;'>".number_format(floatval($c['unit_price']??0),2,',','.')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:center;'>".number_format(floatval($c['quantity']??1),2,',','.')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:right;'>".($c['amount']>0?number_format(floatval($c['amount']),2,',','.'):'-')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:right;'>".($c['amount_vnd']>0?number_format(floatval($c['amount_vnd']),0,',','.'):'-')."</td>
                <td style='padding:6px 8px;border:1px solid #dee2e6;text-align:right;font-weight:bold;color:#198754;'>".($c['total_vnd']>0?number_format(floatval($c['total_vnd']),0,',','.'):'-')."</td>
            </tr>";
        }
        $total_vnd = array_sum(array_column($charges,'total_vnd'));
        $html .= "<tr style='background:#fff3cd;font-weight:bold;'>
            <td colspan='6' style='padding:7px 8px;border:1px solid #ccc;text-align:right;'>TỔNG:</td>
            <td style='padding:7px 8px;border:1px solid #ccc;text-align:right;color:#dc3545;'>".($total_vnd>0?number_format($total_vnd,0,',','.').' VND':'-')."</td>
        </tr>";
        return $html;
    };

    $grand = array_sum(array_column($foreign_charges,'total_vnd')) + array_sum(array_column($domestic_charges,'total_vnd'));
    $extra_html = !empty($extra) ? "<div style='margin:12px 0;padding:10px 14px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;font-size:13px;'>".nl2br(htmlspecialchars($extra))."</div>" : '';

    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,sans-serif;font-size:13px;color:#333;'>
<div style='max-width:700px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,.1);'>
<div style='background:linear-gradient(135deg,#1B3A6B 0%,#2E75B6 100%);padding:20px 28px;text-align:center;'>
    <h1 style='color:#fff;margin:0 0 4px;font-size:20px;'>LIPRO LOGISTICS CO.,LTD</h1>
    <p style='color:#a8c6e8;margin:0;font-size:11px;'>No. 6 Lane 1002 Lang Street, Lang Ha Ward, Hanoi, Vietnam<br>Tel: (+84) 366 666 322 | Email: lipro.logistics@gmail.com</p>
</div>
<div style='background:#C00000;padding:8px 28px;text-align:center;'>
    <h2 style='color:#fff;margin:0;font-size:14px;letter-spacing:2px;'>GIẤY BÁO HÀNG ĐẾN / ARRIVAL NOTICE</h2>
</div>
<div style='padding:20px 28px;'>
    <div style='background:#fff8e1;border-left:4px solid #F4B942;padding:10px 14px;margin-bottom:16px;border-radius:4px;'>
        <p style='margin:0;font-size:13px;'><strong>Thông báo hàng đến:</strong>
        <span style='color:#C00000;font-weight:bold;font-size:14px;'>".($hawb?" HAWB: {$hawb}":'').($cd_no?" &nbsp;|&nbsp; TK: {$cd_no}":'')."</span></p>
    </div>
    <p style='margin:0 0 4px;font-size:14px;'><strong>Kính chào:</strong>
    <span style='color:#1B3A6B;font-weight:bold;'> ".htmlspecialchars($cus)."</span></p>
    <p style='margin:0 0 16px;color:#555;line-height:1.7;'>Chúng tôi xin trân trọng thông báo lô hàng nhập của Quý công ty đã về đến kho, vui lòng kiểm tra chi tiết bên dưới.</p>
    <table style='width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12px;'>
        <tr style='background:#1B3A6B;color:#fff;'><th colspan='2' style='padding:8px 12px;text-align:left;'>Thông tin lô hàng</th></tr>
        <tr style='background:#f8f9fa;'><td style='padding:6px 12px;font-weight:bold;width:35%;border-bottom:1px solid #dee2e6;'>Job ID:</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;color:#0070C0;'>".htmlspecialchars($shipment['job_no']??'')."</td></tr>
        <tr><td style='padding:6px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>HAWB:</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;'>".htmlspecialchars($hawb)."</td></tr>
        <tr style='background:#f8f9fa;'><td style='padding:6px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>MBL:</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;'>".htmlspecialchars($shipment['mawb']??'—')."</td></tr>
        <tr><td style='padding:6px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>Shipper:</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;'>".htmlspecialchars($shipment['shipper']??'—')."</td></tr>
        <tr style='background:#f8f9fa;'><td style='padding:6px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>POL → POD:</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;'>".htmlspecialchars(($shipment['pol']??'').' → '.($shipment['pod']??''))."</td></tr>
        <tr><td style='padding:6px 12px;font-weight:bold;border-bottom:1px solid #dee2e6;'>Ngày đến (ETA):</td><td style='padding:6px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;color:#C00000;'>{$arrival}</td></tr>
        <tr style='background:#f8f9fa;'><td style='padding:6px 12px;font-weight:bold;'>Số tờ khai:</td><td style='padding:6px 12px;'>".htmlspecialchars($cd_no?:'—')."</td></tr>
    </table>
    <p style='font-size:11px;color:#555;margin-bottom:8px;'>Tỷ giá: <strong>USD = ".number_format($usd_rate,0,',','.')." VND</strong> &nbsp;|&nbsp; <strong>EUR = ".number_format($eur_rate,0,',','.')." VND</strong></p>
    <table style='width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12px;'>
        ".$makeRows($foreign_charges,'PHÍ NƯỚC NGOÀI (EXW + FREIGHT)')."
        ".$makeRows($domestic_charges,'PHÍ TẠI VIỆT NAM')."
        <tr style='background:#1B3A6B;font-weight:bold;color:#fff;'>
            <td colspan='6' style='padding:9px 8px;text-align:right;font-size:14px;'>TỔNG THANH TOÁN:</td>
            <td style='padding:9px 8px;text-align:right;font-size:15px;color:#90ee90;'>".($grand>0?number_format($grand,0,',','.').' VND':'-')."</td>
        </tr>
    </table>
    {$extra_html}
    <div style='background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:14px;margin-bottom:14px;'>
        <h4 style='color:#1B5E20;margin:0 0 8px;font-size:13px;border-bottom:1px solid #dee2e6;padding-bottom:5px;'>Thông tin chuyển khoản:</h4>
        <table style='width:100%;font-size:12px;'>
            <tr><td style='padding:2px 0;font-weight:bold;width:35%;'>Số TK:</td><td>9039998888 (VND)</td></tr>
            <tr><td style='padding:2px 0;font-weight:bold;'>Ngân hàng:</td><td>MB Bank (Quân đội)</td></tr>
            <tr><td style='padding:2px 0;font-weight:bold;'>Thụ hưởng:</td><td>CONG TY TNHH LIPRO LOGISTICS</td></tr>
        </table>
    </div>
    <div style='border-top:1px solid #dee2e6;padding-top:12px;'>
        <p style='margin:0 0 4px;color:#555;'>Trân trọng,</p>
        <p style='margin:0;'><strong style='color:#1B3A6B;font-size:13px;'>LIPRO LOGISTICS CO.,LTD</strong><br>
        <span style='color:#888;font-size:11px;'>📞 (+84) 366 666 322 &nbsp;|&nbsp; ✉️ lipro.logistics@gmail.com</span></p>
    </div>
</div>
<div style='background:#f8f9fa;padding:10px 28px;text-align:center;border-top:1px solid #dee2e6;'>
    <p style='margin:0;color:#aaa;font-size:10px;'>Email được tạo tự động từ hệ thống Forwarder System.</p>
</div>
</div></body></html>";
}

// ============================================================
// XỬ LÝ GỬI MAIL
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_mail'])) {
    $to_email   = trim($_POST['to_email']   ?? '');
    $to_name    = trim($_POST['to_name']    ?? '');
    $cc_str     = trim($_POST['cc_email']   ?? '');
    $bcc_str    = trim($_POST['bcc_email']  ?? '');
    $subject    = trim($_POST['subject']    ?? $auto_subject);
    $body_extra = trim($_POST['body_extra'] ?? '');

    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email người nhận không hợp lệ!';
    } else {
        $tmpFile = '';
        try {
            // Build Excel content
            $xlsContent = buildArrivalXlsxContent($shipment, $foreign_charges, $domestic_charges, $usd_rate, $eur_rate);
            if (empty($xlsContent)) {
                throw new \Exception('Không tạo được file Excel!');
            }

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

            // BCC cố định
            $mail->addBCC('dung@dnaexpress.vn', 'Dung');

            foreach (splitEmails($cc_str)  as $cc)  $mail->addCC($cc);
            foreach (splitEmails($bcc_str) as $bcc) $mail->addBCC($bcc);

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = buildArrivalMailBody($shipment, $foreign_charges, $domestic_charges, $usd_rate, $eur_rate, $body_extra);
            $mail->AltBody = "LIPRO LOGISTICS - ARRIVAL NOTICE\nJob: ".($shipment['job_no']??'')."\nHAWB: ".$hawb;

            // Đính kèm Excel Arrival Notice
            $attachName = 'ArrivalNotice_'.preg_replace('/[^A-Za-z0-9_]/','_',$shipment['job_no']??$id).'_'.date('Ymd').'.xlsx';
            $mail->addStringAttachment($xlsContent, $attachName, 'base64', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            // Đính kèm file đã lưu
            $baseDir = dirname(__DIR__).'/';
            foreach ($saved_attachments as $att) {
                $fullPath = $baseDir.$att['file_path'];
                if (file_exists($fullPath)) $mail->addAttachment($fullPath, $att['file_name']);
            }

            $mail->send();

            // Cập nhật trạng thái
            $conn2 = getDBConnection();
            $sent_at = date('Y-m-d H:i:s');
            $stmt_upd = $conn2->prepare("UPDATE shipments SET an_email_sent='yes', an_email_sent_at=?, an_email_sent_by=? WHERE id=?");
            $stmt_upd->bind_param("sii", $sent_at, $_SESSION['user_id'], $id);
            $stmt_upd->execute();
            $conn2->close();

            $shipment['an_email_sent']    = 'yes';
            $shipment['an_email_sent_at'] = $sent_at;

            // Reload attachments
            $conn3 = getDBConnection();
            $stmt_att2 = $conn3->prepare("SELECT * FROM shipment_attachments WHERE shipment_id = ? ORDER BY uploaded_at ASC");
            $stmt_att2->bind_param("i", $id);
            $stmt_att2->execute();
            $saved_attachments = $stmt_att2->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_att2->close();
            $conn3->close();

            $attCount = count($saved_attachments);
            $success = '✅ Đã gửi Thông Báo Hàng Đến thành công đến <strong>'.htmlspecialchars($to_email).'</strong>!'
                     . ($attCount > 0 ? " (kèm {$attCount} file đính kèm)" : '');

        } catch (Exception $e) {
            $error = '❌ Lỗi gửi mail: '.(isset($mail) ? $mail->ErrorInfo : $e->getMessage());
        } catch (\Throwable $e) {
            $error = '❌ Lỗi hệ thống: '.$e->getMessage().' <small>(line '.$e->getLine().')</small>';
        }
    }
}

$conn->close();

// ============================================================
// HELPERS HTML
// ============================================================
function fmtSize(int $bytes): string {
    if ($bytes < 1024)    return $bytes.' B';
    if ($bytes < 1048576) return round($bytes/1024,1).' KB';
    return round($bytes/1048576,1).' MB';
}
function fileIcon(string $type): string {
    if (str_contains($type,'pdf'))   return 'bi-file-earmark-pdf text-danger';
    if (str_contains($type,'excel') || str_contains($type,'spreadsheet')) return 'bi-file-earmark-excel text-success';
    if (str_contains($type,'word')  || str_contains($type,'document'))    return 'bi-file-earmark-word text-primary';
    if (str_contains($type,'image')) return 'bi-file-earmark-image text-info';
    if (str_contains($type,'zip')   || str_contains($type,'rar')) return 'bi-file-earmark-zip text-warning';
    return 'bi-file-earmark text-secondary';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi Thông Báo Hàng Đến - <?php echo htmlspecialchars($shipment['job_no']??''); ?></title>
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
                <li class="nav-item"><a class="nav-link" href="arrival_notice.php?id=<?php echo $id; ?>">← Arrival Notice</a></li>
                <li class="nav-item"><a class="nav-link" href="view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($shipment['job_no']??''); ?></a></li>
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
            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($shipment['job_no']??''); ?></a></li>
            <li class="breadcrumb-item"><a href="arrival_notice.php?id=<?php echo $id; ?>">Arrival Notice</a></li>
            <li class="breadcrumb-item active">Gửi Email</li>
        </ol>
    </nav>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
        <div class="mt-2 d-flex gap-2">
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-success btn-sm"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <a href="send_arrival.php?id=<?php echo $id; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-envelope"></i> Gửi lại</a>
            <a href="export_arrival.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-excel"></i> Tải Excel</a>
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
                <div class="card-header bg-info text-white py-2">
                    <h5 class="mb-0"><i class="bi bi-envelope-fill"></i> Gửi Arrival Notice - <?php echo htmlspecialchars($shipment['job_no']??''); ?></h5>
                </div>
                <div class="card-body">

                    <?php if (!empty($shipment['an_email_sent']) && $shipment['an_email_sent'] == 'yes'): ?>
                    <div class="email-sent-banner">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-envelope-check-fill text-success fs-4"></i>
                            <div>
                                <strong class="text-success">Đã gửi email trước đó!</strong>
                                <?php if (!empty($shipment['an_email_sent_at'])): ?>
                                <br><small class="text-muted"><i class="bi bi-clock"></i> Lần gửi gần nhất: <strong><?php echo date('d/m/Y H:i', strtotime($shipment['an_email_sent_at'])); ?></strong></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2"><small class="text-muted"><i class="bi bi-exclamation-triangle text-warning"></i> Bạn có thể gửi lại nếu cần.</small></div>
                    </div>
                    <?php endif; ?>

                    <!-- Chips thông tin -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="info-chip"><i class="bi bi-box text-primary"></i> <?php echo htmlspecialchars($shipment['job_no']??''); ?></span>
                        <span class="info-chip"><i class="bi bi-people text-info"></i> <?php echo htmlspecialchars($shipment['customer_short']??''); ?></span>
                        <?php if ($hawb): ?><span class="info-chip"><i class="bi bi-file-text text-warning"></i> HAWB: <?php echo htmlspecialchars($hawb); ?></span><?php endif; ?>
                        <?php if ($cd_no): ?><span class="info-chip"><i class="bi bi-card-text text-success"></i> TK: <?php echo htmlspecialchars($cd_no); ?></span><?php endif; ?>
                        <?php
                            $grand_total = array_sum(array_column($foreign_charges,'total_vnd')) + array_sum(array_column($domestic_charges,'total_vnd'));
                        ?>
                        <span class="info-chip"><i class="bi bi-currency-dollar text-success"></i> <?php echo number_format($grand_total,0,',','.'); ?> VND</span>
                    </div>

                    <form method="POST" action="send_arrival.php?id=<?php echo $id; ?>" id="sendForm">
                        <input type="hidden" name="send_mail" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-envelope text-primary"></i> Gửi đến (To) <span class="text-danger">*</span></label>
                            <input type="email" name="to_email" id="toEmail" class="form-control" required
                                   placeholder="email@company.com" oninput="updatePreviewInfo()"
                                   value="<?php echo htmlspecialchars($_POST['to_email'] ?? ($shipment['customer_email'] ?? '')); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person text-primary"></i> Tên người nhận</label>
                            <input type="text" name="to_name" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['to_name'] ?? ($shipment['customer_contact'] ?? $shipment['company_name'] ?? '')); ?>">
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

                            <!-- Arrival Notice Excel - auto -->
                            <div class="att-item mb-2">
                                <i class="bi bi-file-earmark-excel text-success fs-5"></i>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small">ArrivalNotice_<?php echo htmlspecialchars($shipment['job_no']??''); ?>_<?php echo date('Ymd'); ?>.xlsx</div>
                                    <small class="text-muted">Arrival Notice Excel — tự động tạo</small>
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
                            <a href="arrival_notice.php?id=<?php echo $id; ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                            <?php if (!empty($shipment['an_email_sent']) && $shipment['an_email_sent'] == 'yes'): ?>
                            <button type="submit" class="btn btn-warning btn-lg px-4" id="sendBtn"
                                    onclick="return confirm('Lô hàng này đã được gửi Arrival Notice!\nBạn có chắc muốn GỬI LẠI không?')">
                                <i class="bi bi-send-fill"></i> Gửi lại Email
                            </button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-info btn-lg px-4 text-white" id="sendBtn">
                                <i class="bi bi-send-fill"></i> Gửi Arrival Notice
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
                    <span class="badge bg-info" id="previewTo"><?php echo htmlspecialchars($shipment['customer_email'] ?? 'Chưa có email'); ?></span>
                </div>
                <ul class="nav nav-tabs px-3 pt-2 bg-light border-bottom">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPreview"><i class="bi bi-eye-fill"></i> Nội dung email</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSummary"><i class="bi bi-info-circle"></i> Tóm tắt</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabPreview">
                        <iframe class="preview-frame" id="previewFrame"
                                srcdoc="<?php echo htmlspecialchars(buildArrivalMailBody($shipment, $foreign_charges, $domestic_charges, $usd_rate, $eur_rate)); ?>">
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
                                        <div><i class="bi bi-file-earmark-excel text-success"></i> ArrivalNotice_<?php echo htmlspecialchars($shipment['job_no']??''); ?>_<?php echo date('Ymd'); ?>.xlsx <span class="badge bg-success">Auto</span></div>
                                        <?php foreach ($saved_attachments as $att): ?>
                                        <div><i class="bi <?php echo fileIcon($att['file_type'] ?? ''); ?>"></i> <?php echo htmlspecialchars($att['file_name']); ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <tr><th class="bg-light">Job No:</th><td><strong class="text-primary"><?php echo htmlspecialchars($shipment['job_no']??''); ?></strong></td></tr>
                                <tr><th class="bg-light">HAWB:</th><td><?php echo htmlspecialchars($hawb ?: '—'); ?></td></tr>
                                <tr><th class="bg-light">Số tờ khai:</th><td><?php echo htmlspecialchars($cd_no ?: '—'); ?></td></tr>
                                <tr><th class="bg-light">Tổng thanh toán:</th><td><strong class="text-success fs-5"><?php echo number_format($grand_total,0,',','.'); ?> VND</strong></td></tr>
                                <tr><th class="bg-light">Tỷ giá USD:</th><td><?php echo number_format($usd_rate,0,',','.'); ?> VND</td></tr>
                                <tr><th class="bg-light">Tỷ giá EUR:</th><td><?php echo number_format($eur_rate,0,',','.'); ?> VND</td></tr>
                                <tr><th class="bg-light">Phí nước ngoài:</th><td><?php echo count($foreign_charges); ?> dòng</td></tr>
                                <tr><th class="bg-light">Phí trong nước:</th><td><?php echo count($domestic_charges); ?> dòng</td></tr>
                                <tr><th class="bg-light">File đính kèm:</th><td><?php echo 1 + count($saved_attachments); ?> file (gồm Arrival Notice + <?php echo count($saved_attachments); ?> file thêm)</td></tr>
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
    let icon = 'bi-file-earmark text-secondary';
    if (att.file_type) {
        if (att.file_type.includes('pdf'))                                            icon = 'bi-file-earmark-pdf text-danger';
        else if (att.file_type.includes('excel') || att.file_type.includes('spreadsheet')) icon = 'bi-file-earmark-excel text-success';
        else if (att.file_type.includes('word')  || att.file_type.includes('document'))    icon = 'bi-file-earmark-word text-primary';
        else if (att.file_type.includes('image'))                                     icon = 'bi-file-earmark-image text-info';
        else if (att.file_type.includes('zip')   || att.file_type.includes('rar'))    icon = 'bi-file-earmark-zip text-warning';
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