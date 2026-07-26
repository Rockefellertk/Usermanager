from django.contrib.auth.decorators import login_required
from django.db.models import Sum
from django.shortcuts import render
from django.utils import timezone

from apps.billing.models import Invoice
from apps.ppp_users.models import LocalUser
from apps.routers.models import Mikrotik
from apps.traffic.models import TrafficLog


@login_required
def index(request):
    today = timezone.localdate()
    month_start = today.replace(day=1)

    total_users = LocalUser.objects.count()
    active_users = LocalUser.objects.filter(status="active").count()
    expired_users = LocalUser.objects.filter(status="expired").count()

    online_count = 0
    for mikrotik in Mikrotik.objects.filter(is_active=True):
        from django.core.cache import cache
        active = cache.get(f"mikrotik:{mikrotik.id}:active")
        if active:
            online_count += len(active)

    traffic_today = TrafficLog.objects.filter(date=today).aggregate(
        total_in=Sum("bytes_in"), total_out=Sum("bytes_out")
    )
    revenue_this_month = (
        Invoice.objects.filter(status="paid", paid_at__date__gte=month_start).aggregate(total=Sum("total"))["total"]
        or 0
    )
    unpaid_total = Invoice.objects.filter(status__in=["unpaid", "overdue"]).aggregate(total=Sum("total"))["total"] or 0
    overdue_count = Invoice.objects.filter(status="overdue").count()

    context = {
        "total_users": total_users,
        "active_users": active_users,
        "expired_users": expired_users,
        "online_count": online_count,
        "traffic_in_gb": round((traffic_today["total_in"] or 0) / 1e9, 2),
        "traffic_out_gb": round((traffic_today["total_out"] or 0) / 1e9, 2),
        "revenue_this_month": revenue_this_month,
        "unpaid_total": unpaid_total,
        "overdue_count": overdue_count,
        "routers": Mikrotik.objects.filter(is_active=True),
    }
    return render(request, "dashboard/index.html", context)
