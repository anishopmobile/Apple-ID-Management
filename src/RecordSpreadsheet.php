<?php
declare(strict_types=1);

require_once __DIR__ . '/RecordManager.php';

/**
 * .xlsx only. Import is all-or-nothing; a validated preview is never treated
 * as trusted for final commit. Files are kept outside the document root.
 */
final class RecordSpreadsheet
{
    public const MAX_BYTES = 2097152;
    public const MAX_ROWS = 1000;
    public const MAX_COLUMNS = 40;
    private RecordManager $manager;

    public function __construct(RecordManager $manager) { $this->manager = $manager; }

    public function expectedFields(): array
    {
        $fields = $this->manager->activeFields();
        if (count($fields) > self::MAX_COLUMNS) {
            throw new DomainException('بیش از ۴۰ فیلد فعال وجود دارد؛ ورود اکسل در این وضعیت مجاز نیست.');
        }
        return $fields;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(array_map(
            static fn(array $f): array => [$f['id'],$f['field_key'],$f['label'],$f['type'],$f['options_json'],$f['required'],$f['active']],
            $this->expectedFields()
        ), JSON_UNESCAPED_UNICODE));
    }

    public function exportAll(): string
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $this->manager->assertSystemFields();
        // The 40-column cap protects uploads/imports; exports must include
        // ALL active date and other fields even on installations with >40.
        $fields = $this->manager->activeFields();
        if (count($fields) > 100) {
            throw new DomainException('خروجی اکسل حداکثر ۱۰۰ فیلد فعال را پشتیبانی می‌کند.');
        }
        $workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('اطلاعات');
        $sheet->setRightToLeft(true);
        $sheet->getDefaultRowDimension()->setRowHeight(22);
        $sheet->getSheetView()->setZoomScale(95);
        foreach ($fields as $index=>$field) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1);
            $sheet->setCellValueExplicit($col . '1',$field['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getColumnDimension($col)->setWidth(min(40,max(16,mb_strlen($field['label'])+5)));
        }
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1,count($fields)));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEFB');
        $sheet->freezePane('A2');
        $line=2; $page=1;
        while (true) {
            // Export does not inherit the UI filters; it contains ALL non-deleted records.
            $batch = $this->manager->listing(['page'=>$page,'per_page'=>100]);
            foreach ($batch['rows'] as $row) {
                foreach ($fields as $index=>$field) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1);
                    $type = $field['type'];
                    $raw = $row['values'][(int)$field['id']] ?? null;
                    $value = $raw === null ? '' : (string)$raw;
                    if ($type === 'date' && $value !== '') $value=RecordManager::dateLabel($value);
                    if ($type === 'boolean' && $value !== '') $value=$value === '1' ? 'بله' : 'خیر';
                    // Numeric types are exported as real numeric Excel cells when
                    // within Excel's 15-digit precision, otherwise exact text.
                    $numeric = in_array($type,['number','money'],true) && $value !== '' &&
                        preg_match('/^-?\d{1,15}(?:\.\d{1,4})?$/D',$value);
                    if ($numeric) {
                        $sheet->setCellValueExplicit($col . $line,(float)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                        $sheet->getStyle($col . $line)->getNumberFormat()->setFormatCode('#,##0.####');
                    } else {
                        // Explicit TEXT protects leading zeroes and blocks formula injection.
                        $sheet->setCellValueExplicit($col . $line,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
                $line++;
            }
            if ($page >= $batch['pages']) break;
            $page++;
        }
        $sheet->setAutoFilter('A1:' . $lastColumn . max(1,$line-1));
        $temp=tempnam(sys_get_temp_dir(),'aid_export_');
        if ($temp===false) throw new RuntimeException('امکان ایجاد فایل موقت خروجی وجود ندارد.');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($temp);
            return $temp; // Caller must unlink after streaming.
        } catch (Throwable $ex) {
            @unlink($temp); throw $ex;
        } finally { $workbook->disconnectWorksheets(); }
    }

    public static function validateFile(string $path, int $bytes): void
    {
        if ($bytes < 100 || $bytes > self::MAX_BYTES || !is_file($path)) {
            throw new DomainException('حجم فایل باید کمتر از ۲ مگابایت و بیشتر از ۱۰۰ بایت باشد.');
        }
        $stream=fopen($path,'rb'); if (!$stream) throw new DomainException('فایل قابل خواندن نیست.');
        $signature=fread($stream,4); fclose($stream);
        if (!in_array($signature,["PK\x03\x04","PK\x05\x06"],true)) {
            throw new DomainException('فقط فایل واقعی Excel با پسوند xlsx پذیرفته می‌شود.');
        }
        $zip=new \ZipArchive();
        if ($zip->open($path)!==true) throw new DomainException('فایل اکسل خراب یا نامعتبر است.');
        try {
            if ($zip->numFiles > 500) throw new DomainException('فایل دارای تعداد اجزای بیش از حد مجاز است.');
            $total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $item=$zip->statIndex($i);
                if (!$item) throw new DomainException('ساختار فایل نامعتبر است.');
                $total+=(int)$item['size'];
                if ($total > 32*1024*1024 || (int)$item['size'] > 16*1024*1024) {
                    throw new DomainException('فایل فشرده دارای محتوای بیش از حد مجاز است.');
                }
                if (preg_match('~(?:^|/)(?:externalLinks|embeddings|activeX|vbaProject)[^/]*~i',(string)$item['name'])) {
                    throw new DomainException('فایل شامل پیوند یا محتوای اجرایی غیرمجاز است.');
                }
            }
            if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                throw new DomainException('ساختار واقعی فایل xlsx معتبر نیست.');
            }
        } finally { $zip->close(); }
    }

    public function inspect(string $file): array
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $this->manager->assertSystemFields();
        self::validateFile($file,filesize($file) ?: 0);
        $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(false);
        if (!$reader->canRead($file)) throw new DomainException('فایل قابل‌خواندن توسط اکسل نیست.');
        $book=$reader->load($file);
        try {
            $sheet=$book->getSheet(0);
            $highestRow=$sheet->getHighestDataRow();
            $highestCol=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($highestRow>self::MAX_ROWS+1 || $highestCol>self::MAX_COLUMNS) {
                throw new DomainException('فایل حداکثر ۱۰۰۰ ردیف اطلاعات و ۴۰ ستون می‌تواند داشته باشد.');
            }
            $fields=$this->expectedFields();
            if ($highestCol!==count($fields)) {
                throw new DomainException('تعداد ستون‌های فایل با فیلدهای فعال سامانه تطابق ندارد.');
            }
            foreach ($fields as $i=>$f) {
                $head=$sheet->getCell([$i+1,1]);
                if ($head->getDataType()===\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA ||
                    trim((string)$head->getValue())!==$f['label']) {
                    throw new DomainException('عنوان یا ترتیب ستون شماره ' . ($i+1) .
                        ' نامعتبر است؛ عنوان مورد انتظار: «' . $f['label'] . '».');
                }
            }
            $rows=[]; $issues=[]; $warnings=[]; $phones=[];
            for ($r=2;$r<=$highestRow;$r++) {
                $raw=[]; $created=null; $empty=true;
                foreach ($fields as $i=>$field) {
                    $cell=$sheet->getCell([$i+1,$r]);
                    if ($cell->getDataType()===\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                        $issues[]='ردیف ' . $r . ': وجود فرمول به جای داده مجاز نیست.';
                        continue;
                    }
                    $value=$cell->getValue();
                    if ($value!==null && trim((string)$value)!=='') $empty=false;
                    if (is_bool($value)) $value=$value ? '1' : '0';
                    if ($field['type']==='boolean') {
                        $value = match (trim((string)$value)) {
                            'بله' => '1', 'خیر' => '0', default => (string)$value
                        };
                    }
                    if ($field['type']==='date' && is_numeric($value) && $value!==null && (string)$value!=='') {
                        try { $value=\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value)->format('Y-m-d'); }
                        catch (Throwable $ex) { $issues[]='ردیف ' . $r . ': تاریخ اکسل نامعتبر است.'; }
                    }
                    if ($field['field_key']==='created_at') $created=(string)$value;
                    else $raw[(int)$field['id']]=(string)($value??'');
                }
                if ($empty) continue;
                try {
                    if ($created!==null && trim($created)!=='' && RecordManager::normalizeDate($created)===null) {
                        throw new DomainException('تاریخ ثبت معتبر نیست.');
                    }
                    $normalized=$this->manager->validateValues($raw);
                    $system=array_column($fields,null,'field_key');
                    $phone=$normalized[(int)$system['mobile']['id']] ?? '';
                    if ($phone!=='') {
                        if (isset($phones[$phone])) $warnings[]='ردیف ' . $r . ': موبایل با ردیف ' . $phones[$phone] .
                            ' تکرار شده است؛ رکورد دوم به همان مشتری متصل می‌شود.';
                        else $phones[$phone]=$r;
                    }
                    $rows[]=['excel_row'=>$r,'data'=>$raw,'date'=>$created];
                } catch (RecordValidationException $ex) {
                    foreach ($ex->fieldErrors as $message) $issues[]='ردیف ' . $r . ': ' . $message;
                } catch (DomainException $ex) { $issues[]='ردیف ' . $r . ': ' . $ex->getMessage(); }
                if (count($issues)>100) throw new DomainException('تعداد خطاها از ۱۰۰ مورد بیشتر شد؛ ابتدا فایل را اصلاح کنید.');
            }
            if (!$rows && !$issues) $issues[]='فایل هیچ ردیف اطلاعاتی ندارد.';
            return ['rows'=>$rows,'errors'=>$issues,'warnings'=>$warnings,'columns'=>array_column($fields,'label')];
        } finally { $book->disconnectWorksheets(); }
    }

    public function commit(string $path): int
    {
        // Preview validity is never trusted: inspect with CURRENT field settings.
        $result=$this->inspect($path);
        if ($result['errors'] || !$result['rows']) throw new DomainException('فایل پس از پیش‌نمایش تغییر کرده یا داده نامعتبر دارد.');
        $pdo=db();
        $pdo->beginTransaction();
        try {
            foreach ($result['rows'] as $row) {
                $this->manager->saveRecord($row['data'],null,$row['date'] ?: null,true);
            }
            $pdo->commit();
            return count($result['rows']);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    }
}
