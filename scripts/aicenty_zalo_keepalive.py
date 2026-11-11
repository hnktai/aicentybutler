#!/usr/bin/env python3
"""Monitor live Zalo channel state without modifying channel or session data."""

import time
import logging
import os
import urllib.request
import psycopg2
from psycopg2.extras import RealDictCursor

from butler_runtime_health import channel_statuses

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] [ZaloKeepAlive] %(message)s'
)

DB_CONFIG = {
    "dbname": "aicenty_butler_db",
    "user": "aicenty_user",
    "password": os.environ["BUTLER_DB_PASSWORD"],
    "host": "127.0.0.1",
    "port": 5435
}

def is_engine_healthy():
    try:
        req = urllib.request.Request("http://127.0.0.1:18895/health", headers={'User-Agent': 'ZaloKeepAlive/2.1'})
        with urllib.request.urlopen(req, timeout=10.0) as resp:
            return resp.status == 200
    except Exception as e:
        logging.warning(f"GoClaw Engine healthcheck probe warning: {e}")
        return False

def get_enabled_zalo_channels():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor(cursor_factory=RealDictCursor)
        cur.execute("""
            SELECT id, tenant_id, name, display_name, updated_at
            FROM channel_instances
            WHERE channel_type = 'zalo_personal' AND enabled = true
            ORDER BY name;
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        return rows
    except Exception as e:
        logging.error(f"DB error fetching channel instances: {e}")
        return []

def run_watchdog():
    logging.info("=== Aicenty Butler Zalo SIM Health Monitor Started ===")
    cycle = 0
    consecutive_healthcheck_failures = 0
    previous_failed = set()

    while True:
        cycle += 1
        try:
            # 1. Check Engine container health with 3-strike tolerance
            if not is_engine_healthy():
                consecutive_healthcheck_failures += 1
                logging.warning(f"Engine healthcheck failed (Attempt {consecutive_healthcheck_failures}/3)")
                if consecutive_healthcheck_failures >= 3:
                    logging.error("Engine failed 3 consecutive healthchecks; operator action required")
                time.sleep(10)
                continue
            else:
                consecutive_healthcheck_failures = 0

            # 2. Get all active Zalo SIM channels
            channels = get_enabled_zalo_channels()
            channel_names = [ch['name'] for ch in channels]

            try:
                statuses = channel_statuses(os.environ["BUTLER_GATEWAY_TOKEN"])
                failed = {ch["name"] for ch in channels if not statuses.get(ch["name"], {}).get("running")}
            except Exception as exc:
                logging.error("Unable to read live channel status: %s", exc)
                failed = set(channel_names)

            if failed != previous_failed:
                if failed:
                    logging.error("Zalo channels not running: %s", ", ".join(sorted(failed)))
                else:
                    logging.info("All enabled Zalo channels are running")
                previous_failed = failed

            # Periodic status report every 5 minutes (10 cycles) or first cycle
            if cycle == 1 or cycle % 10 == 0:
                state = "DEGRADED" if failed else "OK"
                logging.info("Heartbeat %s (Cycle %s): %s enabled Zalo channels", state, cycle, len(channels))

        except Exception as e:
            logging.error(f"Unexpected error in watchdog loop: {e}")

        time.sleep(30)

if __name__ == '__main__':
    run_watchdog()
