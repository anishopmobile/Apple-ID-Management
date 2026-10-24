'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('customer-export-form');
  if (!form) return;

  const choices = Array.from(form.querySelectorAll('input.export-field-check[type="checkbox"]'));
  const selectAll = document.getElementById('export-select-all');
  const selectNone = document.getElementById('export-select-none');
  const counter = document.getElementById('export-selected-count');
  const submit = document.getElementById('export-submit');

  const refresh = () => {
    const selected = choices.reduce((total, input) => total + (input.checked ? 1 : 0), 0);
    counter.textContent = selected + ' فیلد از ' + choices.length + ' فیلد انتخاب شده است.';
    submit.disabled = selected === 0;
  };

  // Each checkbox is independent. Checkbox names are intentionally fields[]
  // so PHP receives only the IDs of the selected fields.
  form.addEventListener('change', (event) => {
    if (event.target.matches('input.export-field-check[type="checkbox"]')) refresh();
  });

  const setAll = (checked) => {
    for (const input of choices) input.checked = checked;
    refresh();
  };
  selectAll.addEventListener('click', (event) => {
    event.preventDefault();
    setAll(true);
  });
  selectNone.addEventListener('click', (event) => {
    event.preventDefault();
    setAll(false);
  });

  form.addEventListener('submit', (event) => {
    if (choices.every((input) => !input.checked)) {
      event.preventDefault();
      window.alert('حداقل یک فیلد را برای خروجی انتخاب کنید.');
    }
  });

  refresh();
});
