#!/usr/bin/env python3
"""
Aicenty Butler - SePay Automated Payment & Customer Account Setup Daemon
Also serves Deep Healthcheck (/healthz) and Internal Guardrail / Quality Gates
Listens on 127.0.0.1:18896
"""
import json
import hmac
import logging
import os
import re
import urllib.request
import urllib.parse
import socket
from http.server import HTTPServer, BaseHTTPRequestHandler
import psycopg2
from psycopg2.extras import RealDictCursor

from butler_runtime_health import channel_statuses

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')

DB_CONFIG = {
    "dbname": "aicenty_butler_db",
    "user": "aicenty_user",
    "password": os.environ["BUTLER_DB_PASSWORD"],
    "host": "127.0.0.1",
    "port": 5435
}

def check_redis():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(2.0)
        s.connect(('127.0.0.1', 6380))
        s.sendall(b"AUTH " + os.environ["BUTLER_REDIS_PASSWORD"].encode("ascii") + b"\r\nPING\r\n")
        resp = s.recv(1024).decode('utf-8', errors='ignore')
        s.close()
        return "PONG" in resp
    except Exception as e:
        logging.error(f"Redis check error: {e}")
        return False

def check_engine():
    try:
        req = urllib.request.Request("http://127.0.0.1:18895/health", headers={'User-Agent': 'HealthCheck/1.0'})
        with urllib.request.urlopen(req, timeout=2.0) as response:
            return response.status == 200
    except Exception as e:
        logging.error(f"Engine check error: {e}")
        return False

def check_db():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT 1;")
        cur.fetchone()
        cur.close()
        conn.close()
        return True
    except Exception as e:
        logging.error(f"DB check error: {e}")
        return False


def check_required_channels():
    try:
        enabled = get_enabled_zalo_channels()
        statuses = channel_statuses(os.environ["BUTLER_GATEWAY_TOKEN"])
        failed = [row["name"] for row in enabled if not statuses.get(row["name"], {}).get("running")]
        return not failed, failed
    except Exception as exc:
        logging.error("Channel health check failed: %s", exc)
        return False, ["status_unavailable"]


def get_enabled_zalo_channels():
    with psycopg2.connect(**DB_CONFIG) as conn:
        with conn.cursor(cursor_factory=RealDictCursor) as cur:
            cur.execute("SELECT name FROM channel_instances WHERE channel_type = 'zalo_personal' AND enabled = true")
            return cur.fetchall()

class SePayWebhookHandler(BaseHTTPRequestHandler):
    def _send_json(self, status_code, data):
        self.send_response(status_code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(data, ensure_ascii=False).encode('utf-8'))

    def do_GET(self):
        if self.path in ['/health', '/healthz', '/api/v1/billing/webhook/sepay/health', '/api/v1/billing/webhook/sepay']:
            db_ok = check_db()
            redis_ok = check_redis()
            engine_ok = check_engine()
            channels_ok, failed_channels = check_required_channels() if db_ok and engine_ok else (False, ["status_unavailable"])
            all_ok = db_ok and redis_ok and engine_ok and channels_ok
            status_str = "HEALTHY" if all_ok else "degraded"

            self._send_json(200 if all_ok else 503, {
                "status": status_str,
                "service": "aicenty-butler",
                "components": {
                    "database": "ok" if db_ok else "down",
                    "redis": "ok" if redis_ok else "down",
                    "engine": "ok" if engine_ok else "down",
                    "sepay_daemon": "ok",
                    "zalo_personal": "ok" if channels_ok else "down"
                },
                "failed_channels": failed_channels
            })
        elif self.path.startswith('/api/v1/internal/tenant-lookup'):
            try:
                parsed_url = urllib.parse.urlparse(self.path)
                params = urllib.parse.parse_qs(parsed_url.query)
                user_id = params.get('user_id', [''])[0].strip().lower()
                if not user_id:
                    self._send_json(400, {"ok": False, "error": "user_id is required"})
                    return

                clean_phone = re.sub(r'[^0-9]', '', user_id)
                possible_uids = [user_id]
                if clean_phone:
                    possible_uids.extend([
                        clean_phone,
                        'phone_' + clean_phone,
                        '84' + clean_phone[-9:] if len(clean_phone) >= 9 else clean_phone,
                        'phone_84' + clean_phone[-9:] if len(clean_phone) >= 9 else clean_phone,
                        '0' + clean_phone[-9:] if len(clean_phone) >= 9 else clean_phone,
                        'phone_0' + clean_phone[-9:] if len(clean_phone) >= 9 else clean_phone,
                    ])
                possible_uids = list(set(possible_uids))

                conn = psycopg2.connect(**DB_CONFIG)
                cur = conn.cursor(cursor_factory=RealDictCursor)

                # 1. Tìm trong tenant_users
                cur.execute("""
                    SELECT t.id, t.name, t.slug, u.role
                    FROM tenant_users u
                    JOIN tenants t ON u.tenant_id = t.id
                    WHERE t.status = 'active'
                      AND (
                          LOWER(u.user_id) = ANY(%s)
                          OR LOWER(COALESCE(u.metadata->>'email', '')) = %s
                          OR LOWER(COALESCE(u.metadata->>'phone', '')) = %s
                      )
                    LIMIT 1
                """, (possible_uids, user_id, user_id))
                row = cur.fetchone()

                # 2. Fallback tìm trong quangia_pending_handover
                if not row and clean_phone and len(clean_phone) >= 9:
                    cur.execute("""
                        SELECT t.id, t.name, t.slug, 'owner' as role
                        FROM quangia_pending_handover h
                        JOIN tenants t ON h.dedicated_tenant_id = t.id
                        WHERE (h.phone = %s OR regexp_replace(h.phone, '[^0-9]', '', 'g') = %s)
                          AND t.status = 'active'
                        ORDER BY h.created_at DESC
                        LIMIT 1
                    """, (user_id, clean_phone))
                    row = cur.fetchone()

                cur.close()
                conn.close()

                if row:
                    self._send_json(200, {
                        "ok": True,
                        "tenant_id": str(row["id"]),
                        "slug": row["slug"],
                        "name": row["name"],
                        "role": row["role"]
                    })
                else:
                    self._send_json(404, {"ok": False, "error": "No tenant found for user"})
            except Exception as e:
                logging.error(f"tenant-lookup error: {e}")
                self._send_json(500, {"ok": False, "error": str(e)})
        else:
            self._send_json(404, {"error": "Not Found"})

    def do_POST(self):
        webhook_paths = ['/api/v1/billing/webhook/sepay', '/webhooks/sepay', '/api/v1/billing/webhook/sepay/']
        if self.path in webhook_paths:
            expected = os.environ.get("BUTLER_SEPAY_INBOUND_KEY", "")
            provided = self.headers.get("Authorization", "")
            if not expected or not hmac.compare_digest(provided, "Apikey " + expected):
                self._send_json(401, {"success": False, "error": "Unauthorized"})
                return
            if os.environ.get("BUTLER_PAYMENTS_ENABLED") != "1":
                self._send_json(503, {"success": False, "error": "Payment processing paused"})
                return
        try:
            content_length = int(self.headers.get('Content-Length', 0))
        except ValueError:
            self._send_json(400, {"success": False, "error": "Invalid Content-Length"})
            return
        if content_length < 0 or content_length > 65536:
            self._send_json(413, {"success": False, "error": "Payload too large"})
            return
        body = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else "{}"
        try:
            payload = json.loads(body) if body else {}
        except Exception as e:
            logging.error(f"Error parsing JSON payload: {e}")
            self._send_json(400, {"success": False, "error": "Invalid JSON payload"})
            return

        # 1. Healthcheck via POST
        if self.path in ['/health', '/healthz']:
            self.do_GET()
            return

        # 2. Internal Guardrail Simulation Endpoint
        if self.path == '/api/v1/internal/test-guardrail':
            msg = payload.get("message", "").lower()
            if any(k in msg for k in ["không muốn sống", "tự tử", "chết", "tự hại", "111"]):
                self._send_json(200, {
                    "status": "CRISIS_OVERRIDE",
                    "action": "HOTLINE_111",
                    "priority": "P0_EMERGENCY",
                    "hotline": "111",
                    "bypass_curfew": True,
                    "message": "Báo động đỏ: Kết nối ngay Tổng đài Quốc gia Bảo vệ Trẻ em 111"
                })
            else:
                self._send_json(200, {"status": "NORMAL", "message": "Qua bộ lọc an toàn"})
            return

        # 3. Internal Image Quality Fast-Fail Endpoint
        if self.path == '/api/v1/internal/test-image-quality':
            variance = payload.get("simulated_laplacian_variance", 100)
            if variance < 50:
                self._send_json(200, {
                    "status": "blurry",
                    "variance": variance,
                    "action": "FAST_FAIL_REJECT",
                    "token_cost": 0,
                    "message": "Ảnh mờ nhòe. Yêu cầu chụp lại để bảo toàn chi phí token."
                })
            else:
                self._send_json(200, {"status": "clear", "variance": variance})
            return

        # 4. SePay Webhook Handling
        if self.path not in webhook_paths:
            self._send_json(404, {"error": "Endpoint not found"})
            return

        tx_id = str(payload.get('id') or payload.get('referenceCode') or payload.get('transaction_id') or '')
        amount = float(payload.get('transferAmount') or payload.get('amount') or 0)
        content = str(payload.get('content') or payload.get('code') or payload.get('description') or '')
        gateway = str(payload.get('gateway') or 'SEPAY_VIETQR')
        ref_code = str(payload.get('referenceCode') or '')

        if (not tx_id or amount < 500000 or payload.get('transferType') != 'in'
                or str(payload.get('accountNumber') or '') != '837006868'):
            logging.warning("Rejected SePay webhook with invalid transaction fields")
            self._send_json(422, {"success": False, "error": "Invalid transaction fields"})
            return

        try:
            conn = psycopg2.connect(**DB_CONFIG)
            conn.autocommit = True
            cur = conn.cursor(cursor_factory=RealDictCursor)

            cur.execute("""
                SELECT fn_process_sepay_webhook(%s, %s, %s, %s, %s) AS result;
            """, (tx_id, amount, content, gateway, ref_code))

            row = cur.fetchone()
            result = row['result'] if row else {}

            cur.close()
            conn.close()

            logging.info(f"SePay Process Result for TX {tx_id}: {result}")

            # Forward payload to Laravel for complete tenant provisioning, Web Portal & Zalo alerts
            try:
                fwd_data = json.dumps(payload).encode('utf-8')
                fwd_req = urllib.request.Request(
                    "https://aicenty.com/api/sepay/webhook",
                    data=fwd_data,
                    headers={
                        'Content-Type': 'application/json',
                        'Authorization': 'Apikey ' + os.environ['BUTLER_SEPAY_FORWARD_KEY'],
                        'User-Agent': 'AicentySepayForwarder/1.0'
                    }
                )
                with urllib.request.urlopen(fwd_req, timeout=5.0) as fwd_resp:
                    logging.info(f"Forwarded SePay webhook to Laravel, status={fwd_resp.status}")
            except Exception as fwd_err:
                logging.warning(f"Forward to Laravel warning: {fwd_err}")

            if result.get('status') == 'ALREADY_PROCESSED':
                self._send_json(200, {"success": True, "message": "Transaction already processed", "data": result})
            elif result.get('success'):
                self._send_json(200, {"success": True, "message": "Account setup / upgraded successfully", "data": result})
            else:
                self._send_json(422, {"success": False, "message": result.get('message', 'Processing failed'), "data": result})

        except Exception as err:
            logging.error(f"Database error processing SePay webhook: {err}")
            self._send_json(500, {"success": False, "error": "Payment processing failed"})

def run():
    server_address = ('127.0.0.1', 18896)
    httpd = HTTPServer(server_address, SePayWebhookHandler)
    logging.info("Starting Aicenty Butler Unified Daemon on 127.0.0.1:18896...")
    httpd.serve_forever()

if __name__ == '__main__':
    run()
