from datetime import timedelta

from django.core.cache import cache
from django.db import transaction
from django.utils import timezone

from apps.activity.models import log_activity
from apps.billing.services import generate_invoice_for_new_user
from apps.mikrotik.exceptions import MikroTikUnreachable
from apps.mikrotik.registry import call_guarded

from .models import LocalUser


def _invalidate_online_cache(mikrotik_id):
    cache.delete(f"mikrotik:{mikrotik_id}:active")


@transaction.atomic
def create_ppp_user(*, mikrotik, plan, username, password, admin, full_name="", phone="", service="pppoe"):
    """Create locally + on the router inside one DB transaction; if the
    MikroTik call fails, the whole transaction (including the local row and
    the invoice) rolls back so we never end up with a billed user that
    doesn't actually exist on the router."""
    local_user = LocalUser.objects.create(
        mikrotik=mikrotik,
        username=username,
        plan=plan,
        service=service,
        profile=plan.mikrotik_profile,
        rate_limit=plan.rate_limit,
        status=LocalUser.Status.ACTIVE,
        expiration_date=timezone.localdate() + timedelta(days=plan.validity_days),
        full_name=full_name,
        phone=phone,
    )
    local_user.set_password(password)
    local_user.save(update_fields=["password_encrypted"])

    remote = call_guarded(
        mikrotik, "create_secret",
        name=username, password=password, profile=plan.mikrotik_profile,
        service=service, comment=f"panel:{local_user.id}",
    )
    local_user.mikrotik_secret_id = remote.get(".id") or remote.get("ret", "")
    local_user.last_synced_at = timezone.now()
    local_user.save(update_fields=["mikrotik_secret_id", "last_synced_at"])

    invoice = generate_invoice_for_new_user(local_user, plan, admin)

    log_activity(admin, "user_create", "local_user", local_user.id, {"username": username, "plan": plan.name})
    return local_user, invoice


@transaction.atomic
def update_ppp_user(local_user, admin, *, password=None, rate_limit=None, plan=None, full_name=None, phone=None):
    fields = {}
    if password:
        local_user.set_password(password)
        call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, password=password)
        fields["password_encrypted"] = local_user.password_encrypted
    if rate_limit:
        local_user.rate_limit = rate_limit
        fields["rate_limit"] = rate_limit
        # RouterOS applies per-user rate limiting via /ppp/secret's rate-limit field;
        # profile-based limiting is changed instead if the plan itself changes below.
        call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, **{"rate-limit": rate_limit})
    if plan and plan_id_changed(local_user, plan):
        local_user.plan = plan
        local_user.profile = plan.mikrotik_profile
        local_user.rate_limit = rate_limit or plan.rate_limit
        fields.update({"plan": plan, "profile": plan.mikrotik_profile, "rate_limit": local_user.rate_limit})
        call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, profile=plan.mikrotik_profile)
    if full_name is not None:
        local_user.full_name = full_name
        fields["full_name"] = full_name
    if phone is not None:
        local_user.phone = phone
        fields["phone"] = phone

    if fields:
        local_user.save(update_fields=list(fields.keys()))
        _invalidate_online_cache(local_user.mikrotik_id)
        log_activity(admin, "user_update", "local_user", local_user.id, {k: str(v) for k, v in fields.items()})
    return local_user


def plan_id_changed(local_user, new_plan) -> bool:
    return local_user.plan_id != new_plan.id


@transaction.atomic
def set_user_enabled(local_user, enabled: bool, admin):
    call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, disabled="no" if enabled else "yes")
    local_user.status = LocalUser.Status.ACTIVE if enabled else LocalUser.Status.DISABLED
    local_user.save(update_fields=["status"])
    _invalidate_online_cache(local_user.mikrotik_id)
    log_activity(admin, "user_enable" if enabled else "user_disable", "local_user", local_user.id, {})
    return local_user


def reenable_on_device(local_user):
    """Used by billing.services.renew_user right after extending expiration."""
    call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, disabled="no")
    _invalidate_online_cache(local_user.mikrotik_id)


@transaction.atomic
def delete_ppp_user(local_user, admin):
    try:
        call_guarded(local_user.mikrotik, "remove_secret", local_user.mikrotik_secret_id)
    except MikroTikUnreachable:
        # Router is down — do not silently delete the billing record out from
        # under a still-existing secret; surface the failure to the admin.
        raise
    username, mikrotik_id, local_user_id = local_user.username, local_user.mikrotik_id, local_user.id
    local_user.delete()
    _invalidate_online_cache(mikrotik_id)
    log_activity(admin, "user_delete", "local_user", local_user_id, {"username": username})


MISSING_RATIO_ALERT_THRESHOLD = 0.3  # >30% of a router's users vanishing in one pass looks like a restore, not drift


def sync_router(mikrotik):
    """Bi-directional reconciliation between MikroTik and local_users.

    Router wins for on/off state + profile (operational truth).
    Local DB wins for plan/pricing/expiration (billing truth) — never
    overwritten from the router. See docs for the full reasoning.
    """
    try:
        remote_secrets = call_guarded(mikrotik, "list_secrets")
    except MikroTikUnreachable:
        mikrotik.last_status = "offline"
        mikrotik.save(update_fields=["last_status"])
        return {"error": "unreachable"}

    remote_by_name = {row["name"]: row for row in remote_secrets}
    local_qs = LocalUser.objects.filter(mikrotik=mikrotik)
    local_by_name = {u.username: u for u in local_qs}

    created, updated, flagged_missing = 0, 0, 0

    # Router -> local: adopt secrets that exist on-device but not locally.
    for name, remote in remote_by_name.items():
        if name not in local_by_name:
            LocalUser.objects.create(
                mikrotik=mikrotik,
                mikrotik_secret_id=remote.get(".id", ""),
                username=name,
                service=remote.get("service", "pppoe") if remote.get("service") in LocalUser.Service.values else LocalUser.Service.PPPOE,
                profile=remote.get("profile", ""),
                status=LocalUser.Status.NEEDS_PLAN_ASSIGNMENT,
                last_synced_at=timezone.now(),
                comment=remote.get("comment", ""),
            )
            created += 1

    # Local -> reconcile operational fields, or flag as missing on device.
    missing_count = 0
    for name, local_user in local_by_name.items():
        remote = remote_by_name.get(name)
        if remote is None:
            missing_count += 1
            continue
        remote_disabled = remote.get("disabled") == "true" or remote.get("disabled") is True
        new_status = local_user.status
        if local_user.status in (LocalUser.Status.ACTIVE, LocalUser.Status.DISABLED):
            new_status = LocalUser.Status.DISABLED if remote_disabled else LocalUser.Status.ACTIVE
        if new_status != local_user.status or remote.get("profile", local_user.profile) != local_user.profile:
            local_user.status = new_status
            local_user.profile = remote.get("profile", local_user.profile)
            local_user.mikrotik_secret_id = remote.get(".id", local_user.mikrotik_secret_id)
            local_user.last_synced_at = timezone.now()
            local_user.save(update_fields=["status", "profile", "mikrotik_secret_id", "last_synced_at"])
            updated += 1

    if local_by_name and (missing_count / len(local_by_name)) > MISSING_RATIO_ALERT_THRESHOLD:
        # Looks like a router restore/replace, not normal drift — don't mass-flag,
        # pause auto-reconciliation for this router and surface it for a human.
        log_activity(None, "sync_paused_suspected_restore", "mikrotik", mikrotik.id,
                     {"missing_count": missing_count, "total_local": len(local_by_name)})
        return {"created": created, "updated": updated, "paused_suspected_restore": True}

    for name, local_user in local_by_name.items():
        if name not in remote_by_name and local_user.status != LocalUser.Status.MISSING_ON_DEVICE:
            local_user.status = LocalUser.Status.MISSING_ON_DEVICE
            local_user.save(update_fields=["status"])
            flagged_missing += 1

    mikrotik.last_sync_at = timezone.now()
    mikrotik.last_status = "online"
    mikrotik.save(update_fields=["last_sync_at", "last_status"])
    return {"created": created, "updated": updated, "flagged_missing": flagged_missing}


def expire_sweep():
    """Disable + mark expired any active user whose expiration_date has passed.
    Called from Celery Beat (see tasks.py)."""
    today = timezone.localdate()
    count = 0
    for local_user in LocalUser.objects.filter(status=LocalUser.Status.ACTIVE, expiration_date__lt=today):
        try:
            call_guarded(local_user.mikrotik, "set_secret", local_user.mikrotik_secret_id, disabled="yes")
        except MikroTikUnreachable:
            continue  # router offline — will be retried on the next sweep
        local_user.status = LocalUser.Status.EXPIRED
        local_user.save(update_fields=["status"])
        _invalidate_online_cache(local_user.mikrotik_id)
        log_activity(None, "user_auto_expire", "local_user", local_user.id, {})
        count += 1
    return count
