from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.db.models import Sum
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone
from django.utils.translation import gettext as _

from apps.core.decorators import write_required

from . import services
from .forms import RecordPaymentForm
from .models import Invoice


@login_required
def invoice_list(request):
    qs = Invoice.objects.select_related("local_user", "plan").all()
    status = request.GET.get("status", "")
    if status:
        qs = qs.filter(status=status)
    search = request.GET.get("q", "").strip()
    if search:
        qs = qs.filter(local_user__username__icontains=search)

    paginator = Paginator(qs, 25)
    page_obj = paginator.get_page(request.GET.get("page"))

    totals = qs.aggregate(total=Sum("total"))
    return render(request, "billing/invoice_list.html", {
        "page_obj": page_obj, "status": status, "search": search,
        "status_choices": Invoice.Status.choices, "grand_total": totals["total"] or 0,
    })


@login_required
def invoice_detail(request, pk):
    invoice = get_object_or_404(Invoice.objects.select_related("local_user", "plan"), pk=pk)
    if request.method == "POST":
        form = RecordPaymentForm(request.POST)
        if form.is_valid():
            services.record_payment(
                invoice, form.cleaned_data["amount"], form.cleaned_data["method"], request.user,
                reference=form.cleaned_data["reference"], notes=form.cleaned_data["notes"],
            )
            messages.success(request, _("Payment recorded."))
            return redirect("billing:invoice_detail", pk=pk)
    else:
        form = RecordPaymentForm(initial={"amount": invoice.balance_due})
    return render(request, "billing/invoice_detail.html", {
        "invoice": invoice, "payments": invoice.payments.all(), "form": form,
    })


@write_required
def revenue_report(request):
    today = timezone.localdate()
    month_start = today.replace(day=1)
    year_start = today.replace(month=1, day=1)
    paid = Invoice.objects.filter(status=Invoice.Status.PAID)
    context = {
        "revenue_today": paid.filter(paid_at__date=today).aggregate(t=Sum("total"))["t"] or 0,
        "revenue_month": paid.filter(paid_at__date__gte=month_start).aggregate(t=Sum("total"))["t"] or 0,
        "revenue_year": paid.filter(paid_at__date__gte=year_start).aggregate(t=Sum("total"))["t"] or 0,
        "overdue": Invoice.objects.filter(status=Invoice.Status.OVERDUE),
    }
    return render(request, "billing/revenue_report.html", context)
