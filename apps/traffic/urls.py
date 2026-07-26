from django.urls import path

from . import views

app_name = "traffic"

urlpatterns = [
    path("<int:local_user_id>/chart-data/", views.chart_data, name="chart_data"),
]
