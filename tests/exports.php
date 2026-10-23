<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/ExportService.php';

$headers = ['نام مشتری', 'وضعیت', 'تاریخ شمسی'];
$rows = [
    ['علی رضایی', 'فعال', '۱۴۰۵/۰۸/۰۱'],
    ['=HYPERLINK("https://invalid.example")', 'آزمایشی', '۱۴۰۵/۰۸/۰۲'],
];
$pdf = ExportService::pdfTable('گزارش مشتریان آنی شاپ', $headers, $rows);
if (!str_starts_with($pdf, '%PDF') || strlen($pdf) < 2000) {
    fwrite(STDERR, "Invalid Persian PDF output\n");
    exit(1);
}
$xlsx = ExportService::xlsxTable('گزارش مشتریان آنی شاپ', $headers, $rows);
if (!str_starts_with($xlsx, "PK") || strlen($xlsx) < 1000) {
    fwrite(STDERR, "Invalid Persian XLSX output\n");
    exit(1);
}
$path = tempnam(sys_get_temp_dir(), 'aid_excel_validation_');
file_put_contents($path, $xlsx);
try {
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $book = $reader->load($path);
    $sheet = $book->getActiveSheet();
    if (!$sheet->getRightToLeft() || $sheet->getCell('A4')->getValue() !== 'علی رضایی') {
        throw new RuntimeException('RTL/persian cell data not preserved.');
    }
    if ($sheet->getCell('A5')->getDataType() !== \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING) {
        throw new RuntimeException('Formula injection defense absent.');
    }
    $book->disconnectWorksheets();
} finally { @unlink($path); }
echo "PDF with Vazirmatn and Persian RTL XLSX verified.\n";
