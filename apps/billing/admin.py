from django.contrib import admin

from .models import Invoice, InvoiceCounter, Payment


class PaymentInline(admin.TabularInline):
    model = Payment
    extra = 0
    readonly_fields = ("received_at",)


@admin.register(Invoice)
class InvoiceAdmin(admin.ModelAdmin):
    list_display = ("invoice_number", "local_user", "total", "status", "issue_date", "due_date")
    list_filter = ("status",)
    search_fields = ("invoice_number", "local_user__username")
    inlines = [PaymentInline]


@admin.register(Payment)
class PaymentAdmin(admin.ModelAdmin):
    list_display = ("invoice", "amount", "method", "received_by", "received_at")
    list_filter = ("method",)


@admin.register(InvoiceCounter)
class InvoiceCounterAdmin(admin.ModelAdmin):
    list_display = ("year_month", "last_value")
