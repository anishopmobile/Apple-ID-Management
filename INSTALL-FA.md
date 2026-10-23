# راهنمای نصب بسته آماده | Apple ID Management

این فایل مخصوص بسته خروجی GitHub Actions است. بسته شامل vendor (کتابخانه‌های PHP)، فایل‌های CSS/JavaScript/فونت محلی و کد برنامه است. **برای نصب بسته در مقصد به Composer یا Node.js/npm نیازی ندارید.**

## دریافت بسته و اعتبارسنجی آن

1. وارد مخزن GitHub شوید و از بخش Actions، اجرای موفق workflow «PHP / MySQL / RTL assets checks» را باز کنید.
2. در بخش Artifacts فایل apple-id-management-ready-<SHA> را دانلود کنید. (ممکن است به ورود به GitHub نیاز باشد.)
3. ZIP دانلودی از Actions یک پوشش ZIP است؛ آن را باز کنید تا فایل‌های اصلی apple-id-management-<SHA>.zip، فایل sha256 و گزارش json را ببینید.
4. پیش از بازکردن بسته اصلی، SHA-256 آن را با مقدار فایل sha256 مقایسه کنید:
   - ویندوز PowerShell: Get-FileHash .\apple-id-management-<SHA>.zip -Algorithm SHA256
   - لینوکس/مک: sha256sum apple-id-management-<SHA>.zip
5. بسته اصلی را باز کنید. داخل آن پوشه apple-id-management و زیرپوشه‌های public، src، vendor و storage قرار دارند.

> توجه: Artifactهای Actions فقط برای مدتی محدود در GitHub باقی می‌مانند؛ نسخه موفق موردنظر را در محل امن نگه دارید.

## پیش‌نیازهای مقصد

- **PHP 8.2 یا بالاتر**؛ سازگاری در GitHub Actions با PHP 8.3 بررسی می‌شود.
- اکستنشن‌های PHP: pdo_mysql، mbstring، gd، zip، xml، intl و سایر پیش‌نیازهای پوشه vendor.
- سرور MySQL 8 یا سازگار، همراه دیتابیس از قبل ایجادشده.
- وب‌سرور Apache/Nginx و HTTPS برای درخواست‌های غیرمحلی.
- اجازه نوشتن PHP در پوشه storage و امکان حذف public/install.php پس از نصب.

**مهم:** بسته خود PHP، MySQL، وب‌سرور یا دیتابیس را نصب نمی‌کند. فایل‌های پیکربندی دیتابیس و اطلاعات مشتریان داخل ZIP توزیع نمی‌شوند.

## نصب روی ویندوز (مثلاً XAMPP/WAMP)

1. PHP/MySQL و Apache را نصب و افزونه‌های لازم را در php.ini فعال کنید.
2. فایل ZIP اصلی را در C:\sites\apple-id-management استخراج کنید؛ مسیر نهایی باید شامل C:\sites\apple-id-management\public\index.php باشد.
3. در Apache یک **VirtualHost با DocumentRoot پوشه public** بسازید، نه پوشه والد آن. برنامه بر پایه URLهای ریشه‌ای /index.php و /install.php کار می‌کند؛ باز کردن از زیرپوشه /apple-id-management/public مناسب نیست.
4. پوشه storage باید توسط PHP قابل نوشتن باشد. مجوزهای NTFS را محدود به حساب وب‌سرور کنید؛ chmod جایگزین کنترل NTFS نیست.
5. در MySQL دیتابیس و کاربر مخصوص پروژه بسازید. سایت را در مرورگر باز و راه‌انداز را تکمیل کنید.
6. پس از نصب، public/install.php نباید وجود داشته باشد؛ اگر PHP نتوانسته آن را حذف کند، دستی حذف کنید. storage/config.php را هرگز در پوشه عمومی کپی نکنید.

## انتقال به هاست لینوکسی

**حالت پیشنهادی:** فایل‌ها را در /home/account/apple-id-management/ استخراج و DocumentRoot دامنه را روی /home/account/apple-id-management/public تنظیم کنید.

**اگر هاست فقط public_html دارد:** محتویات public/ را داخل /home/account/public_html/ کپی و پوشه‌های src/، vendor/، storage/ را مستقیم در /home/account/ و **بیرون public_html** نگه دارید. ساختار مهم است چون PHP با dirname(__DIR__) فایل‌های داخلی را پیدا می‌کند. فایل‌های مخفی .htaccess را نیز منتقل کنید.

HTTPS را اجباری کنید. اگر HTTPS در reverse proxy تمام می‌شود، تنها از طریق تنظیمات امن سرور و بعد از اعتبارسنجی پروکسی، متغیر HTTPS را به PHP برسانید.

## بررسی نصب

- اولین بازدید سایت نصب دو مرحله‌ای را نشان می‌دهد.
- پس از تأیید اتصال MySQL، اطلاعات مدیر را ثبت کنید.
- صفحه ورود مدیر نمایش داده می‌شود و پس از ورود، داشبورد فارسی و نمودارها باز می‌شوند.
- فایل‌های public/assets/vendor از همان هاست لود می‌شوند و CDN لازم نیست.
- فایل‌های storage/config.php و storage/installed.lock باید وجود داشته باشند اما از طریق HTTP قابل دانلود نباشند.

## ارتقای پروژه پس از نصب

نسخهٔ جدید ممکن است با نصب‌کننده public/install.php تحویل داده شود؛ **هنگام بروزرسانی یک نصب موجود، فایل نصاب را به سرور فعال منتقل نکنید.** فقط فایل‌های اجرایی جدید را جایگزین و storage کنونی (شامل config و کلید و قفل نصب) را حفظ کنید. پیش از ارتقا از داده‌ها نسخه پشتیبان رمزنگاری‌شده بگیرید. تا زمان آماده‌سازی سیستم migration، تغییرات ساختار دیتابیس باید با برنامه ارتقای سازگار انجام شوند.
