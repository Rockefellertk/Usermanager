# پنل PHP مدیریت MikroTik User Manager برای cPanel

این پروژه یک پنل مستقل PHP 8.1 و MySQL برای مدیریت **MikroTik User Manager در RouterOS v7** است. نصب و اجرا به SSH، Composer، Python، Redis یا سرویس جانبی نیاز ندارد و تمام مراحل اولیه از طریق مرورگر و File Manager‌ هاست انجام می‌شود.

> این نسخه از `/user-manager` استفاده می‌کند و کاربران را در `/ppp secret` نمی‌سازد.

## امکانات فعلی

- رابط فارسی و انگلیسی، واکنش‌گرا و مناسب موبایل
- حالت روشن و Dark Mode با ذخیره انتخاب کاربر
- اتصال هم‌زمان به چند روتر RouterOS v7 از طریق REST API
- نمایش زنده User، Profile، Limitation، User Profile و Session
- واردکردن خودکار کاربران و Profileهای از قبل ساخته‌شده
- ساخت، ویرایش، فعال/غیرفعال، تمدید و حذف کاربران User Manager
- تعیین تعداد اتصال هم‌زمان با `shared-users`؛ برای حساب دوکاربره مقدار `2`
- ساخت Profile و Limitation و اتصال آن‌ها از طریق Profile Limitation
- تفکیک کامل Planها بر اساس روتر؛ هر روتر Planهای مستقل خودش را دارد
- فیلتر Planها پس از انتخاب Router در فرم Add User
- تعیین سرعت، حجم، اعتبار، قیمت و واحد پول Plan
- صدور، مشاهده و حذف فاکتور، ثبت پرداخت و گزارش درآمد
- نمایش کاربران آنلاین و جستجو/فیلتر لحظه‌ای
- دریافت مستقیم `rx-byte` و `tx-byte` از Interface با نام `Internet` و نوع PPPoE
- انتخاب ترافیک هر روتر یا Total همه روترها از داشبورد
- نمایش RX، TX، Total، مصرف امروز و نمودار ۲۴ ساعت اخیر
- پایش Session و Interface هر ۶۰ ثانیه و همگام‌سازی کامل هر ۵ دقیقه
- انقضای خودکار کاربران و تشخیص فاکتورهای معوق
- نقش‌های `superadmin`، `operator`، `billing` و `viewer`
- رمزنگاری اطلاعات حساس با AES-256-GCM، محافظت CSRF و Activity Log

## پیش‌نیازها

- PHP 8.1 یا جدیدتر
- MySQL 5.7+ یا MariaDB 10.3+
- افزونه‌های PHP: `pdo_mysql`، `curl`، `openssl` و `mbstring`
- RouterOS v7 با پکیج User Manager
- امکان اتصال خروجی هاست به IP و پورت `www` یا `www-ssl` روتر
- مجوزهای `read`، `write`، `policy` و `rest-api` برای کاربر REST

بسته‌بودن SSH مشکلی ایجاد نمی‌کند. مهم این است که سرور PHP بتواند به روتر متصل شود. بازشدن روتر در مرورگر کامپیوتر شما، دسترسی سرور هاست را ثابت نمی‌کند.

## نصب بدون SSH

1. در cPanel وارد **MySQL Databases** شوید.
2. دیتابیس و کاربر MySQL بسازید و **All Privileges** را فعال کنید.
3. PHP را روی نسخه 8.1 یا بالاتر قرار دهید و افزونه‌های لازم را فعال کنید.
4. ZIP را مثلاً در `public_html/usermanager` آپلود و Extract کنید.
5. آدرس `https://example.com/usermanager/install.php` را باز کنید.
6. مشخصات دیتابیس و مدیر اولیه را وارد کنید.
7. بعد از نصب موفق، `install.php` را با File Manager حذف کنید.
8. وارد پنل شوید و روتر را از قسمت **Routers** اضافه کنید.

اگر نصب‌کننده نتواند `config.php` را بنویسد، محتوای آماده فایل را نمایش می‌دهد. آن را کنار `index.php` ذخیره کنید.

## آماده‌سازی MikroTik User Manager

```routeros
/user-manager set enabled=yes use-profiles=yes
/user group add name=rest-panel policy=read,write,policy,rest-api
/user add name=usermanager group=rest-panel password="CHANGE-ME"
```

دسترسی `www` یا `www-ssl` را فقط به IP خروجی هاست محدود کنید. HTTP اطلاعات Basic Auth را رمزنگاری نمی‌کند؛ HTTPS انتخاب امن‌تری است.

اگر User Manager سرویس PPP را احراز هویت می‌کند، RADIUS و Accounting نیز باید درست تنظیم شده باشند. Session و مصرف کاربران فقط وقتی ثبت می‌شود که Accounting فعال باشد.

## ترتیب راه‌اندازی

1. روتر را اضافه و **Test** را اجرا کنید.
2. **Sync** را بزنید تا اطلاعات قبلی User Manager وارد شوند.
3. هنگام ساخت Plan ابتدا روتر را انتخاب کنید؛ Plan فقط روی همان روتر ساخته می‌شود.
4. در Add User روتر را انتخاب کنید؛ فقط Planهای همان روتر نمایش داده می‌شوند.
5. برای حساب چندکاربره مقدار **Simultaneous users** را تعیین کنید.
6. Cron یک‌دقیقه‌ای را فعال کنید.

## همگام‌سازی زنده

وقتی پنل در مرورگر باز است، Sessionها و Interface `Internet` هر ۶۰ ثانیه و User/Profile/Planها هر ۵ دقیقه همگام می‌شوند. برای کارکرد شبانه‌روزی، حتی وقتی مرورگر بسته است، Cron را فعال کنید.

## تنظیم Cron در cPanel

از **System Status** مسیر واقعی `cron.php` را کپی و زمان‌بندی را روی هر دقیقه قرار دهید:

```text
* * * * *
```

اگر Cron فقط PHP Script قبول می‌کند، مستقیماً این فایل را انتخاب کنید:

```text
/home/CPANEL_USERNAME/public_html/usermanager/cron.php
```

در اجرای مستقیم PHP نیازی به Token، `wget` یا `curl` نیست. برای سرویس Web Cron از URL دارای Token در System Status استفاده کنید و Token را محرمانه نگه دارید.

## ترافیک Interface

پنل فقط Interface با نام دقیق `Internet` و نوع PPPoE را پردازش می‌کند. Counterها از این مسیر خوانده می‌شوند:

```text
POST /rest/interface/print
rx-byte
tx-byte
```

در داشبورد می‌توانید یک روتر یا **Total Internet — all routers** را انتخاب کنید. مقادیر اصلی برحسب GiB و مطابق Counterهای RouterOS نمایش داده می‌شوند. نمودار از زمان فعال‌شدن پایش شروع می‌شود و اطلاعات ساعتی تا ۳۱ روز نگهداری می‌شود.

## ارتقای نسخه موجود

1. از دیتابیس و `config.php` پشتیبان بگیرید.
2. فایل‌های جدید را جایگزین کنید.
3. `config.php` را حذف یا بازنویسی نکنید.
4. پنل را باز و `Ctrl+F5` بزنید.

تغییرات لازم دیتابیس هنگام اجرای نسخه جدید خودکار ایجاد می‌شوند و نصب مجدد لازم نیست.

## امنیت و پشتیبان‌گیری

- `config.php` شامل اطلاعات دیتابیس، کلید رمزنگاری و Cron Token است.
- `encryption_key` را بعد از شروع استفاده تغییر ندهید.
- REST روتر را برای کل اینترنت باز نگذارید و فقط IP خروجی هاست را مجاز کنید.
- برای محیط عملیاتی از HTTPS و گواهی معتبر استفاده کنید.
- از MySQL و دیتابیس User Manager روتر مرتب پشتیبان بگیرید.

## عیب‌یابی

- **Connection timed out:** دسترسی خروجی هاست و مسیر شبکه را بررسی کنید.
- **401 Unauthorized:** نام کاربری، رمز یا Policyهای REST اشتباه است.
- **Bad Request:** متن Detail روتر را بررسی کنید.
- **Plan اشتباه نمایش داده می‌شود:** روترها را Sync کنید تا هر Profile به Router خودش متصل شود.
- **کاربر حذف‌شده هنوز دیده می‌شود:** تا همگام‌سازی بعدی صبر کنید یا Cron/Sync را اجرا کنید.
- **Session خالی است:** Accounting در RADIUS/NAS فعال نیست.
- **ترافیک صفر است:** Interface باید دقیقاً `Internet` و نوع آن PPPoE باشد؛ یک دوره ۶۰ ثانیه‌ای صبر کنید.
- **Cron فقط PHP قبول می‌کند:** مسیر مستقیم `cron.php` را وارد کنید، نه URL یا `wget`.
- **خطای دیتابیس:** نام دیتابیس و کاربر در cPanel معمولاً پیشوند حساب دارند.
- **خطای 500:** بخش Errors در cPanel، نسخه PHP و افزونه‌ها را بررسی کنید.
