<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Color;

// ============================================================
// THAM SỐ LỌC
// ============================================================
$search       = trim($_GET['search']       ?? '');
$search_email = trim($_GET['search_email'] ?? '');
$status_kh    = trim($_GET['status_kh']    ?? '');
$status_ncc   = trim($_GET['status_ncc']   ?? '');
$month        = trim($_GET['month']        ?? '');
$customer_id  = trim($_GET['customer_id']  ?? '');
$is_locked    = trim($_GET['is_locked']    ?? '');

$conn = getDBConnection();

// ============================================================
// BUILD WHERE
// ============================================================
$where  = ["s.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(s.job_no LIKE ? OR s.hawb LIKE ? OR s.mawb LIKE ? OR c.company_name LIKE ? OR s.customs_declaration_no LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}
if ($search_email !== '') {
    $like_email = '%' . $search_email . '%';
    $where[]    = 'c.email LIKE ?';
    $params[]   = $like_email;
    $types     .= 's';
}
if ($month !== '') {
    $where[]  = 'DATE_FORMAT(s.arrival_date, "%Y-%m") = ?';
    $params[] = $month;
    $types   .= 's';
}
if ($customer_id !== '' && intval($customer_id) > 0) {
    $where[]  = 's.customer_id = ?';
    $params[] = intval($customer_id);
    $types   .= 'i';
}
if ($is_locked !== '') {
    $where[]  = 's.is_locked = ?';
    $params[] = $is_locked;
    $types   .= 's';
}

$sql = "SELECT s.id, s.job_no, s.hawb, s.mawb, s.customs_declaration_no,
    s.arrival_date, s.invoice_no, s.invoice_date,
    c.id AS cust_id, c.company_name, c.short_name, c.email AS cust_email,
    COALESCE((SELECT SUM(sc.total_amount) FROM shipment_costs sc WHERE sc.shipment_id = s.id), 0) AS total_cost,
    COALESCE((SELECT SUM(ss.total_amount) FROM shipment_sells ss WHERE ss.shipment_id = s.id), 0) AS total_sell,
    COALESCE(s.customer_paid_amount, 0) AS customer_paid_amount,
    s.customer_paid_at,
    s.customer_paid_note,
    COALESCE(s.supplier_paid_amount, 0) AS supplier_paid_amount,
    s.supplier_paid_note
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.arrival_date DESC, s.id DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Filter status sau khi lấy data
$data = [];
foreach ($rows as $row) {
    $sell       = floatval($row['total_sell']);
    $cost       = floatval($row['total_cost']);
    $kh_paid    = floatval($row['customer_paid_amount']);
    $ncc_paid   = floatval($row['supplier_paid_amount']);
    $kh_remain  = $sell - $kh_paid;
    $ncc_remain = $cost - $ncc_paid;

    if ($status_kh === 'paid'    && $kh_paid  < $sell)  continue;
    if ($status_kh === 'unpaid'  && $kh_paid  >= $sell) continue;
    if ($status_kh === 'partial' && ($kh_paid <= 0 || $kh_paid >= $sell)) continue;
    if ($status_ncc === 'paid'    && $ncc_paid  < $cost)  continue;
    if ($status_ncc === 'unpaid'  && $ncc_paid  >= $cost) continue;
    if ($status_ncc === 'partial' && ($ncc_paid <= 0 || $ncc_paid >= $cost)) continue;

    $row['total_sell']  = $sell;
    $row['total_cost']  = $cost;
    $row['kh_remain']   = $kh_remain;
    $row['ncc_remain']  = $ncc_remain;
    $data[] = $row;
}

$conn->close();

// Totals
$sum_sell      = array_sum(array_column($data, 'total_sell'));
$sum_kh_paid   = array_sum(array_column($data, 'customer_paid_amount'));
$sum_kh_remain = array_sum(array_column($data, 'kh_remain'));

// ============================================================
// HELPERS
// ============================================================
function xc($sh, $cell, $val)  { $sh->setCellValue($cell, $val); }
function xcs($sh, $cell, $val) { $sh->setCellValueExplicit($cell, (string)$val, DataType::TYPE_STRING); }
function xs($sh, $r, $s)       { $sh->getStyle($r)->applyFromArray($s); }
function xr($sh, $n, $h)       { $sh->getRowDimension($n)->setRowHeight($h); }
function xm($sh, $r)           { $sh->mergeCells($r); }
function xfill($sh, $r, $c)    {
    $sh->getStyle($r)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $c]]
    ]);
}
function xborder($sh, $r, $c = '1B3A6B', $w = Border::BORDER_MEDIUM) {
    $sh->getStyle($r)->applyFromArray([
        'borders' => ['outline' => ['borderStyle' => $w, 'color' => ['rgb' => $c]]]
    ]);
}
function fmtNum($n) {
    return ($n != 0) ? number_format((float)$n, 0, ',', '.') : '-';
}

// ============================================================
// SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Debt Report');

$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);
$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->setShowGridlines(false);

// ============================================================
// ĐỘ RỘNG CỘT
// Bỏ cột E (Email) — dịch chuyển: E=HAWB, F=TờKhai, G=SốHĐ,
// H=NgàyHĐ, I=NgàyĐến, J=Sell, K=KHĐãTrả, L=NgàyTrả, M=CònNợ, N=GhiChú
// ============================================================
$colWidths = [
    'A' => 1.2,  // padding trái
    'B' => 5,    // STT
    'C' => 13,   // Job No
    'D' => 26,   // Khách hàng (rộng hơn vì bỏ email)
    'E' => 15,   // HAWB
    'F' => 16,   // Tờ Khai
    'G' => 13,   // Số HĐ
    'H' => 11,   // Ngày HĐ
    'I' => 11,   // Ngày Đến
    'J' => 15,   // Tổng Sell
    'K' => 15,   // KH Đã Trả
    'L' => 12,   // Ngày Trả KH
    'M' => 15,   // KH Còn Nợ
    'N' => 24,   // Ghi Chú KH
    'O' => 1.2,  // padding phải
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ============================================================
// KHỞI ĐẦU
// ============================================================
$R = 1;

// ============================================================
// ROW 1 — TOP PADDING
// ============================================================
xr($sheet, $R, 5); $R++;

// ============================================================
// ROWS 2-9 — HEADER: LOGO TEXT (trái B-E) + CÔNG TY (phải F-O)
// ============================================================

// --- LOGO BLOCK: cột B-E, rows 2-8 (merge cứng đến row 8) ---
xm($sheet, "B{$R}:E8");
xr($sheet, $R, 30);

// RichText logo 2 dòng: "LIPRO" lớn + "· L O G I S T I C S ·" nhỏ
$logoFull = new RichText();

$l2 = $logoFull->createTextRun("L");
$l2->getFont()->setBold(true)->setSize(38)->setName('Arial Black')
   ->setColor(new Color('FF1B3A6B'));
$i2 = $logoFull->createTextRun("I");
$i2->getFont()->setBold(true)->setSize(38)->setName('Arial Black')
   ->setColor(new Color('FFF4B942'));
$p2 = $logoFull->createTextRun("P");
$p2->getFont()->setBold(true)->setSize(38)->setName('Arial Black')
   ->setColor(new Color('FF1B3A6B'));
$r2 = $logoFull->createTextRun("R");
$r2->getFont()->setBold(true)->setSize(38)->setName('Arial Black')
   ->setColor(new Color('FFF4B942'));
$o2 = $logoFull->createTextRun("O");
$o2->getFont()->setBold(true)->setSize(38)->setName('Arial Black')
   ->setColor(new Color('FF1B3A6B'));

$logoFull->createText("\n");

$sub = $logoFull->createTextRun("· L O G I S T I C S ·");
$sub->getFont()->setBold(false)->setSize(8)->setName('Calibri')
    ->setColor(new Color('FF888888'));

$sheet->setCellValue("B{$R}", $logoFull);
xs($sheet, "B{$R}", [
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FA']],
]);
$sheet->getStyle("B{$R}:E8")->applyFromArray([
    'borders' => [
        'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0DCF0']],
    ],
]);

// --- THÔNG TIN CÔNG TY: cột F-O, rows 2-8 ---

// Row 2 — Tên công ty
xr($sheet, $R, 30);
xm($sheet, "F{$R}:O{$R}");
xc($sheet, "F{$R}", 'LIPRO LOGISTICS CO.,LTD');
xs($sheet, "F{$R}", [
    'font'      => ['bold' => true, 'size' => 20, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 3 — Tagline
xr($sheet, $R, 14);
xm($sheet, "F{$R}:O{$R}");
xc($sheet, "F{$R}", 'FREIGHT FORWARDING & CUSTOMS CLEARANCE');
xs($sheet, "F{$R}", [
    'font'      => ['size' => 9, 'italic' => true, 'name' => 'Calibri',
                    'color' => ['rgb' => 'F4B942']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 4 — Đường kẻ vàng trang trí (chỉ phần phải)
xr($sheet, $R, 3);
xm($sheet, "F{$R}:O{$R}");
xfill($sheet, "F{$R}:O{$R}", 'F4B942');
$R++;

// Row 5 — blank nhỏ
xr($sheet, $R, 5); $R++;

// Row 6 — Địa chỉ
xr($sheet, $R, 15);
xc($sheet, "F{$R}", 'Địa chỉ:');
xs($sheet, "F{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '555555']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "G{$R}:O{$R}");
xc($sheet, "G{$R}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi City, Vietnam');
xs($sheet, "G{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
]);
$R++;

// Row 7 — Điện thoại
xr($sheet, $R, 14);
xc($sheet, "F{$R}", 'Điện thoại:');
xs($sheet, "F{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '555555']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "G{$R}:O{$R}");
xc($sheet, "G{$R}", '0985 572 699');
xs($sheet, "G{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 8 — Email công ty
xr($sheet, $R, 14);
xc($sheet, "F{$R}", 'Email:');
xs($sheet, "F{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '555555']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "G{$R}:O{$R}");
xc($sheet, "G{$R}", 'lipro.logistics@gmail.com');
xs($sheet, "G{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '0563C1'], 'underline' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++; // R = 9

// === Đường kẻ Navy + Gold toàn chiều rộng ===
xr($sheet, $R, 3);
xm($sheet, "B{$R}:O{$R}");
xfill($sheet, "B{$R}:O{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:O{$R}");
xfill($sheet, "B{$R}:O{$R}", 'F4B942');
$R++;
xr($sheet, $R, 8); $R++;

// ============================================================
// TIÊU ĐỀ REPORT
// ============================================================
xr($sheet, $R, 38);
xm($sheet, "B{$R}:O{$R}");
xc($sheet, "B{$R}", 'DEBT REPORT');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 20, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Đường kẻ dưới tiêu đề
xr($sheet, $R, 3);
xm($sheet, "B{$R}:O{$R}");
xfill($sheet, "B{$R}:O{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:O{$R}");
xfill($sheet, "B{$R}:O{$R}", 'C00000');
$R++;
xr($sheet, $R, 12); $R++;

// ============================================================
// THÔNG TIN LỌC / KỲ BÁO CÁO
// ============================================================
$filterDesc = [];
if ($search !== '')       $filterDesc[] = 'Tìm kiếm: ' . $search;
if ($search_email !== '') $filterDesc[] = 'Email: ' . $search_email;
if ($month !== '')        $filterDesc[] = 'Tháng: ' . date('m/Y', strtotime($month . '-01'));
if ($status_kh !== '')    $filterDesc[] = 'Công nợ KH: ' . ['unpaid' => 'Chưa thu', 'partial' => 'Một phần', 'paid' => 'Đã thu'][$status_kh];
if ($is_locked !== '')    $filterDesc[] = 'Khoá: ' . ($is_locked === 'yes' ? 'Đã khoá' : 'Chưa khoá');

if (!empty($filterDesc)) {
    xr($sheet, $R, 16);
    xc($sheet, "B{$R}", 'Bộ lọc:');
    xs($sheet, "B{$R}", [
        'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    xm($sheet, "C{$R}:O{$R}");
    xc($sheet, "C{$R}", implode('   |   ', $filterDesc));
    xs($sheet, "C{$R}", [
        'font'      => ['size' => 10, 'italic' => true, 'name' => 'Calibri',
                        'color' => ['rgb' => '555555']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $R++;
}

// Ngày xuất + tổng số lô
xr($sheet, $R, 16);
xc($sheet, "B{$R}", 'Ngày xuất:');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "C{$R}:G{$R}");
xc($sheet, "C{$R}", date('d/m/Y H:i'));
xs($sheet, "C{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
xc($sheet, "H{$R}", 'Tổng số lô:');
xs($sheet, "H{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "I{$R}:O{$R}");
xc($sheet, "I{$R}", count($data) . ' lô');
xs($sheet, "I{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'bold' => true,
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;
xr($sheet, $R, 10); $R++;

// ============================================================
// HEADER BẢNG
// Cột (bỏ Email): B=STT, C=JobNo, D=KH, E=HAWB, F=TờKhai,
//                 G=SốHĐ, H=NgàyHĐ, I=NgàyĐến, J=Sell,
//                 K=KHĐãTrả, L=NgàyTrả, M=CònNợ, N=GhiChú
// ============================================================
$tableStart = $R;
xr($sheet, $R, 32);

$hStyle = [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1B3A6B']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];

$headers = [
    'B' => ['STT',          Alignment::HORIZONTAL_CENTER],
    'C' => ['Job No',       Alignment::HORIZONTAL_CENTER],
    'D' => ['Khách hàng',   Alignment::HORIZONTAL_LEFT],
    'E' => ['HAWB',         Alignment::HORIZONTAL_CENTER],
    'F' => ['Tờ Khai',      Alignment::HORIZONTAL_CENTER],
    'G' => ['Số HĐ',        Alignment::HORIZONTAL_CENTER],
    'H' => ['Ngày HĐ',      Alignment::HORIZONTAL_CENTER],
    'I' => ['Ngày Đến',     Alignment::HORIZONTAL_CENTER],
    'J' => ['Tổng Sell',    Alignment::HORIZONTAL_RIGHT],
    'K' => ['KH Đã Trả',    Alignment::HORIZONTAL_RIGHT],
    'L' => ['Ngày Trả KH',  Alignment::HORIZONTAL_CENTER],
    'M' => ['KH Còn Nợ',    Alignment::HORIZONTAL_RIGHT],
    'N' => ['Ghi Chú KH',   Alignment::HORIZONTAL_LEFT],
];

foreach ($headers as $col => [$label, $align]) {
    xc($sheet, "{$col}{$R}", $label);
    xs($sheet, "{$col}{$R}", array_merge($hStyle, [
        'alignment' => ['horizontal' => $align,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
    ]));
}
$R++;

// ============================================================
// STYLE BASE CHO DỮ LIỆU
// ============================================================
$dataStyleBase = [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR,
                                     'color'       => ['rgb' => 'BDD7EE']]],
];

// ============================================================
// DỮ LIỆU
// ============================================================
$stt = 1;
foreach ($data as $row) {
    xr($sheet, $R, 20);
    $bg        = ($stt % 2 === 0) ? 'EEF4FB' : 'FFFFFF';
    $kh_remain = $row['kh_remain'];

    $remain_color = $kh_remain > 0 ? 'C00000' : '198754';

    $inv_date  = (!empty($row['invoice_date'])     && $row['invoice_date']     !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['invoice_date']))  : '';
    $arr_date  = (!empty($row['arrival_date'])      && $row['arrival_date']      !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['arrival_date']))  : '';
    $paid_date = (!empty($row['customer_paid_at']) && $row['customer_paid_at'] !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['customer_paid_at'])) : '';

    // Cột text thường (số, ngày)
    $textCells = [
        'B' => [$stt,       Alignment::HORIZONTAL_CENTER, false, '333333'],
        'H' => [$inv_date,  Alignment::HORIZONTAL_CENTER, false, '333333'],
        'I' => [$arr_date,  Alignment::HORIZONTAL_CENTER, false, '333333'],
        'L' => [$paid_date, Alignment::HORIZONTAL_CENTER, false, '555555'],
    ];
    foreach ($textCells as $col => [$val, $align, $bold, $color]) {
        xc($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align]),
        ]));
    }

    // Cột string (ngăn scientific notation)
    $stringCells = [
        'C' => [$row['job_no']                 ?? '', Alignment::HORIZONTAL_CENTER, true,  '1B3A6B'],
        'D' => [$row['company_name']           ?? '', Alignment::HORIZONTAL_LEFT,   false, '333333'],
        'E' => [$row['hawb']                   ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'F' => [$row['customs_declaration_no'] ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'G' => [$row['invoice_no']             ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'N' => [$row['customer_paid_note']     ?? '', Alignment::HORIZONTAL_LEFT,   false, '666666'],
    ];
    foreach ($stringCells as $col => [$val, $align, $bold, $color]) {
        xcs($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align, 'wrapText' => true]),
        ]));
    }

    // Cột số tiền
    $numCells = [
        'J' => [fmtNum($row['total_sell']),           false, '1B3A6B'],
        'K' => [fmtNum($row['customer_paid_amount']), false, '198754'],
        'M' => [fmtNum($kh_remain),                   true,  $remain_color],
    ];
    foreach ($numCells as $col => [$val, $bold, $color]) {
        xcs($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => Alignment::HORIZONTAL_RIGHT]),
        ]));
    }

    $stt++;
    $R++;
}

// ============================================================
// DÒNG TỔNG CỘNG
// ============================================================
xr($sheet, $R, 26);
$totalRow = $R;
$tStyle = [
    'font'      => ['bold' => true, 'size' => 11, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'fill'      => ['fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D6E4F0']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];

// Label TỔNG CỘNG — B:I
xm($sheet, "B{$R}:I{$R}");
xc($sheet, "B{$R}", 'TỔNG CỘNG  (' . count($data) . ' lô)');
xs($sheet, "B{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Tổng Sell — J
xcs($sheet, "J{$R}", fmtNum($sum_sell));
xs($sheet, "J{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Tổng KH đã trả — K
xcs($sheet, "K{$R}", fmtNum($sum_kh_paid));
xs($sheet, "K{$R}", array_merge($tStyle, [
    'font'      => array_merge($tStyle['font'], ['color' => ['rgb' => '198754']]),
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Cột L (Ngày Trả) — trống
xcs($sheet, "L{$R}", '');
xs($sheet, "L{$R}", $tStyle);

// Tổng còn nợ — M
xcs($sheet, "M{$R}", fmtNum($sum_kh_remain));
xs($sheet, "M{$R}", array_merge($tStyle, [
    'font'      => array_merge($tStyle['font'], [
        'color' => ['rgb' => $sum_kh_remain > 0 ? 'C00000' : '198754'],
        'size'  => 12,
    ]),
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Cột N (Ghi Chú) — trống
xcs($sheet, "N{$R}", '');
xs($sheet, "N{$R}", $tStyle);

// Viền ngoài toàn bảng
xborder($sheet, "B{$tableStart}:N{$totalRow}", '1B3A6B', Border::BORDER_MEDIUM);
$R++;
xr($sheet, $R, 18); $R++;

// ============================================================
// FOOTER — Chữ ký + Ghi chú
// ============================================================
xr($sheet, $R, 16);
xm($sheet, "B{$R}:E{$R}");
xc($sheet, "B{$R}", 'Người lập báo cáo');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => '1B3A6B']]],
]);
xm($sheet, "J{$R}:N{$R}");
xc($sheet, "J{$R}", 'Giám đốc');
xs($sheet, "J{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => '1B3A6B']]],
]);
$R++;

xr($sheet, $R, 50);
xm($sheet, "B{$R}:E{$R}");
xm($sheet, "J{$R}:N{$R}");
xs($sheet, "B{$R}:N{$R}", [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => 'DDDDDD']]],
]);
$R++;

xr($sheet, $R, 16);
xm($sheet, "B{$R}:N{$R}");
xc($sheet, "B{$R}", '* Báo cáo được xuất tự động từ hệ thống. Số tiền đơn vị VNĐ.');
xs($sheet, "B{$R}", [
    'font'      => ['size' => 9, 'italic' => true, 'name' => 'Calibri',
                    'color' => ['rgb' => '999999']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);

// ============================================================
// PRINT AREA & OUTPUT
// ============================================================
$sheet->getPageSetup()->setPrintArea("A1:O{$R}");

$date_slug = $month ? '_' . str_replace('-', '', $month) : '';
$filename  = 'DebtReport' . $date_slug . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>