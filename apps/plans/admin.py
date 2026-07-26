from django.contrib import admin

from .models import Plan


@admin.register(Plan)
class PlanAdmin(admin.ModelAdmin):
    list_display = ("name", "mikrotik_profile", "rate_limit", "price", "currency", "validity_days", "is_active")
    list_filter = ("is_active", "currency")
    search_fields = ("name", "mikrotik_profile")
