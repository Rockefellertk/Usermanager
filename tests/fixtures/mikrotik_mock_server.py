"""Standalone fake RouterOS v7 REST API for offline development/testing.

Run it locally:
    pip install flask
    python tests/fixtures/mikrotik_mock_server.py
    # serves plain HTTP on :8443 (add a self-signed cert if you need to test TLS)

Then point a Mikrotik row at host=127.0.0.1, port=8443, and (for local testing
only) relax the panel to allow http:// for this router, or front it with a
local nginx TLS terminator if you want to exercise the real HTTPS path.

Implements just enough of /ppp/secret, /ppp/active, /ppp/profile, and
/system/resource for the panel's client (apps/mikrotik/client.py) to run
against, backed by an in-memory list — no real RouterOS/CHR needed for
day-to-day feature work.
"""
from __future__ import annotations

import itertools

from flask import Flask, jsonify, request

app = Flask(__name__)

_id_counter = itertools.count(1)
_secrets: dict[str, dict] = {}
_profiles = [
    {".id": "*1", "name": "default", "rate-limit": "10M/2M"},
    {".id": "*2", "name": "10mbps-home", "rate-limit": "10M/2M"},
    {".id": "*3", "name": "20mbps-home", "rate-limit": "20M/5M"},
]


def _next_id():
    return f"*{next(_id_counter):X}"


@app.get("/rest/system/resource")
def system_resource():
    return jsonify({"uptime": "1d00:00:00", "version": "7.15 (mock)"})


@app.get("/rest/ppp/profile")
def list_profiles():
    return jsonify(_profiles)


@app.get("/rest/ppp/secret")
def list_secrets():
    name = request.args.get("name")
    values = list(_secrets.values())
    if name:
        values = [s for s in values if s["name"] == name]
    return jsonify(values)


@app.put("/rest/ppp/secret")
def create_secret():
    body = request.get_json(force=True)
    if any(s["name"] == body["name"] for s in _secrets.values()):
        return jsonify({"message": f"secret with name {body['name']} already exists"}), 400
    secret_id = _next_id()
    row = {
        ".id": secret_id,
        "name": body["name"],
        "password": body.get("password", ""),
        "profile": body.get("profile", "default"),
        "service": body.get("service", "pppoe"),
        "comment": body.get("comment", ""),
        "disabled": "no",
    }
    _secrets[secret_id] = row
    return jsonify(row)


@app.patch("/rest/ppp/secret/<secret_id>")
def update_secret(secret_id):
    if secret_id not in _secrets:
        return jsonify({"message": "no such item"}), 404
    body = request.get_json(force=True)
    _secrets[secret_id].update(body)
    return jsonify(_secrets[secret_id])


@app.delete("/rest/ppp/secret/<secret_id>")
def delete_secret(secret_id):
    _secrets.pop(secret_id, None)
    return "", 204


@app.get("/rest/ppp/active")
def active_sessions():
    # simulate: every non-disabled secret is "online" with fake counters
    active = []
    for s in _secrets.values():
        if s.get("disabled") == "no":
            active.append({
                ".id": s[".id"],
                "name": s["name"],
                "address": "10.10.0.2",
                "uptime": "2h30m00s",
                "bytes-in": "104857600",
                "bytes-out": "20971520",
            })
    return jsonify(active)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8443, debug=True)
