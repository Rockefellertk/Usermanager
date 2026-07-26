from django.conf import settings
from django.db import models


class ActivityLog(models.Model):
    admin = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True,
        on_delete=models.SET_NULL, related_name="activity_logs",
    )
    action = models.CharField(max_length=50)
    target_type = models.CharField(max_length=50, blank=True)
    target_id = models.BigIntegerField(null=True, blank=True)
    detail = models.JSONField(default=dict, blank=True)
    ip_address = models.GenericIPAddressField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-created_at"]
        indexes = [
            models.Index(fields=["admin", "created_at"]),
            models.Index(fields=["target_type", "target_id"]),
        ]

    def __str__(self):
        return f"{self.action} by {self.admin_id} @ {self.created_at:%Y-%m-%d %H:%M}"


def log_activity(admin, action, target_type="", target_id=None, detail=None, ip_address=None):
    return ActivityLog.objects.create(
        admin=admin if (admin and getattr(admin, "is_authenticated", True)) else None,
        action=action,
        target_type=target_type,
        target_id=target_id,
        detail=detail or {},
        ip_address=ip_address,
    )
