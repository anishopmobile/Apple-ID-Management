<?php
declare(strict_types=1);

/**
 * Dynamic record persistence. Existing customers and apple_records remain the
 * source of truth; new metadata/value tables are strictly additive.
 */
final class RecordManager
{
    private PDO $pdo;
    private string $prefix;
    private const TYPES = ['text', 'textarea', 'number', 'money', 'date', 'mobile', 'select', 'boolean'];
    private const SYSTEM = ['created_at', 'customer_name', 'mobile'];

    public function __construct(PDO $pdo, string $prefix)
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}_$/D', $prefix)) {
            throw new InvalidArgumentException('پیشوند دیتابیس نامعتبر است.');
        }
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function t(string $name): string
    {
        if (!in_array($name, ['customers','apple_records','record_fields','record_values','record_deletions','record_meta','customer_mobile_keys'], true)) {
            throw new InvalidArgumentException('نام جدول غیرمجاز.');
        }
        return chr(96) . $this->prefix . $name . chr(96);
    }

    public static function digits(string $input): string
    {
        return strtr($input, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    public static function mobile(string $input): ?string
    {
        $normalized = preg_replace('/[\s\-\(\)]+/u', '', self::digits(trim($input)));
        if (!is_string($normalized)) return null;
        if (preg_match('/^\+98(9\d{9})$/D', $normalized, $m) || preg_match('/^0098(9\d{9})$/D', $normalized, $m)) {
            $normalized = '0' . $m[1];
        } elseif (preg_match('/^98(9\d{9})$/D', $normalized, $m)) {
            $normalized = '0' . $m[1];
        }
        return preg_match('/^09\d{9}$/D', $normalized) ? $normalized : null;
    }

    public static function normalizeDate(string $raw): ?string
    {
        $raw = trim(self::digits($raw));
        if ($raw === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $raw : null;
        }
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/D', $raw, $m)) return null;
        $y = (int)$m[1]; $mo = (int)$m[2]; $day = (int)$m[3];
        if ($y < 1200 || $y > 1600 || $mo < 1 || $mo > 12 || $day < 1 || $day > 31) return null;
        require_once __DIR__ . '/vendor/jdf.php';
        [$gy, $gm, $gd] = jalali_to_gregorian($y, $mo, $day);
        if (!checkdate((int)$gm, (int)$gd, (int)$gy)) return null;
        [$jy, $jm, $jd] = gregorian_to_jalali((int)$gy, (int)$gm, (int)$gd);
        if ($jy !== $y || $jm !== $mo || $jd !== $day) return null;
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    public static function dateLabel(?string $iso): string
    {
        if (!$iso) return '';
        require_once __DIR__ . '/vendor/jdf.php';
        $p = explode('-', substr($iso, 0, 10));
        if (count($p) !== 3) return '';
        [$y,$m,$d] = gregorian_to_jalali((int)$p[0], (int)$p[1], (int)$p[2]);
        return sprintf('%04d/%02d/%02d', $y, $m, $d);
    }

    public function migrate(): void
    {
        // DDL is idempotent. Never DROP or rebuild customers or apple_records.
        $f = $this->t('record_fields'); $r = $this->t('apple_records'); $v = $this->t('record_values');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS $f (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            field_key VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            label_hash CHAR(64) NOT NULL UNIQUE,
            type VARCHAR(15) NOT NULL,
            options_json TEXT NULL,
            max_length SMALLINT UNSIGNED NOT NULL DEFAULT 255,
            sort_order INT NOT NULL DEFAULT 100,
            required TINYINT(1) NOT NULL DEFAULT 0,
            show_in_table TINYINT(1) NOT NULL DEFAULT 1,
            filterable TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fields_active (deleted_at,active,sort_order,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS $v (
            record_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            value_text TEXT NULL,
            value_decimal DECIMAL(22,4) NULL,
            value_date DATE NULL,
            value_bool TINYINT(1) NULL,
            PRIMARY KEY (record_id,field_id),
            INDEX idx_field_decimal (field_id,value_decimal),
            INDEX idx_field_date (field_id,value_date),
            INDEX idx_field_bool (field_id,value_bool),
            FOREIGN KEY (record_id) REFERENCES $r(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES $f(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS " . $this->t('record_deletions') . " (
            record_id BIGINT UNSIGNED PRIMARY KEY,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (record_id) REFERENCES $r(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS " . $this->t('customer_mobile_keys') . " (
            normalized_mobile CHAR(11) PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_customer_mobile_key (customer_id),
            FOREIGN KEY (customer_id) REFERENCES " . $this->t('customers') . "(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS " . $this->t('record_meta') . " (
            meta_key VARCHAR(60) PRIMARY KEY, meta_value VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $defaults = [
            ['created_at', 'تاریخ ثبت', 'date', 0, 1, 1, 1, 0],
            ['customer_name', 'نام مشتری', 'text', 1, 1, 1, 1, 1],
            ['mobile', 'موبایل', 'mobile', 2, 1, 1, 0, 1],
            ['apple_email', 'Apple ID', 'text', 3, 0, 1, 1, 0],
        ];
        $insert = $this->pdo->prepare("INSERT IGNORE INTO $f
          (field_key,label,label_hash,type,sort_order,required,show_in_table,filterable,is_system)
          VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($defaults as [$key,$label,$type,$order,$required,$visible,$filter,$sys]) {
            $insert->execute([$key,$label,hash('sha256', mb_strtolower($label)),$type,$order,$required,$visible,$filter,$sys]);
        }
        $this->syncLegacyMobiles();
    }

    private function syncLegacyMobiles(): void
    {
        $meta = $this->t('record_meta');
        if ($this->pdo->query("SELECT meta_value FROM $meta WHERE meta_key='legacy_mobile_scan_v1'")->fetchColumn() === 'done') return;
        // Prevent two installations from doing initial mapping at the same time.
        $lockName = 'aid_mob_' . substr(hash('sha256', $this->prefix . config()['DB_NAME']), 0, 30);
        $q = $this->pdo->prepare('SELECT GET_LOCK(?,5)');
        $q->execute([$lockName]);
        if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('قفل مهاجرت اطلاعات مشتریان در دسترس نیست.');
        try {
            if ($this->pdo->query("SELECT meta_value FROM $meta WHERE meta_key='legacy_mobile_scan_v1'")->fetchColumn() === 'done') return;
            $keys = $this->t('customer_mobile_keys');
            $legacy = $this->pdo->query('SELECT id,mobile FROM ' . $this->t('customers') . ' WHERE mobile IS NOT NULL ORDER BY id');
            $stmt = $this->pdo->prepare("SELECT customer_id FROM $keys WHERE normalized_mobile=?");
            $insert = $this->pdo->prepare("INSERT IGNORE INTO $keys (normalized_mobile,customer_id) VALUES (?,?)");
            while ($customer = $legacy->fetch()) {
                $number = self::mobile((string)$customer['mobile']);
                if ($number === null) continue; // Preserve legacy invalid records unchanged.
                $stmt->execute([$number]); $mapped = $stmt->fetchColumn();
                if ($mapped !== false && (int)$mapped !== (int)$customer['id']) {
                    throw new DomainException('شماره موبایل تکراری در داده‌های قدیمی مشتریان وجود دارد. ابتدا آن را در بخش مشتریان اصلاح کنید: ' . $number);
                }
                $insert->execute([$number, (int)$customer['id']]);
                if ($insert->rowCount() === 0 && $mapped === false) {
                    throw new DomainException('اطلاعات شماره موبایل مشتریان قدیمی ناسازگار است؛ قبل از ثبت رکورد اصلاح شود.');
                }
            }
            $this->pdo->exec("INSERT INTO $meta(meta_key,meta_value) VALUES('legacy_mobile_scan_v1','done')
                ON DUPLICATE KEY UPDATE meta_value='done'");
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]);
        }
    }

    public function fields(bool $includeDeleted = false): array
    {
        $where = $includeDeleted ? '' : ' WHERE deleted_at IS NULL';
        return $this->pdo->query('SELECT * FROM ' . $this->t('record_fields') . $where . ' ORDER BY sort_order ASC,id ASC')->fetchAll();
    }

    public function activeFields(): array
    {
        return array_values(array_filter($this->fields(), static fn(array $f): bool => (int)$f['active'] === 1));
    }

    public function assertSystemFields(): void
    {
        $byKey = [];
        foreach ($this->fields() as $f) $byKey[$f['field_key']] = $f;
        foreach (['customer_name'=>'text','mobile'=>'mobile'] as $key=>$type) {
            if (!isset($byKey[$key]) || (int)$byKey[$key]['active'] !== 1 ||
                $byKey[$key]['type'] !== $type || (int)$byKey[$key]['required'] !== 1 ||
                (int)$byKey[$key]['is_system'] !== 1) {
                throw new DomainException('فیلد سیستمی ضروری ' . $key . ' نامعتبر یا غیرفعال است. ابتدا صفحه «ایجاد فیلد» را بررسی کنید.');
            }
        }
    }

    public static function fieldTypes(): array
    {
        return ['text'=>'متن کوتاه','textarea'=>'متن بلند','number'=>'عدد','money'=>'مبلغ',
            'date'=>'تاریخ','mobile'=>'موبایل','select'=>'انتخابی','boolean'=>'بله / خیر'];
    }

    public static function options(array $field): array
    {
        $data = json_decode((string)($field['options_json'] ?? '[]'), true);
        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    public function saveField(array $data, ?int $id = null): int
    {
        $label = trim((string)($data['label'] ?? ''));
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $type = (string)($data['type'] ?? '');
        if (mb_strlen($label) < 2 || mb_strlen($label) > 120) throw new DomainException('عنوان فیلد باید بین ۲ تا ۱۲۰ کاراکتر باشد.');
        if (!in_array($type, self::TYPES, true)) throw new DomainException('نوع فیلد نامعتبر است.');
        $order = filter_var($data['sort_order'] ?? '100', FILTER_VALIDATE_INT);
        if ($order === false || $order < -100000 || $order > 100000) throw new DomainException('ترتیب نمایش باید عدد صحیح مجاز باشد.');
        $max = filter_var($data['max_length'] ?? '255', FILTER_VALIDATE_INT);
        if ($max === false || $max < 1 || $max > 4000) throw new DomainException('محدودیت طول متن باید بین ۱ تا ۴۰۰۰ باشد.');
        $options = [];
        if ($type === 'select') {
            $text = trim((string)($data['options'] ?? ''));
            $options = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/u', $text) ?: []))));
            if (!$options || count($options) > 100) throw new DomainException('برای فیلد انتخابی ۱ تا ۱۰۰ گزینه تعریف کنید.');
            foreach ($options as $opt) if (mb_strlen($opt) > 100) throw new DomainException('حداکثر طول هر گزینه ۱۰۰ کاراکتر است.');
        }
        $required = !empty($data['required']) ? 1 : 0;
        $active = !empty($data['active']) ? 1 : 0;
        $visible = !empty($data['show_in_table']) ? 1 : 0;
        $filter = !empty($data['filterable']) ? 1 : 0;
        $key = 'custom_' . bin2hex(random_bytes(12));
        if ($id !== null) {
            $existing = $this->fieldById($id);
            if (!$existing) throw new DomainException('فیلد یافت نشد.');
            $key = $existing['field_key'];
            if ((int)$existing['is_system'] === 1) {
                if ($type !== $existing['type'] || $required !== (int)$existing['required'] ||
                    $active !== 1) throw new DomainException('نوع، وضعیت و الزامی‌بودن فیلد سیستمی قابل تغییر نیست.');
            }
            if ($type !== $existing['type'] || ($type === 'select' && $existing['options_json'] !== json_encode($options, JSON_UNESCAPED_UNICODE))) {
                $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->t('record_values') . ' WHERE field_id=?');
                $count->execute([$id]);
                if ((int)$count->fetchColumn() > 0) {
                    throw new DomainException('تغییر نوع یا گزینه‌های فیلد دارای اطلاعات مجاز نیست؛ اطلاعات قدیمی باید حفظ شوند.');
                }
            }
            if ($type === 'text' && (int)$max < (int)$existing['max_length']) {
                $v = $this->pdo->prepare('SELECT MAX(CHAR_LENGTH(value_text)) FROM ' . $this->t('record_values') . ' WHERE field_id=?');
                $v->execute([$id]);
                if ((int)$v->fetchColumn() > $max) throw new DomainException('محدودیت جدید از طول داده‌های قبلی کمتر است.');
            }
        }
        $hash = hash('sha256', mb_strtolower($label));
        $q = $this->pdo->prepare('SELECT id FROM ' . $this->t('record_fields') . ' WHERE label_hash=? AND deleted_at IS NULL LIMIT 1');
        $q->execute([$hash]); $match = $q->fetchColumn();
        if ($match !== false && (int)$match !== (int)$id) throw new DomainException('فیلدی با این عنوان قبلاً ثبت شده است.');
        try {
            if ($id === null) {
                $q = $this->pdo->prepare('INSERT INTO ' . $this->t('record_fields') . '
                  (field_key,label,label_hash,type,options_json,max_length,sort_order,required,show_in_table,filterable,active)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$key,$label,$hash,$type,json_encode($options,JSON_UNESCAPED_UNICODE),
                    $max,$order,$required,$visible,$filter,$active]);
                return (int)$this->pdo->lastInsertId();
            }
            $q = $this->pdo->prepare('UPDATE ' . $this->t('record_fields') . ' SET
               label=?,label_hash=?,type=?,options_json=?,max_length=?,sort_order=?,required=?,show_in_table=?,filterable=?,active=?
               WHERE id=? AND deleted_at IS NULL');
            $q->execute([$label,$hash,$type,json_encode($options,JSON_UNESCAPED_UNICODE),
                $max,$order,$required,$visible,$filter,$active,$id]);
            return $id;
        } catch (PDOException $ex) {
            if ((string)$ex->getCode() === '23000') throw new DomainException('عنوان تکراری یا ناسازگار؛ دوباره بررسی کنید.');
            throw $ex;
        }
    }

    public function fieldById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM ' . $this->t('record_fields') . ' WHERE id=? AND deleted_at IS NULL');
        $s->execute([$id]); return $s->fetch() ?: null;
    }

    public function deleteField(int $id): void
    {
        $f = $this->fieldById($id);
        if (!$f) throw new DomainException('فیلد یافت نشد.');
        if ((int)$f['is_system'] === 1 || in_array($f['field_key'], self::SYSTEM, true)) {
            throw new DomainException('فیلد سیستمی قابل حذف نیست.');
        }
        // Soft delete always retains historical values and stable ID.
        $q = $this->pdo->prepare('UPDATE ' . $this->t('record_fields') . ' SET deleted_at=UTC_TIMESTAMP(),active=0 WHERE id=?');
        $q->execute([$id]);
    }

    public function validateValues(array $raw, bool $editing = false): array
    {
        $this->assertSystemFields();
        $values = []; $errors = [];
        foreach ($this->activeFields() as $f) {
            $key = $f['field_key'];
            if ($key === 'created_at') continue; // Always maintained by the server on manual forms.
            $id = (int)$f['id'];
            $input = isset($raw[$id]) && is_scalar($raw[$id]) ? trim(self::digits((string)$raw[$id])) : '';
            $label = $f['label'];
            if ($input === '') {
                if ((int)$f['required'] === 1) $errors[(string)$id] = 'تکمیل «' . $label . '» الزامی است.';
                $values[$id] = null;
                continue;
            }
            switch ($f['type']) {
                case 'text':
                    if (mb_strlen($input) > (int)$f['max_length']) $errors[(string)$id] = 'طول متن بیش از حد مجاز است.';
                    break;
                case 'textarea':
                    if (mb_strlen($input) > 10000) $errors[(string)$id] = 'متن حداکثر ۱۰هزار کاراکتر باشد.';
                    break;
                case 'number':
                case 'money':
                    $numeric = str_replace([',','٬',' '], '', $input);
                    if (!preg_match('/^-?\d{1,18}(?:\.\d{1,4})?$/D', $numeric)) $errors[(string)$id] = 'عدد معتبر با حداکثر ۴ رقم اعشار وارد کنید.';
                    else $input = $numeric;
                    break;
                case 'date':
                    $date = self::normalizeDate($input);
                    if ($date === null) $errors[(string)$id] = 'تاریخ شمسی معتبر (مانند ۱۴۰۵/۰۸/۰۱) وارد کنید.';
                    else $input = $date;
                    break;
                case 'mobile':
                    $phone = self::mobile($input);
                    if ($phone === null) $errors[(string)$id] = 'شماره موبایل ایرانی معتبر وارد کنید.';
                    else $input = $phone;
                    break;
                case 'select':
                    if (!in_array($input, self::options($f), true)) $errors[(string)$id] = 'گزینه انتخاب‌شده مجاز نیست.';
                    break;
                case 'boolean':
                    if (!in_array($input, ['0','1'], true)) $errors[(string)$id] = 'فقط بله یا خیر مجاز است.';
                    break;
            }
            $values[$id] = $input;
        }
        if ($errors) throw new RecordValidationException($errors);
        return $values;
    }

    private function identifyCustomer(string $mobile, string $name): int
    {
        $keys = $this->t('customer_mobile_keys');
        $query = $this->pdo->prepare("SELECT customer_id FROM $keys WHERE normalized_mobile=? FOR UPDATE");
        $query->execute([$mobile]);
        $id = $query->fetchColumn();
        if ($id !== false) return (int)$id;
        $create = $this->pdo->prepare('INSERT INTO ' . $this->t('customers') . ' (full_name,mobile) VALUES (?,?)');
        $create->execute([$name,$mobile]);
        $id = (int)$this->pdo->lastInsertId();
        // In case of a concurrent insert the UNIQUE constraint triggers a rollback:
        // the extra customer row is never left without a corresponding record.
        $link = $this->pdo->prepare("INSERT INTO $keys (normalized_mobile,customer_id) VALUES (?,?)");
        $link->execute([$mobile,$id]);
        return $id;
    }

    public function saveRecord(array $raw, ?int $recordId = null, ?string $importedDate = null, bool $alreadyInTransaction = false): int
    {
        $values = $this->validateValues($raw, $recordId !== null);
        $f = array_column($this->fields(), null, 'field_key');
        $name = trim((string)($values[(int)$f['customer_name']['id']] ?? ''));
        $mobile = (string)($values[(int)$f['mobile']['id']] ?? '');
        if ($name === '' || $mobile === '') throw new DomainException('نام مشتری و موبایل معتبر الزامی است.');
        $date = $importedDate === null ? null : self::normalizeDate($importedDate);
        if ($importedDate !== null && $date === null) throw new DomainException('تاریخ ثبت فایل اکسل نامعتبر است.');
        if (!$alreadyInTransaction) $this->pdo->beginTransaction();
        try {
            if ($recordId !== null) {
                $q = $this->pdo->prepare('SELECT r.id FROM ' . $this->t('apple_records') . ' r
                  LEFT JOIN ' . $this->t('record_deletions') . ' d ON d.record_id=r.id
                  WHERE r.id=? AND d.record_id IS NULL FOR UPDATE');
                $q->execute([$recordId]);
                if (!$q->fetchColumn()) throw new DomainException('رکورد موردنظر پیدا نشد.');
            }
            $customer = $this->identifyCustomer($mobile,$name);
            $appleEmail = isset($f['apple_email']) ? ($values[(int)$f['apple_email']['id']] ?? null) : null;
            if ($recordId === null) {
                $q = $this->pdo->prepare('INSERT INTO ' . $this->t('apple_records') . ' (customer_id,apple_email,created_at) VALUES (?,?,COALESCE(?,UTC_TIMESTAMP()))');
                $q->execute([$customer,$appleEmail,$date === null ? null : $date . ' 12:00:00']);
                $recordId = (int)$this->pdo->lastInsertId();
            } else {
                $q = $this->pdo->prepare('UPDATE ' . $this->t('apple_records') . ' SET customer_id=?,apple_email=COALESCE(?,apple_email) WHERE id=?');
                $q->execute([$customer,$appleEmail,$recordId]);
            }
            $saveValue = $this->pdo->prepare('INSERT INTO ' . $this->t('record_values') . '
                (record_id,field_id,value_text,value_decimal,value_date,value_bool) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),
                  value_decimal=VALUES(value_decimal),value_date=VALUES(value_date),value_bool=VALUES(value_bool)');
            $fieldById = array_column($this->activeFields(), null, 'id');
            foreach ($values as $fieldId=>$value) {
                $type = $fieldById[$fieldId]['type'];
                $text = $value === null ? null : (string)$value;
                $saveValue->execute([
                    $recordId,$fieldId,
                    in_array($type,['text','textarea','mobile','select'],true) ? $text : null,
                    in_array($type,['number','money'],true) ? $text : null,
                    $type === 'date' ? $text : null,
                    $type === 'boolean' ? $text : null,
                ]);
            }
            if (!$alreadyInTransaction) $this->pdo->commit();
            return $recordId;
        } catch (Throwable $ex) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') {
                throw new DomainException('شماره موبایل هم‌زمان توسط درخواست دیگری ثبت شد؛ دوباره تلاش کنید.');
            }
            throw $ex;
        }
    }

    public function deleteRecord(int $id): void
    {
        $q = $this->pdo->prepare('SELECT id FROM ' . $this->t('apple_records') . ' WHERE id=?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new DomainException('رکورد یافت نشد.');
        $q = $this->pdo->prepare('INSERT IGNORE INTO ' . $this->t('record_deletions') . ' (record_id) VALUES (?)');
        $q->execute([$id]); // Soft delete; the customer is retained.
    }

    private function valueExpression(array $f): string
    {
        $type = $f['type'];
        $id = (int)$f['id'];
        if ($f['field_key'] === 'created_at') return 'DATE(r.created_at)';
        $column = match ($type) {
            'number','money'=>'value_decimal', 'date'=>'value_date',
            'boolean'=>'value_bool', default=>'value_text'
        };
        $expr = '(SELECT rv.' . $column . ' FROM ' . $this->t('record_values') . ' rv WHERE rv.record_id=r.id AND rv.field_id=' . $id . ' LIMIT 1)';
        if ($f['field_key'] === 'customer_name') return 'COALESCE(' . $expr . ',c.full_name)';
        if ($f['field_key'] === 'mobile') return 'COALESCE(' . $expr . ',c.mobile)';
        if ($f['field_key'] === 'apple_email') return 'COALESCE(' . $expr . ',r.apple_email)';
        return $expr;
    }

    private function whereAndOrder(array $query, array $fields): array
    {
        $clauses = ['d.record_id IS NULL']; $params = [];
        $allowed = array_column($fields,null,'id');
        $filters = is_array($query['filter'] ?? null) ? $query['filter'] : [];
        $minima = is_array($query['filter_min'] ?? null) ? $query['filter_min'] : [];
        $maxima = is_array($query['filter_max'] ?? null) ? $query['filter_max'] : [];
        foreach ($allowed as $id=>$f) {
            if (!(int)$f['filterable']) continue;
            $type = $f['type']; $expr = $this->valueExpression($f);
            $value = isset($filters[$id]) && is_scalar($filters[$id]) ? trim(self::digits((string)$filters[$id])) : '';
            if ($value !== '') {
                if (in_array($type,['text','textarea','mobile'],true)) {
                    if (mb_strlen($value) > 120) throw new DomainException('طول فیلتر غیرمجاز است.');
                    $clauses[] = "$expr LIKE ? ESCAPE '!'";
                    $params[] = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$value) . '%';
                } elseif ($type === 'select') {
                    if (!in_array($value,self::options($f),true)) throw new DomainException('مقدار فیلتر انتخابی نامعتبر است.');
                    $clauses[] = "$expr = ?"; $params[] = $value;
                } elseif ($type === 'boolean') {
                    if (!in_array($value,['0','1'],true)) throw new DomainException('مقدار فیلتر بله/خیر نامعتبر است.');
                    $clauses[] = "$expr = ?"; $params[] = (int)$value;
                }
            }
            if (in_array($type,['date','number','money'],true)) {
                foreach (['min'=>$minima,'max'=>$maxima] as $edge=>$items) {
                    $v = isset($items[$id]) && is_scalar($items[$id]) ? trim(self::digits((string)$items[$id])) : '';
                    if ($v === '') continue;
                    if ($type === 'date') {
                        $v = self::normalizeDate($v);
                        if ($v === null) throw new DomainException('تاریخ فیلتر نامعتبر است.');
                    } else {
                        $v = str_replace([',','٬',' '], '', $v);
                        if (!preg_match('/^-?\d{1,18}(?:\.\d{1,4})?$/D',$v)) throw new DomainException('عدد فیلتر نامعتبر است.');
                    }
                    $clauses[] = $expr . ($edge === 'min' ? ' >= ?' : ' <= ?');
                    $params[] = $v;
                }
            }
        }
        $sortId = filter_var($query['sort'] ?? '0',FILTER_VALIDATE_INT);
        $order = 'r.id DESC';
        if ($sortId !== false && isset($allowed[$sortId]) && (int)$allowed[$sortId]['show_in_table']) {
            $direction = ($query['direction'] ?? '') === 'asc' ? 'ASC' : 'DESC';
            $order = $this->valueExpression($allowed[$sortId]) . ' ' . $direction . ',r.id DESC';
        }
        return [' WHERE ' . implode(' AND ',$clauses),$params,$order];
    }

    public function listing(array $query, bool $all = false): array
    {
        $fields = $this->activeFields();
        [$where,$params,$order] = $this->whereAndOrder($query,$fields);
        $from = ' FROM ' . $this->t('apple_records') . ' r
           JOIN ' . $this->t('customers') . ' c ON c.id=r.customer_id
           LEFT JOIN ' . $this->t('record_deletions') . ' d ON d.record_id=r.id';
        $countStmt = $this->pdo->prepare('SELECT COUNT(*)' . $from . $where);
        $countStmt->execute($params); $count = (int)$countStmt->fetchColumn();
        $requestedPer = (int)($query['per_page'] ?? 25);
        $per = in_array($requestedPer, [25,50,100], true) ? $requestedPer : 25;
        $pages = max(1,(int)ceil($count/$per));
        $page = min($pages, max(1,(int)($query['page'] ?? 1)));
        $offset = ($page-1)*$per;
        $sql = 'SELECT r.id,r.created_at,r.apple_email,c.full_name,c.mobile' . $from . $where . ' ORDER BY ' . $order;
        if (!$all) $sql .= ' LIMIT ' . $per . ' OFFSET ' . $offset;
        $q = $this->pdo->prepare($sql); $q->execute($params);
        $rows = $q->fetchAll();
        $this->hydrateValues($rows, $fields);
        return ['rows'=>$rows,'total'=>$count,'page'=>$page,'pages'=>$pages,'per_page'=>$per,'fields'=>$fields];
    }

    private function hydrateValues(array &$rows, array $fields): void
    {
        if (!$rows) return;
        $ids = array_column($rows,'id');
        $placeholders = implode(',',array_fill(0,count($ids),'?'));
        $q = $this->pdo->prepare('SELECT record_id,field_id,value_text,value_decimal,value_date,value_bool FROM '
            . $this->t('record_values') . " WHERE record_id IN ($placeholders)");
        $q->execute($ids);
        $values = [];
        $byId = array_column($fields,null,'id');
        while ($entry = $q->fetch()) {
            $id = (int)$entry['field_id'];
            if (!isset($byId[$id])) continue;
            $values[(int)$entry['record_id']][$id] = match ($byId[$id]['type']) {
                'number','money' => $entry['value_decimal'],
                'date' => $entry['value_date'],
                'boolean' => $entry['value_bool'],
                default => $entry['value_text'],
            };
        }
        foreach ($rows as &$row) {
            $row['values'] = [];
            foreach ($fields as $f) {
                $id = (int)$f['id'];
                $fallback = match ($f['field_key']) {
                    'created_at' => substr($row['created_at'],0,10),
                    'customer_name' => $row['full_name'],
                    'mobile' => $row['mobile'],
                    'apple_email' => $row['apple_email'],
                    default => null,
                };
                $row['values'][$id] = $values[(int)$row['id']][$id] ?? $fallback;
            }
        }
        unset($row);
    }

    public function record(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT r.id,r.created_at,r.apple_email,c.full_name,c.mobile FROM ' .
            $this->t('apple_records') . ' r JOIN ' . $this->t('customers') . ' c ON c.id=r.customer_id LEFT JOIN ' .
            $this->t('record_deletions') . ' d ON d.record_id=r.id WHERE r.id=? AND d.record_id IS NULL');
        $q->execute([$id]); $row=$q->fetch();
        if (!$row) return null;
        $rows=[$row]; $this->hydrateValues($rows,$this->fields());
        return $rows[0];
    }
}

final class RecordValidationException extends DomainException
{
    public function __construct(public readonly array $fieldErrors)
    {
        parent::__construct('مقادیر یک یا چند فیلد معتبر نیست.');
    }
}
