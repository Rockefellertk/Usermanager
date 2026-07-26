from django.urls import path

from . import views

app_name = "ppp_users"

urlpatterns = [
    path("", views.list_view, name="list"),
    path("add/", views.create_view, name="add"),
    path("<int:pk>/", views.detail_view, name="detail"),
    path("<int:pk>/edit/", views.edit_view, name="edit"),
    path("<int:pk>/delete/", views.delete_view, name="delete"),
    path("<int:pk>/toggle-status/", views.toggle_status_view, name="toggle_status"),
    path("<int:pk>/renew/", views.renew_view, name="renew"),
]
