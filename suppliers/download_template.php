<?php
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Nha cung cap');

// Style header
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ED7D31']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]]
];

// Style dữ liệu mẫu
$dataStyle = [
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFD966']]]
];

// Style ghi chú
$noteStyle = [
    'font' => ['italic' => true, 'color' => ['rgb' => '856404']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFECB5']]]
];

// Headers
$headers = [
    'A1' => 'Tên nhà cung cấp (*)',
    'B1' => 'Tên viết tắt (*)',
    'C1' => 'Mã số thuế',
    'D1' => 'Địa chỉ',
    'E1' => 'Điện thoại',
    'F1' => 'Email',
    'G1' => 'Người liên hệ',
    'H1' => 'Tên ngân hàng',
    'I1' => 'Số tài khoản',
    'J1' => 'Trạng thái (active/inactive)',
];

foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
    $sheet->getStyle($cell)->applyFromArray($headerStyle);
}

// Dữ liệu mẫu
$sampleData = [
    2 => ['Công ty Vận tải ABC', 'ABC', '0123456789', '123 Nguyễn Văn A, Q1, HCM', '028-1234567', 'abc@company.com', 'Nguyễn Văn A', 'Vietcombank', '0123456789', 'active'],
    3 => ['Công ty Logistics XYZ', 'XYZ', '0987654321', '456 Trần Hưng Đạo, Q5, HCM', '028-7654321', 'xyz@company.com', 'Trần Văn B', 'Techcombank', '9876543210', 'active'],
    4 => ['Công ty TNHH DEF', 'DEF', '0111222333', '789 Lê Lợi, Q3, HCM', '028-3333333', 'def@company.com', 'Lê Văn C', 'BIDV', '1112223330', 'inactive'],
];

$cols = ['A','B','C','D','E','F','G','H','I','J'];
foreach ($sampleData as $rowNum => $rowData) {
    foreach ($cols as $i => $col) {
        $sheet->setCellValue($col . $rowNum, $rowData[$i]);
        $sheet->getStyle($col . $rowNum)->applyFromArray($dataStyle);
    }
}

// Dòng ghi chú
$sheet->mergeCells('A5:J5');
$sheet->setCellValue('A5', 'Lưu ý: Cột A và B là bắt buộc (*). Cột J chỉ nhập "active" hoặc "inactive". Dữ liệu bắt đầu từ dòng 2.');
$sheet->getStyle('A5:J5')->applyFromArray($noteStyle);

// Chiều rộng cột
$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(40);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(25);
$sheet->getColumnDimension('G')->setWidth(20);
$sheet->getColumnDimension('H')->setWidth(20);
$sheet->getColumnDimension('I')->setWidth(20);
$sheet->getColumnDimension('J')->setWidth(25);

$sheet->getRowDimension(1)->setRowHeight(30);

// Download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Mau_Import_Nha_Cung_Cap.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>