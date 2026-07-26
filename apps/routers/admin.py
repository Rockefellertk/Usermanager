from django import forms
from django.contrib import admin

from .models import Mikrotik


class MikrotikAdminForm(forms.ModelForm):
    password = forms.CharField(
        required=False, widget=forms.PasswordInput, help_text="Leave blank to keep the current password."
    )
    api_key = forms.CharField(
        required=False, widget=forms.PasswordInput, help_text="Leave blank to keep the current API key."
    )

    class Meta:
        model = Mikrotik
        exclude = ["password_encrypted", "api_key_encrypted"]

    def save(self, commit=True):
        instance = super().save(commit=False)
        if self.cleaned_data.get("password"):
            instance.set_password(self.cleaned_data["password"])
        if self.cleaned_data.get("api_key"):
            instance.set_api_key(self.cleaned_data["api_key"])
        if commit:
            instance.save()
        return instance


@admin.register(Mikrotik)
class MikrotikAdmin(admin.ModelAdmin):
    form = MikrotikAdminForm
    list_display = ("name", "host", "port", "use_api_key", "is_active", "last_status", "last_sync_at")
    list_filter = ("is_active", "last_status", "use_api_key")
    search_fields = ("name", "host")
