from django.contrib import admin
from django.contrib.auth.admin import UserAdmin

from .models import AdminUser


@admin.register(AdminUser)
class AdminUserAdmin(UserAdmin):
    fieldsets = UserAdmin.fieldsets + (
        ("Panel settings", {"fields": ("role", "language", "phone")}),
    )
    list_display = ("username", "email", "role", "language", "is_active", "is_staff")
    list_filter = ("role", "is_active", "is_staff")
