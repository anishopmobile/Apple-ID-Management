'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const table = document.getElementById('apple-card-table');
  if (!table) return;

  const query = document.getElementById('apple-card-search');
  const perPage = document.getElementById('apple-card-page-size');
  const rows = document.getElementById('apple-card-rows');
  const status = document.getElementById('apple-card-status');
  const pageInfo = document.getElementById('apple-card-page-info');
  const pagination = document.getElementById('apple-card-pages');
  const sortButtons = [...table.querySelectorAll('.apple-card-sort')];

  const params = new URLSearchParams(window.location.search);
  let page = Math.max(1, Number(params.get('page')) || 1);
  let pages = 1;
  let sort = ['id', 'customer_name', 'mobile', 'apple_id'].includes(params.get('sort'))
    ? params.get('sort') : 'id';
  let direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  let serial = 0;
  let ongoing = null;
  let timer = null;

  const td = (value, className = '') => {
    const element = document.createElement('td');
    element.textContent = value ?? '—';
    if (className) element.className = className;
    return element;
  };

  function renderRow(record) {
    const row = document.createElement('tr');
    row.dataset.recordId = String(record.id);
    const name = td(record.customer_name || '—');
    const mobile = td(record.mobile || '—', 'text-start');
    const apple = td(record.apple_id || '—', 'text-start');
    mobile.dir = 'ltr';
    apple.dir = 'ltr';

    const controls = document.createElement('td');
    const pdf = document.createElement('button');
    pdf.type = 'button';
    pdf.className = 'btn btn-sm btn-outline-primary apple-card-pdf';
    pdf.dataset.recordId = String(record.id);
    pdf.textContent = 'دریافت خروجی PDF';
    controls.append(pdf);

    row.append(td('#' + record.id, 'text-secondary'), name, mobile, apple, controls);
    return row;
  }

  function pageButton(label, target, active = false) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline-secondary');
    button.dataset.cardPage = String(target);
    button.textContent = label;
    if (active) button.setAttribute('aria-current', 'page');
    return button;
  }

  function renderPages() {
    pagination.replaceChildren();
    const previous = pageButton('قبلی', page - 1);
    previous.disabled = page === 1;
    pagination.append(previous);

    const first = Math.max(1, Math.min(page - 2, pages - 4));
    const last = Math.min(pages, first + 4);
    for (let index = first; index <= last; index++) {
      pagination.append(pageButton(String(index), index, index === page));
    }
    const next = pageButton('بعدی', page + 1);
    next.disabled = page >= pages;
    pagination.append(next);
  }

  function renderSort() {
    for (const button of sortButtons) {
      const current = button.dataset.sort === sort;
      button.setAttribute('aria-pressed', current ? 'true' : 'false');
      const indicator = button.querySelector('.apple-card-sort-indicator');
      indicator.textContent = current ? (direction === 'asc' ? '↑' : '↓') : '';
    }
  }

  function urlParams(route = 'reports_apple_id_card_api') {
    const args = new URLSearchParams({
      route,
      q: query.value,
      per_page: perPage.value,
      page: String(page),
      sort,
      direction
    });
    return args;
  }

  async function refresh() {
    const current = ++serial;
    ongoing?.abort();
    ongoing = new AbortController();
    status.textContent = 'در حال جستجو و دریافت اطلاعات...';
    try {
      const response = await fetch('/index.php?' + urlParams(), {
        method: 'GET',
        credentials: 'same-origin',
        signal: ongoing.signal,
        headers: { Accept: 'application/json' }
      });
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('دریافت اطلاعات گزارش ناموفق بود.');
      }
      const result = await response.json();
      if (current !== serial) return;
      if (!result.ok || !Array.isArray(result.rows)) {
        throw new Error(result.message || 'پاسخ سرور نامعتبر است.');
      }
      page = result.page;
      pages = result.pages;
      sort = result.sort;
      direction = result.direction;

      rows.replaceChildren();
      for (const record of result.rows) rows.append(renderRow(record));
      if (!result.rows.length) {
        const empty = document.createElement('tr');
        empty.className = 'apple-card-empty';
        const cell = td('اطلاعاتی مطابق جستجو یافت نشد.', 'text-center text-secondary empty-row');
        cell.colSpan = 5;
        empty.append(cell);
        rows.append(empty);
      }

      pageInfo.textContent = 'صفحه ' + page + ' از ' + pages + ' · مجموع ' + result.total + ' رکورد';
      status.textContent = result.total + ' رکورد مطابق جستجو پیدا شد.';
      renderSort();
      renderPages();
      history.replaceState(null, '', '/index.php?' + urlParams('reports_apple_id_card'));
    } catch (error) {
      if (error.name !== 'AbortError' && current === serial) {
        status.textContent = error.message || 'ارتباط با سرور برقرار نشد.';
      }
    }
  }

  const restart = () => {
    page = 1;
    clearTimeout(timer);
    timer = setTimeout(refresh, 180);
  };
  query.addEventListener('input', restart);
  perPage.addEventListener('change', restart);
  for (const button of sortButtons) {
    button.addEventListener('click', () => {
      const requested = button.dataset.sort;
      direction = sort === requested && direction === 'asc' ? 'desc' : 'asc';
      sort = requested;
      page = 1;
      clearTimeout(timer);
      refresh();
    });
  }

  pagination.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-card-page]');
    if (!button || button.disabled) return;
    const target = Number(button.dataset.cardPage);
    if (target < 1 || target > pages || target === page) return;
    page = target;
    clearTimeout(timer);
    refresh();
  });

  table.addEventListener('click', (event) => {
    const button = event.target.closest('button.apple-card-pdf');
    if (!button) return;
    const id = button.dataset.recordId || '';
    if (!/^[1-9][0-9]{0,17}$/.test(id)) return;
    const form = document.getElementById('apple-card-pdf-form');
    const secretOption = document.getElementById('apple-card-include-secrets');
    document.getElementById('apple-card-pdf-id').value = id;
    document.getElementById('apple-card-pdf-sensitive').value = secretOption.checked ? '1' : '0';
    // Only a record identifier and CSRF token are sent. Sensitive fields are
    // fetched exclusively on the server and never exposed to the report DOM.
    form.requestSubmit();
  });

  refresh();
});
