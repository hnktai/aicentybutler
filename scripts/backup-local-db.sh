#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="/opt/goclaw-aicenty/data/backups"
mkdir -p "$BACKUP_DIR"
DATE=$(date +'%Y%m%d_%H%M%S')
TARGET_FILE="$BACKUP_DIR/aicenty_db_$DATE.sql.gz"

echo ">> [BACKUP] Đang sao lưu cơ sở dữ liệu aicenty_butler_db..."
docker exec -t aicenty-butler-postgres pg_dump -U aicenty_user aicenty_butler_db | gzip > "$TARGET_FILE"

# Keep last 14 days
find "$BACKUP_DIR" -name 'aicenty_db_*.sql.gz' -mtime +14 -delete
SIZE=$(du -h "$TARGET_FILE" | cut -f1)
echo ">> [BACKUP] Hoàn tất sao lưu: $TARGET_FILE (Dung lượng: $SIZE)"
