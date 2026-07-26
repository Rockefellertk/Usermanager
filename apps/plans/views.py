from django.urls import reverse_lazy
from django.views.generic import CreateView, DeleteView, ListView, UpdateView

from apps.core.mixins import RoleRequiredMixin, WriteRoleRequiredMixin

from .models import Plan


class PlanListView(RoleRequiredMixin, ListView):
    model = Plan
    template_name = "plans/list.html"
    context_object_name = "plans"
    paginate_by = 25


class PlanCreateView(WriteRoleRequiredMixin, CreateView):
    model = Plan
    fields = ["name", "mikrotik_profile", "rate_limit", "price", "currency", "validity_days", "data_cap_gb", "is_active"]
    template_name = "plans/form.html"
    success_url = reverse_lazy("plans:list")


class PlanUpdateView(WriteRoleRequiredMixin, UpdateView):
    model = Plan
    fields = ["name", "mikrotik_profile", "rate_limit", "price", "currency", "validity_days", "data_cap_gb", "is_active"]
    template_name = "plans/form.html"
    success_url = reverse_lazy("plans:list")


class PlanDeleteView(WriteRoleRequiredMixin, DeleteView):
    model = Plan
    template_name = "plans/confirm_delete.html"
    success_url = reverse_lazy("plans:list")
