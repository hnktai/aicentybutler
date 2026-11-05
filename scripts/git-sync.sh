#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: AUTOMATED GIT SYNC & BACKUP TO GITHUB
# Tự động đồng bộ mã nguồn và tùy biến lên hnktai/aicentybutler
# ==============================================================================
set -e

PROJECT_DIR="/opt/goclaw-aicenty"
GITHUB_REPO="https://x-access-token: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@github.com/hnktai/aicentybutler.git"

cd "$PROJECT_DIR"

# Đảm bảo không commit file nhạy cảm .env chứa API Key thật
if [ ! -f .gitignore ]; then
    echo ".env" >> .gitignore
    echo "data/" >> .gitignore
    echo "logs/" >> .gitignore
fi

echo ">> [GIT SYNC] Bắt đầu đồng bộ lên GitHub: $(date)"
git add .
git commit -m "chore(sync): automated sync butler.aicenty.com [$(date '+%Y-%m-%d %H:%M:%S')]" || echo "Không có thay đổi mới cần commit."

# Đẩy lên nhánh main của kho lưu trữ backup
git push origin main || git push backup main || echo "⚠️ Lỗi khi đẩy lên GitHub, vui lòng kiểm tra kết nối mạng hoặc token."
echo ">> [GIT SYNC] Hoàn tất đồng bộ lên https://github.com/hnktai/aicentybutler!"
