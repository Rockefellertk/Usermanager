# MikroTik v7 UserManager Panel

> **cPanel / PHP edition:** A standalone PHP 8.1 + MySQL rewrite that installs entirely from the browser (no SSH, Composer, Redis, Celery, or Python) is available in [`cpanel-php/`](cpanel-php/README_FA.md). A ready-to-upload ZIP is produced from that directory.

A Django panel for managing MikroTik RouterOS v7 PPP users (PPPoE/PPTP/L2TP/SSTP),
with billing/invoicing, bilingual (Persian/English) RTL UI, and background sync
via Celery. See `MIKROTIK_USERMANAGER_GUIDE.md` for the full design rationale.

This is a working scaffold covering the Phase-1 essentials (dashboard, PPP user
CRUD, enable/disable, renew, plans, invoices/payments, traffic charts, i18n/RTL)
plus the core Phase-2 billing pieces (proration, overdue sweep, credit notes).
Notification/export/backup (Phase 3) are not implemented — see the guide for how
they'd plug into this structure.

---

## 1. Requirements

- Linux server (Debian/Ubuntu assumed below; adapt package manager for others)
- Python 3.11+
- PostgreSQL 15+ (recommended for production; SQLite works out of the box for local dev)
- Redis 7 (cache + Celery broker)
- A MikroTik RouterOS v7 device with the REST API enabled (`/ip/service` → `www-ssl` on 443)

## 2. Quick local dev setup (SQLite, no Docker)

```bash
# system packages
sudo apt update && sudo apt install -y python3.11 python3.11-venv python3-pip redis-server

# project
cd mikrotik-usermanager
python3.11 -m venv .venv
source .venv/bin/activate
pip install -r requirements-dev.txt

# environment
cp .env.example .env
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
# paste the printed key into CREDENTIAL_ENCRYPTION_KEY in .env

# database (SQLite by default when DATABASE_URL is unset in .env)
python manage.py migrate
python manage.py createsuperuser

# static files (optional in dev; runserver serves them automatically)
python manage.py collectstatic --noinput

# run
python manage.py runserver 0.0.0.0:8000
```

In separate terminals (needed for online-status polling, expiry sweeps, invoicing):

```bash
source .venv/bin/activate
celery -A config worker -l info --concurrency=2

# in another terminal
celery -A config beat -l info
```

Open http://localhost:8000/ and log in with the superuser you created.
Add your first router at http://localhost:8000/admin/routers/mikrotik/add/
(or via `python manage.py add_router --name ... --host ... --username ... --password ...`).

### Developing without a real router

Run the bundled mock RouterOS REST server and point a `Mikrotik` row at it:

```bash
python tests/fixtures/mikrotik_mock_server.py   # serves http://127.0.0.1:8443
```

It implements `/ppp/secret`, `/ppp/active`, `/ppp/profile`, `/system/resource`
against an in-memory store — enough to exercise every screen in the panel
without a physical or virtual router.

## 3. Production setup (PostgreSQL + Docker Compose) — recommended for real use

```bash
# system packages
sudo apt update && sudo apt install -y docker.io docker-compose-plugin

cd mikrotik-usermanager
cp .env.example .env
```

Edit `.env` and set (all required for production):
- `SECRET_KEY` — random 50-char string
- `CREDENTIAL_ENCRYPTION_KEY` — from `python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"`
- `DB_PASSWORD` — a strong Postgres password
- `ALLOWED_HOSTS` — your domain, e.g. `panel.example.com`
- `DJANGO_SETTINGS_MODULE=config.settings.prod` (docker-compose.yml already sets this for you)

TLS certificates for Nginx: put `fullchain.pem` and `privkey.pem` in `./certs/`
(e.g. from `certbot certonly --standalone -d panel.example.com`, or your CA of choice).

```bash
mkdir -p certs
# copy/symlink your fullchain.pem and privkey.pem into ./certs/

docker compose build
docker compose up -d db redis
docker compose up -d web worker beat nginx

# create the first panel admin
docker compose exec web python manage.py createsuperuser

# register your MikroTik router
docker compose exec web python manage.py add_router \
    --name "Main-Router" --host 10.0.0.1 --port 443 --username api-user
```

Visit `https://panel.example.com/`.

Check logs if something doesn't come up:
```bash
docker compose logs -f web
docker compose logs -f worker
docker compose logs -f beat
```

### Production without Docker (systemd + Nginx + Gunicorn)

If you'd rather not use Docker:

```bash
sudo apt install -y python3.11 python3.11-venv postgresql redis-server nginx

sudo -u postgres psql -c "CREATE DATABASE usermanager;"
sudo -u postgres psql -c "CREATE USER usermanager_app WITH PASSWORD 'change-me';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE usermanager TO usermanager_app;"

cd mikrotik-usermanager
python3.11 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

cp .env.example .env   # fill in SECRET_KEY, CREDENTIAL_ENCRYPTION_KEY, DATABASE_URL, ALLOWED_HOSTS
export DJANGO_SETTINGS_MODULE=config.settings.prod

python manage.py migrate
python manage.py createsuperuser
python manage.py collectstatic --noinput

gunicorn config.wsgi:application -c deploy/gunicorn.conf.py
```

Run `celery -A config worker -l info` and `celery -A config beat -l info` as
systemd services (or under `supervisor`) alongside Gunicorn, then point Nginx
at Gunicorn using `deploy/nginx.conf` as a starting point (edit `proxy_pass`
if you're not using the `web` Docker service name, and drop in your
certificate paths).

## 4. Enabling the REST API on the router itself

On the MikroTik, in terminal or Winbox:

```
/ip service set www-ssl disabled=no port=443
/ip service set www disabled=yes
/user add name=api-user group=full password=... 
```

(Create a dedicated, least-privilege API user rather than reusing `admin` —
see the security notes in `MIKROTIK_USERMANAGER_GUIDE.md` §6.4.) On RouterOS
7.13+, prefer `/user/api-key add user=api-user` and use `--api-key` with
`add_router` instead of a password.

## 5. Translations (Persian/English)

Source strings live in code as `{% trans %}` / `gettext()`. To (re)compile
`.po` → `.mo` after editing `locale/fa/LC_MESSAGES/django.po`:

```bash
sudo apt install -y gettext   # needed for makemessages/compilemessages
python manage.py makemessages -l fa
# edit locale/fa/LC_MESSAGES/django.po
python manage.py compilemessages
```

## 6. Project layout

```
apps/
  core/        crypto (Fernet encryption for stored credentials), shared mixins/decorators
  accounts/    panel admin users, login/logout, brute-force throttling
  routers/     Mikrotik device model (encrypted creds)
  mikrotik/    the RouterOS v7 REST client (no Django dependency) + connection pool/circuit breaker
  plans/       internet plans/pricing
  ppp_users/   the PPP secrets cache + create/edit/delete/enable/renew/sync services
  billing/     invoices, payments, invoice numbering, proration, credit notes
  traffic/     daily traffic accumulation from /ppp/active polling + chart data API
  dashboard/   the landing page
  activity/    audit log (model + middleware)
config/        settings (base/dev/prod split), urls, celery app
templates/     Bootstrap5 (RTL/LTR) server-rendered templates
locale/        fa/en translation catalogs
tests/fixtures/mikrotik_mock_server.py   offline RouterOS REST simulator
deploy/        nginx.conf, gunicorn.conf.py
```

## 7. What's implemented vs. what's a next step

Implemented: dashboard, PPP user list/search/filter/pagination/create/edit/
delete/enable-disable/renew, plan CRUD, invoice list/detail/payment recording,
revenue report, per-user traffic chart, role-based access (superadmin/operator/
billing/viewer), bilingual RTL UI with language switcher, Celery-driven online
status polling + expiry sweep + router sync + overdue sweep, audit log,
encrypted router/PPP credential storage, login brute-force throttling.

Deliberately left as a next step (see the guide for the design): email/Telegram/
SMS expiry notifications, Excel/PDF export, automated DB backups, and a bulk
renew/bulk-expire UI (the underlying `renew_user`/`expire_sweep` service
functions already support being called in a loop — only the bulk-selection UI
is missing).

## 8. Running tests

```bash
pip install -r requirements-dev.txt
pytest
```

(Test files aren't included in this scaffold yet — `pytest-django`, `factory-boy`,
`freezegun`, and `respx` are wired into `requirements-dev.txt` and `pytest.ini`
so you can start writing them against `apps/*/services.py` directly, per the
testing approach in `MIKROTIK_USERMANAGER_GUIDE.md` §7.)
