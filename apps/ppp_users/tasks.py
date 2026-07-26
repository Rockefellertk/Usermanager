import logging

from celery import shared_task

from apps.routers.models import Mikrotik

from . import services

logger = logging.getLogger("ppp_users")


@shared_task(bind=True, max_retries=3, default_retry_delay=30)
def sync_router_task(self, mikrotik_id):
    mikrotik = Mikrotik.objects.get(id=mikrotik_id)
    result = services.sync_router(mikrotik)
    logger.info("sync_router(%s) -> %s", mikrotik.name, result)
    return result


@shared_task
def sync_all_routers():
    ids = list(Mikrotik.objects.filter(is_active=True).values_list("id", flat=True))
    for mikrotik_id in ids:
        sync_router_task.delay(mikrotik_id)
    return {"routers_queued": len(ids)}


@shared_task
def expire_sweep():
    count = services.expire_sweep()
    logger.info("expire_sweep disabled %s users", count)
    return {"expired": count}
