'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form=document.getElementById('customer-export-form');
  if (!form) return;
  const fields=[...form.querySelectorAll('.export-field-check')];
  const count=document.getElementById('export-selected-count');
  const submit=document.getElementById('export-submit');
  const redraw=() => {
    const n=fields.filter(x=>x.checked).length;
    count.textContent=n+' فیلد انتخاب شده است.';
    submit.disabled=n===0;
  };
  document.getElementById('export-select-all').addEventListener('click',()=>{
    fields.forEach(x=>{x.checked=true;});redraw();
  });
  document.getElementById('export-select-none').addEventListener('click',()=>{
    fields.forEach(x=>{x.checked=false;});redraw();
  });
  fields.forEach(x=>x.addEventListener('change',redraw));
  form.addEventListener('submit',event=>{
    if (!fields.some(x=>x.checked)) {
      event.preventDefault();
      window.alert('حداقل یک فیلد را برای خروجی انتخاب کنید.');
    }
  });
  redraw();
});
