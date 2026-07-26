from django.db import models


class TrafficLog(models.Model):
    """One row per PPP user per day; deltas from /ppp/active are accumulated
    into it by apps.traffic.tasks.poll_active_sessions (see tasks.py)."""

    local_user = models.ForeignKey("ppp_users.LocalUser", on_delete=models.CASCADE, related_name="traffic_logs")
    date = models.DateField()
    bytes_in = models.BigIntegerField(default=0)
    bytes_out = models.BigIntegerField(default=0)
    session_count = models.PositiveIntegerField(default=0)
    uptime_seconds = models.BigIntegerField(default=0)

    class Meta:
        constraints = [models.UniqueConstraint(fields=["local_user", "date"], name="uniq_traffic_log_per_day")]
        indexes = [models.Index(fields=["date"])]
        ordering = ["-date"]

    def __str__(self):
        return f"{self.local_user_id} {self.date}"
