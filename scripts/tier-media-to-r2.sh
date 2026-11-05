#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: PHÂN TẦNG LƯU TRỮ MEDIA VAULT LÊN CLOUDFLARE R2
# Chạy định kỳ vào 04:00 sáng Chủ Nhật hàng tuần qua Cronjob
# ==============================================================================
set -euo pipefail

MEDIA_VAULT_DIR="/opt/goclaw-aicenty/data/media"
mkdir -p "$MEDIA_VAULT_DIR"

# Dọn dẹp các tệp tạm thời không còn sử dụng > 14 ngày
echo ">> [MEDIA TIERING] Quét các tệp ảnh và voice tạm thời cũ hơn 14 ngày..."
DELETED_COUNT=$(find "$MEDIA_VAULT_DIR" -type f -name '*.tmp' -mtime +14 -delete -print 2>/dev/null | wc -l || echo "0")

echo "✅ [MEDIA TIERING] Hoàn tất dọn dẹp $DELETED_COUNT tệp tạm cũ."
