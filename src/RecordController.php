<?php
declare(strict_types=1);
require_once __DIR__ . '/RecordSpreadsheet.php';

final class RecordController
{
    private RecordManager $manager;
    private RecordSpreadsheet $excel;

    public function __construct()
    {
        $this->manager = new RecordManager(db(), config()['DB_PREFIX']);
        $this->manager->migrate();
        $this->excel = new RecordSpreadsheet($this->manager);
    }

    private static function json(array $payload, int $status=200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function dispatch(string $route): void
    {
        if ($route === 'records_listing_api') {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') self::json(['ok'=>false,'message'=>'روش درخواست نامعتبر است.'],405);
            try { self::json(['ok'=>true,'listing'=>$this->manager->listing($_GET)]); }
            catch (DomainException $ex) { self::json(['ok'=>false,'message'=>$ex->getMessage()],422); }
        }
        if ($route === 'records_api') {
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'record') {
                $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
                if (!$id || $id < 1) self::json(['ok'=>false,'message'=>'شناسه رکورد نامعتبر است.'],422);
                $record=$this->manager->record((int)$id);
                if (!$record) self::json(['ok'=>false,'message'=>'رکورد یافت نشد.'],404);
                self::json(['ok'=>true,'record'=>$record]);
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') self::json(['ok'=>false,'message'=>'روش درخواست مجاز نیست.'],405);
            if (!valid_csrf()) self::json(['ok'=>false,'message'=>'توکن امنیتی منقضی شده است؛ صفحه را تازه‌سازی کنید.'],419);
            try {
                $action=(string)($_POST['action'] ?? '');
                switch ($action) {
                    case 'field_save':
                        $id = filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
                        $this->manager->saveField($_POST, $id && $id > 0 ? (int)$id : null);
                        break;
                    case 'field_delete':
                        $id = filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
                        if (!$id || $id < 1) throw new DomainException('شناسه فیلد نامعتبر است.');
                        $this->manager->deleteField((int)$id);
                        break;
                    case 'record_save':
                        $id = filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
                        $values = $_POST['v'] ?? [];
                        if (!is_array($values) || count($values)>200) throw new DomainException('ساختار مقادیر رکورد نامعتبر است.');
                        $this->manager->saveRecord($values, $id && $id>0 ? (int)$id : null);
                        break;
                    case 'record_delete':
                        $id = filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
                        if (!$id || $id<1) throw new DomainException('شناسه رکورد نامعتبر است.');
                        $this->manager->deleteRecord((int)$id);
                        break;
                    default:
                        self::json(['ok'=>false,'message'=>'عملیات نامعتبر است.'],400);
                }
                self::json(['ok'=>true,'message'=>'اطلاعات با موفقیت ذخیره شد.']);
            } catch (RecordValidationException $ex) {
                self::json(['ok'=>false,'message'=>$ex->getMessage(),'fields'=>$ex->fieldErrors],422);
            } catch (DomainException $ex) {
                self::json(['ok'=>false,'message'=>$ex->getMessage()],422);
            } catch (Throwable $ex) {
                error_log('[Record API] ' . $ex->getMessage());
                self::json(['ok'=>false,'message'=>'خطای داخلی در ذخیره اطلاعات.'],500);
            }
        }
        if ($route === 'records_export') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405);exit; }
            require_csrf();
            try {
                $file=$this->excel->exportAll();
                $filename='apple-records-' . gmdate('Ymd-His') . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($file));
                header('X-Content-Type-Options: nosniff');
                readfile($file);
                @unlink($file);
                exit;
            } catch (Throwable $ex) {
                error_log('[Record XLSX export] ' . $ex->getMessage());
                http_response_code(500);
                exit('تولید خروجی اکسل ناموفق بود. تنظیمات و حافظه سرور را بررسی کنید.');
            }
        }
        if ($route === 'records' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            require_csrf();
            $action=(string)($_POST['action'] ?? '');
            if ($action === 'import_preview') {
                $this->previewUpload();
            } elseif ($action === 'import_commit') {
                $this->commitUpload();
            } else {
                http_response_code(400);
                exit('عملیات نامعتبر است.');
            }
        }
    }

    private function previewUpload(): void
    {
        $oldStage = $_SESSION['record_import_preview'] ?? null;
        unset($_SESSION['record_import_preview']);
        if (is_array($oldStage) && isset($oldStage['path']) && is_string($oldStage['path']) &&
            str_starts_with($oldStage['path'], STORAGE_PATH . '/imports/')) {
            @unlink($oldStage['path']);
        }
        // Abandoned previews are not retained indefinitely. Only randomly
        // named staged uploads in this private directory are eligible.
        $importDir = STORAGE_PATH . '/imports';
        foreach (glob($importDir . '/*.xlsx') ?: [] as $abandoned) {
            if (preg_match('/^[a-f0-9]{48}\.xlsx$/D', basename($abandoned)) &&
                is_file($abandoned) && !is_link($abandoned) &&
                (time() - (int)filemtime($abandoned)) > 3600) {
                @unlink($abandoned);
            }
        }
        try {
            $upload=$_FILES['xlsx'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? -1) !== UPLOAD_ERR_OK ||
                !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                throw new DomainException('آپلود فایل انجام نشد؛ فایل xlsx معتبر انتخاب کنید.');
            }
            if (!preg_match('/\.xlsx$/i',(string)($upload['name'] ?? ''))) {
                throw new DomainException('تنها فرمت xlsx مجاز است.');
            }
            $size=(int)$upload['size'];
            if ($size<100 || $size>RecordSpreadsheet::MAX_BYTES) throw new DomainException('حداکثر حجم فایل ۲ مگابایت است.');
            $dir=STORAGE_PATH . '/imports';
            if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new DomainException('پوشه موقت امن قابل ایجاد نیست.');
            $path=$dir . '/' . bin2hex(random_bytes(24)) . '.xlsx';
            if (!move_uploaded_file($upload['tmp_name'],$path)) throw new DomainException('انتقال فایل ناموفق بود.');
            chmod($path,0600);
            try {
                $result=$this->excel->inspect($path);
                if (!$result['errors']) {
                    $token=bin2hex(random_bytes(24));
                    $_SESSION['record_import_preview']=[
                        'token'=>$token,'path'=>$path,'at'=>time(),'hash'=>hash_file('sha256',$path),
                        'schema'=>$this->excel->fingerprint(),'admin_id'=>(int)$_SESSION['admin_id'],
                    ];
                    $_SESSION['record_import_result']=[
                        'columns'=>$result['columns'],
                        'rows'=>array_slice($result['rows'],0,12),
                        'count'=>count($result['rows']),'errors'=>[],'warnings'=>array_slice($result['warnings'],0,25),'token'=>$token,
                    ];
                } else {
                    @unlink($path);
                    $_SESSION['record_import_result']=[
                        'columns'=>$result['columns'],'rows'=>array_slice($result['rows'],0,12),
                        'count'=>count($result['rows']),'errors'=>array_slice($result['errors'],0,100),
                        'warnings'=>array_slice($result['warnings'],0,25),'token'=>'',
                    ];
                }
            } catch (Throwable $ex) { @unlink($path);throw $ex; }
        } catch (DomainException $ex) {
            $_SESSION['record_import_result']=['columns'=>[],'rows'=>[],'count'=>0,'errors'=>[$ex->getMessage()],'warnings'=>[],'token'=>''];
        } catch (Throwable $ex) {
            error_log('[Record import preview] ' . $ex->getMessage());
            $_SESSION['record_import_result']=['columns'=>[],'rows'=>[],'count'=>0,'errors'=>['خواندن فایل اکسل ناموفق بود.'],'warnings'=>[],'token'=>''];
        }
        go('/index.php?route=records&preview=1');
    }

    private function commitUpload(): void
    {
        $stage=$_SESSION['record_import_preview'] ?? null;
        unset($_SESSION['record_import_preview']);
        $token=$_POST['token'] ?? null;
        try {
            if (!is_array($stage) || !is_string($token) || !hash_equals($stage['token'],$token) ||
                (int)$stage['admin_id'] !== (int)$_SESSION['admin_id'] ||
                time()-(int)$stage['at']>900 || !is_file($stage['path']) ||
                !hash_equals($stage['hash'],hash_file('sha256',$stage['path'])) ||
                !hash_equals($stage['schema'],$this->excel->fingerprint())) {
                throw new DomainException('اعتبار پیش‌نمایش تمام شده یا فیلدها تغییر کرده‌اند؛ فایل را دوباره بررسی کنید.');
            }
            $number=$this->excel->commit($stage['path']);
            unset($_SESSION['record_import_result']);
            @unlink($stage['path']);
            go('/index.php?route=records&imported=' . $number);
        } catch (DomainException $ex) {
            $_SESSION['record_import_result']=[
                'columns'=>[],'rows'=>[],'count'=>0,'errors'=>[$ex->getMessage()],
                'warnings'=>[],'token'=>'',
            ];
        } catch (Throwable $ex) {
            error_log('[Record import commit] ' . $ex->getMessage());
            $_SESSION['record_import_result']=[
                'columns'=>[],'rows'=>[],'count'=>0,
                'errors'=>['ثبت گروهی انجام نشد؛ هیچ رکورد یا مشتری ناقصی ذخیره نشد.'],
                'warnings'=>[],'token'=>'',
            ];
        }
        if (is_array($stage) && isset($stage['path']) && is_string($stage['path'])) @unlink($stage['path']);
        go('/index.php?route=records&preview=1');
    }

    private static function yn($bool): string
    {
        return (int)$bool ? '<span class="badge bg-green-lt text-green">بله</span>' : '<span class="badge bg-secondary-lt text-secondary">خیر</span>';
    }

    public function render(string $route): void
    {
        if ($route==='records_fields') $this->fieldsPage();
        else $this->recordsPage();
    }

    private function fieldsPage(): void
    {
        $fields=$this->manager->fields();
        ?>
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
          <p class="text-secondary mb-0">فیلدهای اختصاصی را بدون تغییر دیتابیس تعریف کنید. شناسه داخلی آن‌ها ثابت می‌ماند.</p>
          <button class="btn btn-primary flex-shrink-0" id="create-field">+ ایجاد فیلد جدید</button>
        </div>
        <section class="card"><div class="table-responsive">
        <table class="table table-vcenter card-table"><thead><tr><th>ترتیب</th><th>نام نمایشی</th><th>نوع فیلد</th><th>الزامی</th><th>در جدول</th><th>قابل فیلتر</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach ($fields as $f): ?>
        <tr><td><?=e((string)$f['sort_order'])?></td><td class="fw-semibold"><?=e($f['label'])?><?php if ((int)$f['is_system']): ?> <span class="badge bg-blue-lt">سیستمی</span><?php endif;?></td>
        <td><?= e(RecordManager::fieldTypes()[$f['type']]??$f['type'])?></td>
        <td><?=self::yn($f['required'])?></td><td><?=self::yn($f['show_in_table'])?></td><td><?=self::yn($f['filterable'])?></td>
        <td><span class="badge <?= (int)$f['active'] ? 'bg-green-lt text-green' : 'bg-yellow-lt text-yellow' ?>"><?= (int)$f['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td class="text-nowrap">
          <button type="button" class="btn btn-sm btn-outline-primary edit-field" data-field="<?=e(json_encode($f,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>">ویرایش</button>
          <?php if (!(int)$f['is_system']): ?><button type="button" class="btn btn-sm btn-outline-danger delete-field" data-id="<?=e((string)$f['id'])?>">حذف</button><?php endif;?>
        </td></tr>
        <?php endforeach; ?>
        <?php if (!$fields): ?><tr><td colspan="8" class="empty-row text-secondary text-center">هنوز فیلدی تعریف نشده است.</td></tr><?php endif;?>
        </tbody></table></div></section>
        <div class="modal modal-blur fade" id="field-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <form id="field-form" action="/index.php?route=records_api" method="post">
          <div class="modal-header"><h5 class="modal-title" id="field-modal-title">ایجاد فیلد جدید</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button></div>
          <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="field_save"><input type="hidden" name="id" value="">
          <div class="alert alert-danger d-none form-error" role="alert"></div>
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="f-label">نام نمایشی فیلد *</label><input id="f-label" class="form-control" name="label" maxlength="120" required></div>
            <div class="col-md-4"><label class="form-label" for="f-type">نوع فیلد *</label><select id="f-type" class="form-select" name="type" required>
            <?php foreach (RecordManager::fieldTypes() as $id=>$name): ?><option value="<?=e($id)?>"><?=e($name)?></option><?php endforeach;?></select></div>
            <div class="col-md-6"><label class="form-label" for="f-order">ترتیب نمایش</label><input id="f-order" class="form-control ltr-input" type="number" name="sort_order" value="100" min="-100000" max="100000" required></div>
            <div class="col-md-6"><label class="form-label" for="f-length">حداکثر طول متن کوتاه</label><input id="f-length" class="form-control ltr-input" type="number" name="max_length" value="255" min="1" max="4000" required></div>
            <div class="col-12" id="field-options-wrap"><label class="form-label" for="f-options">گزینه‌های انتخابی (هر گزینه در یک خط)</label><textarea id="f-options" name="options" class="form-control" rows="4"></textarea></div>
            <div class="col-md-6"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="required" value="1" id="f-required"><span class="form-check-label">الزامی بودن</span></label></div>
            <div class="col-md-6"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_in_table" value="1" id="f-visible" checked><span class="form-check-label">نمایش در جدول</span></label></div>
            <div class="col-md-6"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="filterable" value="1" id="f-filter"><span class="form-check-label">قابل فیلتر بودن</span></label></div>
            <div class="col-md-6"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="active" value="1" id="f-active" checked><span class="form-check-label">فیلد فعال باشد</span></label></div>
          </div></div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-primary">ذخیره فیلد</button></div>
        </form></div></div></div>
        <?php
    }

    private static function fieldControl(array $field, string $name, string $prefix='v'): void
    {
        $id=(int)$field['id']; $key=$field['field_key']; $type=$field['type'];
        $required=(int)$field['required'] ? ' required' : '';
        $nameAttr=$prefix.'['.$id.']';
        $common='id="value-'.$id.'" name="'.e($nameAttr).'" class="form-control"'.$required;
        echo '<div class="col-md-6 record-control"><label class="form-label" for="value-'.$id.'">'.e($field['label']).((int)$field['required']?' <span class="text-danger">*</span>':'').'</label>';
        if ($type==='textarea') {
            echo '<textarea '.$common.' rows="3" maxlength="10000"></textarea>';
        } elseif ($type==='select' || $type==='boolean') {
            echo '<select id="value-'.$id.'" name="'.e($nameAttr).'" class="form-select"'.($type==='select'?' data-hcg-select data-placeholder="انتخاب کنید"':'').$required.'><option value="">انتخاب کنید</option>';
            $options = $type==='boolean' ? ['1'=>'بله','0'=>'خیر'] : array_combine(RecordManager::options($field),RecordManager::options($field));
            foreach ($options as $value=>$label) echo '<option value="'.e((string)$value).'">'.e((string)$label).'</option>';
            echo '</select>';
        } else {
            $inputType=in_array($type,['number','money'],true)?'text':'text';
            $classes = in_array($type,['mobile','number','money'],true)?' form-control ltr-input':' form-control';
            $extra = match($type) {
                'mobile'=>' inputmode="tel" maxlength="25" placeholder="09123456789"',
                'number','money'=>' inputmode="decimal" placeholder="مثلاً 12345.50"',
                'date'=>' data-jalali-date placeholder="'.(date_display_mode()==='gregorian'?'۲۰۲۶/۱۰/۲۴ یا ۱۴۰۵/۰۸/۰۲':'۱۴۰۵/۰۸/۰۲ یا ۲۰۲۶/۱۰/۲۴').'"',
                'text'=>' maxlength="'.(int)$field['max_length'].'"',
                default=>'',
            };
            echo '<input type="'.$inputType.'" id="value-'.$id.'" name="'.e($nameAttr).'" class="'.trim($classes).'"'.$extra.$required.'>';
        }
        echo '<div class="text-danger small mt-1 field-error" data-error-field="'.$id.'"></div></div>';
    }

    private function recordsPage(): void
    {
        try {
            $this->manager->assertSystemFields();
            $ready=true; $issue='';
        } catch (DomainException $ex) { $ready=false;$issue=$ex->getMessage(); }
        $fields=$this->manager->activeFields();
        $preview=null;
        if (!empty($_GET['preview'])) {
            $preview=$_SESSION['record_import_result'] ?? null;
            unset($_SESSION['record_import_result']);
        }
        try { $listing=$this->manager->listing($_GET); }
        catch (DomainException $ex) {
            $listing=$this->manager->listing([]);
            echo '<div class="alert alert-danger">'.e($ex->getMessage()).'</div>';
        }
        $visible=array_values(array_filter($fields,static fn($f)=>(int)$f['show_in_table']===1));
        ?>
        <?php if (!$ready): ?><div class="alert alert-danger"><?=e($issue)?> <a href="/index.php?route=records_fields">مدیریت فیلدها</a></div><?php endif;?>
        <?php if (isset($_GET['imported'])): ?><div class="alert alert-success">تعداد <?=e((string)(int)$_GET['imported'])?> رکورد از اکسل با موفقیت ثبت شد.</div><?php endif;?>
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
          <button class="btn btn-primary" id="create-record" <?= $ready ? '' : 'disabled' ?>>+ ایجاد رکورد جدید</button>
          <form method="post" action="/index.php?route=records_export" class="m-0"><input type="hidden" name="_csrf" value="<?=e(csrf())?>"><button type="submit" class="btn btn-outline-success" <?= $ready ? '' : 'disabled' ?>>↓ خروجی اکسل (همه رکوردها)</button></form>
          <button type="button" class="btn btn-outline-primary" id="open-import" <?= $ready ? '' : 'disabled' ?>>↑ ورود از اکسل</button>
          <span class="text-secondary small ms-auto" id="record-total">رکوردها: <?=e((string)$listing['total'])?></span>
        </div>
        <section class="card mb-3"><div class="card-header"><h3 class="card-title">فیلتر و مرتب‌سازی پیشرفته</h3></div><div class="card-body">
        <form method="get" action="/index.php" id="record-filters"><input type="hidden" name="route" value="records"><div class="row g-3">
        <?php foreach ($fields as $f): if (!(int)$f['filterable'] && !in_array($f['field_key'],['customer_name','mobile','apple_email'],true)) continue;
            $id=(int)$f['id']; $type=$f['type'];
        ?>
          <?php if (in_array($type,['number','money','date'],true)): ?>
          <div class="col-sm-6 col-lg-3"><label class="form-label"><?=e($f['label'])?> (از)</label><input class="form-control <?= $type==='date'?'jalali-filter':'ltr-input'?>" <?= $type==='date'?'data-jalali-date':'' ?> name="filter_min[<?=$id?>]" value="<?=e((string)($_GET['filter_min'][$id]??''))?>" placeholder="<?= $type==='date'?'شمسی یا میلادی':'حداقل'?>"></div>
          <div class="col-sm-6 col-lg-3"><label class="form-label"><?=e($f['label'])?> (تا)</label><input class="form-control <?= $type==='date'?'jalali-filter':'ltr-input'?>" <?= $type==='date'?'data-jalali-date':'' ?> name="filter_max[<?=$id?>]" value="<?=e((string)($_GET['filter_max'][$id]??''))?>" placeholder="<?= $type==='date'?(date_display_mode()==='gregorian'?'۲۰۲۶/۱۰/۲۴':'۱۴۰۵/۰۸/۰۲'):'حداکثر'?>"></div>
          <?php elseif ($type==='select' || $type==='boolean'): ?>
          <div class="col-sm-6 col-lg-3"><label class="form-label"><?=e($f['label'])?></label><select class="form-select" data-hcg-select name="filter[<?=$id?>]"><option value="">همه</option>
            <?php $opts=$type==='boolean'?['1'=>'بله','0'=>'خیر']:array_combine(RecordManager::options($f),RecordManager::options($f));
            foreach ($opts as $value=>$label): ?>
            <option value="<?=e((string)$value)?>" <?= (string)($_GET['filter'][$id]??'')===(string)$value?'selected':''?>><?=e((string)$label)?></option>
            <?php endforeach; ?></select></div>
          <?php else: ?>
          <div class="col-sm-6 col-lg-3"><label class="form-label"><?=e($f['label'])?></label><input class="form-control" name="filter[<?=$id?>]" value="<?=e((string)($_GET['filter'][$id]??''))?>" maxlength="120" <?=in_array($f['field_key'],['customer_name','mobile','apple_email'],true)?'data-live-record-search="1"':''?>></div>
          <?php endif; ?>
        <?php endforeach; ?>
        <div class="col-sm-6 col-lg-3"><label class="form-label">تعداد در صفحه</label><select class="form-select" name="per_page" id="per-page">
        <?php foreach ([25,50,100] as $n): ?><option value="<?=$n?>" <?= $n===$listing['per_page']?'selected':'' ?>><?=$n?> رکورد</option><?php endforeach;?></select></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">اعمال فیلتر</button><a class="btn btn-outline-secondary" href="/index.php?route=records">پاک کردن فیلترها</a></div>
        </div></form><div id="record-live-status" aria-live="polite" class="text-secondary small mt-2"></div></div></section>
        <section class="card" id="record-grid"><div class="table-responsive"><table class="table table-vcenter card-table text-nowrap">
          <thead><tr><th>#</th>
          <?php foreach ($visible as $f):
              $params=$_GET; $params['route']='records'; $params['sort']=$f['id'];
              $same=(string)($_GET['sort']??'')===(string)$f['id'];
              $params['direction']=$same && ($_GET['direction']??'')==='asc'?'desc':'asc';
              $params['page']=1; ?>
              <th><a data-sort-field="<?=e((string)$f['id'])?>" data-sort-label="<?=e($f['label'])?>" href="/index.php?<?=e(http_build_query($params))?>"><?=e($f['label'])?><?= $same ? (($_GET['direction']??'')==='asc'?' ↑':' ↓'):'' ?></a></th>
          <?php endforeach;?>
          <th>عملیات</th></tr></thead><tbody id="record-table-body">
          <?php foreach ($listing['rows'] as $record): ?><tr><td class="text-secondary">#<?=e((string)$record['id'])?></td>
          <?php foreach ($visible as $field):
              $value=$record['values'][(int)$field['id']]??null;
              if ($field['type']==='date') $value=RecordManager::dateLabel($value);
              if ($field['type']==='boolean' && $value!==null && $value!=='') $value=(int)$value?'بله':'خیر';
              if ($field['type']==='money' && $value!==null && $value!=='') {
                  $parts=explode('.',(string)$value,2);
                  $parts[0]=preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$parts[0]);
                  $decimals=isset($parts[1]) ? rtrim($parts[1],'0') : '';
                  $value=$parts[0] . ($decimals!==''?'.'.$decimals:'');
              }
          ?><td><?=e($value===null || $value===''?'—':mb_strimwidth((string)$value,0,110,'…'))?></td><?php endforeach;?>
          <td><div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary edit-record" data-id="<?=e((string)$record['id'])?>">ویرایش</button><button type="button" class="btn btn-sm btn-outline-danger delete-record" data-id="<?=e((string)$record['id'])?>">حذف</button></div></td>
          </tr><?php endforeach;?>
          <?php if (!$listing['rows']): ?><tr><td class="empty-row text-center text-secondary" colspan="<?=count($visible)+2?>">رکوردی مطابق فیلترها وجود ندارد.</td></tr><?php endif;?>
          </tbody></table></div>
          <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="text-secondary" id="record-page-info">صفحه <?=e((string)$listing['page'])?> از <?=e((string)$listing['pages'])?> · مجموع <?=e((string)$listing['total'])?> رکورد</span>
            <div class="btn-group">
              <?php foreach ([-1,1] as $delta):
                  $params=$_GET; $params['route']='records'; $params['page']=$listing['page']+$delta; $enabled=$params['page']>=1 && $params['page']<=$listing['pages']; ?>
                <a data-record-page="<?=e((string)$params['page'])?>" class="btn btn-outline-secondary <?= $enabled?'':'disabled' ?>" href="<?= $enabled?'/index.php?'.e(http_build_query($params)):'#' ?>" <?= $enabled?'':'aria-disabled="true"' ?>><?= $delta<0?'صفحه قبل':'صفحه بعد' ?></a>
              <?php endforeach;?>
            </div>
          </div></section>
        <div class="modal modal-blur fade" id="record-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable record-modal-dialog"><div class="modal-content">
        <form id="record-form" method="post" action="/index.php?route=records_api">
        <div class="modal-header"><h5 class="modal-title" id="record-modal-title">ایجاد رکورد جدید</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="record_save"><input type="hidden" name="id" value="">
          <div class="alert alert-danger form-error d-none" role="alert"></div>
          <p class="text-secondary small">تاریخ ثبت به‌صورت خودکار ثبت می‌شود. شماره موبایل، شناسه یکتای مشتری است. تاریخ فیلدهای دلخواه را می‌توانید با تقویم شمسی یا میلادی انتخاب یا وارد کنید.</p><div class="row g-3">
          <?php foreach ($fields as $f): if ($f['field_key']==='created_at') continue; self::fieldControl($f,$f['label']); endforeach;?>
          </div>
        </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-primary">ذخیره رکورد</button></div>
        </form></div></div></div>
        <div class="modal modal-blur fade" id="import-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
          <form action="/index.php?route=records" method="post" enctype="multipart/form-data" id="import-form">
          <div class="modal-header"><h5 class="modal-title">ورود گروهی از اکسل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="import_preview">
            <p class="text-secondary">فقط XLSX، حداکثر ۲ مگابایت و ۱۰۰۰ ردیف. عناوین و ترتیب ستون‌ها باید دقیقاً مطابق فیلدهای فعال باشد.</p>
            <label class="form-label" for="xlsx">انتخاب فایل اکسل</label><input class="form-control" type="file" accept=".xlsx" id="xlsx" name="xlsx" required>
            <div class="mt-2 small text-secondary" id="file-name"></div>
            <p class="small text-secondary mt-3">ترتیب ستون‌ها: <?=e(implode(' ← ',array_column($fields,'label')))?></p>
          </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-primary">خواندن و پیش‌نمایش</button></div>
          </form>
        </div></div></div>
        <?php if (is_array($preview)): ?>
        <section class="card mt-4" id="import-preview"><div class="card-header"><h3 class="card-title">نتیجه بررسی فایل اکسل</h3></div><div class="card-body">
        <?php foreach ($preview['errors'] as $error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
        <?php foreach ($preview['warnings'] as $warn): ?><div class="alert alert-warning"><?=e($warn)?></div><?php endforeach;?>
        <p><?=e((string)$preview['count'])?> ردیف معتبر بررسی شد.</p>
        <?php if ($preview['rows']): ?><div class="table-responsive"><table class="table table-bordered"><thead><tr><th>ردیف</th><?php foreach ($preview['columns'] as $label): ?><th><?=e($label)?></th><?php endforeach;?></tr></thead><tbody>
        <?php $ordered=$fields; foreach ($preview['rows'] as $row): ?><tr><td><?=e((string)$row['excel_row'])?></td><?php foreach ($ordered as $f):
            $cell=$f['field_key']==='created_at'?$row['date']:($row['data'][(int)$f['id']]??''); ?>
            <td><?php
            $shown=(string)$cell;
            if ($f['type']==='date' && trim($shown)!=='') {
                $normalized=RecordManager::normalizeDate($shown);
                if ($normalized!==null) $shown=RecordManager::dateLabel($normalized);
            }
            echo e($shown);
            ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <?php if ($preview['token'] && !$preview['errors']): ?><form method="post" action="/index.php?route=records" class="mt-3">
          <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="import_commit">
          <input type="hidden" name="token" value="<?=e($preview['token'])?>">
          <button type="submit" class="btn btn-success" id="commit-import">ثبت نهایی <?=e((string)$preview['count'])?> رکورد (اتمیک)</button>
        </form><?php else: ?><button class="btn btn-success" disabled>ثبت نهایی غیرفعال است</button><?php endif;?>
        </div></section>
        <?php endif;?>
        <?php
    }
}
