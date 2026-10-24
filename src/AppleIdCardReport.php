<?php
declare(strict_types=1);
require_once __DIR__ . '/RecordManager.php';

/**
 * Read-only card report. Every live Apple ID record stays a separate row,
 * even when several records belong to the same customer.
 */
final class AppleIdCardReport
{
    private PDO $pdo;
    private RecordManager $manager;

    public function __construct()
    {
        $this->pdo = db();
        $this->manager = new RecordManager($this->pdo, config()['DB_PREFIX']);
        $this->manager->migrate();
    }

    public function listing(array $input): array
    {
        $query = is_string($input['q'] ?? null) ? trim($input['q']) : '';
        if (mb_strlen($query) > 120) {
            throw new DomainException('عبارت جستجو باید حداکثر ۱۲۰ کاراکتر باشد.');
        }
        $per = filter_var($input['per_page'] ?? 25, FILTER_VALIDATE_INT);
        if (!in_array($per, [25, 50, 100], true)) $per = 25;
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = $page === false ? 1 : max(1, $page);
        $requestedSort = is_string($input['sort'] ?? null) ? $input['sort'] : 'id';
        $sort = in_array($requestedSort, ['id','customer_name','mobile','apple_id'], true)
            ? $requestedSort : 'id';
        $direction = ($input['direction'] ?? '') === 'asc' ? 'asc' : 'desc';

        // The fixed field keys are never interpolated from user input.
        // EAV values keep the names/mobiles/Apple IDs originally captured per
        // record; fall back to existing legacy columns when those are absent.
        $name = "COALESCE(NULLIF(n.value_text,''),c.full_name)";
        $mobile = "COALESCE(NULLIF(m.value_text,''),c.mobile)";
        $apple = "COALESCE(NULLIF(a.value_text,''),r.apple_email)";
        $base = ' FROM ' . table('apple_records') . ' r'
            . ' JOIN ' . table('customers') . ' c ON c.id=r.customer_id'
            . ' LEFT JOIN ' . table('record_deletions') . ' tomb ON tomb.record_id=r.id'
            . ' LEFT JOIN ' . table('record_fields') . " fn ON fn.field_key='customer_name'"
            . ' LEFT JOIN ' . table('record_values') . ' n ON n.record_id=r.id AND n.field_id=fn.id'
            . ' LEFT JOIN ' . table('record_fields') . " fm ON fm.field_key='mobile'"
            . ' LEFT JOIN ' . table('record_values') . ' m ON m.record_id=r.id AND m.field_id=fm.id'
            . ' LEFT JOIN ' . table('record_fields') . " fa ON fa.field_key='apple_email'"
            . ' LEFT JOIN ' . table('record_values') . ' a ON a.record_id=r.id AND a.field_id=fa.id';

        $where = ' WHERE tomb.record_id IS NULL';
        $args = [];
        if ($query !== '') {
            $escape = static fn(string $text): string =>
                str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
            $digits = RecordManager::digits($query);
            $normalized = preg_replace('/[\s()\-]+/u','',$digits) ?? $digits;
            if (str_starts_with($normalized, '+98')) $normalized='0'.substr($normalized,3);
            if (str_starts_with($normalized, '0098')) $normalized='0'.substr($normalized,4);
            if (str_starts_with($normalized, '98') && strlen($normalized)===12) {
                $normalized='0'.substr($normalized,2);
            }
            $mobileExpression = "REPLACE(REPLACE(REPLACE(REPLACE($mobile,' ',''),'-',''),'+',''),'(', '')";
            $where .= " AND ($name LIKE ? ESCAPE '!' OR $apple LIKE ? ESCAPE '!'"
                . " OR $mobileExpression LIKE ? ESCAPE '!')";
            $args = [
                '%' . $escape($query) . '%',
                '%' . $escape($query) . '%',
                '%' . $escape($normalized) . '%',
            ];
        }

        $totalStmt = $this->pdo->prepare('SELECT COUNT(*)'.$base.$where);
        $totalStmt->execute($args);
        $total = (int)$totalStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $offset = ($page - 1) * $per;

        $orderExpression = [
            'id' => 'r.id',
            'customer_name' => $name,
            'mobile' => $mobile,
            'apple_id' => $apple,
        ][$sort];
        $sql = 'SELECT r.id AS id, ' . $name . ' AS customer_name, '
            . $mobile . ' AS mobile, ' . $apple . ' AS apple_id'
            . $base . $where . ' ORDER BY '
            . $orderExpression . ' ' . strtoupper($direction) . ',r.id DESC'
            . ' LIMIT ' . $per . ' OFFSET ' . $offset;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id'] = (int)$row['id'];
        unset($row);

        return compact('rows', 'total', 'page', 'pages', 'per', 'sort', 'direction');
    }

    public function api(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok'=>false,'message'=>'روش درخواست مجاز نیست.'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $result = $this->listing($_GET);
            echo json_encode(['ok'=>true]+$result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (DomainException $ex) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'message'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function render(): void
    {
        $result = $this->listing($_GET);
        $columns = ['customer_name'=>'نام مشتری','mobile'=>'موبایل مشتری','apple_id'=>'Apple ID'];
        ?>
        <section class="card mb-3">
          <div class="card-body">
            <div class="row g-3 align-items-end">
              <div class="col-12 col-lg-9">
                <label class="form-label" for="apple-card-search">جستجوی لحظه‌ای</label>
                <input type="search" id="apple-card-search" class="form-control"
                  placeholder="نام مشتری، شماره موبایل یا Apple ID را وارد کنید"
                  maxlength="120" autocomplete="off" aria-controls="apple-card-table"
                  aria-describedby="apple-card-status">
              </div>
              <div class="col-12 col-lg-3">
                <label class="form-label" for="apple-card-page-size">تعداد نمایش در صفحه</label>
                <select id="apple-card-page-size" class="form-select">
                  <option value="25">۲۵ رکورد</option>
                  <option value="50">۵۰ رکورد</option>
                  <option value="100">۱۰۰ رکورد</option>
                </select>
              </div>
            </div>
            <div class="mt-3 p-2 border rounded bg-light">
              <label class="form-check mb-0" for="apple-card-include-secrets">
                <input type="checkbox" class="form-check-input" id="apple-card-include-secrets" value="1">
                <span class="form-check-label fw-semibold">نمایش رمز عبور و پاسخ‌های امنیتی در فایل PDF</span>
              </label>
              <p class="small text-secondary mb-0 mt-2">
                حالت پیش‌فرض امن است: رمز عبور و پاسخ‌ها مخفی‌اند. در صورت فعال‌کردن این گزینه،
                اطلاعات حساس رکورد انتخاب‌شده داخل فایل دانلودی قرار می‌گیرد؛ از فایل محافظت کنید.
              </p>
            </div>
            <div id="apple-card-status" class="small text-secondary mt-3"
              role="status" aria-live="polite"><?=e((string)$result['total'])?> رکورد ثبت‌شده وجود دارد.</div>
          </div>
        </section>
        <section class="card" id="apple-card-table">
          <div class="table-responsive">
            <table class="table table-vcenter card-table text-nowrap">
              <thead><tr><th>#</th>
                <?php foreach ($columns as $key=>$label): ?>
                  <th><button type="button" class="btn btn-link p-0 text-decoration-none apple-card-sort"
                    data-sort="<?=e($key)?>" aria-label="مرتب‌سازی بر اساس <?=e($label)?>"
                    aria-pressed="false"><?=e($label)?><span class="apple-card-sort-indicator ms-1"></span></button></th>
                <?php endforeach; ?>
                <th>عملیات</th>
              </tr></thead>
              <tbody id="apple-card-rows">
              <?php foreach ($result['rows'] as $row): ?>
                <tr data-record-id="<?=e((string)$row['id'])?>">
                  <td class="text-secondary">#<?=e((string)$row['id'])?></td>
                  <td><?=e((string)($row['customer_name'] ?? '—'))?></td>
                  <td dir="ltr" class="text-start"><?=e((string)($row['mobile'] ?: '—'))?></td>
                  <td dir="ltr" class="text-start"><?=e((string)($row['apple_id'] ?: '—'))?></td>
                  <td><button type="button" class="btn btn-sm btn-outline-primary apple-card-pdf"
                    data-record-id="<?=e((string)$row['id'])?>">دریافت خروجی PDF</button></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$result['rows']): ?><tr class="apple-card-empty"><td colspan="5" class="text-center text-secondary empty-row">اطلاعاتی مطابق جستجو یافت نشد.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card-footer d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <span id="apple-card-page-info" class="text-secondary">
              صفحه <?=e((string)$result['page'])?> از <?=e((string)$result['pages'])?>
              · مجموع <?=e((string)$result['total'])?> رکورد
            </span>
            <nav id="apple-card-pages" class="d-flex flex-wrap gap-1" aria-label="صفحه‌بندی Apple ID Card">
              <button type="button" class="btn btn-outline-secondary" data-card-page="previous" disabled>قبلی</button>
              <button type="button" class="btn btn-outline-secondary" data-card-page="next"
                <?=$result['pages']<=1?'disabled':''?>>بعدی</button>
            </nav>
          </div>
        </section>
        <form id="apple-card-pdf-form" action="/index.php?route=reports_apple_id_card_pdf"
          method="post" target="_blank" class="d-none">
          <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
          <input type="hidden" name="record_id" id="apple-card-pdf-id" value="">
          <input type="hidden" name="include_sensitive" id="apple-card-pdf-sensitive" value="0">
        </form>
        <p class="text-secondary small mt-3">فایل PDF هر رکورد با ابعاد واقعی ۸۵ × ۵۵ میلی‌متر تولید می‌شود. برای چاپ بدون تغییر اندازه، گزینه Actual Size / اندازه واقعی را انتخاب کنید.</p>
        <?php
    }
}
