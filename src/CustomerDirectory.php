<?php
declare(strict_types=1);

/** Read-only, server-paginated customer directory. All routes require an admin. */
final class CustomerDirectory
{
    private PDO $pdo;
    public function __construct() { $this->pdo = db(); }

    public function listing(array $request): array
    {
        $q = is_string($request['q'] ?? null) ? trim($request['q']) : '';
        if (mb_strlen($q) > 100) throw new DomainException('عبارت جستجو باید حداکثر ۱۰۰ کاراکتر باشد.');
        $perPage = filter_var($request['per_page'] ?? 25, FILTER_VALIDATE_INT);
        if (!in_array($perPage, [25, 50, 100], true)) $perPage = 25;
        $page = filter_var($request['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = $page === false ? 1 : max(1, $page);
        $customers = table('customers');
        $records = table('apple_records');

        $params = [];
        $where = '';
        if ($q !== '') {
            $escape = static fn(string $value): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
            $mobile = preg_replace('/[\s()\-]+/u', '', RecordManager::digits($q)) ?? $q;
            if (str_starts_with($mobile, '+98')) $mobile = '0' . substr($mobile, 3);
            if (str_starts_with($mobile, '0098')) $mobile = '0' . substr($mobile, 4);
            $mobile = ltrim($mobile, '+');
            $normalizedStored = "REPLACE(REPLACE(REPLACE(COALESCE(c.mobile,''),' ',''),'-',''),'+','')";
            $where = " WHERE (c.full_name LIKE ? ESCAPE '!' OR $normalizedStored LIKE ? ESCAPE '!')";
            $params = ['%' . $escape($q) . '%', '%' . $escape($mobile) . '%'];
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $customers . ' c' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, $page);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.id,c.full_name,c.mobile,c.created_at,COUNT(r.id) AS record_count FROM '
            . $customers . ' c LEFT JOIN ' . $records . ' r ON r.customer_id=c.id';
        // Existing installations without record_deletions must also work.
        $hasTombstones = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $hasTombstones->execute([config()['DB_PREFIX'] . 'record_deletions']);
        if ((int)$hasTombstones->fetchColumn() > 0) {
            $sql .= ' LEFT JOIN ' . table('record_deletions') . ' d ON d.record_id=r.id';
            $sql = str_replace('COUNT(r.id) AS record_count', 'COUNT(IF(d.record_id IS NULL,r.id,NULL)) AS record_count', $sql);
        }
        $sql .= $where . ' GROUP BY c.id,c.full_name,c.mobile,c.created_at ORDER BY c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['record_count'] = (int)$row['record_count'];
            $row['created_jalali'] = jdatetime((string)$row['created_at'], 'Y/m/d');
        }
        unset($row);
        return compact('rows', 'total', 'page', 'pages', 'perPage', 'q');
    }

    public function api(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $result = $this->listing($_GET);
            echo json_encode(['ok'=>true] + $result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (DomainException $ex) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'message'=>$ex->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function render(): void
    {
        $result = $this->listing([]);
        ?>
        <section class="card mb-3"><div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-6"><label for="customer-search-query" class="form-label">جستجوی لحظه‌ای</label>
              <input id="customer-search-query" class="form-control" type="search" autocomplete="off"
                placeholder="نام مشتری یا شماره موبایل مشتری را وارد کنید" maxlength="100" aria-controls="customer-list" aria-describedby="customer-live-status">
            </div>
            <div class="col-6 col-lg-3"><label for="customer-page-size" class="form-label">تعداد نمایش در صفحه</label>
              <select id="customer-page-size" class="form-select">
                <option value="25">۲۵ مشتری</option><option value="50">۵۰ مشتری</option><option value="100">۱۰۰ مشتری</option>
              </select>
            </div>
            <div class="col-6 col-lg-3"><label class="form-label" for="customers-excel-link">خروجی اکسل</label>
              <a id="customers-excel-link" class="btn btn-outline-success w-100" href="/index.php?route=customers_export">انتخاب فیلدها و خروجی Excel</a>
            </div>
          </div>
          <div id="customer-live-status" role="status" aria-live="polite" class="mt-3 text-secondary small">
            <?=e((string)$result['total'])?> مشتری ثبت شده است.
          </div>
        </div></section>
        <section class="card" id="customer-list">
          <div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th>شناسه</th><th>نام مشتری</th><th>شماره موبایل</th><th>تعداد رکوردها</th><th>تاریخ ثبت (شمسی)</th></tr></thead>
            <tbody id="customer-rows">
            <?php foreach ($result['rows'] as $row): ?>
            <tr><td class="text-secondary">#<?=e((string)$row['id'])?></td><td><?=e((string)$row['full_name'])?></td>
              <td class="ltr-input"><?=e((string)($row['mobile'] ?: '—'))?></td>
              <td><?=e((string)$row['record_count'])?></td><td><?=e($row['created_jalali'])?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span id="customer-page-info" class="text-secondary">صفحه ۱ از <?=e((string)$result['pages'])?> · مجموع <?=e((string)$result['total'])?> مشتری</span>
            <div class="btn-group"><button type="button" id="customer-prev" class="btn btn-outline-secondary" disabled>قبلی</button>
            <button type="button" id="customer-next" class="btn btn-outline-secondary" <?= $result['pages'] <= 1 ? 'disabled' : '' ?>>بعدی</button></div>
          </div>
        </section>
        <?php
    }
}
