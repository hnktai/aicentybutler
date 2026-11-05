#!/usr/bin/env python3
"""
Aicenty Butler - SePay Automated Payment & Customer Account Setup Daemon
Listens on 127.0.0.1:18896
Handles VietQR SePay webhooks, creates/upgrades VIP accounts automatically
"""
import json
import logging
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

class SePayWebhookHandler(BaseHTTPRequestHandler):
    def _send_json(self, status_code, data):
        self.send_response(status_code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(data, ensure_ascii=False).encode('utf-8'))

    def do_GET(self):
        if self.path in ['/health', '/healthz', '/api/v1/billing/webhook/sepay/health']:
            self._send_json(200, {"status": "HEALTHY", "service": "aicenty-sepay-webhook", "port": 18896})
        else:
            self._send_json(404, {"error": "Not Found"})

    def do_POST(self):
        if self.path not in ['/api/v1/billing/webhook/sepay', '/webhooks/sepay', '/api/v1/billing/webhook/sepay/']:
            self._send_json(404, {"error": "Endpoint not found"})
            return

        try:
            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length).decode('utf-8')
            payload = json.loads(body) if body else {}
        except Exception as e:
            logging.error(f"Error parsing JSON payload: {e}")
            self._send_json(400, {"success": false, "error": "Invalid JSON payload"})
            return

        logging.info(f"Incoming SePay Webhook Payload: {payload}")

        # Extract fields from SePay standard format
        tx_id = str(payload.get('id') or payload.get('referenceCode') or payload.get('transaction_id') or '')
        amount = float(payload.get('transferAmount') or payload.get('amount') or 0)
        content = str(payload.get('content') or payload.get('code') or payload.get('description') or '')
        gateway = str(payload.get('gateway') or 'SEPAY_VIETQR')
        ref_code = str(payload.get('referenceCode') or '')

        if not tx_id or amount <= 0:
            logging.warning("Missing tx_id or invalid amount")
            self._send_json(400, {"success": false, "error": "Missing transaction id or invalid amount"})
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
    logging.info("Starting Aicenty Butler SePay Webhook Daemon on 127.0.0.1:18896...")
    httpd.serve_forever()

if __name__ == '__main__':
    run()
