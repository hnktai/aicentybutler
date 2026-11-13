"""Read the GoClaw channel manager's live status over its authenticated RPC."""

import json

from websockets.sync.client import connect


def _request(ws, request_id, method, params=None):
    ws.send(json.dumps({
        "type": "req",
        "id": request_id,
        "method": method,
        "params": params or {},
    }))
    for _ in range(10):
        frame = json.loads(ws.recv(timeout=3))
        if frame.get("type") != "res" or frame.get("id") != request_id:
            continue
        if not frame.get("ok"):
            raise RuntimeError(f"GoClaw RPC {method} failed: {frame.get('error', {}).get('code', 'unknown')}")
        return frame.get("payload") or {}
    raise TimeoutError(f"GoClaw RPC {method} response not received")


def channel_statuses(token):
    if not token:
        raise ValueError("BUTLER_GATEWAY_TOKEN is not configured")
    with connect(
        "ws://127.0.0.1:18895/ws",
        origin="https://butler.aicenty.com",
        open_timeout=3,
        close_timeout=1,
    ) as ws:
        _request(ws, "phase0-connect", "connect", {"token": token, "user_id": "system"})
        return _request(ws, "phase0-channels", "channels.status").get("channels", {})
