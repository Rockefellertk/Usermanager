from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.cache import cache
from django.core.paginator import Paginator
from django.shortcuts import get_object_or_404, redirect, render
from django.utils.translation import gettext as _

from apps.billing.services import renew_user
from apps.core.decorators import write_required
from apps.mikrotik.exceptions import MikroTikAPIError, MikroTikUnreachable

from . import services
from .forms import CreatePppUserForm, EditPppUserForm
from .models import LocalUser


@login_required
def list_view(request):
    qs = LocalUser.objects.select_related("plan", "mikrotik").all()

    search = request.GET.get("q", "").strip()
    if search:
        qs = qs.filter(username__icontains=search)

    status = request.GET.get("status", "")
    if status:
        qs = qs.filter(status=status)

    plan_id = request.GET.get("plan", "")
    if plan_id:
        qs = qs.filter(plan_id=plan_id)

    paginator = Paginator(qs, 25)
    page_obj = paginator.get_page(request.GET.get("page"))

    online_names = set()
    for mikrotik_id in qs.values_list("mikrotik_id", flat=True).distinct():
        active = cache.get(f"mikrotik:{mikrotik_id}:active") or []
        online_names.update(a["name"] for a in active)

    return render(request, "ppp_users/list.html", {
        "page_obj": page_obj,
        "online_names": online_names,
        "search": search,
        "status": status,
        "status_choices": LocalUser.Status.choices,
    })


@write_required
def create_view(request):
    if request.method == "POST":
        form = CreatePppUserForm(request.POST)
        if form.is_valid():
            try:
                local_user, invoice = services.create_ppp_user(
                    mikrotik=form.cleaned_data["mikrotik"],
                    plan=form.cleaned_data["plan"],
                    username=form.cleaned_data["username"],
                    password=form.cleaned_data["password"],
                    admin=request.user,
                    full_name=form.cleaned_data["full_name"],
                    phone=form.cleaned_data["phone"],
                    service=form.cleaned_data["service"],
                )
                messages.success(request, _("User created and invoice %(num)s generated.") % {"num": invoice.invoice_number})
                return redirect("ppp_users:detail", pk=local_user.pk)
            except MikroTikUnreachable:
                messages.error(request, _("The router is unreachable. The user was not created."))
            except MikroTikAPIError as exc:
                messages.error(request, str(exc))
    else:
        form = CreatePppUserForm()
    return render(request, "ppp_users/form.html", {"form": form, "mode": "create"})


@login_required
def detail_view(request, pk):
    local_user = get_object_or_404(LocalUser.objects.select_related("plan", "mikrotik"), pk=pk)
    active = cache.get(f"mikrotik:{local_user.mikrotik_id}:active") or []
    session = next((a for a in active if a["name"] == local_user.username), None)
    invoices = local_user.invoices.all()[:20]
    return render(request, "ppp_users/detail.html", {
        "local_user": local_user, "session": session, "invoices": invoices,
    })


@write_required
def edit_view(request, pk):
    local_user = get_object_or_404(LocalUser, pk=pk)
    if request.method == "POST":
        form = EditPppUserForm(request.POST)
        if form.is_valid():
            try:
                services.update_ppp_user(
                    local_user, request.user,
                    password=form.cleaned_data["password"] or None,
                    rate_limit=form.cleaned_data["rate_limit"] or None,
                    plan=form.cleaned_data["plan"] or None,
                    full_name=form.cleaned_data["full_name"],
                    phone=form.cleaned_data["phone"],
                )
                messages.success(request, _("User updated."))
                return redirect("ppp_users:detail", pk=pk)
            except MikroTikUnreachable:
                messages.error(request, _("The router is unreachable. Changes were not applied."))
    else:
        form = EditPppUserForm(initial={
            "rate_limit": local_user.rate_limit, "plan": local_user.plan_id,
            "full_name": local_user.full_name, "phone": local_user.phone,
        })
    return render(request, "ppp_users/form.html", {"form": form, "mode": "edit", "local_user": local_user})


@write_required
def delete_view(request, pk):
    local_user = get_object_or_404(LocalUser, pk=pk)
    if request.method == "POST":
        try:
            services.delete_ppp_user(local_user, request.user)
            messages.success(request, _("User deleted."))
            return redirect("ppp_users:list")
        except MikroTikUnreachable:
            messages.error(request, _("The router is unreachable. The user was not deleted."))
            return redirect("ppp_users:detail", pk=pk)
    return render(request, "ppp_users/confirm_delete.html", {"local_user": local_user})


@write_required
def toggle_status_view(request, pk):
    local_user = get_object_or_404(LocalUser, pk=pk)
    if request.method == "POST":
        enable = local_user.status != LocalUser.Status.ACTIVE
        try:
            services.set_user_enabled(local_user, enable, request.user)
            messages.success(request, _("User enabled.") if enable else _("User disabled."))
        except MikroTikUnreachable:
            messages.error(request, _("The router is unreachable. Status was not changed."))
    return redirect("ppp_users:detail", pk=pk)


@write_required
def renew_view(request, pk):
    local_user = get_object_or_404(LocalUser, pk=pk)
    if request.method == "POST":
        try:
            invoice = renew_user(local_user, request.user)
            messages.success(request, _("User renewed. Invoice %(num)s generated.") % {"num": invoice.invoice_number})
        except MikroTikUnreachable:
            messages.error(request, _("The router is unreachable. Renewal was not applied."))
    return redirect("ppp_users:detail", pk=pk)
