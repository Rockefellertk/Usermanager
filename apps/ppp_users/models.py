from django.db import models

from apps.core.crypto import decrypt_secret, encrypt_secret


class LocalUser(models.Model):
    """Cached copy of a MikroTik /ppp/secret entry, plus billing linkage.

    MikroTik is the source of truth for `disabled`/`profile` (operational
    state); this table is the source of truth for plan/pricing/expiration
    (billing state). See services.py for how writes keep both in sync.
    """

    class Status(models.TextChoices):
        ACTIVE = "active", "Active"
        DISABLED = "disabled", "Disabled"
        EXPIRED = "expired", "Expired"
        SUSPENDED = "suspended", "Suspended"
        MISSING_ON_DEVICE = "missing_on_device", "Missing on device"
        NEEDS_PLAN_ASSIGNMENT = "needs_plan_assignment", "Needs plan assignment"

    class Service(models.TextChoices):
        PPPOE = "pppoe", "PPPoE"
        PPTP = "pptp", "PPTP"
        L2TP = "l2tp", "L2TP"
        SSTP = "sstp", "SSTP"

    mikrotik = models.ForeignKey("routers.Mikrotik", on_delete=models.CASCADE, related_name="local_users")
    mikrotik_secret_id = models.CharField(max_length=50, blank=True)
    username = models.CharField(max_length=100)
    password_encrypted = models.BinaryField(blank=True, null=True)
    service = models.CharField(max_length=10, choices=Service.choices, default=Service.PPPOE)
    plan = models.ForeignKey("plans.Plan", on_delete=models.SET_NULL, null=True, blank=True, related_name="local_users")
    profile = models.CharField(max_length=100, blank=True)
    rate_limit = models.CharField(max_length=50, blank=True)
    status = models.CharField(max_length=25, choices=Status.choices, default=Status.ACTIVE)
    expiration_date = models.DateField(null=True, blank=True)
    full_name = models.CharField(max_length=150, blank=True)
    phone = models.CharField(max_length=30, blank=True)
    address = models.TextField(blank=True)
    comment = models.TextField(blank=True)
    last_synced_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        constraints = [models.UniqueConstraint(fields=["mikrotik", "username"], name="uniq_local_user_per_router")]
        indexes = [
            models.Index(fields=["expiration_date"]),
            models.Index(fields=["status"]),
            models.Index(fields=["username"]),
        ]
        ordering = ["username"]

    def __str__(self):
        return f"{self.username}@{self.mikrotik.name}"

    def set_password(self, raw_password: str) -> None:
        self.password_encrypted = encrypt_secret(raw_password)

    def get_password(self) -> str:
        return decrypt_secret(self.password_encrypted)

    @property
    def is_expired(self) -> bool:
        from django.utils import timezone
        return bool(self.expiration_date and self.expiration_date < timezone.localdate())
