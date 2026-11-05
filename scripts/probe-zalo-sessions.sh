#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: HEALTHCHECK PROBE PHIÊN ZALO ĐỊNH KỲ MỖI 5 PHÚT
# Kiểm tra phiên sống/chết của Cụm Bot Dùng Chung & Quản Gia Thửa Riêng
# ==============================================================================
set -euo pipefail

CONTAINER_NAME="aicenty-butler-engine"
AUTH_TOKEN="aicenty_butler_token_9f8d2e1a4b7c3d0e"

# Thăm dò Gateway API kiểm tra trạng thái channels
CHANNELS_STATUS=$(curl -s -H "Authorization: Bearer $AUTH_TOKEN" -H "X-GoClaw-User-Id: system" http://127.0.0.1:18895/v1/health 2>/dev/null || echo "DOWN")

if [[ "$CHANNELS_STATUS" == *"DOWN"* ]]; then
    echo "⚠️ [ZALO PROBE CẢNH BÁO] Không thể kết nối tới Gateway Engine!"
    exit 1
else
    echo "✅ [ZALO PROBE] Gateway Engine liên lạc bình thường, hệ thống phiên Zalo sẵn sàng."
fi
