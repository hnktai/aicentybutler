#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: AUTOMATED BACKUP RESTORE VERIFICATION DRILL
# Chạy tự động vào 03:30 sáng Chủ Nhật hàng tuần qua Cronjob
# ==============================================================================
set -e

# Đăng ký bẫy tín hiệu SRE: Luôn luôn hủy container diễn tập khi script kết thúc (kể cả khi gặp lỗi)
trap 'docker rm -f aicenty-restore-drill > /dev/null 2>&1 || true' EXIT
docker rm -f aicenty-restore-drill > /dev/null 2>&1 || true

BACKUP_DIR="/opt/backups/aicenty-butler"
LATEST_DB_BACKUP=$(ls -t ${BACKUP_DIR}/aicenty_db_*.sql.gz 2>/dev/null | head -n 1)
TELEGRAM_BOT_TOKEN="YOUR_TELEGRAM_BOT_TOKEN"
TELEGRAM_ALERT_CHAT_ID="YOUR_TELEGRAM_ADMIN_CHAT_ID"

if [ -z "$LATEST_DB_BACKUP" ] || [ ! -f "$LATEST_DB_BACKUP" ]; then
    MSG="🚨 [CẢNH BÁO P0] Không tìm thấy bất kỳ bản sao lưu DB nào tại $BACKUP_DIR để diễn tập khôi phục!"
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d "chat_id=${TELEGRAM_ALERT_CHAT_ID}&text=${MSG}" > /dev/null
    exit 1
fi

echo "🔍 [BACKUP DRILL] 1. Kiểm tra tính toàn vẹn nhị phân của tệp nén CSDL..."
if ! gunzip -t "$LATEST_DB_BACKUP"; then
    MSG="🚨 [CẢNH BÁO P0] Bản sao lưu $LATEST_DB_BACKUP bị hỏng cấu trúc nén gzip (Corrupted file)!"
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d "chat_id=${TELEGRAM_ALERT_CHAT_ID}&text=${MSG}" > /dev/null
    exit 1
fi

echo "🔍 [BACKUP DRILL] 1B. Kiểm tra tính toàn vẹn gói phiên Zalo Sessions..."
LATEST_SESSIONS_BACKUP=$(ls -t ${BACKUP_DIR}/aicenty_zalo_sessions_*.tar.gz 2>/dev/null | head -n 1)
if [ -n "$LATEST_SESSIONS_BACKUP" ] && [ -f "$LATEST_SESSIONS_BACKUP" ]; then
    if ! tar -tzf "$LATEST_SESSIONS_BACKUP" > /dev/null 2>&1; then
        MSG="🚨 [CẢNH BÁO P1] Bản sao lưu Zalo Sessions $LATEST_SESSIONS_BACKUP bị lỗi cấu trúc tar.gz!"
        curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d "chat_id=${TELEGRAM_ALERT_CHAT_ID}&text=${MSG}" > /dev/null
    else
        echo "✅ [BACKUP DRILL] Gói Zalo Sessions toàn vẹn 100%!"
    fi
fi

echo "🔍 [BACKUP DRILL] 1C. Kiểm tra tính toàn vẹn gói Media Vault Vĩnh Viễn..."
LATEST_MEDIA_BACKUP=$(ls -t ${BACKUP_DIR}/aicenty_media_permanent_*.tar.gz 2>/dev/null | head -n 1)
if [ -n "$LATEST_MEDIA_BACKUP" ] && [ -f "$LATEST_MEDIA_BACKUP" ]; then
    if ! tar -tzf "$LATEST_MEDIA_BACKUP" > /dev/null 2>&1; then
        MSG="🚨 [CẢNH BÁO P1] Bản sao lưu Media Vault $LATEST_MEDIA_BACKUP bị lỗi cấu trúc tar.gz!"
        curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d "chat_id=${TELEGRAM_ALERT_CHAT_ID}&text=${MSG}" > /dev/null
    else
        echo "✅ [BACKUP DRILL] Gói Media Vault Vĩnh Viễn toàn vẹn 100%!"
    fi
fi

echo "🐳 [BACKUP DRILL] 2. Khởi tạo Container PostgreSQL phụ tạm thời để diễn tập..."
docker run -d --name aicenty-restore-drill \
    -e POSTGRES_DB=drill_test_db \
    -e POSTGRES_USER=aicenty_user \
    -e POSTGRES_PASSWORD=drill_temp_password_2026 \
    pgvector/pgvector:pg16 > /dev/null

echo "⏳ [BACKUP DRILL] Chờ PostgreSQL container sẵn sàng qua pg_isready..."
for attempt in {1..15}; do
    if docker exec aicenty-restore-drill pg_isready -U aicenty_user -d drill_test_db > /dev/null 2>&1; then
        break
    fi
    sleep 1
done

# Khởi tạo chủ động tiện ích mở rộng vector và unaccent trong container diễn tập
docker exec -i aicenty-restore-drill psql -U aicenty_user -d drill_test_db -c "
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;
" > /dev/null 2>&1

echo "📦 [BACKUP DRILL] 3. Nạp thử dữ liệu sao lưu vào Container diễn tập..."
gunzip -c "$LATEST_DB_BACKUP" | docker exec -i aicenty-restore-drill psql -U aicenty_user -d drill_test_db > /dev/null 2>&1

TABLE_COUNT=$(docker exec -i aicenty-restore-drill psql -U aicenty_user -d drill_test_db -t -c "
    SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';
" | tr -d ' ')

echo "🧹 [BACKUP DRILL] 4. Hủy container diễn tập an toàn..."
docker rm -f aicenty-restore-drill > /dev/null

if [ "$TABLE_COUNT" -ge 21 ]; then
    echo "✅ [BACKUP DRILL PASSED] Diễn tập khôi phục thành công hoàn hảo: Khôi phục trọn vẹn $TABLE_COUNT bảng CSDL!"
else
    MSG="🚨 [CẢNH BÁO P1] Diễn tập khôi phục DB chỉ thu được $TABLE_COUNT bảng (Yêu cầu >= 21 bảng). Kiểm tra file dump ngay!"
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d "chat_id=${TELEGRAM_ALERT_CHAT_ID}&text=${MSG}" > /dev/null
    exit 1
fi
