'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('restore-form');
  if (!form) return;
  form.addEventListener('submit', (event) => {
    if (!form.reportValidity()) return;
    const file = document.getElementById('restore-sql').files[0];
    if (!file || !/\.sql$/i.test(file.name)) {
      event.preventDefault();
      window.alert('فقط فایل SQL انتخاب کنید.');
      return;
    }
    if (file.size > 64 * 1024 * 1024) {
      event.preventDefault();
      window.alert('حجم فایل بیشتر از ۶۴ مگابایت است.');
      return;
    }
    if (!window.confirm('هشدار جدی: تمام اطلاعات کنونی دیتابیس با محتویات این فایل جایگزین می‌شود. آیا از بازیابی اطمینان دارید؟')) {
      event.preventDefault();
      return;
    }
    const button = document.getElementById('restore-submit');
    button.disabled = true;
    button.textContent = 'در حال بررسی و بازیابی...';
  });
});
