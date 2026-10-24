<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/RecordManager.php';
require dirname(__DIR__) . '/src/CustomerDirectory.php';
require dirname(__DIR__) . '/src/BackupService.php';

function ccheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('[customers/backup] '.$message);
}
function creject(callable $call,string $why): void {
    try { $call(); } catch (DomainException $exception) { return; }
    throw new RuntimeException('[customers/backup] unexpected acceptance: '.$why);
}

$directory=new CustomerDirectory();
$first=$directory->listing([]);
ccheck($first['perPage']===25 && count($first['rows'])<=25,'25 customers by default');
$before=(int)db()->query('SELECT COUNT(*) FROM '.table('customers'))->fetchColumn();
$insert=db()->prepare('INSERT INTO '.table('customers').' (full_name,mobile) VALUES (?,?)');
for ($i=0;$i<28;$i++) $insert->execute(['مشتری جستجوی زنده شماره '.$i,sprintf('0933%07d',$i)]);
$size25=$directory->listing(['per_page'=>25]);
ccheck($size25['total']===$before+28 && count($size25['rows'])===25,'total and 25 recent customers');
ccheck(count($directory->listing(['page'=>2])['rows'])>0,'page 2 includes older customers');
foreach ([25,50,100] as $size) ccheck($directory->listing(['per_page'=>$size])['perPage']===$size,'page size '.$size);
ccheck($directory->listing(['by'=>'name','q'=>'جستجوی زنده شماره 13'])['total']===1,'name search');
ccheck($directory->listing(['by'=>'mobile','q'=>'۰۹۳۳۰۰۰۰۰۱۳'])['total']===1,'Persian mobile digits');
ccheck($directory->listing(['by'=>'mobile','q'=>'093300'])['total']===28,'partial phone');
ccheck($directory->listing(['by'=>'name','q'=>'%'])['total']===0,'SQL LIKE escapes wildcard');
ccheck($directory->listing(['q'=>"' OR 1=1"])['total']===0,'SQL injection string is parameterized');
ccheck($directory->listing(['q'=>'093300'])['total']===28,'phone search works without selector');
ccheck($directory->listing(['q'=>'جستجوی زنده شماره 13'])['total']===1,'name search works without selector');
ccheck($directory->listing(['per_page'=>999])['perPage']===25,'invalid page size rejected');

$backup=new BackupService();
$folder=sys_get_temp_dir().'/aid-sql-integration-'.bin2hex(random_bytes(6));
mkdir($folder,0700);
try {
    $saved=$backup->saveDirectory($folder);
    ccheck($saved===realpath($folder) && $backup->directory()===$saved,'saved backup path reused');
    creject(fn()=> $backup->saveDirectory(ROOT_PATH.'/public'),'backup forbidden in web root');
    creject(fn()=> $backup->saveDirectory(ROOT_PATH),'backup forbidden in project root');
    $archive=$backup->create($saved);
    ccheck(is_file($archive['file']) && str_ends_with($archive['file'],'.sql'),'real SQL file written');
    $actualSql=file_get_contents($archive['file']);
    ccheck(str_contains($actualSql,'INSERT INTO ') && !str_contains($actualSql,'DROP TABLE'),'data-only SQL');
    $manifest=$backup->inspect($archive['file']);
    ccheck($manifest['counts']['customers']===$before+28,'all customers in SQL');
    ccheck($manifest['counts']['admins']>=1,'admin included');
    ccheck($manifest['counts']['record_values']>=1,'dynamic fields included');

    $bad=$folder.'/untrusted.sql';
    file_put_contents($bad,"DROP TABLE testing;\n");
    creject(fn()=> $backup->restore($bad),'untrusted SQL must be rejected');
    $bad=$folder.'/tampered.sql';
    file_put_contents($bad,str_replace('-- TABLE customers','-- TABLE unknown',$actualSql));
    creject(fn()=> $backup->restore($bad),'changed SQL must be rejected');
    ccheck((int)db()->query('SELECT COUNT(*) FROM '.table('customers'))->fetchColumn()===$before+28,
        'invalid SQL did not touch customers');

    db()->prepare('UPDATE '.table('settings').' SET value=? WHERE name=?')->execute(['موقت','site_name']);
    $insert->execute(['مشتری حذف‌شونده بعد از بازیابی','09339998877']);
    ccheck((int)db()->query('SELECT COUNT(*) FROM '.table('customers'))->fetchColumn()===$before+29,
        'additional customer before restore');
    $restored=$backup->restore($archive['file']);
    ccheck($restored['counts']['customers']===$before+28,'restored counts match');
    ccheck((int)db()->query('SELECT COUNT(*) FROM '.table('customers'))->fetchColumn()===$before+28,
        'restore removes records created after backup');
    ccheck(setting('site_name')==='آنی شاپ','settings truly restored');
    ccheck((int)db()->query('SELECT COUNT(*) FROM '.table('record_values'))->fetchColumn()===$manifest['counts']['record_values'],
        'dynamic values restored');

    // Forge a signed SQL file that would violate a foreign key; this proves
    // restore rolls back its earlier DELETEs and INSERTs on any SQL error.
    $prefix='INSERT INTO '.chr(96).config()['DB_PREFIX'].'record_values'.chr(96);
    $rows=explode("\n",$actualSql);
    $changed=false;
    foreach ($rows as &$line) {
        if (!$changed && str_starts_with($line,$prefix)) {
            $start=strpos($line,') VALUES (');
            if ($start===false) continue;
            $at=$start+strlen(') VALUES (');
            $firstEnd=strpos($line,',',$at);
            $secondEnd=strpos($line,',',$firstEnd+1);
            if ($firstEnd===false || $secondEnd===false) continue;
            $line=substr($line,0,$firstEnd+1)."CONVERT(X'3939393939393939' USING utf8mb4)".substr($line,$secondEnd);
            $changed=true;
        }
    }
    unset($line);
    ccheck($changed,'prepare signed failing FK fixture');
    $sql=implode("\n",$rows);
    $sql=preg_replace('/-- HMAC-SHA256 [a-f0-9]{64}\n$/','',$sql);
    ccheck(is_string($sql),'strip test signature');
    $forged=$folder.'/foreign-key-failure.sql';
    file_put_contents($forged,$sql.'-- HMAC-SHA256 '.hash_hmac('sha256',$sql,config()['APP_KEY'])."\n");
    $backup->inspect($forged);
    try {
        $backup->restore($forged);
        throw new RuntimeException('Signed invalid FK restore succeeded unexpectedly');
    } catch (PDOException $expected) {}
    ccheck((int)db()->query('SELECT COUNT(*) FROM '.table('customers'))->fetchColumn()===$before+28,
        'full rollback preserved customers on SQL foreign-key error');
    ccheck(setting('site_name')==='آنی شاپ','rollback preserved settings');
    echo "[customers/backup] search, pagination, path, signed SQL, restore, rejected injection, rollback: PASS\n";
} finally {
    foreach (glob($folder.'/*.sql')?:[] as $filename) @unlink($filename);
    @rmdir($folder);
    db()->prepare('DELETE FROM '.table('settings').' WHERE name=?')->execute(['backup_directory']);
}
