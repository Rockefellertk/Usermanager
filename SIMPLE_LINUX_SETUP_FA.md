# راه‌اندازی ساده روی سرور لینوکس (بدون SSL - فعلاً)

این راهنما ساده‌ترین مسیر برای بالا آوردن پنل روی سرور لینوکس‌تونه، **بدون Docker و بدون Nginx و بدون SSL**.
فقط با آدرس `http://IP-سرور:8000` بهش دسترسی پیدا می‌کنید. هروقت دامنه و گواهی SSL آماده شد،
در انتهای همین فایل توضیح دادم چطور با یک تغییر ساده فعالش کنید.

> فرض: سرور لینوکس Ubuntu/Debian هست و به آن با SSH دسترسی root یا sudo دارید.

---

## مرحله ۱: نصب پیش‌نیازها

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip redis-server unzip
sudo systemctl enable --now redis-server
```

## مرحله ۲: آپلود و استخراج پروژه

فایل `mikrotik-usermanager.zip` رو روی سرور کپی کنید (با `scp` از سیستم خودتون، یا آپلود مستقیم)، بعد:

```bash
mkdir -p ~/mikrotik-usermanager
cd ~/mikrotik-usermanager
unzip ~/mikrotik-usermanager.zip -d .
```

## مرحله ۳: محیط پایتون و نصب پکیج‌ها

```bash
cd ~/mikrotik-usermanager
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
```

اگه اینجا با خطای نصب `psycopg`/`cryptography` مواجه شدید (نادره ولی ممکنه)، این رو بزنید و دوباره `pip install -r requirements.txt`:

```bash
sudo apt install -y build-essential python3-dev libpq-dev
```

## مرحله ۴: تنظیم فایل `.env` (فقط همین چند خط لازمه)

```bash
cp .env.example .env
```

حالا این دستور رو بزنید تا یک کلید امنیتی تصادفی بسازه:

```bash
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
```

خروجی (یک رشته‌ی رمزی) رو کپی کنید. حالا فایل `.env` رو باز کنید:

```bash
nano .env
```

و این مقادیر رو دقیقاً اینطوری تنظیم کنید (بقیه‌ی خطوط رو دست نزنید):

```ini
DJANGO_SETTINGS_MODULE=config.settings.prod
SECRET_KEY=یک-رشته-تصادفی-طولانی-اینجا-بنویسید-حداقل-۵۰-کاراکتر
DEBUG=False
ALLOWED_HOSTS=IP-سرور-شما,localhost,127.0.0.1
ENABLE_SSL=False

CREDENTIAL_ENCRYPTION_KEY=همون-کلیدی-که-بالا-ساختید-رو-اینجا-بچسبونید
```

نکته مهم: `ENABLE_SSL=False` دقیقاً همون چیزیه که الان لازم دارید — بدون این، چون سایت HTTPS نیست،
مرورگر کوکی‌های ورود رو قبول نمی‌کنه و لاگین‌تون کار نمی‌کنه. وقتی SSL رو اضافه کردید (پایین صفحه)، این رو `True` کنید.

برای `SECRET_KEY` هم می‌تونید همین دستور رو یک بار دیگه بزنید و خروجیش رو (یا هر رشته‌ی طولانی تصادفی دیگه) بذارید:

```bash
python -c "import secrets; print(secrets.token_urlsafe(50))"
```

ذخیره و خروج در `nano`: `Ctrl+O` بعد `Enter`، بعد `Ctrl+X`.

## مرحله ۵: ساخت دیتابیس و اکانت ادمین

```bash
python manage.py migrate
python manage.py createsuperuser
```

(یک یوزرنیم/پسورد برای خودتون به‌عنوان مدیر پنل وارد کنید — این با کاربرهای PPP فرق داره.)

## مرحله ۶: اجرای اولیه (تست سریع)

```bash
python manage.py collectstatic --noinput
python manage.py runserver 0.0.0.0:8000
```

حالا از مرورگر برید به: `http://IP-سرور-شما:8000/`

اگه سرورتون فایروال (ufw) داره، پورت رو باز کنید (در یک ترمینال دیگه، چون ترمینال فعلی مشغول اجرای سرورِ). `Ctrl+C` بزنید بعد این دستور رو بزنید بعد دوباره runserver رو اجرا کنید:

```bash
sudo ufw allow 8000/tcp
```

اگه صفحه‌ی لاگین رو دیدید و تونستید وارد بشید، مرحله‌ی اول موفقیت‌آمیز بوده.

## مرحله ۷: اجرای دائمی (به‌جای runserver، برای واقعی شدن سرویس)

`runserver` فقط برای تست سریعه. برای اجرای واقعی روی سرور، ۳ پردازش لازمه که همیشه روشن بمونن:
وب‌سرور (gunicorn)، و دو تا Celery (worker و beat) که کارهای پس‌زمینه (وضعیت آنلاین، انقضای خودکار، فاکتورها) رو انجام می‌دن.

ساده‌ترین راه برای شروع (بدون پیچیدگی systemd)، استفاده از `tmux` هست:

```bash
sudo apt install -y tmux
cd ~/mikrotik-usermanager
source .venv/bin/activate

tmux new -s web -d 'gunicorn config.wsgi:application --bind 0.0.0.0:8000 --workers 3 --threads 4'
tmux new -s worker -d 'celery -A config worker -l info --concurrency=2'
tmux new -s beat -d 'celery -A config beat -l info'
```

با `tmux ls` می‌بینید هر سه در حال اجراست. برای دیدن لاگ هرکدوم: `tmux attach -t web` (خروج بدون بستن: `Ctrl+B` بعد `D`).

بعد از هر ری‌استارت سرور، این سه دستور بالا رو دوباره باید بزنید (چون tmux با ری‌استارت سرور پاک می‌شه) —
اگه می‌خواید خودکار بالا بیاد، بعداً می‌تونیم یک سرویس `systemd` ساده هم براتون بسازیم.

## مرحله ۸: افزودن روتر MikroTik

```bash
python manage.py add_router --name "Main-Router" --host 10.0.0.1 --port 443 --username api-user
```

(پسورد رو موقع اجرا ازتون می‌پرسه.) بعد از پنل، یک Plan (طرح اینترنتی) هم از منوی Plans اضافه کنید، بعد می‌تونید اولین کاربر PPP رو بسازید.

---

## بعداً که دامنه و SSL آماده شد

وقتی یک دامنه (مثلاً `panel.example.com`) و گواهی SSL (مثلاً با `certbot`) آماده کردید:

1. Nginx نصب کنید و طبق `deploy/nginx.conf` جلوی gunicorn (پورت 8000) قرارش بدید تا روی 443 با HTTPS سرویس بده.
2. در `.env`: `ENABLE_SSL=True` و `ALLOWED_HOSTS=panel.example.com` کنید.
3. سرویس‌های web/worker/beat رو ری‌استارت کنید.

هر وقت به این مرحله رسیدید بگید، راهنمای گام‌به‌گام همون بخش رو هم به همین سادگی می‌نویسم.

---

## اگه به مشکل خوردید

- **صفحه باز نمی‌شه از بیرون**: `sudo ufw allow 8000/tcp` رو زدید؟ و `runserver`/gunicorn با `0.0.0.0:8000` (نه `127.0.0.1`) اجرا شده؟
- **بعد از لاگین دوباره برمی‌گرده به صفحه لاگین**: یعنی `ENABLE_SSL` هنوز `True` مونده یا اصلاً در `.env` نیست — دوباره چک کنید `False` باشه.
- **خطای `DisallowedHost`**: IP یا دامنه‌ای که باهاش وارد می‌شید رو به `ALLOWED_HOSTS` در `.env` اضافه کنید.
- **خطای اتصال Redis**: `sudo systemctl status redis-server` بزنید، اگه خاموش بود `sudo systemctl start redis-server`.
- **وضعیت آنلاین/آفلاین کاربرها آپدیت نمی‌شه**: یعنی Celery worker/beat اجرا نیستن — مرحله ۷ رو چک کنید.
