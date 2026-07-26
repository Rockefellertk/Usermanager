"""Simple cache-backed brute-force login throttle (no extra dependency needed).

Keyed on username+IP so one attacker can't lock out a legitimate admin from a
different network, while still stopping credential stuffing against one account.
"""
from django.conf import settings
from django.core.cache import cache


def _key(username: str, ip: str) -> str:
    return f"login_fail:{username}:{ip}"


def is_locked_out(username: str, ip: str) -> bool:
    return cache.get(_key(username, ip), 0) >= settings.LOGIN_FAILURE_LIMIT


def register_failure(username: str, ip: str) -> None:
    key = _key(username, ip)
    count = cache.get(key, 0) + 1
    cache.set(key, count, timeout=settings.LOGIN_COOLOFF_SECONDS)


def clear_failures(username: str, ip: str) -> None:
    cache.delete(_key(username, ip))
