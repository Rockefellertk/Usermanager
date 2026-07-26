from django.contrib.auth import login as auth_login
from django.contrib.auth import logout as auth_logout
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render
from django.utils.translation import gettext as _
from django.views.decorators.http import require_http_methods

from apps.activity.models import log_activity

from .forms import PanelAuthenticationForm
from .throttle import clear_failures, is_locked_out, register_failure


def _client_ip(request):
    xff = request.META.get("HTTP_X_FORWARDED_FOR")
    return xff.split(",")[0].strip() if xff else request.META.get("REMOTE_ADDR")


@require_http_methods(["GET", "POST"])
def login_view(request):
    if request.user.is_authenticated:
        return redirect("dashboard:index")

    error = None
    if request.method == "POST":
        username = request.POST.get("username", "")
        ip = _client_ip(request)

        if is_locked_out(username, ip):
            error = _("Too many failed attempts. Try again in a few minutes.")
        else:
            form = PanelAuthenticationForm(request, data=request.POST)
            if form.is_valid():
                user = form.get_user()
                clear_failures(username, ip)
                auth_login(request, user)
                log_activity(user, "login", ip_address=ip)
                return redirect(request.GET.get("next") or "dashboard:index")
            register_failure(username, ip)
            error = _("Invalid username or password.")
    form = PanelAuthenticationForm()
    return render(request, "registration/login.html", {"form": form, "error": error})


@login_required
def logout_view(request):
    log_activity(request.user, "logout", ip_address=_client_ip(request))
    auth_logout(request)
    return redirect("accounts:login")
