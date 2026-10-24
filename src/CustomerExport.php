<?php
declare(strict_types=1);
require_once __DIR__ . '/RecordManager.php';

/**
 * Export one spreadsheet row per non-deleted record, not per customer.
 * Customers with no surviving records still get one row with their details.
 * Field IDs are strictly matched to metadata (not SQL column names).
 */
final class CustomerExport
{
    private PDO $pdo;
    private RecordManager $manager;

    public function __construct()
    {
        $this->pdo = db();
        $this->manager = new RecordManager($this->pdo,config()['DB_PREFIX']);
        $this->manager->migrate();
    }

    public function fields(): array
    {
        return $this->manager->fields(); // Non-deleted, including inactive and hidden fields.
    }

    public function selection(array $ids): array
    {
        if (!$ids || count($ids)>80) throw new DomainException('حداقل یک فیلد و حداکثر ۸۰ فیلد انتخاب کنید.');
        $selected=[];
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) {
                throw new DomainException('شناسه فیلد برای خروجی نامعتبر است.');
            }
            $selected[(int)$id]=true;
        }
        $ordered=array_values(array_filter($this->fields(),
            static fn(array $f): bool => isset($selected[(int)$f['id']])));
        if (count($ordered)!==count($selected)) throw new DomainException('فیلد ناشناس یا حذف‌شده انتخاب شده است.');
        return $ordered;
    }

    public function create(array $selectedIds): array
    {
        $fields=$this->selection($selectedIds);
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet=$book->getActiveSheet();
        $sheet->setTitle('اطلاعات مشتریان');
        $sheet->setRightToLeft(true);
        $sheet->freezePane('A2');
        foreach ($fields as $i=>$field) {
            $col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
            $sheet->setCellValueExplicit($col.'1',$field['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getColumnDimension($col)->setWidth(min(48,max(16,mb_strlen($field['label'])+5)));
        }
        $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEFB');
        $pfx=config()['DB_PREFIX'];
        $customers=table('customers');
        $records=table('apple_records');
        $values=chr(96).$pfx.'record_values'.chr(96);
        $deletions=table('record_deletions');
        $sql='SELECT c.id AS customer_id,c.full_name,c.mobile,c.created_at AS customer_created_at,
             r.id AS record_id,r.created_at AS record_created_at,r.apple_email
             FROM '.$customers.' c LEFT JOIN '.$records.' r ON r.customer_id=c.id
             AND NOT EXISTS (SELECT 1 FROM '.$deletions.' d WHERE d.record_id=r.id)
             ORDER BY c.id DESC,r.id DESC LIMIT 500 OFFSET ';
        $line=2; $offset=0; $maximum=15000;
        try {
            while (true) {
                $rows=$this->pdo->query($sql.$offset)->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;
                if ($offset+count($rows)>$maximum) {
                    throw new DomainException('تعداد ردیف‌های خروجی بیشتر از ۱۵هزار است؛ برای خروجی کامل از روش پردازش حجیم استفاده کنید.');
                }
                $recordIds=array_values(array_filter(array_column($rows,'record_id'),
                    static fn($id): bool => $id!==null));
                $eav=[];
                if ($recordIds) {
                    $marks=implode(',',array_fill(0,count($recordIds),'?'));
                    $query=$this->pdo->prepare('SELECT record_id,field_id,value_text,value_decimal,value_date,value_bool
                       FROM '.$values.' WHERE record_id IN ('.$marks.')');
                    $query->execute($recordIds);
                    while ($row=$query->fetch(PDO::FETCH_ASSOC)) {
                        $eav[(int)$row['record_id']][(int)$row['field_id']]=$row;
                    }
                }
                foreach ($rows as $customer) {
                    foreach ($fields as $index=>$field) {
                        $id=(int)$field['id'];
                        $key=$field['field_key'];
                        $type=$field['type'];
                        $raw=match($key) {
                            'created_at' => substr((string)($customer['record_created_at'] ?? $customer['customer_created_at']),0,10),
                            'customer_name' => $customer['full_name'],
                            'mobile' => $customer['mobile'],
                            'apple_email' => $customer['apple_email'],
                            default => null,
                        };
                        if ($customer['record_id']!==null &&
                            array_key_exists($id,$eav[(int)$customer['record_id']]??[])) {
                            $entry=$eav[(int)$customer['record_id']][$id];
                            $raw=match($type) {
                                'number','money' => $entry['value_decimal'],
                                'date' => $entry['value_date'],
                                'boolean' => $entry['value_bool'],
                                default => $entry['value_text'],
                            };
                        }
                        $value=$raw===null ? '' : (string)$raw;
                        if ($type==='date' && $value!=='') $value=RecordManager::dateLabel($value);
                        if ($type==='boolean' && $value!=='') $value=$value==='1' ? 'بله' : 'خیر';
                        $coordinate=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1).$line;
                        $safeNumber=in_array($type,['number','money'],true)
                            && preg_match('/^-?\d{1,15}(?:\.\d{1,4})?$/D',$value);
                        if ($safeNumber) {
                            $sheet->setCellValueExplicit($coordinate,(float)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0.####');
                        } else {
                            // Explicit string protects leading-zero phones, passwords,
                            // answers and formula-like untrusted customer input.
                            $sheet->setCellValueExplicit($coordinate,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        }
                    }
                    $line++;
                }
                $offset+=count($rows);
                if (count($rows)<500) break;
            }
            $sheet->setAutoFilter('A1:'.$last.max(1,$line-1));
            $path=tempnam(sys_get_temp_dir(),'aid_customer_xlsx_');
            if ($path===false) throw new RuntimeException('امکان ساخت فایل خروجی وجود ندارد.');
            try {
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
                return ['file'=>$path,'rows'=>$line-2];
            } catch (Throwable $error) {
                @unlink($path);throw $error;
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function render(): void
    {
        $fields=$this->fields();
        ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <p class="text-secondary mb-0">فیلدهای موردنظر را انتخاب کنید. هر رکورد ثبت‌شده مشتری یک سطر مستقل در اکسل خواهد داشت؛ تمام مشتریان خروجی گرفته می‌شوند.</p>
          <a href="/index.php?route=customers" class="btn btn-outline-secondary">بازگشت به مشتریان</a>
        </div>
        <form id="customer-export-form" method="post" action="/index.php?route=customers_export" class="card">
          <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
          <div class="card-header d-flex align-items-center gap-3 flex-wrap">
            <h3 class="card-title">انتخاب ستون‌های خروجی اکسل</h3>
            <button type="button" id="export-select-all" class="btn btn-sm btn-outline-primary">انتخاب همه</button>
            <button type="button" id="export-select-none" class="btn btn-sm btn-outline-secondary">حذف انتخاب‌ها</button>
          </div>
          <div class="card-body">
            <p class="text-secondary small">همه رکوردهای حذف‌نشده، حتی اگر متعلق به یک مشتری باشند، در خروجی قرار می‌گیرند. فیلدهایی که تیک ندارند در فایل نخواهند بود. فیلدهای شامل رمز عبور یا پاسخ امنیتی را فقط در صورت نیاز انتخاب کنید؛ فایل خروجی رمزگذاری نشده است.</p>
            <div class="row g-3">
            <?php foreach ($fields as $field): ?>
              <div class="col-sm-6 col-lg-4">
                <label class="form-check">
                  <input class="form-check-input export-field-check" type="checkbox" name="fields[]" value="<?=e((string)$field['id'])?>" <?=in_array($field['field_key'],['customer_name','mobile'],true)?'checked':''?>>
                  <span class="form-check-label"><?=e($field['label'])?><?php if (!(int)$field['active']): ?> <small class="text-secondary">(غیرفعال)</small><?php endif;?></span>
                </label>
              </div>
            <?php endforeach;?>
            </div>
          </div>
          <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3">
            <span class="text-secondary small" id="export-selected-count">۲ فیلد انتخاب شده است.</span>
            <button class="btn btn-success" id="export-submit" type="submit">تهیه خروجی اکسل</button>
          </div>
        </form>
        <?php
    }
}
