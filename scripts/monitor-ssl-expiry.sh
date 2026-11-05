#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: SSL EXPIRY PROACTIVE MONITOR & AUTO-RENEWAL WATCHDOG
# Chạy tự động lúc 08:00 sáng hàng ngày qua Cronjob
# ==============================================================================
set -euo pipefail

DOMAIN="butler.aicenty.com"
EXPIRY_DATE=$(echo | openssl s_client -servername "$DOMAIN" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate | cut -d= -f2 || true)

if [ -z "$EXPIRY_DATE" ]; then
    echo "⚠️ [SSL MONITOR] Không thể trích xuất ngày hết hạn của $DOMAIN"
    exit 1
fi

EXPIRY_EPOCH=$(date -d "$EXPIRY_DATE" +%s)
NOW_EPOCH=$(date +%s)
DAYS_LEFT=$(( (EXPIRY_EPOCH - NOW_EPOCH) / 86400 ))

echo ">> [SSL MONITOR] Tên miền $DOMAIN: Còn $DAYS_LEFT ngày hiệu lực (Hết hạn lúc $EXPIRY_DATE)"

if [ "$DAYS_LEFT" -le 15 ]; then
    echo "⚠️ [SSL MONITOR CẢNH BÁO] Chứng chỉ SSL của $DOMAIN sắp hết hạn (< 15 ngày). Đang yêu cầu Caddy/Certbot gia hạn..."
    docker exec caddy caddy reload --config /etc/caddy/Caddyfile 2>/dev/null || true
else
    echo "✅ [SSL MONITOR] Chứng chỉ SSL đang hoạt động an toàn và ổn định."
fi
