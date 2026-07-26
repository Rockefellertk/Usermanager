from django.contrib import admin

from .models import LocalUser


@admin.register(LocalUser)
class LocalUserAdmin(admin.ModelAdmin):
    list_display = ("username", "mikrotik", "plan", "status", "expiration_date", "last_synced_at")
    list_filter = ("status", "mikrotik", "plan", "service")
    search_fields = ("username", "full_name", "phone")
