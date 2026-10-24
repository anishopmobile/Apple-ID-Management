<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/AppleIdCardPdf.php';

function card_pdf_assert(bool $check,string $why): void {
    if (!$check) throw new RuntimeException('[card PDF] '.$why);
}

$manager=new RecordManager(db(),config()['DB_PREFIX']);
$manager->migrate();
$names=[
  'question1'=>'سوال امنیتی 1','answer1'=>'پاسخ سوال امنیتی 1',
  'question2'=>'سوال امنیتی 2','answer2'=>'پاسخ سوال امنیتی 2',
  'question3'=>'سوال امنیتی 3','answer3'=>'پاسخ سوال امنیتی3',
  'password'=>'پسورد','birthday'=>'تاریخ تولد',
  'first_name'=>'نام کوچک','last_name'=>'نام خانوادگی',
];
$ids=[];$existing=$manager->fields();
foreach ($names as $key=>$label) {
    $matching=array_values(array_filter($existing,static fn($f)=>$f['label']===$label));
    $field=$matching[0]??false;
    if ($field===false) {
        $fieldId=$manager->saveField([
            'label'=>$label,'type'=>$key==='birthday'?'date':'text',
            'required'=>0,'active'=>1,'filterable'=>0,'show_in_table'=>0,
            'sort_order'=>550+count($ids),'max_length'=>255,
        ]);
    } else $fieldId=(int)$field['id'];
    $ids[$key]=$fieldId;
}
$metadata=array_column($manager->fields(),null,'field_key');
$customer=db()->query('SELECT id FROM '.table('customers').' ORDER BY id LIMIT 1')->fetchColumn();
card_pdf_assert($customer!==false,'fixture customers present');
$insert=db()->prepare('INSERT INTO '.table('apple_records').'(customer_id,apple_email) VALUES (?,?)');
$insert->execute([$customer,'stale-first@example.test']);$id1=(int)db()->lastInsertId();
$insert->execute([$customer,'second-card@example.test']);$id2=(int)db()->lastInsertId();
$val=db()->prepare('INSERT INTO '.table('record_values').'(record_id,field_id,value_text,value_date) VALUES (?,?,?,?)');
$values=[
    'question1'=>'In what city were you born?',
    'answer1'=>'Tehran-ANSWER-01',
    'question2'=>'What was the name of your first pet?',
    'answer2'=>'Parrot-ANSWER-02',
    'question3'=>'Who was your favorite childhood teacher?',
    'answer3'=>'Ali-ANSWER-03',
    'password'=>'FIRST-SECRET-2026',
    'birthday'=>'2026-10-24',
    'first_name'=>'آرمان',
    'last_name'=>'محمودی',
];
foreach ($values as $key=>$value) {
    $isDate=$key==='birthday';
    $val->execute([$id1,$ids[$key],$isDate?null:$value,$isDate?$value:null]);
}
$val->execute([$id1,(int)$metadata['apple_email']['id'],'card-real@example.test',null]);
$val->execute([$id1,(int)$metadata['customer_name']['id'],'آرمان محمودی',null]);
$val->execute([$id1,(int)$metadata['mobile']['id'],'09123456789',null]);
$val->execute([$id2,$ids['password'],'SECOND-SECRET-2026',null]);

$builder=new AppleIdCardPdf($manager);
$loaded=$builder->dataForRecord($id1);
card_pdf_assert($loaded['apple_id']==='card-real@example.test','uses record value, not stale Apple ID');
card_pdf_assert($loaded['first_name']==='آرمان' && $loaded['last_name']==='محمودی','Persian names decoded');
card_pdf_assert($loaded['question3']==='Who was your favorite childhood teacher?','third question field mapping');
card_pdf_assert($loaded['answer3']==='Ali-ANSWER-03','third answer field mapping');
card_pdf_assert($loaded['password']==='FIRST-SECRET-2026','password field mapping');
card_pdf_assert($loaded['birthday']==='1405/08/02','birthday converted to Jalali label');
card_pdf_assert($builder->dataForRecord($id2)['password']==='SECOND-SECRET-2026',
    'different record uses different password');

$masked=$builder->generate($id1,false);
$full=$builder->generate($id1,true);
$second=$builder->generate($id2,true);
foreach ([$masked,$full,$second] as $pdf) {
    card_pdf_assert(str_starts_with($pdf,'%PDF-') && strlen($pdf)>3000,'valid PDF bytes');
}
card_pdf_assert($masked!==$full && $full!==$second,'masked/full/two-record files are distinct');
$folder=sys_get_temp_dir().'/aid-card-pdf-ci-'.bin2hex(random_bytes(6));
mkdir($folder,0700);
try {
    $files=['masked'=>$masked,'full'=>$full,'second'=>$second];
    foreach ($files as $kind=>$pdf) file_put_contents($folder.'/'.$kind.'.pdf',$pdf);
    foreach ($files as $kind=>$pdf) {
        // PDF page-dictionary sizes are expressed in points: 85/55mm.
        $info=[];$code=0;
        exec('pdfinfo -box '.escapeshellarg($folder.'/'.$kind.'.pdf').' 2>&1',$info,$code);
        card_pdf_assert($code===0,'poppler can open '.$kind.' PDF');
        $text=implode("\n",$info);
        card_pdf_assert(preg_match('/^Pages:\s+1\s*$/m',$text)===1,
            $kind.' exactly one card page');
        card_pdf_assert(preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/m',$text,$m)===1,
            $kind.' readable page size');
        card_pdf_assert(abs((float)$m[1]-85*72/25.4)<0.2 && abs((float)$m[2]-55*72/25.4)<0.2,
            $kind.' exactly 85x55mm ±0.1mm, got '.(string)$m[1].'x'.(string)$m[2].' pt; '.preg_replace('/\\s+/',' ', $text));
        $extracted=$folder.'/'.$kind.'.txt';
        exec('pdftotext -enc UTF-8 '.escapeshellarg($folder.'/'.$kind.'.pdf').' '.escapeshellarg($extracted),$unused,$code);
        card_pdf_assert($code===0 && is_file($extracted),'text extractable from '.$kind);
        $plain=file_get_contents($extracted);
        card_pdf_assert(is_string($plain),'pdf text read');
        if ($kind==='masked') {
            card_pdf_assert(!str_contains($plain,'FIRST-SECRET-2026')
                && !str_contains($plain,'Tehran-ANSWER-01') && !str_contains($plain,'Parrot-ANSWER-02'),
                'masked PDF does not reveal password or security answers');
        } elseif ($kind==='full') {
            card_pdf_assert(str_contains($plain,'FIRST-SECRET-2026')
                && str_contains($plain,'Tehran-ANSWER-01')
                && str_contains($plain,'card-real@example.test'),
                'full PDF contains correct selected-record values');
            card_pdf_assert(!str_contains($plain,'SECOND-SECRET-2026'),
                'first PDF does not contain second record secret');
        } else {
            card_pdf_assert(str_contains($plain,'SECOND-SECRET-2026')
                && !str_contains($plain,'FIRST-SECRET-2026'),
                'second record PDF is independent');
        }
    }
    $fonts=[];$code=0;
    exec('pdffonts '.escapeshellarg($folder.'/full.pdf').' 2>&1',$fonts,$code);
    card_pdf_assert($code===0 && str_contains(implode("\n",$fonts),'yes'),
        'PDF contains embedded fonts for Persian/English text');
    // Render at approximately 300 DPI to confirm readers can rasterize it
    // at physical print dimensions (about 1004x650 pixels).
    $pixels=[];$code=0;
    exec('pdftoppm -f 1 -l 1 -r 300 -singlefile -png '.
        escapeshellarg($folder.'/masked.pdf').' '.escapeshellarg($folder.'/rendered').' 2>&1',$pixels,$code);
    card_pdf_assert($code===0 && is_file($folder.'/rendered.png'),'card render succeeds at 300 DPI');
    $image=getimagesize($folder.'/rendered.png');
    card_pdf_assert(is_array($image) && abs($image[0]-1004)<=2 && abs($image[1]-650)<=2,
        '300dpi raster matches real 85x55mm print geometry');
    // Store only the synthetic, password-masked raster for visual QA in CI.
    // It is not placed under public/ and is not part of the deployment package.
    if (getenv('CARD_PDF_VISUAL_PREVIEW') === '1') {
        card_pdf_assert(copy($folder.'/rendered.png',STORAGE_PATH.'/card-preview-ci.png'),
            'copy synthetic masked card preview for visual QA');
    }
} finally {
    foreach (glob($folder.'/*')?:[] as $file) @unlink($file);
    @rmdir($folder);
}

$manager->deleteRecord($id1);
try {
    $builder->generate($id1,true);
    throw new RuntimeException('deleted record should not render');
} catch (DomainException $expected) {}
echo "[card PDF] real record mapping, masked/full modes, distinct records, exact 85x55mm page, embedded fonts, 300dpi render: PASS\n";
