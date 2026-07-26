from django.db import models

from apps.core.crypto import decrypt_secret, encrypt_secret


class Mikrotik(models.Model):
    """A managed RouterOS v7 device, reachable over its REST API (port 443 recommended)."""

    class Status(models.TextChoices):
        ONLINE = "online", "Online"
        OFFLINE = "offline", "Offline"
        UNKNOWN = "unknown", "Unknown"

    name = models.CharField(max_length=100, unique=True)
    host = models.CharField(max_length=255, help_text="IP or hostname, no scheme/port")
    port = models.PositiveIntegerField(default=443)
    username = models.CharField(max_length=100)
    password_encrypted = models.BinaryField(blank=True, null=True)
    use_api_key = models.BooleanField(
        default=False, help_text="RouterOS 7.13+ API keys are preferred over Basic Auth"
    )
    use_tls = models.BooleanField(
        default=True,
        help_text="Keep enabled for any real router (credentials go over the wire otherwise). "
                   "Only disable for a local plain-HTTP dev/test target such as the bundled mock server.",
    )
    api_key_encrypted = models.BinaryField(blank=True, null=True)
    tls_ca_cert = models.TextField(
        blank=True, help_text="Pin the router's certificate (PEM). Leave blank to use system CAs."
    )
    is_active = models.BooleanField(default=True)
    last_sync_at = models.DateTimeField(null=True, blank=True)
    last_status = models.CharField(max_length=20, choices=Status.choices, default=Status.UNKNOWN)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        constraints = [models.UniqueConstraint(fields=["host", "port"], name="uniq_mikrotik_host_port")]
        ordering = ["name"]

    def __str__(self):
        return f"{self.name} ({self.host}:{self.port})"

    @property
    def base_url(self) -> str:
        scheme = "https" if self.use_tls else "http"
        return f"{scheme}://{self.host}:{self.port}"

    def set_password(self, raw_password: str) -> None:
        self.password_encrypted = encrypt_secret(raw_password)

    def get_password(self) -> str:
        return decrypt_secret(self.password_encrypted)

    def set_api_key(self, raw_key: str) -> None:
        self.api_key_encrypted = encrypt_secret(raw_key)

    def get_api_key(self) -> str:
        return decrypt_secret(self.api_key_encrypted)
