# Complete Guide to Building a UserManager Panel with Billing for MikroTik v7

> Scope note: this guide describes a **new, standalone project**. The rest of this
> repository (`admin/`, `reseller/`, `includes/ibsng_api.php`) is a legacy PHP panel
> built against **IBSng**, not MikroTik RouterOS — unrelated to what's below. It should
> live in its own directory/repo (e.g. `mikrotik-usermanager/`) rather than be merged
> into the legacy code.
>
> ⚠️ **Security note on the existing code**: `includes/config.php` and
> `includes/ibsng_api.php` currently contain **plaintext DB and admin credentials
> committed to git** (`DB_PASS`, `IBS_PASS`). Those credentials should be rotated and
> scrubbed from git history regardless of this project — see the end of this document.

---

## Table of Contents

1. [Technology Selection](#1-technology-selection)
2. [MikroTik v7 REST API Architecture](#2-mikrotik-v7-rest-api-architecture)
3. [Database Schema](#3-database-schema)
4. [Project Structure](#4-project-structure)
5. [Code Examples](#5-code-examples)
6. [Answers to the 10 Architecture Questions](#6-answers-to-the-10-architecture-questions)
7. [Installation Guide (Zero to Running)](#7-installation-guide-zero-to-running)
8. [Best Practices](#8-best-practices)
9. [MikroTik v7 Special Notes](#9-mikrotik-v7-special-notes)
10. [Sample Config Files](#10-sample-config-files)
11. [Performance Tuning for 1000 Users](#11-performance-tuning-for-1000-users)

---

## 1. Technology Selection

### Recommendation: **Option A — Python + Django (+ Django REST Framework for the AJAX/API layer)**

| Criteria | Django | Flask | Laravel | Express | Gin |
|---|---|---|---|---|---|
| Setup speed | Fast (batteries included) | Fast but you assemble everything | Fast | Fast | Slower (build more yourself) |
| MikroTik REST comms | Trivial — it's just JSON over HTTPS via `requests`/`httpx`, no special lib needed | Same | Same via Guzzle | Same via `axios`/`fetch` | Same via `net/http` |
| Built-in security | CSRF, session hardening, SQLi-safe ORM, clickjacking headers — **on by default** | You bolt these on manually | Good (CSRF, Eloquent) | You bolt these on manually | You bolt these on manually |
| Resource use for 1000 users | Low (few Gunicorn workers handle this easily) | Low | Low-medium | Low | Lowest, but not needed here |
| Session management | Built-in, mature | Needs `Flask-Login`/`Flask-Session` | Built-in | Needs `express-session` | Needs manual work |
| i18n / RTL (Persian) | **Built-in** `django.utils.translation`, `LANGUAGE_BIDI`, `{% load i18n %}`, `.po/.mo` workflow | Needs `Flask-Babel` | Good (`laravel-lang`) | Needs `i18next` | Needs manual work |
| Admin registers users only | Django's `LoginRequiredMixin` + permission system fits perfectly; you also get a free `/admin/` for panel-admin housekeeping | Manual | Good (Gates/Policies) | Manual | Manual |
| Ecosystem for billing | `django-money`, ORM `Decimal` fields, mature migrations | Fine, more manual | Very good (`laravel-cashier` exists but is Stripe-centric) | Fine | Fine |
| Background jobs (Celery) | First-class, huge community | First-class | Laravel Queues (also solid) | Bull/BullMQ | Manual/goroutines |

**Why Django wins here specifically:**
- You need **CSRF + session security + SQL-injection-safe ORM out of the box** — this is a billing panel, security matters more than raw throughput.
- **Multilingual/RTL support is a first-class Django feature**, not a bolt-on — critical since Persian/RTL is a hard requirement, not a nice-to-have.
- The **Django admin** gives you a free, secure back-office for things like managing plan definitions, admin accounts, and audit trails, cutting real development time.
- **1000 PPP users is a small workload** for Django + PostgreSQL + Gunicorn (a few thousand DB rows, low request rate) — Django's overhead vs. Flask/Express is irrelevant at this scale; the win is in *not re-inventing* auth/CSRF/ORM/migrations/i18n.
- MikroTik v7's REST API is plain HTTPS+JSON — **no MikroTik-specific library is required** in any of these stacks; a 40-line `requests`/`httpx` wrapper is all you need, so "library availability" doesn't favor Node or Go here.
- If you already know PHP well, **Laravel is the legitimate second choice** (same reasoning: batteries included, good i18n, Eloquent ORM, built-in CSRF) — pick it if your team's fastest velocity is in PHP. Flask/Express/Gin all require assembling security and session middleware by hand, which is exactly the kind of yak-shaving you want to avoid for a billing system.

**Final stack:**
- **Backend**: Python 3.12, Django 5.x, Django REST Framework (for the internal AJAX/JSON endpoints used by the dashboard's live-refresh widgets)
- **DB**: PostgreSQL 15+
- **Cache/Broker**: Redis 7
- **Background jobs**: Celery + Celery Beat (sync jobs, expirations, notifications)
- **Frontend**: Server-rendered Django templates + Bootstrap 5 (official RTL build) + htmx + Alpine.js for interactivity + Chart.js for graphs — **not** React/Vue (see §6.5)
- **Web/app server**: Gunicorn (sync workers, `gthread` worker class) behind Nginx
- **Containerization**: Docker Compose

---

## 2. MikroTik v7 REST API Architecture

### 2.A Authentication & Connection

RouterOS v7's REST API (enabled via `/ip/service` on ports 80/443, or preferably **only 443 with `www-ssl`**) is a thin JSON wrapper over the CLI tree. Key facts that shape the design:

- It uses **HTTP Basic Auth on every request** — there is no OAuth-style login/refresh-token exchange like a typical web API. "Token management" in the MikroTik sense doesn't exist pre-7.13; each request just carries `Authorization: Basic base64(user:pass)` over TLS.
- **RouterOS 7.13+** supports **API keys** (`/user/api-key add`) as an alternative to basic auth — recommended when available: revocable, scoped, no need to hand the router your admin's login password.
- Because there's no session/token to refresh, "session management between panel and router" reduces to: **keep a pooled HTTPS connection, re-send Basic Auth (or API key) each call, and handle 401s by marking the router unreachable and alerting**.

**Secure credential storage**: router credentials (or API keys) must be **encrypted, not hashed** (you need to read them back to use them). Use symmetric encryption (Fernet/AES-GCM) with a key held in an environment variable / secrets manager — never in the DB, never in git.

```python
# core/crypto.py
from cryptography.fernet import Fernet
from django.conf import settings

_fernet = Fernet(settings.CREDENTIAL_ENCRYPTION_KEY)  # 32-byte urlsafe base64 key from env

def encrypt_secret(plain: str) -> bytes:
    return _fernet.encrypt(plain.encode())

def decrypt_secret(token: bytes) -> str:
    return _fernet.decrypt(token).decode()
```

```bash
# generate the key once, put it in .env as CREDENTIAL_ENCRYPTION_KEY
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
```

### 2.B Required Operations → REST Endpoint Mapping

| Panel operation | RouterOS v7 REST endpoint | Method |
|---|---|---|
| List PPP users | `/ppp/secret` | GET (supports `?name=`, `.proplist=` filters via query params, or POST to `/ppp/secret/print` with `.query`) |
| Create user | `/ppp/secret/add` | PUT/POST |
| Edit user | `/ppp/secret/set` (or `PATCH /ppp/secret/{id}`) | PATCH |
| Delete user | `/ppp/secret/remove` | POST (or `DELETE /ppp/secret/{id}`) |
| Enable/disable | `/ppp/secret/set` with `{"disabled": "yes|no"}` | PATCH |
| Online/offline status | `/ppp/active` | GET |
| Traffic/uptime per active session | `/ppp/active` (`bytes-in`, `bytes-out`, `uptime` fields) | GET |
| Available profiles | `/ppp/profile` | GET |
| Real-time bandwidth (interface-level) | `/interface/monitor-traffic` (streaming) or polling `/interface` counters | POST/GET |

**Note**: `/ppp/secret` itself has **no persistent traffic counters** — cumulative traffic must be derived by the panel: poll `/ppp/active` periodically, diff `bytes-in`/`bytes-out` against the last poll, and accumulate into `traffic_logs`. This is why local caching/logging (§3) is mandatory, not optional.

### 2.C Error Handling & Failover

```python
# mikrotik/client.py
import httpx
from django.core.cache import cache
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type

class MikroTikUnreachable(Exception):
    pass

class MikroTikClient:
    def __init__(self, base_url: str, username: str, password: str, verify_ssl_ca: str | None):
        self._client = httpx.Client(
            base_url=base_url,               # e.g. https://10.0.0.1
            auth=(username, password),
            timeout=httpx.Timeout(5.0, connect=3.0),
            verify=verify_ssl_ca or True,     # pin the router's cert, don't disable verification
            limits=httpx.Limits(max_connections=10, max_keepalive_connections=5),
        )

    @retry(
        reraise=True,
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=0.5, min=0.5, max=4),
        retry=retry_if_exception_type((httpx.ConnectTimeout, httpx.ReadTimeout, httpx.ConnectError)),
    )
    def _request(self, method: str, path: str, **kwargs):
        try:
            resp = self._client.request(method, path, **kwargs)
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError as e:
            if e.response.status_code == 401:
                raise MikroTikUnreachable("Authentication rejected — check credentials/API key") from e
            raise
        except (httpx.ConnectError, httpx.ConnectTimeout, httpx.ReadTimeout) as e:
            raise  # tenacity retries these; if exhausted, bubbles up

    def list_secrets(self, name_filter: str | None = None):
        params = {".proplist": "name,profile,disabled,comment,last-logged-out"}
        if name_filter:
            params["name"] = name_filter
        return self._request("GET", "/rest/ppp/secret", params=params)

    def active_sessions(self):
        return self._request("GET", "/rest/ppp/active")

    def create_secret(self, name, password, profile, comment=""):
        payload = {"name": name, "password": password, "profile": profile,
                   "service": "pppoe", "comment": comment}
        return self._request("PUT", "/rest/ppp/secret", json=payload)

    def set_secret(self, secret_id: str, **fields):
        return self._request("PATCH", f"/rest/ppp/secret/{secret_id}", json=fields)

    def remove_secret(self, secret_id: str):
        return self._request("DELETE", f"/rest/ppp/secret/{secret_id}")
```

Design points:
- **Timeouts**: short connect (3s) and read (5s) timeouts — a hung router must not hang an admin's browser request.
- **Retry with backoff**: `tenacity`, 3 attempts, exponential backoff, only on connection-level failures (never blindly retry a `POST create` on a `409`, that could double-create).
- **Connection pooling**: one `httpx.Client` per router process-wide (via a small registry keyed by router ID), reused across requests — not re-opened per call.
- **Circuit breaker**: after N consecutive failures, mark the router `unreachable` in cache for a cooldown (e.g. 30s) so the UI shows "router offline" immediately instead of every admin request re-timing-out.
- **Logging**: every outbound call and its result/latency goes to a structured `activity_logs`/app log (see §3) — essential for diagnosing "why didn't user X get created."
- **Queueing under load**: interactive admin actions (create/edit/delete one user) go **synchronous** through Gunicorn's thread pool — they're single-router, single-object, fast. Bulk operations (bulk renew, full sync of 1000 users, nightly expiry sweep) go through **Celery** so they don't block web workers or serialize behind each other. See §6.2 for the full reasoning.

---

## 3. Database Schema

**Recommended DB: PostgreSQL 15+** — needed here for: `JSONB` columns (raw MikroTik payload caching), strong `DECIMAL` support for money, proper concurrent write handling (`SELECT ... FOR UPDATE` for invoice numbering), and full-text search on username/notes at this scale without extra infra. MySQL would also work at 1000 users, but Postgres's constraint/indexing story is simply cleaner for a billing system.

```
┌────────────┐        ┌───────────────┐        ┌──────────────┐
│   users    │        │   mikrotiks   │        │    plans     │
│ (admins)   │        │ (routers)     │        │              │
└─────┬──────┘        └───────┬───────┘        └──────┬───────┘
      │ 1                     │ 1                      │ 1
      │                       │                         │
      │ *                     │ *                       │ *
┌─────┴─────────────┬─────────┴────────┐        ┌───────┴────────┐
│  activity_logs     │   local_users    ├────────┤   invoices     │
│  (admin actions)   │  (cached PPP)    │  1   * └───────┬────────┘
└─────────────────────┴─────┬────────────┘                │ 1
                             │ 1                            │ *
                             │ *                    ┌───────┴────────┐
                       ┌─────┴────────┐              │   payments     │
                       │ traffic_logs │              └────────────────┘
                       └──────────────┘
```

### 3.1 `users` — panel admins (not internet users)
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| username | VARCHAR(150) UNIQUE | |
| password_hash | VARCHAR(255) | Django's PBKDF2/Argon2 hash, **never** plaintext |
| email | VARCHAR(255) | |
| role | VARCHAR(20) | `superadmin`, `operator`, `billing`, `viewer` |
| language | VARCHAR(5) | `fa` / `en`, per-admin UI preference |
| is_active | BOOLEAN | |
| last_login | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |

*(This maps to Django's built-in `auth_user`/custom `AbstractUser` — you rarely hand-roll this table.)*

### 3.2 `mikrotiks` — connected routers
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | Friendly label, e.g. "Branch-1" |
| host | VARCHAR(255) | IP/hostname |
| port | INTEGER | default 443 |
| username | VARCHAR(100) | router API user |
| password_encrypted | BYTEA | Fernet-encrypted, see §2.A |
| use_api_key | BOOLEAN | prefer RouterOS 7.13+ API keys |
| api_key_encrypted | BYTEA NULL | |
| tls_ca_cert | TEXT NULL | pinned router certificate |
| is_active | BOOLEAN | |
| last_sync_at | TIMESTAMPTZ NULL | |
| last_status | VARCHAR(20) | `online`/`offline`/`unknown` |
| created_at | TIMESTAMPTZ | |

Index: `UNIQUE(host, port)`.

### 3.3 `plans` — internet packages
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | e.g. "10Mbps Home" |
| mikrotik_profile | VARCHAR(100) | maps to `/ppp/profile` name |
| rate_limit | VARCHAR(50) | e.g. `10M/2M` (down/up) — mirrors RouterOS format |
| price | DECIMAL(12,2) | |
| currency | VARCHAR(3) | default `IRR`/`USD` per deployment |
| validity_days | INTEGER | e.g. 30 |
| data_cap_gb | INTEGER NULL | NULL = unlimited |
| is_active | BOOLEAN | |
| created_at | TIMESTAMPTZ | |

### 3.4 `local_users` — cached copy of PPP secrets + billing linkage
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| mikrotik_id | BIGINT FK → mikrotiks | |
| mikrotik_secret_id | VARCHAR(50) | RouterOS `.id`, e.g. `*3F` |
| username | VARCHAR(100) | |
| password_encrypted | BYTEA | needed to show/reset PPP password |
| plan_id | BIGINT FK → plans NULL | |
| profile | VARCHAR(100) | denormalized copy for fast listing |
| rate_limit | VARCHAR(50) | |
| status | VARCHAR(20) | `active`, `disabled`, `expired`, `suspended` |
| expiration_date | DATE NULL | |
| full_name | VARCHAR(150) NULL | subscriber's name |
| phone | VARCHAR(30) NULL | |
| address | TEXT NULL | |
| comment | TEXT NULL | |
| last_synced_at | TIMESTAMPTZ | |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

Indexes: `UNIQUE(mikrotik_id, username)`, `INDEX(expiration_date)`, `INDEX(status)`, `INDEX(username)` (trigram index `pg_trgm` for fast search).

### 3.5 `traffic_logs` — daily usage
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| local_user_id | BIGINT FK → local_users | |
| date | DATE | one row per user per day |
| bytes_in | BIGINT | |
| bytes_out | BIGINT | |
| session_count | INTEGER | |
| uptime_seconds | BIGINT | |

Index: `UNIQUE(local_user_id, date)`, `INDEX(date)` for report range-scans.

### 3.6 `activity_logs` — admin actions
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| admin_id | BIGINT FK → users NULL | null = system/celery |
| action | VARCHAR(50) | `user_create`, `user_disable`, `invoice_paid`, ... |
| target_type | VARCHAR(50) | `local_user`, `invoice`, ... |
| target_id | BIGINT NULL | |
| detail | JSONB | before/after diff, request params |
| ip_address | INET NULL | |
| created_at | TIMESTAMPTZ | |

Index: `INDEX(admin_id, created_at)`, `INDEX(target_type, target_id)`.

### 3.7 `invoices`
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| invoice_number | VARCHAR(30) UNIQUE | see numbering scheme §6.6 |
| local_user_id | BIGINT FK → local_users | |
| plan_id | BIGINT FK → plans NULL | |
| amount | DECIMAL(12,2) | |
| discount | DECIMAL(12,2) DEFAULT 0 | |
| tax | DECIMAL(12,2) DEFAULT 0 | |
| total | DECIMAL(12,2) | generated/stored |
| status | VARCHAR(20) | `unpaid`, `paid`, `overdue`, `cancelled`, `credited` |
| issue_date | DATE | |
| due_date | DATE | |
| paid_at | TIMESTAMPTZ NULL | |
| notes | TEXT NULL | |
| created_by | BIGINT FK → users NULL | |
| created_at | TIMESTAMPTZ | |

Indexes: `INDEX(local_user_id)`, `INDEX(status, due_date)` (drives the "overdue" list).

### 3.8 `payments`
| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| invoice_id | BIGINT FK → invoices | |
| amount | DECIMAL(12,2) | |
| method | VARCHAR(20) | `cash`, `bank_transfer`, `online_gateway` |
| reference | VARCHAR(100) NULL | bank ref / gateway transaction id |
| received_by | BIGINT FK → users NULL | |
| received_at | TIMESTAMPTZ | |
| notes | TEXT NULL | |

Index: `INDEX(invoice_id)`.

### Local DB ↔ MikroTik sync model
- **MikroTik is the source of truth for**: whether the secret exists, its `disabled` flag, live online/active session data.
- **Local DB is the source of truth for**: plan/pricing, expiration policy, billing history, subscriber contact info.
- Every write path does **local DB transaction → MikroTik API call → confirm → commit**, in that order with the local row locked (`select_for_update`), so a MikroTik failure can roll back the local change instead of leaving them inconsistent. See §6.10 for the full reconciliation job.

---

## 4. Project Structure

```
mikrotik-usermanager/
├── manage.py
├── requirements.txt
├── requirements-dev.txt
├── .env.example
├── docker-compose.yml
├── Dockerfile
├── pytest.ini
├── config/                      # Django project package
│   ├── settings/
│   │   ├── base.py
│   │   ├── dev.py
│   │   └── prod.py
│   ├── urls.py
│   ├── celery.py
│   └── wsgi.py
├── apps/
│   ├── accounts/                # panel admins, auth, roles
│   │   ├── models.py
│   │   ├── views.py
│   │   ├── permissions.py
│   │   └── middleware.py        # audit logging middleware
│   ├── routers/                 # `mikrotiks` table + client management
│   │   ├── models.py
│   │   └── admin.py
│   ├── mikrotik/                # the API client library (no Django deps)
│   │   ├── client.py
│   │   ├── exceptions.py
│   │   └── registry.py          # per-router pooled MikroTikClient instances
│   ├── ppp_users/                # `local_users` — the PPPoE/PPTP/L2TP/SSTP users
│   │   ├── models.py
│   │   ├── forms.py
│   │   ├── views.py
│   │   ├── services.py          # create/edit/delete orchestration (local + MikroTik)
│   │   ├── tasks.py              # celery: sync, expiry sweep, notifications
│   │   └── urls.py
│   ├── plans/
│   │   ├── models.py
│   │   └── views.py
│   ├── billing/
│   │   ├── models.py             # invoices, payments
│   │   ├── services.py           # invoice generation, proration, numbering
│   │   ├── views.py
│   │   └── tasks.py
│   ├── traffic/
│   │   ├── models.py             # traffic_logs
│   │   ├── tasks.py               # periodic polling of /ppp/active
│   │   └── views.py               # chart data endpoints
│   ├── dashboard/
│   │   └── views.py
│   └── core/
│       ├── crypto.py
│       └── mixins.py
├── templates/
│   ├── base.html                 # dir="{{ LANGUAGE_BIDI|yesno:'rtl,ltr' }}"
│   ├── dashboard/
│   ├── ppp_users/
│   └── billing/
├── static/
│   ├── css/  (bootstrap RTL + custom)
│   ├── js/   (htmx, alpine, chart.js)
│   └── i18n/
├── locale/
│   ├── fa/LC_MESSAGES/django.po
│   └── en/LC_MESSAGES/django.po
├── tests/
│   ├── conftest.py
│   ├── fixtures/mikrotik_mock_server.py   # offline dev/test simulator
│   ├── test_mikrotik_client.py
│   ├── test_billing.py
│   └── test_ppp_users.py
└── deploy/
    ├── nginx.conf
    ├── gunicorn.conf.py
    └── supervisor/ (if not using Docker)
```

---

## 5. Code Examples

### 5.1 Fetching the PPP user list (`/ppp/secret`)

```python
# apps/ppp_users/services.py
from apps.mikrotik.registry import get_client
from apps.ppp_users.models import LocalUser

def fetch_remote_secrets(mikrotik):
    client = get_client(mikrotik)
    return client.list_secrets()  # -> list[dict] straight from RouterOS

def list_users_for_display(mikrotik, search=None, status=None, page=1, per_page=25):
    qs = LocalUser.objects.filter(mikrotik=mikrotik)
    if search:
        qs = qs.filter(username__icontains=search)
    if status:
        qs = qs.filter(status=status)
    qs = qs.select_related("plan").order_by("username")
    start = (page - 1) * per_page
    return qs[start:start + per_page], qs.count()
```

### 5.2 Fetching online users (`/ppp/active`)

```python
# apps/traffic/tasks.py
from celery import shared_task
from django.core.cache import cache
from django.utils import timezone
from apps.routers.models.Mikrotik import Mikrotik
from apps.mikrotik.registry import get_client
from apps.ppp_users.models import LocalUser
from apps.traffic.models import TrafficLog

ONLINE_CACHE_TTL = 15  # seconds

@shared_task(bind=True, max_retries=3, default_retry_delay=5)
def poll_active_sessions(self, mikrotik_id):
    mikrotik = Mikrotik.objects.get(id=mikrotik_id)
    client = get_client(mikrotik)
    active = client.active_sessions()  # list of dicts w/ name, address, uptime, bytes-in/out

    cache.set(f"mikrotik:{mikrotik_id}:active", active, timeout=ONLINE_CACHE_TTL)

    today = timezone.localdate()
    for session in active:
        username = session["name"]
        bytes_in = int(session.get("bytes-in", 0))
        bytes_out = int(session.get("bytes-out", 0))
        local_user = LocalUser.objects.filter(mikrotik=mikrotik, username=username).first()
        if not local_user:
            continue
        log, _ = TrafficLog.objects.get_or_create(
            local_user=local_user, date=today,
            defaults={"bytes_in": 0, "bytes_out": 0, "session_count": 0, "uptime_seconds": 0},
        )
        # accumulate deltas rather than overwrite — RouterOS counters reset per session
        log.bytes_in += bytes_in
        log.bytes_out += bytes_out
        log.session_count += 1
        log.uptime_seconds = max(log.uptime_seconds, int(session.get("uptime_seconds", 0)))
        log.save(update_fields=["bytes_in", "bytes_out", "session_count", "uptime_seconds"])
```

`config/celery.py` schedules this every 5 minutes per active router via `celery beat` (see §11 for tuning at 1000 users).

### 5.3 Creating a new user with a billing plan

```python
# apps/ppp_users/services.py (continued)
from django.db import transaction
from apps.mikrotik.exceptions import MikroTikUnreachable
from apps.billing.services import generate_invoice_for_new_user
from apps.core.crypto import encrypt_secret

@transaction.atomic
def create_ppp_user(*, mikrotik, plan, username, password, admin, full_name="", phone=""):
    client = get_client(mikrotik)

    local_user = LocalUser.objects.create(
        mikrotik=mikrotik,
        username=username,
        password_encrypted=encrypt_secret(password),
        plan=plan,
        profile=plan.mikrotik_profile,
        rate_limit=plan.rate_limit,
        status="active",
        expiration_date=timezone.localdate() + timedelta(days=plan.validity_days),
        full_name=full_name,
        phone=phone,
    )

    try:
        remote = client.create_secret(
            name=username, password=password, profile=plan.mikrotik_profile,
            comment=f"panel:{local_user.id}",
        )
        local_user.mikrotik_secret_id = remote["ret"] if "ret" in remote else remote.get(".id")
        local_user.last_synced_at = timezone.now()
        local_user.save(update_fields=["mikrotik_secret_id", "last_synced_at"])
    except MikroTikUnreachable:
        # local row exists but unconfirmed on-device — rolled back entirely,
        # since @transaction.atomic wraps this whole function
        raise

    invoice = generate_invoice_for_new_user(local_user, plan, admin)

    log_activity(admin, "user_create", "local_user", local_user.id,
                 detail={"username": username, "plan": plan.name})

    return local_user, invoice
```

### 5.4 Enable / disable a user

```python
# apps/ppp_users/services.py (continued)
def set_user_enabled(local_user, enabled: bool, admin):
    client = get_client(local_user.mikrotik)
    client.set_secret(local_user.mikrotik_secret_id, disabled="no" if enabled else "yes")

    local_user.status = "active" if enabled else "disabled"
    local_user.save(update_fields=["status"])

    cache.delete(f"mikrotik:{local_user.mikrotik_id}:active")  # invalidate

    log_activity(admin, "user_enable" if enabled else "user_disable",
                 "local_user", local_user.id, detail={})
```

### 5.5 Extending expiration + generating an invoice

```python
# apps/billing/services.py
from django.db import transaction
from datetime import timedelta
from django.utils import timezone
from .models import Invoice

INVOICE_PREFIX = "INV"

def next_invoice_number():
    # sequential, gapless-enough via DB-level locking on a counter row
    from .models import InvoiceCounter
    with transaction.atomic():
        counter, _ = InvoiceCounter.objects.select_for_update().get_or_create(
            year_month=timezone.localdate().strftime("%Y%m"), defaults={"last_value": 0}
        )
        counter.last_value += 1
        counter.save(update_fields=["last_value"])
        return f"{INVOICE_PREFIX}-{counter.year_month}-{counter.last_value:04d}"

@transaction.atomic
def generate_invoice_for_new_user(local_user, plan, admin):
    return Invoice.objects.create(
        invoice_number=next_invoice_number(),
        local_user=local_user,
        plan=plan,
        amount=plan.price,
        total=plan.price,
        status="unpaid",
        issue_date=timezone.localdate(),
        due_date=timezone.localdate() + timedelta(days=3),
        created_by=admin,
    )

@transaction.atomic
def renew_user(local_user, admin, extend_days=None):
    plan = local_user.plan
    days = extend_days or plan.validity_days
    base = max(local_user.expiration_date or timezone.localdate(), timezone.localdate())
    local_user.expiration_date = base + timedelta(days=days)
    local_user.status = "active"
    local_user.save(update_fields=["expiration_date", "status"])

    client = get_client(local_user.mikrotik)
    client.set_secret(local_user.mikrotik_secret_id, disabled="no")

    invoice = Invoice.objects.create(
        invoice_number=next_invoice_number(),
        local_user=local_user, plan=plan, amount=plan.price, total=plan.price,
        status="unpaid", issue_date=timezone.localdate(),
        due_date=timezone.localdate() + timedelta(days=3), created_by=admin,
    )
    return invoice
```

### 5.6 Calculating & displaying traffic usage

```python
# apps/traffic/views.py
from django.db.models import Sum
from django.http import JsonResponse
from apps.traffic.models import TrafficLog

def user_traffic_chart_data(request, local_user_id):
    days = int(request.GET.get("days", 30))
    since = timezone.localdate() - timedelta(days=days)
    rows = (
        TrafficLog.objects.filter(local_user_id=local_user_id, date__gte=since)
        .order_by("date")
        .values("date")
        .annotate(total_in=Sum("bytes_in"), total_out=Sum("bytes_out"))
    )
    return JsonResponse({
        "labels": [r["date"].isoformat() for r in rows],
        "download_gb": [round(r["total_in"] / 1e9, 3) for r in rows],
        "upload_gb": [round(r["total_out"] / 1e9, 3) for r in rows],
    })
```

```html
<!-- templates/ppp_users/detail.html snippet -->
<canvas id="trafficChart"></canvas>
<script>
fetch("{% url 'traffic:chart_data' local_user.id %}?days=30")
  .then(r => r.json())
  .then(d => new Chart(document.getElementById('trafficChart'), {
    type: 'line',
    data: { labels: d.labels, datasets: [
      { label: '{% trans "Download (GB)" %}', data: d.download_gb },
      { label: '{% trans "Upload (GB)" %}', data: d.upload_gb },
    ]},
  }));
</script>
```

---

## 6. Answers to the 10 Architecture Questions

### 6.1 Overall Architecture: Monolithic MVC — yes, deliberately

For a single internal panel with ~1000 users, one admin team, and one core external dependency (MikroTik), a **Django-style monolithic MVC (technically MVT)** is the right call over microservices:
- One deploy target, one DB, one codebase to reason about — matches your team size and ops capacity.
- Django's app-per-domain layout (`ppp_users/`, `billing/`, `traffic/`) already gives you clean module boundaries *inside* the monolith, so you keep separation of concerns without network-call overhead or deployment complexity.
- The only place that genuinely benefits from being "outside" the request/response cycle is background work (sync, expiry sweeps, notifications) — that's what **Celery workers** are for, not a second service.
- Microservices would only start paying off if you had multiple independent teams, wildly different scaling needs per component, or needed to reuse "user management" from other products — none apply here.

### 6.2 Sync vs Async with MikroTik

- **Interactive, single-object admin actions** (create/edit/delete/enable one user) stay **synchronous** inside the Django request, using Gunicorn's `gthread` worker class (e.g. `--workers 3 --threads 4`) so one slow router call doesn't block other admins — threads, not full asyncio, because Django's ORM and most of this stack is sync-first and 1000 users doesn't need asyncio's complexity.
- **Bulk/background work** (full 1000-user sync, nightly expiry sweep, traffic polling, notification fan-out) runs in **Celery** with a worker pool (`--concurrency 4-8`), so it never contends with the web workers and can retry/backoff independently per router.
- **Concurrent admins editing the same user**: guard with `select_for_update()` around the local row during the create/edit/delete service functions (§5.3–5.5) — the second admin's transaction blocks briefly on the DB row lock rather than racing a duplicate MikroTik call. Optionally add optimistic concurrency (`updated_at` version check) if lock contention becomes visible in practice, but at this scale row-locking is enough.
- **Concurrent admins on different routers**: no contention — each `MikroTikClient` is per-router with its own connection pool.

### 6.3 Caching Strategy (Redis)

| Data | TTL | Invalidate on |
|---|---|---|
| `/ppp/active` (online status) | 10–15s | never manually — short TTL is enough since it's polled by Celery anyway |
| `/ppp/profile` list | 5 min | admin edits a profile in-panel |
| Router reachability flag | 30s | successful call to that router |
| Dashboard aggregate stats (total online, revenue today) | 30–60s | invoice payment, user create/delete |
| Per-user detail page | not cached (DB read is already cheap) | — |

Use Django's `django-redis` cache backend. Invalidate explicitly (`cache.delete(key)`) right after any write path that changes the underlying data (§5.4 shows this for enable/disable) rather than relying purely on TTL — TTL is the safety net, explicit invalidation is what keeps the UI feeling live.

### 6.4 Security

- **CSRF**: Django's CSRF middleware is on by default for all POST/PATCH/DELETE forms and htmx requests (send the CSRF token via the `X-CSRFToken` header on htmx/AJAX calls). Not something you build — just don't disable it.
- **Session fixation**: Django rotates the session key on login (`django.contrib.auth.login()` does this automatically) and use `SESSION_COOKIE_SECURE=True`, `SESSION_COOKIE_HTTPONLY=True`, `SESSION_COOKIE_SAMESITE="Lax"`, short `SESSION_COOKIE_AGE` with sliding renewal.
- **Session cookies over JWT**: for a server-rendered internal panel with no separate mobile/public API, **session cookies win** — CSRF protection is mature and automatic, there's no token-storage-in-JS risk (XSS→token theft), and you don't need statelessness since there's one app server pool behind one Nginx. Reach for JWT only if you later add a genuinely separate API client (mobile app, third-party integration) — explicitly out of scope per your spec.
- **MikroTik credentials in DB**: **encrypt (Fernet/AES-GCM), never hash** — you must be able to decrypt to use them (see §2.A). Key lives in env/secrets manager, rotated independently of the DB.
- **Login brute-force protection**: `django-axes` (locks out after N failed attempts per username/IP with cooldown) or `django-ratelimit` on the login view.
- **Input validation**: Django forms/`ModelForm` give you server-side validation and auto-escaping in templates (XSS-safe by default); the ORM parameterizes all queries (SQLi-safe by default) as long as you avoid raw SQL string interpolation. Validate PPP usernames against RouterOS's allowed charset before sending to the router.
- **Transport security**: panel behind Nginx with TLS (Let's Encrypt/certbot); MikroTik REST calls over HTTPS only, with the router's certificate pinned (`tls_ca_cert` in §3.2) rather than `verify=False`.

### 6.5 Frontend

- **Server-rendered templates + Bootstrap 5 RTL + htmx + Alpine.js + Chart.js** — not React/Vue.
  - Why: this is an internal CRUD-heavy panel for ~1000 records, not a rich client app. A full SPA adds a build pipeline, a second language/tooling stack, and a client-side auth story you don't need — all cost, no benefit at this scale. htmx gives you partial-page updates (live online-status refresh, inline edit) with a fraction of the complexity.
- **Bilingual/RTL**: Django's i18n framework — `{% load i18n %}` + `{% trans %}`/`{% blocktrans %}` in templates, `.po`/`.mo` files per language (`locale/fa/`, `locale/en/`), `LocaleMiddleware`, and a per-admin `language` field (§3.1) that sets `django.utils.translation.activate()` per request. In `base.html`:
  ```html
  <html lang="{{ LANGUAGE_CODE }}" dir="{% if LANGUAGE_BIDI %}rtl{% else %}ltr{% endif %}">
  ```
  Load Bootstrap's official `bootstrap.rtl.min.css` when `LANGUAGE_BIDI` is true, the LTR build otherwise. Use a Persian web font (e.g. Vazirmatn) for `fa`.
- **Charts**: **Chart.js** — lightweight, no build step, plain `<script>` include, good enough for traffic/revenue line and bar charts at this data volume. (If you outgrow it, ECharts is the next step up — not needed here.)

### 6.6 Billing Implementation

- **Auto-suspend on expiration**: Celery Beat daily task (`expire_sweep`) queries `local_users` where `expiration_date < today AND status = 'active'`, sets `disabled=yes` via `client.set_secret(...)`, flips local `status` to `expired`, logs the action, and (Phase 3) queues a notification.
- **Proration for mid-cycle plan changes**: `new_price_fraction = (old_plan.price / old_plan.validity_days) * days_remaining` credited against the new plan's invoice — i.e. compute unused-day value on the old plan, subtract it from the new plan's invoice `amount` (floor at 0), and record both the original and adjusted amounts in `invoice.notes` for auditability.
- **Invoice status tracking**: `unpaid → paid` (on full payment recorded), `unpaid → overdue` (Celery Beat daily task flips any `unpaid` invoice past `due_date`), `→ cancelled` (manual admin action with reason), `→ credited` (see refunds below). Status is a plain column (§3.7), not derived — simpler to query and index for the "overdue list" report.
- **Invoice numbering**: **sequential per year-month**, `INV-YYYYMM-0001`, via a locked counter row (`InvoiceCounter`, §5.5) — human-readable, sortable, and satisfies most jurisdictions' "sequential, gapless" invoicing requirements better than a UUID. A UUID is fine as the *internal* PK but shouldn't be the number shown to customers.
- **Refunds/credit notes**: model as a **separate `Invoice` row with a negative `total` and `status='credited'`, linked via a `related_invoice_id`** (add this FK if you need refunds in Phase 2) rather than mutating the original paid invoice — preserves the audit trail. A `payments` row with a negative `amount` (or a dedicated `refund` method) records the money movement.

### 6.7 Testing & Debugging

- **MikroTik simulator for offline dev**: a small standalone Flask/FastAPI app (`tests/fixtures/mikrotik_mock_server.py`) that implements just the endpoints in §2.B, backed by an in-memory list, returning RouterOS-shaped JSON. Point `MIKROTIK_BASE_URL` at it in local dev — no physical/virtual router required for day-to-day feature work. Optionally run RouterOS **CHR** (Cloud Hosted Router, free for eval) in a local VM/container for occasional true-integration testing before release.
- **Unit tests**: `pytest-django` + `factory_boy` for model factories; mock `MikroTikClient` at the service-function boundary (`apps/ppp_users/services.py`) with `unittest.mock.patch` or `respx` (httpx-aware mocking) for HTTP-level tests of `client.py` itself.
- **Billing tests**: table-driven unit tests on `generate_invoice_for_new_user`, `renew_user`, and the proration formula with fixed dates (`freezegun`) — these are pure functions once the MikroTik call is mocked out, so they're fast and deterministic.
- **Integration smoke test**: a `pytest` marker (`@pytest.mark.integration`) that only runs against a real/CHR router in CI when explicitly requested, not on every commit.

### 6.8 Deployment

- **Process model**: **Gunicorn (sync/`gthread` workers) behind Nginx** — the standard, well-understood Django production setup; `gthread` gives you the thread-pool behavior discussed in §6.2 without adopting ASGI/async Django.
- **Docker Compose**: yes — for a small ops team, Compose gives reproducible deploys and easy `db`/`redis`/`web`/`worker`/`beat`/`nginx` service separation without the overhead of Kubernetes at this scale. See §10.
- **Sizing for 1000 users**: **2 vCPU / 4 GB RAM / 40 GB SSD** is comfortable headroom (Postgres + Redis + Django + Celery all running on one box); double RAM if you colocate Postgres and expect years of `traffic_logs` growth without archiving. This is a light workload — 1000 rows in the hot tables, low request rate from a handful of admins.
- **Env management**: `django-environ` reading a `.env` file (never committed — see `.env.example` in §10), or a proper secrets manager in more regulated deployments.
- **TLS**: Nginx terminates TLS for the panel (Let's Encrypt via certbot, auto-renewed); MikroTik REST calls use HTTPS with the router's own cert pinned as discussed in §6.4/§2.A — enable `www-ssl` on the router and disable plain `www` (port 80) once confirmed working.

### 6.9 Does MikroTik's User Manager integrate with REST API v7? PPP Secret vs. User Manager vs. transport choice

- **RouterOS's own "User Manager" (`/user-manager`, a RADIUS server package) is a *different* subsystem from `/ppp/secret`.** Since your spec is explicitly "manage PPP users, replace the built-in User Manager," you should manage users via **`/ppp/secret` directly** (local PPP authentication on the router), **not** via `/user-manager`'s RADIUS tables. `/ppp/secret` *is* fully exposed over the REST API in v7 — that's exactly what §2.B uses.
- **REST API vs. SSH vs. legacy binary API (port 8728/8729)**:
  | | REST API (v7) | Legacy API (8728/8729) | SSH |
  |---|---|---|---|
  | Data format | JSON, easy to consume from any HTTP client | Custom binary protocol, needs a dedicated library (`librouteros`) | Plain-text CLI, needs parsing |
  | Library needs | None — any HTTP client | `librouteros` (Python) or similar | `paramiko`/`netmiko` + fragile output parsing |
  | Performance at 1000 users | Good — HTTP/1.1 keep-alive, fine for this volume | Slightly faster raw throughput, marginal at this scale | Slowest, not designed for structured automation |
  | Error handling | Standard HTTP status codes + JSON error body | Custom binary error frames | Exit codes + free-text — brittle |
  | Future-proofing | MikroTik's actively developed direction for v7+ | Still supported, but REST is now preferred for new integrations | Meant for humans, not automation |
- **For 1000 users, REST API is the most reliable and maintainable choice** — structured JSON, standard HTTP semantics (retries, timeouts, status codes) work with off-the-shelf tooling (`httpx`), and you avoid maintaining a binary-protocol or CLI-scraping integration. This is why §2 is built entirely on it.

### 6.10 Migration & Data Sync

- **Initial import**: one-off Celery task that calls `list_secrets()` for each router and upserts into `local_users` (matched by `(mikrotik_id, username)`), leaving `plan`/billing fields null for manual admin assignment afterward.
- **Bi-directional periodic sync** (Celery Beat, every 10–15 min): 
  1. Pull `/ppp/secret` from the router.
  2. For secrets **on the router but not in `local_users`** → create a local row flagged `needs_plan_assignment` (someone added a user directly on the router — surface it to admins rather than silently adopting it).
  3. For `local_users` rows **not found on the router** → mark `status='missing_on_device'` and alert (don't auto-delete — could be a temporary router hiccup or a backup restore, see below).
  4. For rows present on both sides → reconcile `disabled` flag and `profile` from the router into local (**router wins for on/off + profile**, since that's operational state); local's `plan`/`expiration_date`/billing fields are never overwritten from the router (**local wins for billing**).
- **Router restored from backup**: this is exactly case 3 above at scale — a bulk `missing_on_device` flood. The sync job should detect "more than X% of a router's users went missing in one pass" and **pause auto-reconciliation for that router with an alert**, rather than mass-flagging/disabling everything, so an admin can confirm it's a real restore (and choose to re-push all local users back onto the router) versus a transient connectivity issue.
- This periodic job is the same Celery Beat schedule that also refreshes `last_sync_at`/`last_status` on `mikrotiks` (§3.2), so "router health" in the dashboard and "data consistency" share one code path.

---

## 7. Installation Guide (Zero to Running)

```bash
# 1. System packages (Debian/Ubuntu)
sudo apt update && sudo apt install -y python3.12 python3.12-venv python3-pip \
    postgresql postgresql-contrib redis-server nginx git

# 2. Clone and set up the project
git clone <your-repo-url> mikrotik-usermanager
cd mikrotik-usermanager
python3.12 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# 3. PostgreSQL
sudo -u postgres psql -c "CREATE DATABASE usermanager;"
sudo -u postgres psql -c "CREATE USER usermanager_app WITH PASSWORD 'change-me';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE usermanager TO usermanager_app;"
sudo -u postgres psql -d usermanager -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"

# 4. Environment
cp .env.example .env
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
# paste the output into CREDENTIAL_ENCRYPTION_KEY in .env, fill in DB/Redis URLs, SECRET_KEY

# 5. Django setup
python manage.py migrate
python manage.py createsuperuser
python manage.py compilemessages   # compiles locale/fa, locale/en .po -> .mo
python manage.py collectstatic --noinput

# 6. Add your first router (via Django admin at /admin/ or a management command)
python manage.py add_router --name "Main-Router" --host 10.0.0.1 --port 443 \
    --username api-user --password "..." 

# 7. Run for development
python manage.py runserver 0.0.0.0:8000
# in separate terminals:
celery -A config worker -l info --concurrency=4
celery -A config beat -l info

# 8. Production: see docker-compose.yml (§10) for the containerized path,
#    or systemd units running gunicorn + celery worker + celery beat behind Nginx.
```

---

## 8. Best Practices

**Security**
- Never commit `.env`, credentials, or the Fernet key — `.gitignore` them, inject via CI/CD secrets or the host's secret manager.
- Principle of least privilege on the RouterOS API user/API key — a dedicated user with only `read,write,api` policy, not `full`.
- Rotate `SECRET_KEY`, `CREDENTIAL_ENCRYPTION_KEY`, and router credentials periodically; rotating the Fernet key requires re-encrypting stored secrets (write a small management command for this).
- Enforce `DEBUG=False`, `ALLOWED_HOSTS`, HSTS, and secure cookie flags in `settings/prod.py`.

**Performance**
- Index every column you filter/sort by in the admin UI (`username`, `status`, `expiration_date`, `invoice.status+due_date`) — see §3 and §11.
- Keep MikroTik connection pools alive process-wide (module-level registry, §4's `apps/mikrotik/registry.py`) instead of reconnecting per request.
- Paginate every list view server-side; never load all 1000 users into one template render.

**Billing**
- Generate invoices inside the same DB transaction as the state change that triggers them (§5.3, §5.5) — an invoice should never exist without the corresponding user/renewal action, or vice versa.
- Treat invoice `total` as computed-and-stored (not recomputed on read) so historical invoices don't silently change if plan prices change later.
- Log every payment with `received_by` — billing disputes need an audit trail of *who* recorded *what*.

**Maintenance**
- Nightly `pg_dump` + off-box copy (S3-compatible storage or another host); test restores quarterly, not just backups.
- Centralize logs (Django app log + Celery log + Nginx access/error log) to one place (even just `journald`/rotated files at this scale) so a MikroTik outage is diagnosable from one location.
- Monitor: router reachability (from `mikrotiks.last_status`), Celery queue depth, disk space for Postgres — a simple `django-health-check` endpoint plus an uptime monitor hitting it is enough at this scale.

**Multilingual**
- Never hardcode user-facing strings — always `{% trans %}`/`gettext()`, even for seemingly "obvious" labels, or Persian translation coverage silently degrades over time.
- Test both `fa` and `en` after every UI change — RTL layout bugs (icon direction, table column order, form label alignment) are easy to introduce and easy to miss if you only ever view the panel in one language.

---

## 9. MikroTik v7 Special Notes

- **REST API is new in v7** — v6 had no REST API at all; automation against v6 required the legacy binary API (8728) or SSH/scripting. If you ever need to support a mixed v6/v7 fleet, the `MikroTikClient` abstraction (§2.C) is exactly where you'd add a v6 code path — keep the service layer (§5) calling only the abstraction, never endpoint paths directly, for this reason.
- **Numeric/duration fields serialize as strings** in the REST JSON (e.g., `"bytes-in": "1234567"`, `"uptime": "1d02:03:04"`) — always cast explicitly (`int(...)`), and note `uptime`/duration strings need a small parser (RouterOS's `DdHH:MM:SS`-ish format) rather than being an ISO duration.
- **Field names use hyphens** (`bytes-in`, `last-logged-out`) since they mirror the CLI property names — don't assume `snake_case` or `camelCase` from other REST APIs you've used.
- **`.id` values** (e.g. `*3F`) are what you address existing objects by in `set`/`remove`/PATCH/DELETE calls — store the one you got back from `create_secret` (§5.3) exactly as returned; don't try to reconstruct it.
- **TLS by default is self-signed** — either pin the router's certificate (`tls_ca_cert`, §3.2/§6.4) or issue it a certificate from an internal CA; avoid `verify=False` in production.
- **API keys (`/user/api-key`) require RouterOS 7.13+** — check your fleet's version before relying on them; older 7.x routers need HTTP Basic Auth with a dedicated limited-privilege user instead.
- **Rate/behavior differences from v6**: profile-based rate limiting, PPP queue types, and some property names shifted between v6 and v7 — when porting any v6-era scripts/snippets you find online, verify property names against the v7 `/ppp/profile` and `/ppp/secret` docs rather than assuming v6 syntax works unchanged.

---

## 10. Sample Configuration Files

### `.env.example`
```ini
# Django
DJANGO_SETTINGS_MODULE=config.settings.prod
SECRET_KEY=change-me-to-a-random-50-char-string
DEBUG=False
ALLOWED_HOSTS=panel.example.com

# Database
DATABASE_URL=postgres://usermanager_app:change-me@db:5432/usermanager

# Redis / Celery
REDIS_URL=redis://redis:6379/0
CELERY_BROKER_URL=redis://redis:6379/1

# Credential encryption (Fernet key, generate with cryptography.Fernet.generate_key())
CREDENTIAL_ENCRYPTION_KEY=

# i18n
LANGUAGE_CODE=fa
TIME_ZONE=Asia/Tehran

# Login throttling
AXES_FAILURE_LIMIT=5
AXES_COOLOFF_TIME=1
```

### `docker-compose.yml`
```yaml
services:
  db:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: usermanager
      POSTGRES_USER: usermanager_app
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    restart: unless-stopped

  web:
    build: .
    command: gunicorn config.wsgi:application -c deploy/gunicorn.conf.py
    env_file: .env
    depends_on: [db, redis]
    volumes:
      - static:/app/staticfiles
    restart: unless-stopped

  worker:
    build: .
    command: celery -A config worker -l info --concurrency=4
    env_file: .env
    depends_on: [db, redis]
    restart: unless-stopped

  beat:
    build: .
    command: celery -A config beat -l info
    env_file: .env
    depends_on: [db, redis]
    restart: unless-stopped

  nginx:
    image: nginx:1.25-alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./deploy/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - static:/app/staticfiles:ro
      - ./certs:/etc/nginx/certs:ro
    depends_on: [web]
    restart: unless-stopped

volumes:
  pgdata:
  static:
```

### `deploy/nginx.conf`
```nginx
server {
    listen 80;
    server_name panel.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name panel.example.com;

    ssl_certificate     /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 5M;

    location /static/ {
        alias /app/staticfiles/;
        expires 30d;
    }

    location / {
        proxy_pass http://web:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 30s;
    }
}
```

### `deploy/gunicorn.conf.py`
```python
bind = "0.0.0.0:8000"
workers = 3
worker_class = "gthread"
threads = 4
timeout = 30
max_requests = 1000
max_requests_jitter = 50
```

---

## 11. Performance Tuning for 1000 Users

- **Gunicorn**: `workers = 2*CPU+1` capped low (this workload is I/O-bound waiting on MikroTik, not CPU-bound) — `gthread` with 4 threads/worker handles concurrent admin requests without needing many processes.
- **Celery**: separate queues for `sync` (frequent, light — `/ppp/active` polling) vs `bulk` (occasional, heavier — full resync, invoice sweep) so a slow bulk job never delays the 5-minute online-status refresh; `--concurrency=4` is plenty at this scale.
- **Database indexes** (recap from §3): `local_users(mikrotik_id, username)` unique, `local_users(expiration_date)`, `local_users(status)`, `invoices(status, due_date)`, `traffic_logs(local_user_id, date)` unique — these cover every list/filter/report query in the panel. Run `EXPLAIN ANALYZE` on the dashboard's aggregate queries once you have realistic data volume and add covering indexes if needed.
- **Traffic log growth**: 1000 users × 1 row/day = ~365k rows/year — trivial for Postgres; no partitioning needed at this scale, but consider archiving/rolling up rows older than 12–24 months into monthly aggregates if you want to cap table size long-term.
- **Cache hot paths**: dashboard aggregates and online-status (§6.3) are the only things worth caching at this scale — don't over-cache list views, Postgres will serve 1000-row paginated queries in single-digit milliseconds without help.
- **Polling interval**: 5-minute `/ppp/active` polling per router (§5.2) is enough for "traffic usage" reporting; if you want near-real-time online/offline status in the UI, poll that specific endpoint more often (30–60s) but keep it cheap — it's one HTTP call per router regardless of user count, since RouterOS returns all active sessions in one response.
- **Multi-router scale-out**: the design already shards naturally by `mikrotik_id` — Celery tasks are per-router (§5.2's `poll_active_sessions(mikrotik_id)`), so adding more routers adds more (cheap, parallel) tasks rather than slowing down existing ones.

---

## Appendix: Immediate Action Item (unrelated to this design, found while reviewing the repo)

`includes/config.php` and `includes/ibsng_api.php` in this repository contain **hardcoded, plaintext production credentials committed to git** (MySQL password, IBSng admin password). Regardless of whether/when this new MikroTik panel replaces that system:
1. Rotate those credentials now (DB password, IBSng admin password).
2. Remove them from the current files (move to `.env`/environment variables).
3. Scrub them from git history (e.g. `git filter-repo`) since rotation alone doesn't un-expose history that may already be cloned/cached elsewhere.
