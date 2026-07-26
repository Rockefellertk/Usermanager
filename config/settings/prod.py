from .base import *  # noqa

DEBUG = False

# Set ENABLE_SSL=False in .env to run production settings over plain HTTP
# (e.g. while you don't have a domain/certificate yet). Switch it back to
# True — the default — once Nginx + a real certificate are in front of this.
ENABLE_SSL = env.bool("ENABLE_SSL", default=True)

SECURE_SSL_REDIRECT = ENABLE_SSL
SESSION_COOKIE_SECURE = ENABLE_SSL
CSRF_COOKIE_SECURE = ENABLE_SSL
if ENABLE_SSL:
    SECURE_HSTS_SECONDS = 31536000
    SECURE_HSTS_INCLUDE_SUBDOMAINS = True
    SECURE_HSTS_PRELOAD = True
SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")

if not env("SECRET_KEY", default=None):
    raise RuntimeError("SECRET_KEY must be set via environment in production")
if not CREDENTIAL_ENCRYPTION_KEY:
    raise RuntimeError("CREDENTIAL_ENCRYPTION_KEY must be set via environment in production")
