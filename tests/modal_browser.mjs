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
