import logging
import re

from celery import shared_task
from django.core.cache import cache
from django.utils import timezone

from apps.mikrotik.exceptions import MikroTikUnreachable
from apps.mikrotik.registry import call_guarded
from apps.ppp_users.models import LocalUser
from apps.routers.models import Mikrotik

from .models import TrafficLog

logger = logging.getLogger("traffic")

ONLINE_CACHE_TTL = 900  # kept a bit longer than the poll interval so the UI never shows a false "offline" blip
_UPTIME_RE = re.compile(r"(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$")


def parse_uptime(value: str) -> int:
    """RouterOS uptime strings look like '2h30m00s' or '1w2d03:04:05' —
    handle the common 'wdhms' form used by /ppp/active."""
    if not value:
        return 0
    if ":" in value:
        parts = [int(p) for p in re.split(r"[dh:]", value) if p != ""]
        # fall back: treat as H:M:S (with optional leading Nd)
        seconds = 0
        for p in parts[-3:]:
            seconds = seconds * 60 + p
        return seconds
    match = _UPTIME_RE.match(value)
    if not match:
        return 0
    weeks, days, hours, minutes, secs = (int(g) if g else 0 for g in match.groups())
    return ((weeks * 7 + days) * 24 + hours) * 3600 + minutes * 60 + secs


@shared_task(bind=True, max_retries=3, default_retry_delay=15)
def poll_active_sessions(self, mikrotik_id):
    mikrotik = Mikrotik.objects.get(id=mikrotik_id)
    try:
        active = call_guarded(mikrotik, "active_sessions")
    except MikroTikUnreachable:
        logger.warning("poll_active_sessions: %s unreachable", mikrotik.name)
        return {"error": "unreachable"}

    cache.set(f"mikrotik:{mikrotik_id}:active", active, timeout=ONLINE_CACHE_TTL)

    today = timezone.localdate()
    local_users = {
        u.username: u for u in LocalUser.objects.filter(mikrotik=mikrotik, username__in=[a["name"] for a in active])
    }

    for session in active:
        username = session.get("name")
        local_user = local_users.get(username)
        if not local_user:
            continue

        session_id = session.get(".id", username)
        bytes_in = int(session.get("bytes-in", 0) or 0)
        bytes_out = int(session.get("bytes-out", 0) or 0)

        # /ppp/active's counters are cumulative for the current session, not
        # per-poll deltas — track the last-seen value per session and only
        # add the difference, so polling more often never inflates totals.
        last_key = f"mikrotik:{mikrotik_id}:session_bytes:{session_id}"
        last_in, last_out = cache.get(last_key, (0, 0))
        delta_in = bytes_in - last_in if bytes_in >= last_in else bytes_in  # counter reset -> new session
        delta_out = bytes_out - last_out if bytes_out >= last_out else bytes_out
        cache.set(last_key, (bytes_in, bytes_out), timeout=ONLINE_CACHE_TTL)

        log, _created = TrafficLog.objects.get_or_create(
            local_user=local_user, date=today,
            defaults={"bytes_in": 0, "bytes_out": 0, "session_count": 0, "uptime_seconds": 0},
        )
        log.bytes_in += max(delta_in, 0)
        log.bytes_out += max(delta_out, 0)
        log.uptime_seconds = max(log.uptime_seconds, parse_uptime(session.get("uptime", "")))
        log.save(update_fields=["bytes_in", "bytes_out", "uptime_seconds"])

    mikrotik.last_status = "online"
    mikrotik.save(update_fields=["last_status"])
    return {"sessions": len(active)}


@shared_task
def poll_all_routers():
    ids = list(Mikrotik.objects.filter(is_active=True).values_list("id", flat=True))
    for mikrotik_id in ids:
        poll_active_sessions.delay(mikrotik_id)
    return {"routers_queued": len(ids)}
