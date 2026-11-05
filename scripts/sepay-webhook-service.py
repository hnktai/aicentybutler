#!/usr/bin/env python3
"""
Aicenty Butler - SePay Automated Payment & Customer Account Setup Daemon
Also serves Deep Healthcheck (/healthz) and Internal Guardrail / Quality Gates
Listens on 127.0.0.1:18896
"""
import json
import logging
import urllib.request
import socket
from http.server import HTTPServer, BaseHTTPRequestHandler
import psycopg2
from psycopg2.extras import RealDictCursor

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')

DB_CONFIG = {
    "dbname": "aicenty_butler_db",
    "user": "aicenty_user",
    "password": "AicentySuperSecureDbPass2026!",
    "host": "127.0.0.1",
    "port": 5435
}

def check_redis():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(2.0)
        s.connect(('127.0.0.1', 6380))
        s.sendall(b"AUTH AicentySuperSecureRedisPass2026!\r\nPING\r\n")
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
            all_ok = db_ok and redis_ok and engine_ok
            status_str = "healthy" if all_ok else "degraded"
            
            self._send_json(200 if all_ok else 503, {
                "status": status_str,
                "service": "aicenty-butler",
                "components": {
                    "database": "ok" if db_ok else "down",
                    "redis": "ok" if redis_ok else "down",
                    "engine": "ok" if engine_ok else "down",
                    "sepay_daemon": "ok"
                }
            })
        else:
            self._send_json(404, {"error": "Not Found"})

    def do_POST(self):
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else "{}"
        try:
            payload = json.loads(body) if body else {}
        except Exception as e:
            logging.error(f"Error parsing JSON payload: {e}")
            self._send_json(400, {"success": False, "error": "Invalid JSON payload"})
            return

        # 1. Healthcheck via POST
        if self.path in ['/health', '/healthz'] or payload.get("health") is True:
            self._send_json(200, {"status": "healthy", "service": "aicenty-butler"})
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
        if self.path not in ['/api/v1/billing/webhook/sepay', '/webhooks/sepay', '/api/v1/billing/webhook/sepay/']:
            self._send_json(404, {"error": "Endpoint not found"})
            return

        logging.info(f"Incoming SePay Webhook Payload: {payload}")

        tx_id = str(payload.get('id') or payload.get('referenceCode') or payload.get('transaction_id') or '')
        amount = float(payload.get('transferAmount') or payload.get('amount') or 0)
        content = str(payload.get('content') or payload.get('code') or payload.get('description') or '')
        gateway = str(payload.get('gateway') or 'SEPAY_VIETQR')
        ref_code = str(payload.get('referenceCode') or '')

        if not tx_id or amount <= 0:
            logging.warning("Missing tx_id or invalid amount")
            self._send_json(400, {"success": False, "error": "Missing transaction id or invalid amount"})
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
            
            if result.get('status') == 'ALREADY_PROCESSED':
                self._send_json(200, {"success": True, "message": "Transaction already processed", "data": result})
            elif result.get('success'):
                self._send_json(200, {"success": True, "message": "Account setup / upgraded successfully", "data": result})
            else:
                self._send_json(422, {"success": False, "message": result.get('message', 'Processing failed'), "data": result})

        except Exception as err:
            logging.error(f"Database error processing SePay webhook: {err}")
            self._send_json(500, {"success": False, "error": str(err)})

def run():
    server_address = ('127.0.0.1', 18896)
    httpd = HTTPServer(server_address, SePayWebhookHandler)
    logging.info("Starting Aicenty Butler Unified Daemon on 127.0.0.1:18896...")
    httpd.serve_forever()

if __name__ == '__main__':
    run()
