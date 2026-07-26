from django.urls import path

from . import views

app_name = "plans"

urlpatterns = [
    path("", views.PlanListView.as_view(), name="list"),
    path("add/", views.PlanCreateView.as_view(), name="add"),
    path("<int:pk>/edit/", views.PlanUpdateView.as_view(), name="edit"),
    path("<int:pk>/delete/", views.PlanDeleteView.as_view(), name="delete"),
]
