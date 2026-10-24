/**
 * Real Chromium regression check for the two dynamically opened Tabler modals
 * and the RTL desktop sidebar layout. Run against a freshly installed test app.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const base = process.argv[2] || 'http://127.0.0.1:8181';
const browser = await chromium.launch({ headless: true });
const errors = [];

async function openModal(page, button, selector) {
  await page.locator(button).click();
  const dialog = page.locator(selector);
  await dialog.waitFor({ state: 'visible', timeout: 10000 });
  assert.equal(await dialog.evaluate((node) => node.classList.contains('show')), true,
    'Modal was made visible with the real Tabler controller');
  assert.equal(await page.locator('.modal-backdrop.show').count(), 1,
    'A single backdrop must be rendered (no duplicate Bootstrap initialization)');
  return dialog;
}
async function closeModal(page, selector) {
  await page.locator(selector + ' [data-bs-dismiss="modal"]').first().click();
  await page.locator(selector).waitFor({ state: 'hidden', timeout: 10000 });
  // The dialog hides before Bootstrap's backdrop fade-out animation completes.
  // Wait for actual backdrop removal instead of asserting prematurely.
  await page.locator('.modal-backdrop').waitFor({ state: 'detached', timeout: 10000 });
  assert.equal(await page.locator('.modal-backdrop').count(), 0, 'Closing releases the backdrop');
}

try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (entry) => {
    if (entry.type() === 'error') errors.push(entry.text());
  });

  await page.goto(base + '/index.php?route=login');
  await page.locator('#username').fill('testadmin');
  await page.locator('#password').fill('Example-CI-Pass-1234');
  await Promise.all([
    page.waitForURL(/index\.php(?:\?|$)/),
    page.getByRole('button', { name: 'ورود به پنل مدیریت' }).click(),
  ]);
  console.log('[browser] administrator logged in');

  await page.goto(base + '/index.php?route=customers');
  await page.locator('#customer-search-query').fill('جستجوی زنده شماره 13');
  await page.locator('#customer-live-status').getByText('1 مشتری مطابق جستجو پیدا شد.').waitFor();
  assert.equal(await page.locator('#customer-rows tr').count(),1,
    'Live customer name search updates rows without refresh');
  const customerUrl=page.url();
  assert.equal(await page.locator('#customer-search-by').count(),0,'No separate customer search mode');
  assert.equal(await page.locator('#customer-search-query').getAttribute('placeholder'),
    'نام مشتری یا شماره موبایل مشتری را وارد کنید');
  await page.locator('#customer-search-query').fill('۰۹۳۳۰۰۰۰۰۱۳');
  await page.waitForFunction(() => document.querySelector('#customer-live-status')?.textContent?.includes('1 مشتری مطابق'));
  assert.equal(await page.locator('#customer-rows tr').count(),1,
    'Live search normalizes Persian mobile digits');
  assert.equal(page.url(),customerUrl,'Live filters must not navigate or refresh');
  await page.locator('#customer-search-query').fill('');
  await page.locator('#customer-page-size').selectOption('25');
  await page.waitForFunction(() => {
    const info=document.querySelector('#customer-page-info');
    return info?.textContent?.includes('صفحه 1 از') &&
      document.querySelector('#customer-prev')?.disabled === true &&
      document.querySelectorAll('#customer-rows tr').length === 25;
  });
  assert.equal(await page.locator('#customer-rows tr').count(),25,'25 latest customer rows visible');
  await page.locator('#customer-next').click();
  await page.waitForFunction(() => document.querySelector('#customer-page-info')?.textContent?.includes('صفحه 2 از'));
  assert.equal(page.url(),customerUrl,'Ajax pagination never reloads customer page');
  console.log('[browser] instant customer searches, 25 rows and next page: PASS');

  await page.goto(base + '/index.php?route=customers_export');
  const exportChecks = page.locator('#customer-export-form input.export-field-check');
  const exportCount = await exportChecks.count();
  assert.ok(exportCount > 3, 'Export selector includes configured dynamic fields');
  assert.equal(await exportChecks.evaluateAll(inputs =>
    inputs.filter(input => input.checked).length), 2,
    'Only name and mobile preselected to avoid secret leakage');

  // Real click, not programmatic .checked modification: one label must not
  // toggle all the other inputs. Tabler CSS and RTL labels are exercised.
  const ids = await exportChecks.evaluateAll(inputs => inputs.map(input => input.id));
  assert.equal(new Set(ids).size, exportCount, 'Every checkbox has a unique id');
  const firstUnchecked = page.locator('#customer-export-form input.export-field-check:not(:checked)').first();
  const firstFieldId = await firstUnchecked.getAttribute('id');
  const firstLabel = page.locator('label[for="' + firstFieldId + '"]');
  assert.equal(await firstLabel.count(), 1, 'Only one label targets each checkbox');
  await firstLabel.click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    3, 'Clicking a single field checks only that field, without selecting all');
  await firstLabel.click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    2, 'Clicking again deselects the same field');

  // Bulk controls should alter EVERY checkbox, and the live counter should
  // report precisely what is sent to the server.
  await page.locator('#export-select-all').click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    exportCount, 'Select all checks every box');
  assert.equal(await page.locator('#export-submit').isEnabled(), true);
  await page.locator('#export-select-none').click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    0, 'Clear selection unchecks every box');
  assert.equal(await page.locator('#export-submit').isDisabled(), true,
    'Empty field selection must not allow export');
  assert.match(await page.locator('#export-selected-count').innerText(), /^0 فیلد از /);

  // The actual checkbox can be clicked directly; selection remains isolated
  // and PHP's fields[] payload contains only the chosen IDs.
  await exportChecks.nth(0).click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    1, 'Direct checkbox click checks exactly one option');
  const selected = await page.locator('#customer-export-form').evaluate(form =>
    Array.from(new FormData(form).getAll('fields[]')));
  assert.equal(selected.length, 1, 'FormData includes only the one chosen field');
  assert.equal(await page.locator('#export-submit').isEnabled(), true);
  await exportChecks.nth(0).click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    0, 'Click again unchecks the sole field');

  await page.locator('#export-select-all').click();
  await firstLabel.click();
  assert.equal(await exportChecks.evaluateAll(inputs => inputs.filter(input => input.checked).length),
    exportCount - 1, 'Individual deselection after select-all works');
  assert.equal(await page.locator('#export-submit').isEnabled(), true);
  const source = await page.locator('script[src*="customers-export.js"]').getAttribute('src');
  assert.match(source, /customers-export\.js\?v=\d+/, 'Versioned script avoids stale cached handlers');
  console.log('[browser] isolated export checkbox and label clicks, bulk controls, FormData and cache-busted JS: PASS');

  await page.goto(base + '/index.php?route=settings');
  assert.equal(await page.locator('#settings-site-name').count(),1,'Site title editable');
  assert.equal(await page.locator('#settings-logo').count(),1,'Site logo upload available');
  assert.equal(await page.locator('#settings-current-password').count(),1,'Current password required');
  assert.equal(await page.locator('#settings-new-password').count(),1,'New password required');
  console.log('[browser] secure site settings forms available: PASS');
  assert.equal(await page.locator('#settings-date-mode').inputValue(),'jalali',
    'Existing users retain the default Jalali display setting');
  const allExceptions=page.locator('#date-exception-fields input.date-exemption-input');
  assert.ok(await allExceptions.count()>1,'Existing date fields listed in settings');
  const locked=allExceptions.locator('xpath=../input[@disabled]');
  const systemDate=page.locator('#date-exception-fields input[disabled]');
  assert.equal(await systemDate.count(),1,'System created-at exception is mandatory');
  assert.equal(await systemDate.isChecked(),true,'Created-at must always stay Jalali');
  const optional=page.locator('#date-exception-fields input.date-exemption-input:not([disabled])');
  assert.ok(await optional.count()>0,'Custom date fields can be individually exempted');
  const chosenId=await optional.first().getAttribute('value');
  await optional.first().check();
  assert.deepEqual(JSON.parse(await page.locator('#date-exception-payload').inputValue()),[chosenId],
    'Payload contains exactly the checked checkbox, not every field');
  await page.locator('#settings-date-mode').selectOption('gregorian');
  await page.locator('#date-display-settings button[type=submit]').click();
  assert.equal(await page.locator('#settings-date-mode').inputValue(),'gregorian',
    'Gregorian preference is stored after submitting settings form');
  assert.equal(await page.locator('#date-exception-fields input[value="'+chosenId+'"]').isChecked(),true,
    'Selected dynamic Jalali exception survives settings POST');
  assert.equal(await page.locator('#date-exception-fields input[disabled]').isChecked(),true);
  await page.goto(base + '/index.php?route=customers');
  assert.match(await page.locator('#customer-list thead').innerText(), /شمسی/,
    'System customer registration heading always Jalali despite Gregorian global mode');
  assert.equal(await page.locator('body').getAttribute('data-date-mode'),'gregorian');
  assert.match(await page.locator('#customer-list tbody tr').first().innerText(), new RegExp('[۰-۹]{4}/[۰-۹]{2}/[۰-۹]{2}'),
    'Customer date values remain Jalali under Gregorian mode');
  await page.goto(base + '/index.php?route=records');
  assert.equal(await page.locator('body').getAttribute('data-date-mode'),'gregorian');
  assert.ok((await page.locator('body').getAttribute('data-date-exempt-ids')).split(',').includes(chosenId),
    'AJAX client receives saved per-field IDs');
  await page.locator('#record-filters').evaluate(form => form.requestSubmit());
  await page.waitForFunction(() => document.querySelector('#record-live-status')?.textContent?.includes('رکورد مطابق'));
  const dateHeader=page.locator('#record-grid thead a[data-sort-label="تاریخ ثبت"]');
  assert.equal(await dateHeader.count(),1,'Record system created-at column is rendered');
  const createAtColumn=await dateHeader.evaluate(a => Array.from(a.closest('tr').children).indexOf(a.closest('th')));
  const createdValue=await page.locator('#record-table-body tr[data-record-id],#record-table-body tr').first().locator('td').nth(createAtColumn).innerText();
  assert.match(createdValue,new RegExp('[۰-۹]{4}/[۰-۹]{2}/[۰-۹]{2}'),
    'AJAX record system created_at remains Jalali even when general calendar is Gregorian');
  await page.goto(base + '/index.php?route=dashboard');
  assert.match(await page.locator('main').innerText(), /شمسی/,
    'Dashboard recent-record registration date stays Jalali');
  await page.goto(base + '/index.php?route=settings');
  await page.locator('label[for="date-exception-'+chosenId+'"]').click();
  assert.deepEqual(JSON.parse(await page.locator('#date-exception-payload').inputValue()),[],
    'Clicking a single label unchecks only that field, serializing an empty array');
  await page.locator('#date-display-settings button[type=submit]').click();
  assert.equal(await page.locator('#date-exception-fields input[value="'+chosenId+'"]').isChecked(),false,
    'Unchecking custom date exception is saved');
  assert.equal(await page.locator('#date-exception-payload').inputValue(),'[]',
    'Empty date exception list remains empty after reload');
  assert.equal(await page.locator('#date-exception-fields input[disabled]').isChecked(),true,
    'Permanent system exception cannot be unchecked');
  await page.locator('#settings-date-mode').selectOption('jalali');
  await page.locator('#date-display-settings button[type=submit]').click();
  assert.equal(await page.locator('#settings-date-mode').inputValue(),'jalali');
  console.log('[browser] system date always Jalali, custom exceptions selectable/persistent with Gregorian mode, record AJAX and dashboard: PASS');

  // The offline HugeRTE must initialize and keep an editable native textarea.
  await page.goto(base + '/index.php?route=settings');
  await page.waitForFunction(() => Boolean((window.hugeRTE || window.hugerte)?.get?.('commitment-html')));
  assert.equal(await page.locator('#commitment-editor-mode').inputValue(),'simple');
  await page.locator('#commitment-editor-mode').selectOption('advanced');
  await page.waitForFunction(() => Boolean((window.hugeRTE || window.hugerte)?.get?.('commitment-html')));
  const selfHosted=await page.evaluate(() => performance.getEntriesByType('resource').filter(r=>r.name.includes('hugerte')).map(x=>x.name));
  assert.ok(selfHosted.length>0 && selfHosted.every(url=>new URL(url).origin===new URL(base).origin),
    'All editor assets load only from this host');
  await page.evaluate(() => (window.hugeRTE || window.hugerte).get('commitment-html')
    .setContent('<h2>تعهدنامه مرورگر</h2><p><strong>آزمایش موفق</strong> چاپ PDF A4</p>'));
  await page.locator('#commitment-settings-form button[type=submit]').click();
  assert.equal(await page.locator('#commitment-editor-mode').inputValue(),'advanced',
    'Editor complexity mode persists');
  const savedCommitment=await page.locator('#commitment-html').inputValue();
  assert.match(savedCommitment,/تعهدنامه مرورگر/,'Formatted commitment text saves');

  await page.goto(base + '/index.php?route=reports_commitment');
  assert.equal(await page.locator('.nav-subitem[href="/index.php?route=reports_commitment"]').count(),1,
    'New reports submenu renders');
  assert.equal(await page.locator('#apple-card-table').getAttribute('data-report-kind'),'commitment',
    'Report reuses the same grid');
  assert.equal(await page.locator('#apple-card-rows tr[data-record-id]').count(),25,
    'Commitment report server paging matches Apple Card');
  await page.locator('#apple-card-search').fill('card-one@example.test');
  await page.waitForFunction(()=>document.querySelector('#apple-card-status')?.textContent?.includes('1 رکورد مطابق'));
  assert.equal(await page.locator('#apple-card-rows tr[data-record-id]').count(),1,
    'Commitment AJAX search works without reload');
  assert.match(page.url(),/route=reports_commitment/,'AJAX report retains commitment route');
  await page.locator('#apple-card-search').fill('');
  await page.waitForFunction(()=>document.querySelector('#apple-card-rows tr[data-record-id]')!==null);
  const commitmentAction=await page.locator('#apple-card-pdf-form').getAttribute('action');
  assert.equal(commitmentAction,'/index.php?route=reports_commitment_pdf');
  console.log('[browser] self-hosted HugeRTE simple/advanced editor and commitment AJAX report: PASS');

  // Apple ID Card is a new Reports submenu, not a customer aggregation:
  // one record equals one table row, with independently searched Apple IDs.
  await page.goto(base + '/index.php?route=reports_apple_id_card');
  assert.equal(await page.locator('.nav-subitem[href="/index.php?route=reports_apple_id_card"]').count(), 1,
    'Reports submenu contains an Apple ID Card link');
  assert.equal(await page.locator('#apple-card-rows tr[data-record-id]').count(), 25,
    'Initial report is server-rendered with exactly 25 recent records');
  assert.equal(await page.locator('#apple-card-page-size').inputValue(), '25');
  await page.evaluate(() => { window.__cardPageAlive = 'persistent'; });
  const cardQuery = page.locator('#apple-card-search');
  await cardQuery.fill('card-one@example.test');
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-status')?.textContent?.includes('1 رکورد مطابق جستجو')
  );
  assert.equal(await page.locator('#apple-card-rows tr[data-record-id]').count(),1,
    'Apple ID search matches an individual record');
  assert.match(await page.locator('#apple-card-rows tr[data-record-id]').first().innerText(),
    /card-one@example.test/);

  await cardQuery.fill('نام کارت اختصاصی');
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-status')?.textContent?.includes('1 رکورد مطابق جستجو')
  );
  assert.match(await page.locator('#apple-card-rows tr[data-record-id]').first().innerText(),
    /نام کارت اختصاصی/, 'Per-record customer name can be found in one search');
  await cardQuery.fill('۰۹۱۲۳۴۵۶۷۸۹');
  await page.waitForFunction(() => {
    const status = document.querySelector('#apple-card-status')?.textContent || '';
    return /[2-9]\d* رکورد مطابق جستجو/.test(status);
  });
  assert.equal(await page.evaluate(() => window.__cardPageAlive), 'persistent',
    'Live name/mobile/Apple ID searches do not reload the page');

  await cardQuery.fill('card-');
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-status')?.textContent?.includes('2 رکورد مطابق جستجو')
  );
  const sortApple = page.locator('#apple-card-table button[data-sort="apple_id"]');
  await sortApple.click();
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-table button[data-sort="apple_id"]')?.getAttribute('aria-pressed') === 'true' &&
    document.querySelector('#apple-card-rows tr[data-record-id] td:nth-child(4)')?.textContent?.includes('card-one@example.test')
  );
  assert.equal(await page.locator('#apple-card-rows tr[data-record-id]').count(),2);
  await sortApple.click();
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-rows tr[data-record-id] td:nth-child(4)')?.textContent?.includes('card-two@example.test')
  );
  assert.equal(await page.evaluate(() => window.__cardPageAlive), 'persistent',
    'Column sorting switches ASC/DESC over AJAX without full reload');

  await cardQuery.fill('');
  await page.waitForFunction(() => {
    const info=document.querySelector('#apple-card-page-info')?.textContent || '';
    return info.includes('صفحه 1 از') &&
      document.querySelectorAll('#apple-card-rows tr[data-record-id]').length === 25;
  });
  await page.locator('#apple-card-pages').getByRole('button', {name:'2',exact:true}).click();
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-page-info')?.textContent?.includes('صفحه 2 از')
  );
  assert.ok(await page.locator('#apple-card-rows tr[data-record-id]').count()>0,
    'Second page contains remaining report records');
  assert.equal(await page.evaluate(() => window.__cardPageAlive),'persistent',
    'AJAX page navigation does not reload the browser');

  await page.locator('#apple-card-page-size').selectOption('50');
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-page-info')?.textContent?.includes('صفحه 1 از 1') &&
    document.querySelectorAll('#apple-card-rows tr[data-record-id]').length > 25
  );
  await page.locator('#apple-card-page-size').selectOption('100');
  await page.waitForFunction(() =>
    document.querySelector('#apple-card-page-size')?.value === '100' &&
    document.querySelector('#apple-card-status')?.textContent?.includes('رکورد مطابق')
  );
  assert.ok(await page.locator('#apple-card-rows tr[data-record-id]').count()>25,
    'Up to 100 records can be shown on a single page');

  // Intercept requestSubmit only for browser behavior checks: the actual
  // authenticated PDF endpoint and geometry are exercised in the PHP/curl
  // smoke tests. This prevents the test browser from opening downloads.
  await page.evaluate(() => {
    document.querySelector('#apple-card-pdf-form').requestSubmit = () => {
      const form=document.querySelector('#apple-card-pdf-form');
      window.__cardPdfRequest = Object.fromEntries(new FormData(form));
    };
  });
  const requestedId=await page.locator('#apple-card-rows button.apple-card-pdf').first().getAttribute('data-record-id');
  await page.locator('#apple-card-rows button.apple-card-pdf').first().click();
  const safeRequest=await page.evaluate(() => window.__cardPdfRequest);
  assert.equal(safeRequest.record_id,requestedId,
    'PDF request selects precisely the clicked row');
  assert.equal(safeRequest.include_sensitive,'0','Secrets hidden by default');
  assert.ok(typeof safeRequest._csrf==='string' && safeRequest._csrf.length>=32,
    'PDF POST request sends administrator CSRF token');
  assert.equal(Object.keys(safeRequest).sort().join(','),
    '_csrf,include_sensitive,record_id','No password or answer in client PDF request');
  await page.locator('#apple-card-include-secrets').check();
  await page.locator('#apple-card-rows button.apple-card-pdf').first().click();
  const revealed=await page.evaluate(() => window.__cardPdfRequest);
  assert.equal(revealed.include_sensitive,'1','Explicit opt-in requests sensitive data');
  assert.equal(revealed.record_id,requestedId,'Opt-in does not change selected record');
  assert.equal(await page.evaluate(() => window.__cardPageAlive),'persistent',
    'PDF buttons do not trigger a report page refresh');
  console.log('[browser] Reports > Apple ID Card: live search, ASC/DESC, pagination and safe POST PDF actions: PASS');

  await page.goto(base + '/index.php?route=backup');
  assert.equal(await page.locator('#backup-directory').count(),1,'Backup folder input exists');
  assert.equal(await page.locator('#restore-form input[type=file]').count(),1,'Restore upload exists');
  assert.equal(await page.locator('#restore-password').count(),1,'Password verification required');
  assert.equal(await page.locator('#restore-form input[name=restore_ack]').count(),1,
    'Destructive restore acknowledgement required');
  console.log('[browser] secure backup and restore forms available: PASS');

  // Existing fixture has >30 active non-deleted records. Dashboard must show
  // only the latest six, regardless of the total database size.
  await page.goto(base + '/index.php');
  const recentIds = await page.locator('[data-dashboard-recent-record]').evaluateAll(
    (rows) => rows.map((row) => Number(row.dataset.dashboardRecentRecord))
  );
  assert.equal(recentIds.length, 6, 'Dashboard must display exactly 6 recent records');
  assert.deepEqual(recentIds, [...recentIds].sort((a, b) => b - a),
    'Dashboard recent records must be newest-first');
  console.log('[browser] dashboard last six records, descending IDs: PASS');

  await page.goto(base + '/index.php?route=records_fields');
  const dimensions = await page.evaluate(() => {
    const wrapper = document.querySelector('.page-wrapper');
    const sidebar = document.querySelector('.app-sidebar');
    if (!wrapper || !sidebar) throw new Error('Sidebar layout elements missing');
    return {
      marginRight: getComputedStyle(wrapper).marginRight,
      sidebarWidth: sidebar.getBoundingClientRect().width,
      sidebarLeft: sidebar.getBoundingClientRect().left,
      wrapperRight: wrapper.getBoundingClientRect().right,
      width: window.innerWidth,
      tablerModal: typeof window.tabler?.Modal,
      bootstrapModal: typeof window.bootstrap?.Modal,
    };
  });
  assert.equal(dimensions.marginRight, '0px', 'Tabler 15rem right margin is overridden');
  assert.ok(dimensions.wrapperRight < dimensions.width - 200 &&
    dimensions.wrapperRight > dimensions.width - 330,
    'Main content should start directly next to the sidebar (no 15rem extra gap)');
  assert.ok(Math.abs(dimensions.sidebarWidth - 250) < 1,
    'Sidebar width matches the reserved 250px exactly');
  assert.ok(Math.abs(dimensions.wrapperRight - dimensions.sidebarLeft) < 1,
    'No unintended gap remains between main content and the right sidebar');
  assert.equal(dimensions.tablerModal, 'function',
    'The local Tabler bundle exposes the Modal class as tabler.Modal');
  console.log('[browser] desktop RTL margin-right and sidebar alignment passed', dimensions);

  await openModal(page, '#create-field', '#field-modal');
  assert.equal(await page.locator('#f-label').isVisible(), true);
  await closeModal(page, '#field-modal');

  await openModal(page, '#create-field', '#field-modal');
  await page.locator('#f-label').fill('فیلد تست مرورگر');
  await page.locator('#f-type').selectOption('text');
  await page.locator('#f-order').fill('105');
  await page.locator('#field-modal button[type="submit"]').click();
  await page.getByText('فیلد تست مرورگر', { exact: true }).waitFor({ state: 'visible', timeout: 12000 });
  console.log('[browser] create-field modal opens/closes and saves to MySQL');

  await page.goto(base + '/index.php?route=records');
  await openModal(page, '#create-record', '#record-modal');
  const fields = await page.locator('#record-modal .record-control').count();
  assert.ok(fields >= 40, 'The long record form must include 40+ dynamic fields');
  const layout = await page.locator('#record-modal').evaluate((dialog) => {
    const form = dialog.querySelector('form');
    const content = dialog.querySelector('.modal-body');
    const footer = dialog.querySelector('.modal-footer');
    const bounds = footer.getBoundingClientRect();
    const bodyBounds = content.getBoundingClientRect();
    const formStyle = getComputedStyle(form);
    const bodyStyle = getComputedStyle(content);
    return {
      fields: dialog.querySelectorAll('.record-control').length,
      bodyHeight: content.clientHeight,
      bodyScrollHeight: content.scrollHeight,
      bodyOverflowY: bodyStyle.overflowY,
      formDisplay: formStyle.display,
      footerTop: bounds.top,
      footerBottom: bounds.bottom,
      bodyBottom: bodyBounds.bottom,
      viewportHeight: window.innerHeight,
    };
  });
  assert.equal(layout.formDisplay, 'flex', 'Nested form needs a constrained flex layout');
  assert.equal(layout.bodyOverflowY, 'auto', 'Modal body must be scrollable');
  assert.ok(layout.bodyScrollHeight > layout.bodyHeight + 200,
    'With many fields the inner modal content must have a scroll range');
  assert.ok(layout.footerBottom <= layout.viewportHeight && layout.footerTop >= 0,
    'Save/Cancel footer must remain within the viewport');
  assert.ok(layout.bodyBottom <= layout.footerTop + 2,
    'Only the modal content may scroll, never the action footer');
  const scrolled = await page.locator('#record-modal .modal-body').evaluate((body) => {
    body.scrollTop = body.scrollHeight;
    return body.scrollTop;
  });
  assert.ok(scrolled > 100, 'Modal body scrollTop should increase');
  // Verify real Save button works when the form has lots of fields and a
  // Gregorian date is entered into the existing dual-calendar datepicker.
  const recordIdOfDate = await page.locator('#record-modal input[data-jalali-date]').first()
    .getAttribute('name');
  assert.ok(recordIdOfDate, 'A real dynamic date input must be present');
  const allText = page.locator('#record-modal .record-control input[required]:not([inputmode]):not([data-jalali-date])');
  assert.ok(await allText.count() >= 1, 'System customer name is required');
  await allText.nth(0).fill('مشتری مرورگر خارجی');
  if (await allText.count() > 1) {
    await allText.nth(1).fill('متن تست تقویم میلادی');
  }
  await page.locator('#record-modal input[required][inputmode="tel"]').fill('09991234567');
  await page.locator('#record-modal textarea[required]').fill('تست مرورگر با فرم بلند');
  const decimals = page.locator('#record-modal input[required][inputmode="decimal"]');
  assert.equal(await decimals.count(), 2);
  await decimals.nth(0).fill('42');
  await decimals.nth(1).fill('142.25');
  await page.locator('#record-modal input[data-jalali-date]').first().fill('2026/10/24');
  const requiredSelects = page.locator('#record-modal select[required]');
  for (let idx = 0; idx < await requiredSelects.count(); idx++) {
    const select = requiredSelects.nth(idx);
    const option = await select.locator('option:not([value=""])').first().getAttribute('value');
    assert.ok(option, 'Required select has an option');
    await select.selectOption(option);
  }
  await page.locator('#record-modal .modal-footer button[type="submit"]').click();
  const savedCustomer = page.getByText('مشتری مرورگر خارجی', { exact: true }).first();
  await savedCustomer.waitFor({ state: 'visible', timeout: 15000 });
  assert.equal(await page.locator('#record-modal.show').count(), 0, 'Form closed after successful save');
  console.log('[browser] scrolling long record modal, fixed footer, and Gregorian date save: PASS');

  // The field remains a DATE in the DB, saved as canonical Gregorian ISO,
  // even though users are free to input Gregorian or Jalali.
  const newRow = page.locator('table tbody tr').filter({hasText:'مشتری مرورگر خارجی'}).first();
  const displayedId = (await newRow.locator('td').first().innerText()).trim().replace('#', '');
  const backend = await page.evaluate(async (id) => {
    const r=await fetch('/index.php?route=records_api&action=record&id='+encodeURIComponent(id));
    return r.json();
  },displayedId);
  assert.equal(backend.ok,true,'saved record must be retrievable');
  const dateFieldId=recordIdOfDate.match(/^v\[(\d+)\]$/)?.[1];
  assert.ok(dateFieldId);
  assert.equal(backend.record.values[dateFieldId], '2026-10-24',
    'Gregorian input must persist correctly to the normalized MySQL DATE');
  // Live search on system fields and column sort must NOT navigate.
  const filters=page.locator('#record-filters input[data-live-record-search]');
  assert.ok(await filters.count()>=3,'Customer name, mobile, and Apple ID live filters visible');
  const searchName=filters.first();
  const nonce=await page.evaluate(() => { window.__ajaxPagePersisted='alive';return location.pathname;});
  await searchName.fill('مشتری مرورگر خارجی');
  await page.waitForFunction(() => document.querySelector('#record-total')?.textContent?.includes('رکوردها: 1'));
  assert.equal(await page.locator('#record-table-body tr').count(),1,
    'Name live search returns the matching record without a button click');
  const sortLink=page.locator('#record-grid a[data-sort-field]').first();
  await sortLink.click();
  await page.waitForFunction(() => document.querySelector('#record-live-status')?.textContent?.includes('رکورد مطابق'));
  assert.equal(await page.evaluate(() => window.__ajaxPagePersisted),'alive',
    'Sorting columns uses AJAX, not full page navigation');
  assert.equal(await page.locator('#record-table-body tr').count(),1);
  await searchName.fill('');
  await page.waitForFunction(() => document.querySelector('#record-total')?.textContent?.includes('رکوردها: 1')===false);
  assert.equal(await page.evaluate(() => window.__ajaxPagePersisted),'alive',
    'Live search does not refresh the page');
  console.log('[browser] record live search and column sorting over AJAX: PASS');

  await openModal(page, '#open-import', '#import-modal');
  await closeModal(page, '#import-modal');
  console.log('[browser] create-record and Excel import modals open and close correctly');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '/index.php?route=records_fields');
  await openModal(page, '#create-field', '#field-modal');
  const geometry = await page.locator('#field-modal .modal-dialog').boundingBox();
  assert.ok(geometry && geometry.x >= -3 && geometry.x + geometry.width <= 393,
    'Mobile field modal must fit the viewport');
  await closeModal(page, '#field-modal');
  await page.goto(base + '/index.php?route=records');
  await openModal(page, '#create-record', '#record-modal');
  const mobileModal = await page.locator('#record-modal').evaluate((dialog) => {
    const body=dialog.querySelector('.modal-body');
    const rect=dialog.querySelector('.modal-footer').getBoundingClientRect();
    return { footerBottom:rect.bottom, footerTop:rect.top, viewportHeight:innerHeight,
      scrollHeight:body.scrollHeight, clientHeight:body.clientHeight };
  });
  assert.ok(mobileModal.scrollHeight>mobileModal.clientHeight + 100,
    'Record modal scrolls on small screens');
  assert.ok(mobileModal.footerTop>=0 && mobileModal.footerBottom<=mobileModal.viewportHeight,
    'Save/Cancel buttons remain visible on mobile');
  // Use Cancel button at the bottom, not the header close button.
  await page.locator('#record-modal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#record-modal').waitFor({state:'hidden'});
  await page.locator('.modal-backdrop').waitFor({state:'detached'});
  console.log('[browser] mobile viewport field/record modals passed');

  assert.deepEqual(errors, [], 'Browser must not emit JavaScript or network console errors');
  console.log('[browser] all Tabler modal + RTL layout regression checks: PASS');
} finally {
  await browser.close();
}
