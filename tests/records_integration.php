<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/RecordSpreadsheet.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function forbidden(callable $operation, string $message): void {
    $caught=false;
    try { $operation(); } catch (DomainException $ex) { $caught=true; }
    check($caught,$message);
}

$manager = new RecordManager(db(), config()['DB_PREFIX']);
$manager->migrate();
$manager->assertSystemFields();
$fields=array_column($manager->fields(),null,'field_key');
check(isset($fields['created_at'],$fields['customer_name'],$fields['mobile']), 'mandatory system fields');
check($fields['customer_name']['type']==='text' && $fields['mobile']['type']==='mobile', 'system field types');
forbidden(fn()=> $manager->deleteField((int)$fields['mobile']['id']), 'system mobile deletion must be blocked');

$types=['text','textarea','number','money','date','select','boolean'];
$new=[];
foreach ($types as $ix=>$type) {
    $id=$manager->saveField([
        'label'=>'فیلد تست ' . $ix . ' ' . $type,
        'type'=>$type,'sort_order'=>10+$ix,'max_length'=>255,
        'required'=>1,'show_in_table'=>1,'filterable'=>1,'active'=>1,
        'options'=>$type==='select'?"عادی\nویژه":'',
    ]);
    $new[$type]=$manager->fieldById($id);
    check($new[$type]['type']===$type,'field type '.$type);
}
$values=[
    (int)$fields['customer_name']['id']=>'مشتری جدید',
    (int)$fields['mobile']['id']=>'۰۹۱۲۳۴۵۶۷۸۹',
    (int)$new['text']['id']=>'متن آزمایشی',
    (int)$new['textarea']['id']=>'یادداشت بلند',
    (int)$new['number']['id']=>'۱۲۳',
    (int)$new['money']['id']=>'15,600.50',
    (int)$new['date']['id']=>'۱۴۰۵/۰۸/۰۱',
    (int)$new['select']['id']=>'ویژه',
    (int)$new['boolean']['id']=>'1',
];
check(RecordManager::mobile('+989123456789')==='09123456789','+98 normalization');
check(RecordManager::mobile('00989123456789')==='09123456789','0098 normalization');
check(RecordManager::mobile('9123456789')===null,'unsafe/mobile missing leading zero rejected');
check(RecordManager::normalizeDate('۱۴۰۵/۰۸/۰۱')!==null,'Persian date accepted');
check(RecordManager::normalizeDate('۱۴۰۵/۱۳/۴۰')===null,'invalid Jalali date rejected');
$beforeCustomers=(int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn();
$rec1=$manager->saveRecord($values);
$values[(int)$fields['mobile']['id']]='+989123456789';
$values[(int)$fields['customer_name']['id']]='نام متفاوت برای همان مشتری';
$rec2=$manager->saveRecord($values);
check((int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn()===$beforeCustomers+1,'customer deduplication');
$customerName=db()->query('SELECT full_name FROM ' . table('customers') . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
check($customerName==='مشتری جدید','previous customer name preserved');
check($manager->record($rec2)['values'][(int)$fields['customer_name']['id']]==='نام متفاوت برای همان مشتری','per-record name preserved');

$invalid=$values;
$invalid[(int)$new['select']['id']]='غیرمجاز';
forbidden(fn()=> $manager->saveRecord($invalid), 'invalid select must fail');
unset($invalid[(int)$new['select']['id']]);
forbidden(fn()=> $manager->saveRecord($invalid), 'missing required field must fail');
$invalid=$values; $invalid[(int)$new['date']['id']]='۱۴۰۵/۱۳/۴۰';
forbidden(fn()=> $manager->saveRecord($invalid), 'invalid Jalali date must fail');
$invalid=$values; $invalid[(int)$fields['mobile']['id']]='00123';
forbidden(fn()=> $manager->saveRecord($invalid), 'invalid phone must fail');

$edit=$values; $edit[(int)$new['text']['id']]='نسخه ویرایش‌شده';
$manager->saveRecord($edit,$rec1);
check($manager->record($rec1)['values'][(int)$new['text']['id']]==='نسخه ویرایش‌شده','record edit');
$update=[
    'label'=>'فیلد تست تغییر نام','type'=>'text','sort_order'=>1,'max_length'=>255,
    'required'=>1,'show_in_table'=>1,'filterable'=>1,'active'=>1,
];
$manager->saveField($update,(int)$new['text']['id']);
check($manager->record($rec1)['values'][(int)$new['text']['id']]==='نسخه ویرایش‌شده','stable values after rename');
$update['type']='number';
forbidden(fn()=> $manager->saveField($update,(int)$new['text']['id']), 'cannot change type of populated field');
$update['type']='text';

for ($i=0;$i<29;$i++) {
    $row=$values;
    $row[(int)$new['number']['id']]=(string)($i+5);
    $row[(int)$new['boolean']['id']]=($i%2===0)?'1':'0';
    $manager->saveRecord($row);
}
$list=$manager->listing(['per_page'=>25]);
check($list['total']===31 && count($list['rows'])===25 && $list['pages']===2,'pagination 25');
check(count($manager->listing(['per_page'=>50])['rows'])===31,'pagination 50');
check(count($manager->listing(['per_page'=>100])['rows'])===31,'pagination 100');
$sort=$manager->listing(['sort'=>$new['number']['id'],'direction'=>'asc','per_page'=>50]);
check((float)$sort['rows'][0]['values'][(int)$new['number']['id']]===5.0,'numeric sort');
$filtered=$manager->listing([
    'filter_min'=>[$new['number']['id']=>'10'],
    'filter_max'=>[$new['number']['id']=>'20'],
    'filter'=>[$new['boolean']['id']=>'1'],
    'per_page'=>25,
]);
check($filtered['total']>0 && $filtered['total']<31, 'combined typed filters');
$filtered=$manager->listing(['filter'=>[$new['text']['id']=>"' OR 1=1 --"]]);
check($filtered['total']===0,'SQL injection query remains parameterized');

$excel=new RecordSpreadsheet($manager);
$file=$excel->exportAll();
try {
    check(is_file($file) && filesize($file)>2000,'real XLSX archive generated');
    $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $book=$reader->load($file);
    $sheet=$book->getActiveSheet();
    check($sheet->getRightToLeft(),'Excel sheet RTL');
    check($sheet->getCell('A1')->getValue()==='تاریخ ثبت','row 1 headers ordered');
    check($sheet->getHighestDataRow()===32,'export all records irrespective of filters');
    $book->disconnectWorksheets();
} finally { @unlink($file); }

$importBook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $importBook->getActiveSheet();
$active=$manager->activeFields();
foreach ($active as $idx=>$field) {
    $sheet->setCellValueExplicit([$idx+1,1],$field['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    for ($r=2;$r<=3;$r++) {
        $value=match ($field['field_key']) {
            'created_at'=>'۱۴۰۵/۰۸/۰۱',
            'customer_name'=>'مشتری اکسل',
            'mobile'=> $r===2?'۰۹۹۹۱۱۱۲۲۳۳':'+989991112233',
            'apple_email'=>'test@example.com',
            default=> match ($field['type']) {
                'text'=>'متن اکسل', 'textarea'=>'یادداشت اکسل',
                'number'=>'123', 'money'=>'25000.75','date'=>'۱۴۰۵/۰۸/۰۱',
                'select'=>'عادی','boolean'=>'بله',
                'mobile'=>'09991112233', default=>'',
            }
        };
        $sheet->setCellValueExplicit([$idx+1,$r],$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
}
$temp=tempnam(sys_get_temp_dir(),'aid-import-ci-');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($importBook))->save($temp);
$importBook->disconnectWorksheets();
try {
    $check=$excel->inspect($temp);
    check($check['errors']===[] && count($check['rows'])===2,'two-row preview valid');
    check(count($check['warnings'])>=1,'duplicate mobile warning');
    $count=$excel->commit($temp);
    check($count===2,'atomic Excel import');
    check((int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn()===$beforeCustomers+2,
        'import creates only one new customer for equivalent mobile formats');

    // Strict headers, data-type checks, and atomic rejection of invalid files.
    $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $badBook=$reader->load($temp);
    $badSheet=$badBook->getActiveSheet();
    $originalHeader=(string)$badSheet->getCell('A1')->getValue();
    $badSheet->setCellValueExplicit('A1','عنوان جابه‌جا شده',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($badBook))->save($temp);
    forbidden(fn()=> $excel->inspect($temp),'invalid XLSX header rejected');
    $badSheet->setCellValueExplicit('A1',$originalHeader,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    // Mobile is the third column of the system-defined default ordering.
    $badSheet->setCellValueExplicit('C2','شماره نامعتبر',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($badBook))->save($temp);
    $invalidPreview=$excel->inspect($temp);
    check(!empty($invalidPreview['errors']),'invalid Excel cell errors include its row');
    check(str_contains(implode(' ',$invalidPreview['errors']),'ردیف 2'),'invalid row number reported');
    $countBefore=(int)db()->query('SELECT COUNT(*) FROM ' . table('apple_records'))->fetchColumn();
    forbidden(fn()=> $excel->commit($temp),'bad XLSX cannot be imported');
    check((int)db()->query('SELECT COUNT(*) FROM ' . table('apple_records'))->fetchColumn()===$countBefore,
        'bad import did not write partial records');
    $badSheet->setCellValueExplicit('C2','09123456789',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $badSheet->setCellValue('C2','=SUM(1,2)');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($badBook))->save($temp);
    $formulaPreview=$excel->inspect($temp);
    check(!empty($formulaPreview['errors']),'formula cells rejected');
    $badBook->disconnectWorksheets();
} finally { @unlink($temp); }

$manager->deleteRecord($rec1);
check($manager->record($rec1)===null,'soft deleted record cannot be loaded');
check($manager->listing([])['total']===32,'soft deleted record excluded from queries');
check((int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn()===$beforeCustomers+2,
    'soft delete leaves customers intact');
$manager->deleteField((int)$new['text']['id']);
check($manager->fieldById((int)$new['text']['id'])===null,'soft deleted field hidden');
$q=db()->prepare('SELECT COUNT(*) FROM ' . $manager->t('record_values') . ' WHERE field_id=?');
$q->execute([(int)$new['text']['id']]);
check((int)$q->fetchColumn()>0,'old field values not deleted');
echo "[records] field types, validation, stable IDs, customers, transactions, typed filters, pagination, XLSX import/export and soft delete: PASS\n";
