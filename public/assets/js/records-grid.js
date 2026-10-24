'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form=document.getElementById('record-filters');
  const grid=document.getElementById('record-grid');
  if (!form || !grid) return;
  const tbody=document.getElementById('record-table-body');
  const pageInfo=document.getElementById('record-page-info');
  const total=document.getElementById('record-total');
  const status=document.getElementById('record-live-status');
  const initial=new URLSearchParams(location.search);
  let sort=initial.get('sort') || '';
  let direction=initial.get('direction') === 'asc' ? 'asc' : 'desc';
  let page=Math.max(1,Number(initial.get('page'))||1);
  let debounce=null, controller=null, sequence=0;

  function cell(value, className='') {
    const td=document.createElement('td');
    td.textContent=value;
    if(className)td.className=className;
    return td;
  }
  function display(field, input) {
    if (input===null || input===undefined || input==='') return '—';
    let text=String(input);
    if (field.type==='date') {
      if (/^\d{4}-\d\d-\d\d$/.test(text)) {
        const date=new Date(text+'T12:00:00Z');
        if (!Number.isNaN(date.getTime())) {
          text=new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
            year:'numeric',month:'2-digit',day:'2-digit',timeZone:'UTC'
          }).format(date).replaceAll('‏','').replaceAll(' ','');
        }
      }
    } else if (field.type==='boolean') text=String(input)==='1'?'بله':'خیر';
    else if (field.type==='money') {
      const match=/^(-?\d+)(?:\.(\d+))?$/.exec(text);
      if (match) text=match[1].replace(/\B(?=(\d{3})+(?!\d))/g,',')
        +(match[2] && match[2].replace(/0+$/,'')?'.'+match[2].replace(/0+$/,''):'');
    }
    return text.length > 110 ? text.slice(0,109)+'…' : text;
  }
  function queryString(pageToLoad=page, sortToUse=sort, directionToUse=direction) {
    const params=new URLSearchParams(new FormData(form));
    params.set('route','records');
    params.set('page',String(pageToLoad));
    if (sortToUse) {params.set('sort',sortToUse);params.set('direction',directionToUse);}
    else {params.delete('sort');params.delete('direction');}
    return params;
  }
  async function update() {
    const current=++sequence;
    controller?.abort();
    controller=new AbortController();
    const args=queryString();
    args.set('route','records_listing_api');
    status.textContent='در حال جستجو...';
    try {
      const response=await fetch('/index.php?'+args,{
        signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json'}
      });
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('دریافت جدول اطلاعات ناموفق بود.');
      }
      const result=await response.json();
      if (current!==sequence) return;
      if (!result.ok) throw new Error(result.message || 'فیلتر یا مرتب‌سازی معتبر نیست.');
      const listing=result.listing;
      page=listing.page;
      tbody.replaceChildren();
      const visible=listing.fields.filter(field=>Number(field.show_in_table)===1);
      for (const record of listing.rows) {
        const tr=document.createElement('tr');
        tr.append(cell('#'+record.id,'text-secondary'));
        for (const field of visible) tr.append(cell(display(field,record.values[field.id])));
        const controls=document.createElement('td');
        const actions=document.createElement('div');actions.className='d-flex gap-1';
        for(const [cls,label] of [['edit-record','ویرایش'],['delete-record','حذف']]) {
          const button=document.createElement('button');
          button.type='button';
          button.className='btn btn-sm btn-outline-'+(cls==='edit-record'?'primary ':'danger ')+cls;
          button.dataset.id=String(record.id);
          button.textContent=label;actions.append(button);
        }
        controls.append(actions);tr.append(controls);tbody.append(tr);
      }
      if (!listing.rows.length) {
        const tr=document.createElement('tr');
        const td=cell('رکوردی مطابق فیلترها وجود ندارد.','empty-row text-center text-secondary');
        td.colSpan=visible.length+2;tr.append(td);tbody.append(tr);
      }
      total.textContent='رکوردها: '+listing.total;
      pageInfo.textContent='صفحه '+listing.page+' از '+listing.pages+' · مجموع '+listing.total+' رکورد';
      const prev=grid.querySelector('[data-record-page]:first-child');
      const links=[...grid.querySelectorAll('[data-record-page]')];
      links.forEach((link,index)=>{
        const target=page+(index===0?-1:1);
        const enabled=target>=1&&target<=listing.pages;
        link.dataset.recordPage=String(target);
        link.classList.toggle('disabled',!enabled);
        link.setAttribute('aria-disabled',enabled?'false':'true');
        link.href=enabled?'/index.php?'+queryString(target):'#';
      });
      grid.querySelectorAll('a[data-sort-field]').forEach(a=>{
        const id=a.dataset.sortField;
        const selected=id===sort;
        a.textContent=a.dataset.sortLabel+(selected?(direction==='asc'?' ↑':' ↓'):'');
        const next=selected && direction==='asc'?'desc':'asc';
        a.href='/index.php?'+queryString(1,id,next);
      });
      history.replaceState(null,'','/index.php?'+queryString());
      status.textContent=listing.total+' رکورد مطابق جستجو نمایش داده شد.';
    } catch(error) {
      if(error.name!=='AbortError' && current===sequence)
        status.textContent=error.message || 'ارتباط با سرور برقرار نشد.';
    }
  }
  const live=()=>{ page=1;clearTimeout(debounce);debounce=setTimeout(update,180); };
  form.querySelectorAll('[data-live-record-search]').forEach(input=>input.addEventListener('input',live));
  form.addEventListener('submit',event=>{event.preventDefault();page=1;update();});
  grid.addEventListener('click',event=>{
    const sorter=event.target.closest('a[data-sort-field]');
    if(sorter){
      event.preventDefault();
      const selected=sorter.dataset.sortField;
      direction=(sort===selected && direction==='asc')?'desc':'asc';
      sort=selected;page=1;update();return;
    }
    const paging=event.target.closest('a[data-record-page]');
    if(paging){
      event.preventDefault();
      if(paging.classList.contains('disabled'))return;
      page=Number(paging.dataset.recordPage)||1;
      update();
    }
  });
});
