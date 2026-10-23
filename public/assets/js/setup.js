'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const db = document.querySelector('#database-form');
  const admin = document.querySelector('#admin-form');
  if (!db || !admin) return;
  const next = document.querySelector('#next-button');
  const back = document.querySelector('#back-button');
  const message = document.querySelector('#test-message');
  let tested = document.body.dataset.tested === '1';
  function setStep(second) {
    if (second && !tested) return;
    db.classList.toggle('d-none', second);
    admin.classList.toggle('d-none', !second);
    document.querySelector('#step-one').classList.toggle('is-active', !second);
    document.querySelector('#step-two').classList.toggle('is-active', second);
  }
  next.disabled = !tested;
  next.addEventListener('click', () => setStep(true));
  back.addEventListener('click', () => setStep(false));
  db.addEventListener('input', () => {
    tested = false;
    next.disabled = true;
    message.textContent = 'پس از تغییر تنظیمات، اتصال را دوباره آزمایش کنید.';
    message.className = 'alert alert-warning mt-3';
  });
  db.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!db.reportValidity()) return;
    const button = document.querySelector('#test-button');
    button.disabled = true;
    tested = false;
    next.disabled = true;
    message.className = 'alert alert-info mt-3';
    message.textContent = 'در حال بررسی ارتباط با پایگاه داده...';
    try {
      const response = await fetch('/install.php', {
        method: 'POST',
        body: new FormData(db),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) throw new Error('پاسخ نامعتبر از سرور.');
      const result = await response.json();
      tested = result.ok === true;
      next.disabled = !tested;
      message.className = 'alert alert-' + (tested ? 'success' : 'danger') + ' mt-3';
      message.textContent = result.message;
    } catch (_) {
      message.className = 'alert alert-danger mt-3';
      message.textContent = 'ارتباط با سرور برقرار نشد یا نشست منقضی شده است.';
    } finally {
      button.disabled = false;
    }
  });
  if (document.body.dataset.step === 'admin' && tested) setStep(true);
});
