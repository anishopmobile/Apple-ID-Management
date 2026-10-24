'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const textarea=document.getElementById('commitment-html');
  const mode=document.getElementById('commitment-editor-mode');
  const form=document.getElementById('commitment-settings-form');
  if(!textarea || !mode || !form) return;
  const editor=window.hugeRTE || window.hugerte;
  if(!editor || typeof editor.init!=='function') return; // safe textarea fallback

  const config=(level)=>({
    selector:'#commitment-html',
    base_url:'/assets/vendor/hugerte',
    suffix:'.min',
    height:360,
    directionality:'rtl',
    menubar:level==='advanced' ? 'edit format view' : false,
    statusbar:level==='advanced',
    promotion:false,
    branding:false,
    plugins:level==='advanced' ? 'lists advlist code directionality' : 'lists',
    toolbar:level==='advanced'
      ? 'undo redo | blocks | bold italic underline | alignright aligncenter alignleft | bullist numlist | outdent indent | ltr rtl | removeformat | code'
      : 'undo redo | bold italic underline | bullist numlist | removeformat',
    content_style:'body { font-family: Vazirmatn, Tahoma, sans-serif; font-size: 14px; direction:rtl; }',
    setup:instance=>{
      instance.on('change keyup undo redo',()=>instance.save());
    }
  });
  const init=()=>Promise.resolve(editor.init(config(mode.value))).catch(err=>{
    console.error('Commitment editor could not load; textarea remains available.',err);
  });
  mode.addEventListener('change',()=>{
    const current=editor.get('commitment-html');
    if(current) {
      if(current.initialized) textarea.value=current.getContent({format:'html'});
      current.remove();
    }
    init();
  });
  form.addEventListener('submit',()=>{
    const instance=editor.get('commitment-html');
    if (instance?.initialized) textarea.value=instance.getContent({format:'html'});
  });
  init();
});
