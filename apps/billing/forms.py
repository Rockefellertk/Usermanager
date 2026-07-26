from django import forms
from django.utils.translation import gettext_lazy as _

from .models import Payment


class RecordPaymentForm(forms.Form):
    amount = forms.DecimalField(max_digits=12, decimal_places=2, label=_("Amount"))
    method = forms.ChoiceField(choices=Payment.Method.choices, label=_("Method"))
    reference = forms.CharField(max_length=100, required=False, label=_("Reference"))
    notes = forms.CharField(widget=forms.Textarea, required=False, label=_("Notes"))


for field in RecordPaymentForm.base_fields.values():
    existing = field.widget.attrs.get("class", "")
    css = "form-select" if isinstance(field.widget, forms.Select) else "form-control"
    field.widget.attrs["class"] = (existing + " " + css).strip()
