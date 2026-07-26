"""Per-router MikroTikClient pool + a small circuit breaker.

One httpx.Client (and its connection pool) is reused per router per
process, instead of opening a new TLS connection on every admin action.
The circuit breaker short-circuits calls to a router that just failed,
so a hung/offline router can't make every admin request in flight pay
the full connect-timeout cost.
"""
from __future__ import annotations

import threading

from django.core.cache import cache

from .client import MikroTikClient
from .exceptions import MikroTikUnreachable

_clients: dict[int, MikroTikClient] = {}
_lock = threading.Lock()

CIRCUIT_BREAKER_THRESHOLD = 3
CIRCUIT_BREAKER_COOLDOWN = 30  # seconds


def _breaker_key(mikrotik_id: int) -> str:
    return f"mikrotik:{mikrotik_id}:breaker_open"


def _fail_key(mikrotik_id: int) -> str:
    return f"mikrotik:{mikrotik_id}:consecutive_failures"


def is_circuit_open(mikrotik_id: int) -> bool:
    return bool(cache.get(_breaker_key(mikrotik_id)))


def record_success(mikrotik_id: int) -> None:
    cache.delete(_fail_key(mikrotik_id))
    cache.delete(_breaker_key(mikrotik_id))


def record_failure(mikrotik_id: int) -> None:
    key = _fail_key(mikrotik_id)
    count = cache.get(key, 0) + 1
    cache.set(key, count, timeout=CIRCUIT_BREAKER_COOLDOWN * 4)
    if count >= CIRCUIT_BREAKER_THRESHOLD:
        cache.set(_breaker_key(mikrotik_id), True, timeout=CIRCUIT_BREAKER_COOLDOWN)


def get_client(mikrotik) -> MikroTikClient:
    """Return a pooled MikroTikClient for a `apps.routers.models.Mikrotik` instance."""
    with _lock:
        client = _clients.get(mikrotik.id)
        if client is None:
            client = MikroTikClient(
                base_url=mikrotik.base_url,
                username=mikrotik.username,
                password=mikrotik.get_password() if not mikrotik.use_api_key else None,
                api_key=mikrotik.get_api_key() if mikrotik.use_api_key else None,
                ca_cert_pem=mikrotik.tls_ca_cert or None,
            )
            _clients[mikrotik.id] = client
        return client


def invalidate_client(mikrotik_id: int) -> None:
    """Drop a cached client, e.g. after credentials were changed in the admin."""
    with _lock:
        client = _clients.pop(mikrotik_id, None)
        if client:
            client.close()


def call_guarded(mikrotik, fn_name: str, *args, **kwargs):
    """Call a MikroTikClient method by name, honoring the circuit breaker."""
    if is_circuit_open(mikrotik.id):
        raise MikroTikUnreachable(f"{mikrotik.name}: circuit open, skipping call (recent failures)")
    client = get_client(mikrotik)
    try:
        result = getattr(client, fn_name)(*args, **kwargs)
    except MikroTikUnreachable:
        record_failure(mikrotik.id)
        raise
    record_success(mikrotik.id)
    return result
