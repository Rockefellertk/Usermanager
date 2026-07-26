from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin


class RoleRequiredMixin(LoginRequiredMixin, UserPassesTestMixin):
    """Restrict a class-based view to admins with one of `allowed_roles`."""

    allowed_roles: tuple[str, ...] = ("superadmin", "operator", "billing", "viewer")

    def test_func(self):
        user = self.request.user
        return user.is_authenticated and (user.is_superuser or user.role in self.allowed_roles)


class WriteRoleRequiredMixin(RoleRequiredMixin):
    """Restrict to roles that may create/edit/delete (excludes `viewer`)."""

    allowed_roles = ("superadmin", "operator", "billing")
