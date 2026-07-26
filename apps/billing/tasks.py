from celery import shared_task

from . import services


@shared_task
def overdue_sweep():
    count = services.overdue_sweep()
    return {"marked_overdue": count}
