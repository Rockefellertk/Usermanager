from django.contrib import admin

from .models import TrafficLog


@admin.register(TrafficLog)
class TrafficLogAdmin(admin.ModelAdmin):
    list_display = ("local_user", "date", "bytes_in", "bytes_out", "uptime_seconds")
    list_filter = ("date",)
    search_fields = ("local_user__username",)
