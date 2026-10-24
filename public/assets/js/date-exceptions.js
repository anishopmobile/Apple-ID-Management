'use strict';
// UI enhancement only: native named checkboxes are submitted directly.
// Saving date exceptions never depends on JavaScript, hidden JSON or a
// third-party widget. This script displays how many fields are checked.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('date-exception-form');
  if (!form) return;
  const items = [...form.querySelectorAll('input.date-exemption-input:not(:disabled)')];
  const summary = document.getElementById('date-exception-count');
  const refresh = () => {
    const selected = items.filter(input => input.checked).length;
    summary.textContent = selected + ' فیلد از ' + items.length +
      ' فیلد سفارشی برای نمایش دائمی شمسی انتخاب شده است.';
  };
  items.forEach(input => input.addEventListener('change', refresh));
  refresh();
});
