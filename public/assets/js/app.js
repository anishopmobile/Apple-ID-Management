'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const target = document.getElementById('dashboard-chart');
  if (!target) return;
  if (typeof window.ApexCharts !== 'function') {
    target.textContent = 'بارگذاری نمودار ممکن نشد؛ فایل‌های محلی را دوباره بسازید.';
    return;
  }
  try {
    const labels = JSON.parse(target.dataset.labels);
    const records = JSON.parse(target.dataset.records);
    const customers = JSON.parse(target.dataset.customers);
    const chart = new window.ApexCharts(target, {
      chart: { type: 'line', height: 300, fontFamily: 'Vazirmatn, sans-serif', toolbar: { show: false }, animations: { enabled: true } },
      series: [{ name: 'اطلاعات ثبت‌شده', data: records }, { name: 'مشتریان', data: customers }],
      xaxis: { categories: labels, labels: { rotate: 0 } },
      yaxis: { min: 0, forceNiceScale: true, labels: { formatter: (value) => Math.round(value).toLocaleString('fa-IR') } },
      stroke: { curve: 'smooth', width: 3 },
      colors: ['#2563eb', '#0d9488'],
      dataLabels: { enabled: false },
      grid: { borderColor: '#ecf0f5' },
      markers: { size: 0, hover: { size: 6 } },
      tooltip: { shared: true, intersect: false },
      legend: { position: 'top', horizontalAlign: 'right' },
      noData: { text: 'اطلاعاتی وجود ندارد' }
    });
    chart.render();
  } catch (_) {
    target.textContent = 'اطلاعات نمودار قابل نمایش نیست.';
  }
});
