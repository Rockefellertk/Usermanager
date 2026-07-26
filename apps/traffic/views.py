from datetime import timedelta

from django.contrib.auth.decorators import login_required
from django.http import JsonResponse
from django.utils import timezone

from .models import TrafficLog


@login_required
def chart_data(request, local_user_id):
    days = int(request.GET.get("days", 30))
    since = timezone.localdate() - timedelta(days=days)
    rows = TrafficLog.objects.filter(local_user_id=local_user_id, date__gte=since).order_by("date")
    return JsonResponse({
        "labels": [r.date.isoformat() for r in rows],
        "download_gb": [round(r.bytes_in / 1e9, 3) for r in rows],
        "upload_gb": [round(r.bytes_out / 1e9, 3) for r in rows],
    })
