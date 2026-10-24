'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const apiUrl = '/index.php?route=records_api';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  // Tabler 1.4 bundles Bootstrap components but exposes them as
  // window.tabler, NOT window.bootstrap. Never load Bootstrap a second time:
  // duplicate bundles register duplicate click/backdrop handlers.
  const modal = (id) => {
    const element = document.getElementById(id);
    const Modal = window.tabler?.Modal;
    if (!element || typeof Modal?.getOrCreateInstance !== 'function') {
      throw new Error('امکان نمایش فرم وجود ندارد؛ فایل محلی Tabler را بررسی کنید.');
    }
    return Modal.getOrCreateInstance(element);
  };
  const resetErrors = (form) => {
    form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
    const main = form.querySelector('.form-error');
    if (main) { main.classList.add('d-none'); main.textContent = ''; }
  };
  const showError = (form, message, fields = {}) => {
    const main = form.querySelector('.form-error');
    if (main) { main.classList.remove('d-none'); main.textContent = message; }
    for (const [id, explanation] of Object.entries(fields)) {
      const target = form.querySelector('[data-error-field="' + id + '"]');
      if (target) target.textContent = explanation;
    }
  };
  async function submitApi(body) {
    const response = await fetch(apiUrl, {
      method: 'POST', body, credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const format = response.headers.get('content-type') || '';
    if (!format.includes('application/json')) throw new Error('پاسخ سرور معتبر نیست؛ دوباره تلاش کنید.');
    const result = await response.json();
    if (!result.ok) {
      const ex = new Error(result.message || 'عملیات انجام نشد.');
      ex.fields = result.fields || {};
      throw ex;
    }
    return result;
  }
  async function submitForm(form) {
    if (!form.reportValidity()) return;
    resetErrors(form);
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
      await submitApi(new FormData(form));
      window.location.reload();
    } catch (ex) {
      showError(form, ex.message || 'ارتباط با سرور برقرار نشد.', ex.fields);
    } finally { if (button) button.disabled = false; }
  }
  const fieldForm = document.getElementById('field-form');
  if (fieldForm) {
    const type = fieldForm.querySelector('#f-type');
    const optionWrap = fieldForm.querySelector('#field-options-wrap');
    const updateChoices = () => {
      optionWrap.classList.toggle('d-none', type.value !== 'select');
      fieldForm.querySelector('#f-length').closest('.col-md-6').classList.toggle('d-none', type.value !== 'text');
    };
    type.addEventListener('change', updateChoices);
    document.getElementById('create-field')?.addEventListener('click', () => {
      fieldForm.reset();
      fieldForm.elements.id.value = '';
      type.disabled = false;
      fieldForm.querySelector('#f-required').disabled = false;
      fieldForm.querySelector('#f-active').disabled = false;
      document.getElementById('field-modal-title').textContent = 'ایجاد فیلد جدید';
      resetErrors(fieldForm);
      updateChoices();
      modal('field-modal').show();
    });
    document.querySelectorAll('.edit-field').forEach((button) => {
      button.addEventListener('click', () => {
        const data = JSON.parse(button.dataset.field);
        fieldForm.reset();
        fieldForm.elements.id.value = data.id;
        fieldForm.elements.label.value = data.label;
        fieldForm.elements.type.value = data.type;
        fieldForm.elements.sort_order.value = data.sort_order;
        fieldForm.elements.max_length.value = data.max_length;
        const options = JSON.parse(data.options_json || '[]');
        fieldForm.elements.options.value = options.join('\n');
        for (const name of ['required', 'active', 'filterable', 'show_in_table']) {
          fieldForm.elements[name].checked = Number(data[name]) === 1;
        }
        const system = Number(data.is_system) === 1;
        type.disabled = system;
        fieldForm.querySelector('#f-required').disabled = system;
        fieldForm.querySelector('#f-active').disabled = system;
        document.getElementById('field-modal-title').textContent = 'ویرایش فیلد «' + data.label + '»';
        resetErrors(fieldForm);
        updateChoices();
        modal('field-modal').show();
      });
    });
    // Disabled inputs must still be sent on system field edits.
    fieldForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const sys = !!type.disabled;
      type.disabled = false;
      const required = fieldForm.querySelector('#f-required');
      const active = fieldForm.querySelector('#f-active');
      required.disabled = false; active.disabled = false;
      submitForm(fieldForm).finally(() => {
        if (sys) { type.disabled = true; required.disabled = true; active.disabled = true; }
      });
    });
    document.querySelectorAll('.delete-field').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm('فیلد حذف منطقی می‌شود و اطلاعات قبلی در دیتابیس محفوظ می‌ماند. ادامه می‌دهید؟')) return;
        button.disabled = true;
        const body = new FormData();
        body.append('_csrf', csrf); body.append('action', 'field_delete'); body.append('id', button.dataset.id);
        try { await submitApi(body); window.location.reload(); }
        catch (ex) { window.alert(ex.message); button.disabled = false; }
      });
    });
  }

  const recordForm = document.getElementById('record-form');
  if (recordForm) {
    function toJalali(iso) {
      if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '';
      const result = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
        year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC'
      }).format(new Date(iso + 'T12:00:00Z'));
      return result.replaceAll('‏', '').replaceAll(' ', '');
    }
    function refreshSelects() {
      if (typeof window.hcgSelect !== 'function') return;
      recordForm.querySelectorAll('select[data-hcg-select]').forEach((element) => {
        try { window.hcgSelect(element).refresh(); } catch (_) { /* progressive enhancement */ }
      });
    }
    document.getElementById('create-record')?.addEventListener('click', () => {
      recordForm.reset();
      recordForm.elements.id.value = '';
      resetErrors(recordForm);
      document.getElementById('record-modal-title').textContent = 'ایجاد رکورد جدید';
      refreshSelects();
      modal('record-modal').show();
    });
    document.querySelectorAll('.edit-record').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          const url = apiUrl + '&action=record&id=' + encodeURIComponent(button.dataset.id);
          const response = await fetch(url,{credentials:'same-origin'});
          const result = await response.json();
          if (!result.ok) throw new Error(result.message || 'رکورد پیدا نشد.');
          recordForm.reset();
          recordForm.elements.id.value = result.record.id;
          for (const [id,value] of Object.entries(result.record.values)) {
            const input = recordForm.elements.namedItem('v['+id+']');
            if (!input) continue;
            input.value = input.hasAttribute('data-jalali-date') ? toJalali(value) : (value ?? '');
          }
          document.getElementById('record-modal-title').textContent = 'ویرایش رکورد شماره ' + result.record.id;
          resetErrors(recordForm);
          refreshSelects();
          modal('record-modal').show();
        } catch (ex) { window.alert(ex.message || 'خطا در دریافت رکورد.'); }
        finally { button.disabled = false; }
      });
    });
    recordForm.addEventListener('submit', (event) => { event.preventDefault(); submitForm(recordForm); });
    document.querySelectorAll('.delete-record').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!window.confirm('این رکورد به‌صورت منطقی حذف می‌شود. اطلاعات مشتری حذف نخواهد شد. ادامه می‌دهید؟')) return;
        button.disabled = true;
        const body = new FormData();
        body.append('_csrf',csrf); body.append('action','record_delete'); body.append('id',button.dataset.id);
        try { await submitApi(body); window.location.reload(); }
        catch (ex) { window.alert(ex.message); button.disabled = false; }
      });
    });
    document.getElementById('open-import')?.addEventListener('click', () => modal('import-modal').show());
    document.getElementById('xlsx')?.addEventListener('change', (event) => {
      document.getElementById('file-name').textContent = event.target.files[0]?.name || '';
    });
    document.getElementById('per-page')?.addEventListener('change', () => {
      const form = document.getElementById('record-filters');
      form.querySelectorAll('[name=page]').forEach((input) => input.remove());
      form.requestSubmit();
    });
    document.querySelectorAll('[data-jalali-date]').forEach((field) => {
      if (typeof window.jQuery === 'function' && typeof window.jQuery.fn.persianDatepicker === 'function') {
        try {
          window.jQuery(field).persianDatepicker({
            format: 'YYYY/MM/DD', autoClose: true, initialValue: false,
            observer: false, calendar: { persian: { locale: 'fa' } }
          });
        } catch (error) { /* text input remains usable if plugin fails */ }
      }
    });
  }
  const preview = document.getElementById('import-preview');
  if (preview) requestAnimationFrame(() => preview.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  const commit = document.getElementById('commit-import');
  if (commit) commit.closest('form').addEventListener('submit', () => {
    commit.disabled = true;
    commit.textContent = 'در حال ثبت اطلاعات...';
  });
  const importForm = document.getElementById('import-form');
  if (importForm) importForm.addEventListener('submit', () => {
    const btn = importForm.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'در حال بررسی فایل...'; }
  });
});
