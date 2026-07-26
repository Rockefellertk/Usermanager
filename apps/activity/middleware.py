from .models import log_activity

# Only log state-changing requests to keep the table from filling with page views.
_LOGGED_METHODS = {"POST", "PUT", "PATCH", "DELETE"}


class ActivityLogMiddleware:
    """Best-effort audit trail: records who hit which write endpoint and when.

    Fine-grained "what changed" logging happens in the service layer
    (apps/*/services.py) via log_activity() calls with real before/after detail;
    this middleware is the safety net that catches everything else.
    """

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        response = self.get_response(request)
        if (
            request.method in _LOGGED_METHODS
            and getattr(request, "user", None)
            and request.user.is_authenticated
            and 200 <= response.status_code < 400
            and not request.path.startswith("/static/")
        ):
            log_activity(
                admin=request.user,
                action=f"{request.method.lower()}_{request.path.strip('/').replace('/', '_')[:40]}",
                detail={"path": request.path, "status": response.status_code},
                ip_address=_client_ip(request),
            )
        return response


def _client_ip(request):
    xff = request.META.get("HTTP_X_FORWARDED_FOR")
    if xff:
        return xff.split(",")[0].strip()
    return request.META.get("REMOTE_ADDR")
