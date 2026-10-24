'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const rows = document.getElementById('customer-rows');
  if (!rows) return;
  const query = document.getElementById('customer-search-query');
  const by = document.getElementById('customer-search-by');
  const perPage = document.getElementById('customer-page-size');
  const status = document.getElementById('customer-live-status');
  const info = document.getElementById('customer-page-info');
  const next = document.getElementById('customer-next');
  const prev = document.getElementById('customer-prev');
  let page = 1, pages = 1, timer = null, ongoing = null, sequence = 0;

  function rowCell(value, cls = '') {
    const cell = document.createElement('td');
    cell.textContent = value;
    if (cls) cell.className = cls;
    return cell;
  }
  async function refresh() {
    const thisRequest = ++sequence;
    if (ongoing) ongoing.abort();
    ongoing = new AbortController();
    status.textContent = 'در حال جستجو...';
    try {
      const args = new URLSearchParams({
        route: 'customers_api', q: query.value, by: by.value,
        per_page: perPage.value, page: String(page)
      });
      const response = await fetch('/index.php?' + args, {
        signal: ongoing.signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('جستجوی مشتریان ناموفق بود.');
      const data = await response.json();
      if (thisRequest !== sequence) return;
      if (!data.ok) throw new Error(data.message || 'پاسخ سرور نامعتبر است.');
      page = data.page;
      pages = data.pages;
      rows.replaceChildren();
      for (const customer of data.rows) {
        const row = document.createElement('tr');
        row.append(
          rowCell('#' + customer.id, 'text-secondary'),
          rowCell(customer.full_name),
          rowCell(customer.mobile || '—', 'ltr-input'),
          rowCell(String(customer.record_count)),
          rowCell(customer.created_jalali || '—')
        );
        rows.append(row);
      }
      if (!data.rows.length) {
        const row = document.createElement('tr');
        const cell = rowCell('مشتری مطابق جستجو یافت نشد.', 'text-center text-secondary empty-row');
        cell.colSpan = 5;
        row.append(cell);
        rows.append(row);
      }
      info.textContent = 'صفحه ' + page + ' از ' + pages + ' · مجموع ' + data.total + ' مشتری';
      status.textContent = data.total + ' مشتری مطابق جستجو پیدا شد.';
      prev.disabled = page <= 1;
      next.disabled = page >= pages;
    } catch (error) {
      if (error.name !== 'AbortError' && thisRequest === sequence) {
        status.textContent = error.message || 'در دریافت اطلاعات خطا رخ داد.';
      }
    }
  }
  function restart() {
    page = 1;
    clearTimeout(timer);
    timer = setTimeout(refresh, 200);
  }
  query.addEventListener('input', restart);
  by.addEventListener('change', () => {
    query.placeholder = by.value === 'mobile' ? 'شماره موبایل را بنویسید...' : 'نام مشتری را بنویسید...';
    query.inputMode = by.value === 'mobile' ? 'tel' : 'text';
    restart();
  });
  perPage.addEventListener('change', restart);
  prev.addEventListener('click', () => { if (page > 1) { page--; refresh(); } });
  next.addEventListener('click', () => { if (page < pages) { page++; refresh(); } });
  refresh();
});
