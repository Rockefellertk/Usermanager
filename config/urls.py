from django.contrib import admin
from django.urls import include, path

urlpatterns = [
    path("admin/", admin.site.urls),
    path("i18n/", include("django.conf.urls.i18n")),
    path("accounts/", include("apps.accounts.urls")),
    path("", include("apps.dashboard.urls")),
    path("ppp-users/", include("apps.ppp_users.urls")),
    path("plans/", include("apps.plans.urls")),
    path("billing/", include("apps.billing.urls")),
    path("traffic/", include("apps.traffic.urls")),
]
