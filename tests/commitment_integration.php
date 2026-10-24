<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/common.php';
require dirname(__DIR__).'/src/SiteSettings.php';
require dirname(__DIR__).'/src/CommitmentPdf.php';

function commit_check(bool $test,string $message):void {
    if(!$test)throw new RuntimeException('[commitment] '.$message);
}
function commit_reject(callable $call,string $message):void {
    try {$call();} catch(DomainException $ignored) {return;}
    throw new RuntimeException('[commitment] accepted unsafe data: '.$message);
}

$settings=new SiteSettings();
$oldHtml=setting('commitment_html','');
$oldMode=setting('commitment_editor_mode','simple');
$oldLogo=setting('site_logo','');
$oldDates=setting('date_jalali_exempt_ids','[]');
$record=db()->query('SELECT r.id FROM '.table('apple_records').' r
    LEFT JOIN '.table('record_deletions').' d ON d.record_id=r.id
    WHERE d.record_id IS NULL ORDER BY r.id DESC LIMIT 1')->fetchColumn();
commit_check((int)$record>0,'existing nondeleted record fixture');

try {
    $fieldOptions=$settings->dateFields();
    $system=array_values(array_filter($fieldOptions,fn($f)=>$f['field_key']==='created_at'));
    $custom=array_values(array_filter($fieldOptions,fn($f)=>$f['field_key']!=='created_at'));
    commit_check(count($system)===1 && count($custom)>0,'date setting renders both system/custom dates');
    $customId=(string)$custom[0]['id'];
    $settings->updateDatePreferences('gregorian',[$customId]);
    commit_check(date_jalali_exempt_ids()===[(int)$customId],'one date exception persisted');
    $settings->updateDatePreferences('gregorian',[]);
    commit_check(date_jalali_exempt_ids()===[],'cleared date selection persists as empty');
    commit_check(setting('date_jalali_exempt_ids')==='[]','clear selection persisted in MySQL not just view cache');
    $settings->updateDatePreferences('jalali',[$customId]);
    commit_check(date_jalali_exempt_ids()===[(int)$customId],'single selection restored');

    commit_reject(fn()=> $settings->updateCommitment('<p>x</p>','expert'),'unknown editor mode');
    commit_reject(fn()=> $settings->updateCommitment(str_repeat('X',48001),'simple'),'oversized input');
    $settings->updateCommitment('<h2>عنوان تعهدنامه آزمایشی</h2>'
        .'<p style="text-align:center;color:red">متعهد می‌شوم رمز و حساب خصوصی خود را نگهداری کنم.</p>'
        .'<ul><li>بند اول</li><li><strong>بند دوم</strong></li></ul>'
        .'<script>window.EVIL_MARKER=1;</script>'
        .'<img src="javascript:alert(1)" onerror="alert(1)">'
        .'<a href="javascript:alert(1)">پیوند ممنوع</a>',
        'advanced');
    $sanitized=setting('commitment_html');
    commit_check(!str_contains($sanitized,'script') && !str_contains($sanitized,'onerror')
        && !str_contains($sanitized,'javascript:') && !str_contains($sanitized,'<img'),
        'saved rich text strips executable markup and external media');
    commit_check(str_contains($sanitized,'<h2>') && str_contains($sanitized,'<strong>'),
        'safe headings and bold are retained');
    commit_check(setting('commitment_editor_mode')==='advanced','editor mode persisted');
    // Brand image must come from the private application setting, not a
    // public/temporary user-controlled URL.
    $brand=STORAGE_PATH.'/branding';
    if(!is_dir($brand)) mkdir($brand,0700,true);
    $newLogo='logo-'.bin2hex(random_bytes(16)).'.png';
    $png=imagecreatetruecolor(96,60);
    imagefilledrectangle($png,0,0,95,59,imagecolorallocate($png,13,64,137));
    imagepng($png,$brand.'/'.$newLogo);
    imagedestroy($png);
    db()->prepare('INSERT INTO '.table('settings').'(name,value) VALUES (?,?)
      ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['site_logo',$newLogo]);
    $pdf=(new CommitmentPdf())->generate((int)$record,false);
    commit_check(str_starts_with($pdf,'%PDF-')&&strlen($pdf)>10000,'A4 PDF is real');
    $folder=sys_get_temp_dir().'/commitment-'.bin2hex(random_bytes(6));
    mkdir($folder,0700);
    try {
        $path=$folder.'/certificate.pdf';file_put_contents($path,$pdf);
        $info=[];$rc=0;
        exec('pdfinfo -box '.escapeshellarg($path).' 2>&1',$info,$rc);
        $info=implode("\n",$info);
        commit_check($rc===0,'PDF is readable by Poppler');
        commit_check(preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/m',$info,$sizes)===1,
            'PDF page size is inspectable');
        commit_check(abs((float)$sizes[1]-210*72/25.4)<.3
            && abs((float)$sizes[2]-297*72/25.4)<.3,'actual A4 portrait MediaBox');
        $extracted=$folder.'/text.txt';
        exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' '.escapeshellarg($extracted).' 2>&1',$out,$rc);
        commit_check($rc===0 && is_file($extracted),'text extraction from A4 PDF');
        $text=file_get_contents($extracted);
        commit_check(str_contains($text,'ACCOUNT CARD'),
            'imported original card text must be extractable; extracted: '.mb_substr($text,0,700));
        // mPDF stores contextual Arabic presentation forms for Persian glyphs.
        // NFKC normalization returns their semantic Unicode letters for search.
        $readable=\Normalizer::normalize($text,\Normalizer::FORM_KC);
        commit_check(is_string($readable) && str_contains($readable,'تعهدنامه'),
            'Persian agreement remains text-extractable after NFKC normalization');
        commit_check(!str_contains($text,'EVIL_MARKER'),'malicious editor text is never printed');
        $images=[];$code=0;
        exec('pdfimages -list '.escapeshellarg($path).' 2>&1',$images,$code);
        commit_check($code===0 && count($images)>2,'private site logo embedded into A4 PDF');
        $fonts=[];exec('pdffonts '.escapeshellarg($path),$fonts,$rc);
        commit_check($rc===0 && str_contains(implode("\n",$fonts),'yes'),
            'A4 PDF embeds fonts');
        $render=$folder.'/render';
        exec('pdftoppm -f 1 -l 1 -r 110 -singlefile -png '.
            escapeshellarg($path).' '.escapeshellarg($render).' 2>&1',$out,$rc);
        commit_check($rc===0 && is_file($render.'.png'),'A4 PDF rasterizes correctly');
        if(getenv('CARD_PDF_VISUAL_PREVIEW')==='1') {
            commit_check(copy($render.'.png',STORAGE_PATH.'/commitment-preview-ci.png'),
                'save synthetic commitment preview for visual QA');
        }
    } finally {
        foreach(glob($folder.'/*')?:[] as $file) @unlink($file);
        @rmdir($folder);
    }
    commit_check($pdf !== (new AppleIdCardPdf())->generate((int)$record,false),
        'commitment PDF format differs from card-sized PDF');
    $settings->updateCommitment('','simple');
    commit_reject(fn()=> (new CommitmentPdf())->generate((int)$record,false),
        'an unconfigured legal text cannot silently generate a fabricated agreement');
    echo "[commitment] persisted HTML + editor mode, XSS stripping, A4 portrait with original card, Persian text, embedded fonts, render: PASS\n";
 } finally {
    if(isset($newLogo)) {
        @unlink(STORAGE_PATH.'/branding/'.$newLogo);
        if($oldLogo==='') {
            db()->prepare('DELETE FROM '.table('settings').' WHERE name=?')->execute(['site_logo']);
        } else {
            db()->prepare('INSERT INTO '.table('settings').'(name,value) VALUES (?,?)
              ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['site_logo',$oldLogo]);
        }
    }
    $settings->updateCommitment($oldHtml,in_array($oldMode,['simple','advanced'],true)?$oldMode:'simple');
    $previous=json_decode($oldDates,true);
    $settings->updateDatePreferences('jalali',array_map('strval',is_array($previous)?$previous:[]));
}
