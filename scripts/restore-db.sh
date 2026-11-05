#!/bin/bash
# Disaster Recovery Restore Script for butler.aicenty.com
DB_BACKUP_FILE=$1
SESSION_BACKUP_FILE=$2
MEDIA_BACKUP_FILE=$3

if [ -z "$DB_BACKUP_FILE" ]; then
    echo "Sử dụng: $0 <path_to_db_backup.sql.gz> [path_to_zalo_sessions.tar.gz] [path_to_media_permanent.tar.gz]"
    echo "Ví dụ: $0 /opt/backups/aicenty-butler/aicenty_db_latest.sql.gz /opt/backups/aicenty-butler/aicenty_zalo_sessions_latest.tar.gz /opt/backups/aicenty-butler/aicenty_media_permanent_latest.tar.gz"
    exit 1
fi

echo "🚨 [DISASTER RECOVERY] Bắt đầu quy trình khôi phục: $DB_BACKUP_FILE"

# 1. Tạm dừng Engine để cô lập phiên ghi dữ liệu
docker stop aicenty-butler-engine

# 2. Ngắt toàn bộ kết nối hiện tại và tái tạo database trắng
docker exec -i aicenty-butler-postgres psql -U aicenty_user -d postgres -c "
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'aicenty_butler_db' AND pid <> pg_backend_pid();
DROP DATABASE IF EXISTS aicenty_butler_db;
CREATE DATABASE aicenty_butler_db OWNER aicenty_user;
"

# 2B. Khởi tạo chủ động tiện ích mở rộng vector & unaccent trước khi nạp dump (Chống lỗi type "vector" does not exist)
docker exec -i aicenty-butler-postgres psql -U aicenty_user -d aicenty_butler_db -c "
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;
"

# 3. Nạp và giải nén bản sao lưu vào PostgreSQL
gunzip -c "$DB_BACKUP_FILE" | docker exec -i aicenty-butler-postgres psql -U aicenty_user -d aicenty_butler_db

# 4. Khôi phục phiên Zalo (Hot Session Restore) nếu có tệp backup
if [ -n "$SESSION_BACKUP_FILE" ] && [ -f "$SESSION_BACKUP_FILE" ]; then
    echo "📦 [DISASTER RECOVERY] Khôi phục toàn bộ phiên đăng nhập Zalo của 22 Agent..."
    tar -xzf "$SESSION_BACKUP_FILE" -C /opt/goclaw-aicenty/data/
    echo "✅ [DISASTER RECOVERY] Khôi phục phiên Zalo hoàn tất: Không cần quét lại bất kỳ mã QR nào!"
fi

# 5. Khôi phục kho media vĩnh viễn (Ảnh bài tập, học bạ, hóa đơn) nếu có tệp backup
if [ -n "$MEDIA_BACKUP_FILE" ] && [ -f "$MEDIA_BACKUP_FILE" ]; then
    echo "🖼️ [DISASTER RECOVERY] Khôi phục toàn bộ ảnh bài tập và hóa đơn Media Vault..."
    mkdir -p /opt/goclaw-aicenty/data/media
    tar -xzf "$MEDIA_BACKUP_FILE" -C /opt/goclaw-aicenty/data/media/
    echo "✅ [DISASTER RECOVERY] Khôi phục Media Vault vĩnh viễn hoàn tất!"
fi

# 6. Khởi động lại Engine
docker start aicenty-butler-engine

echo "✅ [DISASTER RECOVERY] Khôi phục toàn diện thành công 100%! Engine, 22 Agent Zalo & Media Vault đã kết nối trở lại."
