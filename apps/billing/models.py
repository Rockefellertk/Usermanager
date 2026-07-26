from django.conf import settings
from django.db import models


class InvoiceCounter(models.Model):
    """One row per year-month, locked with select_for_update() to hand out
    gapless sequential invoice numbers (INV-YYYYMM-####) under concurrency."""

    year_month = models.CharField(max_length=6, unique=True)
    last_value = models.PositiveIntegerField(default=0)

    def __str__(self):
        return f"{self.year_month}: {self.last_value}"


class Invoice(models.Model):
    class Status(models.TextChoices):
        UNPAID = "unpaid", "Unpaid"
        PAID = "paid", "Paid"
        OVERDUE = "overdue", "Overdue"
        CANCELLED = "cancelled", "Cancelled"
        CREDITED = "credited", "Credited"

    invoice_number = models.CharField(max_length=30, unique=True)
    local_user = models.ForeignKey("ppp_users.LocalUser", on_delete=models.CASCADE, related_name="invoices")
    plan = models.ForeignKey("plans.Plan", on_delete=models.SET_NULL, null=True, blank=True)
    amount = models.DecimalField(max_digits=12, decimal_places=2)
    discount = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    tax = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    total = models.DecimalField(max_digits=12, decimal_places=2)
    status = models.CharField(max_length=20, choices=Status.choices, default=Status.UNPAID)
    issue_date = models.DateField()
    due_date = models.DateField()
    paid_at = models.DateTimeField(null=True, blank=True)
    related_invoice = models.ForeignKey(
        "self", on_delete=models.SET_NULL, null=True, blank=True, related_name="credit_notes",
        help_text="Set on a credit-note invoice to point back at the invoice it refunds.",
    )
    notes = models.TextField(blank=True)
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        indexes = [
            models.Index(fields=["local_user"]),
            models.Index(fields=["status", "due_date"]),
        ]
        ordering = ["-issue_date", "-id"]

    def __str__(self):
        return self.invoice_number

    @property
    def amount_paid(self):
        return sum((p.amount for p in self.payments.all()), start=0)

    @property
    def balance_due(self):
        return self.total - self.amount_paid


class Payment(models.Model):
    class Method(models.TextChoices):
        CASH = "cash", "Cash"
        BANK_TRANSFER = "bank_transfer", "Bank Transfer"
        ONLINE_GATEWAY = "online_gateway", "Online Gateway"

    invoice = models.ForeignKey(Invoice, on_delete=models.CASCADE, related_name="payments")
    amount = models.DecimalField(max_digits=12, decimal_places=2)
    method = models.CharField(max_length=20, choices=Method.choices, default=Method.CASH)
    reference = models.CharField(max_length=100, blank=True)
    received_by = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, null=True, blank=True)
    received_at = models.DateTimeField(auto_now_add=True)
    notes = models.TextField(blank=True)

    class Meta:
        indexes = [models.Index(fields=["invoice"])]
        ordering = ["-received_at"]

    def __str__(self):
        return f"{self.amount} for {self.invoice.invoice_number}"
