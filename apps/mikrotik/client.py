"""Thin wrapper around RouterOS v7's REST API (/rest/...).

Deliberately has zero Django imports so it can be unit-tested and reused
standalone. See apps/mikrotik/registry.py for how Django model instances
(apps.routers.models.Mikrotik) are turned into MikroTikClient objects.
"""
from __future__ import annotations

import logging
import tempfile

import httpx
from tenacity import retry, retry_if_exception_type, stop_after_attempt, wait_exponential

from .exceptions import MikroTikAPIError, MikroTikUnreachable

logger = logging.getLogger("mikrotik")

_RETRYABLE = (httpx.ConnectError, httpx.ConnectTimeout, httpx.ReadTimeout)


class MikroTikClient:
    def __init__(
        self,
        base_url: str,
        username: str | None = None,
        password: str | None = None,
        api_key: str | None = None,
        ca_cert_pem: str | None = None,
        connect_timeout: float = 3.0,
        read_timeout: float = 8.0,
    ):
        verify: bool | str = True
        self._ca_tempfile = None
        if ca_cert_pem:
            self._ca_tempfile = tempfile.NamedTemporaryFile(suffix=".pem", mode="w", delete=False)
            self._ca_tempfile.write(ca_cert_pem)
            self._ca_tempfile.flush()
            verify = self._ca_tempfile.name

        headers = {}
        auth = None
        if api_key:
            headers["Authorization"] = f"Bearer {api_key}"
        else:
            auth = (username or "", password or "")

        self._client = httpx.Client(
            base_url=base_url,
            auth=auth,
            headers=headers,
            timeout=httpx.Timeout(read_timeout, connect=connect_timeout),
            verify=verify,
            limits=httpx.Limits(max_connections=10, max_keepalive_connections=5),
        )

    def close(self):
        self._client.close()

    # -- low level -----------------------------------------------------
    @retry(
        reraise=True,
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=0.5, min=0.5, max=4),
        retry=retry_if_exception_type(_RETRYABLE),
    )
    def _request(self, method: str, path: str, **kwargs):
        try:
            resp = self._client.request(method, path, **kwargs)
        except _RETRYABLE:
            raise
        except httpx.HTTPError as exc:
            raise MikroTikUnreachable(f"{method} {path} failed: {exc}") from exc

        if resp.status_code == 401:
            raise MikroTikUnreachable("Authentication rejected — check credentials/API key")
        if resp.status_code >= 400:
            try:
                payload = resp.json()
            except ValueError:
                payload = {"raw": resp.text}
            raise MikroTikAPIError(
                payload.get("message", f"HTTP {resp.status_code}"),
                status_code=resp.status_code,
                payload=payload,
            )
        if not resp.content:
            return None
        return resp.json()

    def _get(self, path, **kw):
        try:
            return self._request("GET", path, **kw)
        except _RETRYABLE as exc:
            raise MikroTikUnreachable(str(exc)) from exc

    # -- /ppp/secret -----------------------------------------------------
    def list_secrets(self, name_filter: str | None = None) -> list[dict]:
        params = {}
        if name_filter:
            params["name"] = name_filter
        return self._get("/rest/ppp/secret", params=params) or []

    def get_secret_by_name(self, name: str) -> dict | None:
        results = self.list_secrets(name_filter=name)
        for row in results:
            if row.get("name") == name:
                return row
        return None

    def create_secret(self, name: str, password: str, profile: str, service: str = "pppoe", comment: str = "") -> dict:
        payload = {"name": name, "password": password, "profile": profile, "service": service}
        if comment:
            payload["comment"] = comment
        return self._request("PUT", "/rest/ppp/secret", json=payload)

    def set_secret(self, secret_id: str, **fields) -> dict:
        return self._request("PATCH", f"/rest/ppp/secret/{secret_id}", json=fields)

    def remove_secret(self, secret_id: str) -> None:
        self._request("DELETE", f"/rest/ppp/secret/{secret_id}")

    # -- /ppp/active -------------------------------------------------------
    def active_sessions(self) -> list[dict]:
        return self._get("/rest/ppp/active") or []

    # -- /ppp/profile ------------------------------------------------------
    def list_profiles(self) -> list[dict]:
        return self._get("/rest/ppp/profile") or []

    # -- health --------------------------------------------------------
    def ping(self) -> bool:
        try:
            self._get("/rest/system/resource")
            return True
        except (MikroTikUnreachable, MikroTikAPIError):
            return False
