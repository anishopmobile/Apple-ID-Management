<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/RecordManager.php';
require dirname(__DIR__) . '/src/RecordSpreadsheet.php';
require dirname(__DIR__) . '/src/CustomerDirectory.php';
require dirname(__DIR__) . '/src/CustomerExport.php';
require dirname(__DIR__) . '/src/SiteSettings.php';
require dirname(__DIR__) . '/src/AppleIdCardPdf.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function cal_check(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException('[calendar setting] '.$message);
}
function cal_reject(callable $cb,string $message):void {
    try {$cb();} catch(DomainException $ex) {return;}
    throw new RuntimeException('[calendar setting] input incorrectly accepted: '.$message);
}
$service=new SiteSettings();
$manager=new RecordManager(db(),config()['DB_PREFIX']);
$manager->migrate();
$before=setting('date_display_mode','jalali');
$beforeExceptions=date_jalali_exempt_ids();
$clean=static function()use($service,$before,$beforeExceptions):void {
    $service->updateDatePreferences(
        in_array($before,['jalali','gregorian'],true)?$before:'jalali',
        array_map('strval',$beforeExceptions)
    );
};
$sample='2026-10-24';
$expectedJalali='1405/08/02';
$expectedGregorian='2026/10/24';
$customers=new CustomerDirectory();
$excel=new RecordSpreadsheet($manager);
$export=new CustomerExport();
$card=new AppleIdCardPdf($manager);
$fields=$manager->fields();
$dateField=array_values(array_filter($fields,static fn($f)=>$f['type']==='date' && $f['field_key']!=='created_at'));
cal_check(count($dateField)>=1,'A real dynamic date field is configured');

// Pick an existing record with a real birthday/date; test fixtures created
// previously are stored in the temporary MySQL database only.
$q=db()->prepare('SELECT v.record_id FROM '.table('record_values').' v
 JOIN '.table('apple_records').' r ON r.id=v.record_id
 LEFT JOIN '.table('record_deletions').' tomb ON tomb.record_id=r.id
 WHERE v.value_date=? AND tomb.record_id IS NULL LIMIT 1');
$q->execute([$sample]);
$recordId=$q->fetchColumn();
cal_check($recordId!==false,'fixture with Gregorian ISO date in MySQL');
$recordId=(int)$recordId;
$createdField = array_values(array_filter($fields,static fn($f)=>$f['field_key']==='created_at'))[0]??null;
cal_check($createdField!==null,'system creation date exists');
$createdId=(int)$createdField['id'];
$matchingDates=db()->prepare('SELECT v.field_id FROM '.table('record_values').' v
 JOIN '.table('record_fields').' f ON f.id=v.field_id
 WHERE v.record_id=? AND v.value_date=? AND f.type=\'date\' AND f.deleted_at IS NULL LIMIT 1');
$matchingDates->execute([$recordId,$sample]);
$exemptId=(int)$matchingDates->fetchColumn();
cal_check($exemptId>0,'actual date value stored under an active field ID');
$exemptField=array_values(array_filter($fields,static fn($f)=>(int)$f['id']===$exemptId))[0]??null;
cal_check($exemptField!==null,'date field metadata found');


// Ensure both XLSX exporters include the stored date under either calendar.
$validateSheet=static function(string $file,string $expected)use($sample):void {
    $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $book=$reader->load($file);
    try {
        $sheet=$book->getActiveSheet();
        $found=false;
        $end=$sheet->getHighestDataRow();
        $cols=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($r=2;$r<=$end && !$found;$r++) for($c=1;$c<=$cols;$c++) {
            if ($sheet->getCell([$c,$r])->getFormattedValue()===$expected) {$found=true;break;}
        }
        cal_check($found,'actual XLSX date cells contain '.$expected);
    } finally { $book->disconnectWorksheets(); }
};

try {
    cal_reject(fn()=> $service->updateDateMode('invalid'), 'bad date mode');
    cal_reject(fn()=> $service->updateDateMode('gregorian;DROP TABLE settings'), 'SQL injection-like mode');
    $service->updateDateMode('jalali');
    cal_check(setting('date_display_mode')==='jalali','mode persisted');
    cal_check(date_display_mode()==='jalali','current request cache updated');
    cal_check(RecordManager::dateLabel($sample)===$expectedJalali,'date field Jalali');
    cal_check(display_date($sample)===$expectedJalali,'shared date helper Jalali');
    cal_check(str_contains(RecordManager::digits(jdatetime('2026-10-24 12:00:00','Y/m/d H:i')),$expectedJalali),
        'dashboard timestamp uses Jalali');
    $customerPage=$customers->listing(['per_page'=>25]);
    cal_check(str_contains(RecordManager::digits((string)$customerPage['rows'][0]['created_display']),'14'),
        'customer list date uses Jalali');

    foreach (['jalali'=>$expectedJalali,'gregorian'=>$expectedGregorian] as $mode=>$expected) {
        $service->updateDateMode($mode);
        cal_check(date_display_mode()===$mode,'cached calendar synchronizes after settings update');
        cal_check(setting('date_display_mode')===$mode,'calendar persisted in settings');
        cal_check(RecordManager::dateLabel($sample)===$expected,'record manager display '.$mode);
        cal_check(RecordManager::digits(jdatetime($sample.' 12:00:00','Y/m/d'))===$expected,
            'customer/dashboard timestamp display '.$mode);
        cal_check(RecordManager::normalizeDate('۱۴۰۵/۰۸/۰۲')===$sample &&
            RecordManager::normalizeDate('2026/10/24')===$sample,
            'changing display does not change data validation or ISO storage');

        $selection=array_map(static fn($f)=>(int)$f['id'],
            array_filter($export->fields(),static fn($f)=>$f['type']==='date'));
        cal_check(count($selection)>0,'customer Excel exporter offers date columns');
        $customerFile=$export->create($selection);
        try {$validateSheet($customerFile['file'],$expected);}
        finally {@unlink($customerFile['file']);}

        $recordFile=$excel->exportAll();
        try {$validateSheet($recordFile,$expected);}
        finally {@unlink($recordFile);}

        $cardData=$card->dataForRecord($recordId);
        // The record whose date we selected may have a different date field
        // from the card birthday field; verify birthday when it is configured.
        if ($cardData['birthday']!=='') {
            cal_check(str_contains($cardData['birthday'],substr($expected,0,4)),
                'card PDF birthday uses '.$mode.' calendar');
        }
    }

    // Gregorian global mode with a date-specific Jalali override.
    $options=$service->dateFields();
    cal_check(count($options)>=2,'settings shows system date and custom date fields');
    cal_check(count(array_filter($options,static fn($f)=>$f['field_key']==='created_at'))===1,
        'system created_at offered as permanently protected option');
    cal_reject(fn()=> $service->updateDatePreferences('gregorian',['9999999999']),
        'unknown date field identifier is rejected');
    cal_reject(fn()=> $service->updateDatePreferences('gregorian',[strval((int)$fields[1]['id'])]),
        'non-date field cannot be exempt');
    cal_reject(fn()=> $service->updateDatePreferences('gregorian',['1 OR 1=1']),
        'field identifier injection is rejected');
    cal_check(setting('date_jalali_exempt_ids','[]')==='[]',
        'invalid exception submit leaves previously saved setting intact');
    $service->updateDatePreferences('gregorian',[strval($exemptId)]);
    cal_check(date_display_mode()==='gregorian','global Gregorian setting maintained');
    cal_check(date_jalali_exempt_ids()===[$exemptId],'valid date ID selected');
    cal_check(RecordManager::dateLabel($sample,$exemptId)===$expectedJalali,
        'Jalali override for individual date field');
    cal_check(RecordManager::dateLabel($sample,$createdId,'created_at')===$expectedJalali,
        'system record creation date always Jalali');
    cal_check(display_date($sample)===$expectedGregorian,
        'unexempted date uses Gregorian');
    cal_check(RecordManager::digits(jdatetime($sample.' 12:00:00','Y/m/d',true))===$expectedJalali,
        'system customer and dashboard registration date always Jalali');

    $fieldOnly=$export->create([$createdId,$exemptId]);
    try {
        $read=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $book=$read->load($fieldOnly['file']);
        $sheet=$book->getActiveSheet();
        cal_check((string)$sheet->getCell('A1')->getValue()===$createdField['label'],
            'Excel date creation field column preserved');
        cal_check((string)$sheet->getCell('B1')->getValue()===$exemptField['label'],
            'Excel exception date field column preserved');
        $found=false;
        for($row=2;$row<=$sheet->getHighestDataRow();$row++){
            if((string)$sheet->getCell('B'.$row)->getValue()===$expectedJalali){
                $found=true;break;
            }
        }
        cal_check($found,'customer XLSX exports exempted date as Jalali');
        $book->disconnectWorksheets();
    } finally {@unlink($fieldOnly['file']);}
    $allXlsx=$excel->exportAll();
    try {
        $read=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $book=$read->load($allXlsx);
        $sheet=$book->getActiveSheet();
        $col=null;
        for($n=1;$n<=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());$n++){
            if((string)$sheet->getCell([$n,1])->getValue()===$exemptField['label']){
                $col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);break;
            }
        }
        cal_check($col!==null,'record XLSX includes date exception field');
        $found=false;
        for($row=2;$row<=$sheet->getHighestDataRow();$row++){
            if((string)$sheet->getCell($col.$row)->getValue()===$expectedJalali){$found=true;break;}
        }
        cal_check($found,'record XLSX exports exempted date as Jalali');
        $book->disconnectWorksheets();
    } finally {@unlink($allXlsx);}

    // Removing the exception restores the Gregorian rendering without
    // altering the canonical MySQL DATE values for that record.
    $service->updateDatePreferences('gregorian',[]);
    cal_check(RecordManager::dateLabel($sample,$exemptId)===$expectedGregorian,
        'unchecking restores global calendar');
    cal_check(RecordManager::dateLabel($sample,$createdId,'created_at')===$expectedJalali,
        'created_at remains Jalali even when no exceptions are checked');
    cal_check($manager->record($recordId)['values'][$exemptId]===$sample,
        'changing exceptions does not mutate stored ISO date');

    $service->updateDateMode('gregorian');
    cal_check(display_date('2024-02-29')==='2024/02/29','Gregorian leap day');
    cal_check(display_date('2026-13-01')==='','invalid Gregorian stored date not displayed');
    cal_check($manager->record($recordId)!==null,'calendar toggles do not alter original records');
    cal_check(db()->query('SELECT COUNT(*) FROM '.table('apple_records'))->fetchColumn()>0,
        'calendar setting change preserves record data');
    echo "[calendar setting] persistence, validation, database invariance, table dates, both real XLSX exports and PDF records: PASS\n";
} finally {$clean();}
