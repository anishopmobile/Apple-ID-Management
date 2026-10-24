<?php
declare(strict_types=1);

/**
 * Strict data-only SQL snapshots. Restore accepts ONLY the signed format
 * produced by this installation, never untrusted arbitrary SQL.
 */
final class BackupService
{
    public const MAX_BYTES = 67108864;
    public const MAX_LINE = 2097152;
    public const TABLES = ['admins','settings','customers','apple_records','login_attempts',
        'remember_tokens','record_fields','record_values','record_deletions',
        'customer_mobile_keys','record_meta'];
    private const CLEAR_ORDER = ['remember_tokens','login_attempts','record_values','record_deletions',
        'customer_mobile_keys','apple_records','record_fields','record_meta',
        'customers','settings','admins'];
    private PDO $pdo;
    private string $prefix;

    public function __construct()
    {
        $this->pdo=db();
        $this->prefix=config()['DB_PREFIX'];
    }

    private function tableName(string $suffix): string
    {
        if (!in_array($suffix,self::TABLES,true)) throw new InvalidArgumentException('جدول غیرمجاز است.');
        return chr(96).$this->prefix.$suffix.chr(96);
    }

    private function columnNames(string $name): array
    {
        $names=[];
        foreach ($this->pdo->query('SHOW COLUMNS FROM '.$this->tableName($name)) as $row) {
            $column=(string)$row['Field'];
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D',$column)) throw new RuntimeException('ستون غیرمجاز.');
            $names[]=$column;
        }
        if (!$names) throw new RuntimeException('ساختار جدول ناشناخته است.');
        return $names;
    }

    public function directory(): string
    {
        $saved=setting('backup_directory','');
        if ($saved!=='') return $saved;
        $dir=STORAGE_PATH.DIRECTORY_SEPARATOR.'backups';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new DomainException('ساخت پوشه امن بکاپ ناموفق بود.');
        }
        return $dir;
    }

    public function validateDirectory(string $input): string
    {
        $input=trim($input);
        if ($input==='' || strlen($input)>1024 || str_contains($input,"\0") ||
            preg_match('~^[a-z]+://~i',$input)) throw new DomainException('آدرس پوشه نامعتبر است.');
        $path=realpath($input);
        if ($path===false || !is_dir($path) || !is_writable($path) || is_link($input) ||
            $path===DIRECTORY_SEPARATOR) {
            throw new DomainException('پوشه باید وجود داشته باشد و مجوز نوشتن داشته باشد.');
        }
        $path=rtrim($path,DIRECTORY_SEPARATOR);
        $storage=realpath(STORAGE_PATH) ?: '';
        $project=realpath(ROOT_PATH) ?: '';
        $rawDocroot=(string)($_SERVER['DOCUMENT_ROOT']??'');
        // On PHP CLI, realpath('') resolves to the current working directory,
        // which is NOT a web document root and must not block private storage.
        $docroot=$rawDocroot!=='' ? (realpath($rawDocroot) ?: '') : '';
        $under=static fn(string $a,string $b): bool => $b!=='' &&
            ($a===$b || str_starts_with($a,rtrim($b,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
        if ($under($path,$docroot) || ($under($path,$project) && !$under($path,$storage))) {
            throw new DomainException('مسیر بکاپ نباید زیر پوشه عمومی وب یا کد برنامه باشد.');
        }
        if ($under($storage,$docroot)) throw new DomainException('پوشه storage در دسترس وب است؛ ابتدا آن را امن کنید.');
        return $path;
    }

    public function saveDirectory(string $input): string
    {
        $dir=$this->validateDirectory($input);
        $stmt=$this->pdo->prepare('INSERT INTO '.table('settings').
            ' (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $stmt->execute(['backup_directory',$dir]);
        return $dir;
    }

    private static function write($handle,$hash,string $line): void
    {
        if (strlen($line)>self::MAX_LINE) throw new DomainException('یک رکورد دیتابیس برای این روش بکاپ بیش از حد بزرگ است.');
        if (fwrite($handle,$line)!==strlen($line)) throw new RuntimeException('نوشتن فایل ناقص بود.');
        hash_update($hash,$line);
    }

    public function create(string $input): array
    {
        $dir=$this->validateDirectory($input);
        $file=$dir.DIRECTORY_SEPARATOR.'apple-id-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sql';
        $handle=fopen($file,'x+b');
        if ($handle===false) throw new DomainException('ایجاد فایل پشتیبان مجاز نیست.');
        @chmod($file,0600);
        $success=false;
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->beginTransaction();
            $columns=[];
            foreach (self::TABLES as $t) $columns[$t]=$this->columnNames($t);
            $meta=['format'=>'AID-SQL-V1','prefix'=>$this->prefix,
                'database'=>config()['DB_NAME'],'columns'=>$columns];
            $hash=hash_init('sha256',HASH_HMAC,config()['APP_KEY']);
            self::write($handle,$hash,'-- AID-SQL-V1 '.base64_encode(json_encode($meta,JSON_THROW_ON_ERROR))."\n");
            self::write($handle,$hash,"-- Application-signed data-only SQL; restore only in Apple ID Management\n");
            $counts=[];
            foreach (self::TABLES as $t) {
                self::write($handle,$hash,'-- TABLE '.$t."\n");
                $counts[$t]=0;
                $cols=implode(',',array_map(static fn($x)=>chr(96).$x.chr(96),$columns[$t]));
                $query=$this->pdo->query('SELECT * FROM '.$this->tableName($t));
                while ($row=$query->fetch(PDO::FETCH_ASSOC)) {
                    $parts=[];
                    foreach ($columns[$t] as $col) {
                        $v=$row[$col];
                        $parts[]=$v===null?'NULL':"CONVERT(X'".strtoupper(bin2hex((string)$v))."' USING utf8mb4)";
                    }
                    self::write($handle,$hash,'INSERT INTO '.$this->tableName($t).' ('.$cols.
                        ') VALUES ('.implode(',',$parts).");\n");
                    $counts[$t]++;
                    if (ftell($handle)>self::MAX_BYTES) throw new DomainException('حجم فایل بکاپ بیش از ۶۴ مگابایت است.');
                }
                $query->closeCursor();
            }
            self::write($handle,$hash,'-- COUNTS '.base64_encode(json_encode($counts,JSON_THROW_ON_ERROR))."\n");
            $end='-- HMAC-SHA256 '.hash_final($hash)."\n";
            if (fwrite($handle,$end)!==strlen($end) || ftell($handle)>self::MAX_BYTES) {
                throw new DomainException('تکمیل فایل نسخه پشتیبان ناموفق بود.');
            }
            fflush($handle);
            $this->pdo->commit();
            $success=true;
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            fclose($handle);
            if (!$success) @unlink($file);
        }
        return ['file'=>$file,'size'=>(int)filesize($file),'counts'=>$counts];
    }

    /**
     * Verify exact file format, current database schema and HMAC before any
     * destructive operation. Every payload line is a single allowlisted INSERT.
     */
    public function inspect(string $path): array
    {
        $size=@filesize($path);
        if (!is_file($path) || is_link($path) || $size===false ||
            $size<100 || $size>self::MAX_BYTES) throw new DomainException('حجم یا ساختار فایل SQL نامعتبر است (حداکثر ۶۴ مگابایت).');
        $handle=fopen($path,'rb');
        if (!$handle) throw new DomainException('فایل قابل خواندن نیست.');
        $hash=hash_init('sha256',HASH_HMAC,config()['APP_KEY']);
        $columns=[];$seen=[];$active='';$counts=array_fill_keys(self::TABLES,0);
        $position=0;$ended=false;$signed=false;
        try {
            while (($line=fgets($handle,self::MAX_LINE+1))!==false) {
                if (strlen($line)>self::MAX_LINE || !str_ends_with($line,"\n") || $signed) {
                    throw new DomainException('فایل ناقص یا دارای متن اضافی است.');
                }
                $value=substr($line,0,-1);
                if (str_starts_with($value,'-- HMAC-SHA256 ')) {
                    if (!$ended || !preg_match('/^[a-f0-9]{64}$/D',substr($value,15)) ||
                        !hash_equals(hash_final($hash),substr($value,15))) {
                        throw new DomainException('امضای امنیتی بکاپ نامعتبر است؛ فایل تغییر کرده یا متعلق به این نصب نیست.');
                    }
                    $signed=true;
                    continue;
                }
                hash_update($hash,$line);
                if ($position===0) {
                    if (!str_starts_with($value,'-- AID-SQL-V1 ')) throw new DomainException('فایل SQL مورد تأیید سامانه نیست.');
                    $meta=json_decode(base64_decode(substr($value,14),true) ?: '',true);
                    if (!is_array($meta) || ($meta['format']??'')!=='AID-SQL-V1' ||
                        ($meta['prefix']??'')!==$this->prefix || ($meta['database']??'')!==config()['DB_NAME'] ||
                        !is_array($meta['columns']??null) || array_keys($meta['columns'])!==self::TABLES) {
                        throw new DomainException('بکاپ برای دیتابیس فعلی ساخته نشده است.');
                    }
                    foreach (self::TABLES as $t) {
                        $columns[$t]=$this->columnNames($t);
                        if ($meta['columns'][$t]!==$columns[$t]) throw new DomainException('ساختار جداول با فایل بکاپ مطابقت ندارد.');
                    }
                } elseif ($position===1) {
                    if ($value!=='-- Application-signed data-only SQL; restore only in Apple ID Management') {
                        throw new DomainException('سربرگ فایل معتبر نیست.');
                    }
                } elseif (str_starts_with($value,'-- TABLE ')) {
                    $active=substr($value,9);
                    if ($ended || !in_array($active,self::TABLES,true) || isset($seen[$active]) ||
                        self::TABLES[count($seen)]!==$active) throw new DomainException('ترتیب جدول‌های فایل نامعتبر است.');
                    $seen[$active]=true;
                } elseif (str_starts_with($value,'-- COUNTS ')) {
                    $listed=json_decode(base64_decode(substr($value,10),true) ?: '',true);
                    if ($ended || count($seen)!==count(self::TABLES) || $listed!==$counts) {
                        throw new DomainException('تعداد اطلاعات در بکاپ ناسازگار است.');
                    }
                    $ended=true;
                } else {
                    if ($ended || $active==='') throw new DomainException('دستور SQL غیرمجاز است.');
                    $columnList=implode(',',array_map(static fn($x)=>chr(96).$x.chr(96),$columns[$active]));
                    $prefix='INSERT INTO '.$this->tableName($active).' ('.$columnList.') VALUES (';
                    if (!str_starts_with($value,$prefix) || !str_ends_with($value,');')) {
                        throw new DomainException('فقط SQL امضاشده تولیدی این برنامه پذیرفته می‌شود.');
                    }
                    $args=substr($value,strlen($prefix),-2);
                    $atom="(?:NULL|CONVERT\\(X'[0-9A-F]*' USING utf8mb4\\))";
                    if (!preg_match('/^'.$atom.'(?:,'.$atom.')*$/D',$args) ||
                        count(explode(',',$args))!==count($columns[$active])) {
                        throw new DomainException('مقادیر SQL دارای ساختار غیرمجاز هستند.');
                    }
                    $counts[$active]++;
                }
                $position++;
            }
            if (!$signed) throw new DomainException('امضای پایانی فایل SQL موجود نیست.');
        } finally { fclose($handle); }
        return ['size'=>$size,'counts'=>$counts,'total'=>array_sum($counts)];
    }

    public function restore(string $path): array
    {
        $manifest=$this->inspect($path);
        $lock='aid_rest_'.substr(hash('sha256',$this->prefix.config()['DB_NAME']),0,30);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,5)');
        $q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) throw new DomainException('یک عملیات بازیابی دیگر در حال اجراست.');
        try {
            $this->inspect($path);
            $this->pdo->beginTransaction();
            try {
                foreach (self::CLEAR_ORDER as $name) $this->pdo->exec('DELETE FROM '.$this->tableName($name));
                $fp=fopen($path,'rb');
                if (!$fp) throw new RuntimeException('فایل SQL دیگر قابل دسترسی نیست.');
                try {
                    while (($line=fgets($fp,self::MAX_LINE+1))!==false) {
                        if (str_starts_with($line,'INSERT INTO ')) $this->pdo->exec(rtrim($line,"\n"));
                    }
                } finally { fclose($fp); }
                if (!(int)$this->pdo->query('SELECT COUNT(*) FROM '.$this->tableName('admins'))->fetchColumn()) {
                    throw new DomainException('بکاپ بدون حساب مدیر مجاز نیست.');
                }
                foreach (self::TABLES as $name) {
                    $real=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$this->tableName($name))->fetchColumn();
                    if ($real!==$manifest['counts'][$name]) throw new RuntimeException('تعداد ردیف‌های بازیابی صحیح نیست.');
                }
                // Restore signed customer data, but revoke persisted login tokens.
                $this->pdo->exec('DELETE FROM '.$this->tableName('remember_tokens'));
                $this->pdo->exec('DELETE FROM '.$this->tableName('login_attempts'));
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $error;
            }
        } finally {
            $unlock=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([$lock]);
        }
        return $manifest;
    }
}
