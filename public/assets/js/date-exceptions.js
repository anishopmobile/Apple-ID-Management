'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form=document.getElementById('date-exception-form');
  if(!form) return;
  const inputs=[...form.querySelectorAll('.date-exemption-input:not(:disabled)')];
  const payload=document.getElementById('date-exception-payload');
  const status=document.getElementById('date-exception-count');
  const update=()=>{
    const selected=inputs.filter(c=>c.checked).map(c=>c.value);
    payload.value=JSON.stringify(selected);
    status.textContent=selected.length+' فیلد سفارشی برای نمایش دائمی شمسی انتخاب شده است.';
  };
  // Serialize the exact checked set (including an empty array) as a single
  // validated form value. Disabled system fields are always enforced in PHP.
  form.addEventListener('change',event=>{
    if(event.target.matches('.date-exemption-input')) update();
  });
  form.addEventListener('submit',update);
  update();
});
