from datetime import timedelta

from django.db import transaction
from django.utils import timezone

from apps.activity.models import log_activity

from .models import Invoice, InvoiceCounter, Payment

INVOICE_PREFIX = "INV"
DEFAULT_DUE_DAYS = 3


def next_invoice_number() -> str:
    """Sequential per year-month, e.g. INV-202607-0001 — locked to stay gapless
    under concurrent invoice creation (two admins renewing users at once)."""
    year_month = timezone.localdate().strftime("%Y%m")
    with transaction.atomic():
        counter, _ = InvoiceCounter.objects.select_for_update().get_or_create(
            year_month=year_month, defaults={"last_value": 0}
        )
        counter.last_value += 1
        counter.save(update_fields=["last_value"])
        return f"{INVOICE_PREFIX}-{year_month}-{counter.last_value:04d}"


@transaction.atomic
def generate_invoice_for_new_user(local_user, plan, admin, amount=None):
    total = plan.price if amount is None else amount
    invoice = Invoice.objects.create(
        invoice_number=next_invoice_number(),
        local_user=local_user,
        plan=plan,
        amount=total,
        total=total,
        status=Invoice.Status.UNPAID,
        issue_date=timezone.localdate(),
        due_date=timezone.localdate() + timedelta(days=DEFAULT_DUE_DAYS),
        created_by=admin,
    )
    log_activity(admin, "invoice_create", "invoice", invoice.id, {"amount": str(total)})
    return invoice


def prorate_plan_change(local_user, old_plan, new_plan) -> dict:
    """Credit unused days on `old_plan` against the price of `new_plan`.

    Returns {"new_plan_price", "credit", "amount_due"} — amount_due is
    floored at 0 (never issue a negative invoice from a plan change; use a
    credit note, see issue_credit_note, if the credit exceeds the new price).
    """
    remaining_days = 0
    if local_user.expiration_date:
        remaining_days = max((local_user.expiration_date - timezone.localdate()).days, 0)
    credit = (old_plan.daily_price * remaining_days) if old_plan else 0
    amount_due = max(new_plan.price - credit, 0)
    return {"new_plan_price": new_plan.price, "credit": credit, "amount_due": amount_due}


@transaction.atomic
def renew_user(local_user, admin, extend_days=None):
    """Extend expiration, re-enable on MikroTik if needed, and bill for it."""
    from apps.ppp_users.services import reenable_on_device

    plan = local_user.plan
    days = extend_days or (plan.validity_days if plan else 30)
    base = max(local_user.expiration_date or timezone.localdate(), timezone.localdate())
    local_user.expiration_date = base + timedelta(days=days)
    was_disabled = local_user.status != "active"
    local_user.status = "active"
    local_user.save(update_fields=["expiration_date", "status"])

    if was_disabled:
        reenable_on_device(local_user)

    invoice = generate_invoice_for_new_user(local_user, plan, admin, amount=plan.price if plan else 0)
    log_activity(admin, "user_renew", "local_user", local_user.id, {"new_expiration": str(local_user.expiration_date)})
    return invoice


@transaction.atomic
def record_payment(invoice, amount, method, admin, reference="", notes=""):
    payment = Payment.objects.create(
        invoice=invoice, amount=amount, method=method,
        received_by=admin, reference=reference, notes=notes,
    )
    if invoice.amount_paid >= invoice.total:
        invoice.status = Invoice.Status.PAID
        invoice.paid_at = timezone.now()
        invoice.save(update_fields=["status", "paid_at"])
    log_activity(admin, "payment_record", "invoice", invoice.id, {"amount": str(amount), "method": method})
    return payment


@transaction.atomic
def issue_credit_note(original_invoice, amount, admin, reason=""):
    """Refund / credit note: a separate negative-total invoice linked back to
    the original, rather than mutating the paid invoice — keeps the audit trail."""
    credit = Invoice.objects.create(
        invoice_number=next_invoice_number(),
        local_user=original_invoice.local_user,
        plan=original_invoice.plan,
        amount=-amount,
        total=-amount,
        status=Invoice.Status.CREDITED,
        issue_date=timezone.localdate(),
        due_date=timezone.localdate(),
        related_invoice=original_invoice,
        notes=reason,
        created_by=admin,
    )
    log_activity(admin, "credit_note_issue", "invoice", credit.id, {"amount": str(amount), "reason": reason})
    return credit


def overdue_sweep():
    """Flip unpaid invoices past their due date to overdue. Called from Celery Beat."""
    today = timezone.localdate()
    updated = Invoice.objects.filter(status=Invoice.Status.UNPAID, due_date__lt=today).update(
        status=Invoice.Status.OVERDUE
    )
    return updated
