from functools import wraps

from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied

WRITE_ROLES = ("superadmin", "operator", "billing")


def role_required(*roles):
    def decorator(view_func):
        @wraps(view_func)
        @login_required
        def wrapped(request, *args, **kwargs):
            user = request.user
            if not (user.is_superuser or user.role in roles):
                raise PermissionDenied
            return view_func(request, *args, **kwargs)
        return wrapped
    return decorator


def write_required(view_func):
    return role_required(*WRITE_ROLES)(view_func)
