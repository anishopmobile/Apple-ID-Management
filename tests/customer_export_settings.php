<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/CustomerExport.php';
require dirname(__DIR__) . '/src/SiteSettings.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function ce_check(bool $truth,string $message):void {
    if(!$truth)throw new RuntimeException('[customer-export/settings] '.$message);
}
function ce_reject(callable $cb,string $message):void {
    try{$cb();}catch(DomainException $ignored){return;}
    throw new RuntimeException('[customer-export/settings] unexpected success: '.$message);
}
$export=new CustomerExport();
$fields=array_column($export->fields(),null,'field_key');
ce_check(isset($fields['customer_name'],$fields['mobile'],$fields['apple_email']),'required export options');
$selected=[$fields['customer_name']['id'],$fields['mobile']['id'],$fields['apple_email']['id']];
ce_reject(fn()=> $export->selection([99999999]),'unknown field must be rejected');
ce_reject(fn()=> $export->selection([]),'empty field selection rejected');
ce_reject(fn()=> $export->selection(['1) DROP TABLE test;']),'injectable field ID rejected');
$result=$export->create($selected);
$file=$result['file'];
try {
    ce_check(is_file($file) && filesize($file)>1000,'created actual XLSX');
    $book=(new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($file);
    $sheet=$book->getActiveSheet();
    ce_check($sheet->getRightToLeft(),'Excel RTL');
    ce_check($sheet->getHighestDataColumn()==='C','only explicitly checked fields in XLSX');
    ce_check($sheet->getCell('A1')->getValue()===$fields['customer_name']['label'],'field heading from metadata');
    ce_check($sheet->getCell('B1')->getValue()===$fields['mobile']['label'],'second selected field correct');
    ce_check($sheet->getCell('C1')->getValue()===$fields['apple_email']['label'],'third field correct');
    $phones=[];$multiple=0;
    for($row=2;$row<=$sheet->getHighestDataRow();$row++) {
        $phone=(string)$sheet->getCell('B'.$row)->getValue();
        if($phone!=='')$phones[$phone]=($phones[$phone]??0)+1;
        // Mobiles must retain their leading zero as text, not floating point.
        ce_check($sheet->getCell('B'.$row)->getDataType()===\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,'mobile is text');
    }
    ce_check(($phones['09123456789']??0)>=2,
        'every record for same customer exported as separate row');
    ce_check($sheet->getHighestDataRow()===$result['rows']+1,
        'exported row count consistent with data');
    $book->disconnectWorksheets();
} finally { @unlink($file); }

// Customer name and mobile only: omitted Apple ID must not leak in any cell.
$reduced=$export->create([$fields['mobile']['id'],$fields['customer_name']['id']]);
try {
    $book=(new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($reduced['file']);
    ce_check($book->getActiveSheet()->getHighestDataColumn()==='B',
        'unchecked Apple ID field not included');
    $book->disconnectWorksheets();
} finally { @unlink($reduced['file']); }

$settings=new SiteSettings();
$oldTitle=setting('site_name','مدیریت Apple ID');
$settings->updateTitle('فروشگاه آزمایشی');
ce_check(setting('site_name')==='فروشگاه آزمایشی','save site title');
ce_reject(fn()=> $settings->updateTitle('<'.chr(0).'script>'),'reject control chars');
$settings->updateTitle($oldTitle);
$admin=db()->query('SELECT id,password_hash FROM '.table('admins').' ORDER BY id LIMIT 1')->fetch();
ce_check(password_verify('Example-CI-Pass-1234',$admin['password_hash']),'existing password works');
ce_reject(fn()=> $settings->updatePassword([
    'current_password'=>'WRONG','new_password'=>'New-Example-Pass-2026',
    'new_password_confirm'=>'New-Example-Pass-2026'
],(int)$admin['id']),'reject wrong current password');
ce_reject(fn()=> $settings->updatePassword([
    'current_password'=>'Example-CI-Pass-1234','new_password'=>'short',
    'new_password_confirm'=>'short'
],(int)$admin['id']),'reject weak password');
$settings->updatePassword([
    'current_password'=>'Example-CI-Pass-1234','new_password'=>'New-Example-Pass-2026',
    'new_password_confirm'=>'New-Example-Pass-2026'
],(int)$admin['id']);
$changed=db()->query('SELECT password_hash FROM '.table('admins').' ORDER BY id LIMIT 1')->fetchColumn();
ce_check(password_verify('New-Example-Pass-2026',$changed),'new password valid');
ce_check(!password_verify('Example-CI-Pass-1234',$changed),'old password invalidated');
$settings->updatePassword([
    'current_password'=>'New-Example-Pass-2026','new_password'=>'Example-CI-Pass-1234',
    'new_password_confirm'=>'Example-CI-Pass-1234'
],(int)$admin['id']);
$reverted=db()->query('SELECT password_hash FROM '.table('admins').' ORDER BY id LIMIT 1')->fetchColumn();
ce_check(password_verify('Example-CI-Pass-1234',$reverted),'restore original CI admin password');
echo "[customer-export/settings] multi-record XLSX, omitted columns, title, password rotation: PASS\n";
