from django import forms
from django.utils.translation import gettext_lazy as _

from apps.plans.models import Plan
from apps.routers.models import Mikrotik

from .models import LocalUser


class CreatePppUserForm(forms.Form):
    mikrotik = forms.ModelChoiceField(queryset=Mikrotik.objects.filter(is_active=True), label=_("Router"))
    username = forms.CharField(max_length=100, label=_("Username"))
    password = forms.CharField(widget=forms.PasswordInput, label=_("Password"))
    plan = forms.ModelChoiceField(queryset=Plan.objects.filter(is_active=True), label=_("Plan"))
    service = forms.ChoiceField(choices=LocalUser.Service.choices, initial=LocalUser.Service.PPPOE, label=_("Service"))
    full_name = forms.CharField(max_length=150, required=False, label=_("Full name"))
    phone = forms.CharField(max_length=30, required=False, label=_("Phone"))

    def clean_username(self):
        username = self.cleaned_data["username"]
        if not username.replace("_", "").replace("-", "").isalnum():
            raise forms.ValidationError(_("Username may only contain letters, digits, - and _"))
        return username


class EditPppUserForm(forms.Form):
    password = forms.CharField(widget=forms.PasswordInput, required=False, label=_("New password (leave blank to keep)"))
    rate_limit = forms.CharField(max_length=50, required=False, label=_("Rate limit (e.g. 10M/2M)"))
    plan = forms.ModelChoiceField(queryset=Plan.objects.filter(is_active=True), required=False, label=_("Plan"))
    full_name = forms.CharField(max_length=150, required=False, label=_("Full name"))
    phone = forms.CharField(max_length=30, required=False, label=_("Phone"))


for _form in (CreatePppUserForm, EditPppUserForm):
    for _field in _form.base_fields.values():
        existing = _field.widget.attrs.get("class", "")
        css = "form-select" if isinstance(_field.widget, (forms.Select, forms.SelectMultiple)) else "form-control"
        _field.widget.attrs["class"] = (existing + " " + css).strip()
