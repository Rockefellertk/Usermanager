from django.db import models


class Plan(models.Model):
    name = models.CharField(max_length=100)
    mikrotik_profile = models.CharField(max_length=100, help_text="Must match a /ppp/profile name on the router")
    rate_limit = models.CharField(max_length=50, help_text="RouterOS format, e.g. 10M/2M (down/up)")
    price = models.DecimalField(max_digits=12, decimal_places=2)
    currency = models.CharField(max_length=3, default="IRR")
    validity_days = models.PositiveIntegerField(default=30)
    data_cap_gb = models.PositiveIntegerField(null=True, blank=True, help_text="Blank = unlimited")
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["name"]

    def __str__(self):
        return f"{self.name} ({self.price} {self.currency}/{self.validity_days}d)"

    @property
    def daily_price(self):
        return self.price / self.validity_days if self.validity_days else self.price
