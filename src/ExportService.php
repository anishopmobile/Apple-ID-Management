<?php
declare(strict_types=1);

/**
 * Internal export builders. These are not HTTP endpoints.
 * Only pass data that was authorized and selected by an authenticated admin.
 */
final class ExportService
{
    public static function pdfTable(string $title, array $headers, array $rows): string
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $fontPath = dirname(__DIR__) . '/storage/fonts';
        foreach (['Vazirmatn-Regular.ttf', 'Vazirmatn-Bold.ttf'] as $font) {
            if (!is_file($fontPath . '/' . $font)) {
                throw new RuntimeException('فونت فارسی PDF نصب نشده است؛ npm run build:assets را اجرا کنید.');
            }
        }
        $fonts = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $directories = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'fontDir' => array_merge($directories, [$fontPath]),
            'fontdata' => array_merge($fonts, [
                'vazirmatn' => ['R' => 'Vazirmatn-Regular.ttf', 'B' => 'Vazirmatn-Bold.ttf'],
            ]),
            'default_font' => 'vazirmatn',
            'tempDir' => sys_get_temp_dir(),
            'margin_left' => 12,
            'margin_right' => 12,
        ]);
        $pdf->SetDirectionality('rtl');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:vazirmatn;direction:rtl;font-size:10pt;color:#182337}'
            . 'h1{font-size:17pt;color:#16429a}'
            . 'table{width:100%;border-collapse:collapse;direction:rtl}'
            . 'th,td{border:1px solid #d7e2f0;padding:8px;text-align:right}'
            . 'th{background:#e8eefb;font-weight:bold}'
            . '</style></head><body><h1>' . $escape($title) . '</h1><table><thead><tr>';
        foreach ($headers as $header) { $html .= '<th>' . $escape($header) . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) { $html .= '<td>' . $escape($cell) . '</td>'; }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $pdf->WriteHTML($html);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public static function xlsxTable(string $title, array $headers, array $rows): string
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('گزارش');
        $sheet->setRightToLeft(true);
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $sheet->setCellValueExplicit('A1', $title, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->setSize(15);
        $sheet->getRowDimension(1)->setRowHeight(34);
        foreach (array_values($headers) as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($column . '3', (string)$header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('A3:' . $lastColumn . '3')->getFont()->setBold(true);
        $sheet->getStyle('A3:' . $lastColumn . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEFB');
        foreach (array_values($rows) as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $address = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1) . ($rowIndex + 4);
                // Explicit text prevents user values beginning with "=" being treated as Excel formulas.
                $sheet->setCellValueExplicit($address, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A4');
        $sheet->setAutoFilter('A3:' . $lastColumn . max(3, count($rows) + 3));
        $file = tempnam(sys_get_temp_dir(), 'aid_xlsx_');
        if ($file === false) { throw new RuntimeException('امکان ساخت فایل موقت وجود ندارد.'); }
        try {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook);
            $writer->save($file);
            $bytes = file_get_contents($file);
            if (!is_string($bytes)) { throw new RuntimeException('خواندن فایل Excel ناموفق بود.'); }
            return $bytes;
        } finally {
            $workbook->disconnectWorksheets();
            @unlink($file);
        }
    }
}
