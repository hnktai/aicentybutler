#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: PROXY HEALTH CHECKER & SOCKS5 WATCHDOG
# Kiểm tra kết nối ra Internet thông qua Proxy dân cư tĩnh
# ==============================================================================
set -euo pipefail

PROXY_URL="${ZALO_PROXY_URL:-}"

if [ -z "$PROXY_URL" ]; then
    echo "ℹ️ [PROXY MONITOR] Chưa cấu hình ZALO_PROXY_URL (hệ thống chạy trực tiếp hoặc đang ở chế độ chờ)."
    exit 0
fi

# Thăm dò kiểm tra proxy
STATUS_CODE=$(curl -x "$PROXY_URL" -s -o /dev/null -w "%{http_code}" --connect-timeout 10 https://api.zalo.me/ || echo "000")

if [ "$STATUS_CODE" != "200" ] && [ "$STATUS_CODE" != "403" ] && [ "$STATUS_CODE" != "404" ]; then
    echo "⚠️ [PROXY MONITOR CẢNH BÁO] Proxy có dấu hiệu mất kết nối (HTTP code: $STATUS_CODE). Kích hoạt Kill-switch an toàn!"
    exit 1
else
    echo "✅ [PROXY MONITOR] Kết nối Proxy ổn định."
fi
