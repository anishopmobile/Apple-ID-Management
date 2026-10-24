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
  assert.ok(fields >= 2, 'The record form should contain dynamically configured fields');
  await closeModal(page, '#record-modal');
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
  await closeModal(page, '#record-modal');
  console.log('[browser] mobile viewport field/record modals passed');

  assert.deepEqual(errors, [], 'Browser must not emit JavaScript or network console errors');
  console.log('[browser] all Tabler modal + RTL layout regression checks: PASS');
} finally {
  await browser.close();
}
