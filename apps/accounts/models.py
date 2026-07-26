from django.contrib.auth.models import AbstractUser
from django.db import models


class AdminUser(AbstractUser):
    """Panel administrators — NOT internet/PPP users (see apps.ppp_users.LocalUser)."""

    class Role(models.TextChoices):
        SUPERADMIN = "superadmin", "Super Admin"
        OPERATOR = "operator", "Operator"
        BILLING = "billing", "Billing"
        VIEWER = "viewer", "Viewer"

    class Language(models.TextChoices):
        FA = "fa", "فارسی"
        EN = "en", "English"

    role = models.CharField(max_length=20, choices=Role.choices, default=Role.OPERATOR)
    language = models.CharField(max_length=5, choices=Language.choices, default=Language.FA)
    phone = models.CharField(max_length=30, blank=True)

    def __str__(self):
        return self.username
