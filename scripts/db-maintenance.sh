#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: DATABASE VACUUM & VECTOR REINDEX MAINTENANCE SCRIPT
# Chạy tự động lúc 02:00 sáng Chủ Nhật hàng tuần qua Cronjob
# ==============================================================================
set -e

CONTAINER_NAME="aicenty-butler-postgres"
DB_NAME="aicenty_butler_db"
DB_USER="aicenty_user"

echo "=========================================================="
echo "BẮT ĐẦU BẢO TRÌ CƠ SỞ DỮ LIỆU ĐỊNH KỲ: $(date)"
echo "=========================================================="

TABLES=(
    "family_episodic_memories"
    "curriculum_knowledge_chunks"
    "academic_semantic_cache"
    "learning_sessions"
    "family_financial_records"
)

for TBL in "${TABLES[@]}"; do
    echo ">> [VACUUM] Đang dọn dẹp và thu thập thống kê: $TBL..."
    docker exec -i "$CONTAINER_NAME" psql -U "$DB_USER" -d "$DB_NAME" -c "VACUUM (ANALYZE, VERBOSE) $TBL;" || echo "⚠️ Lỗi khi VACUUM $TBL"
    
    echo ">> [REINDEX] Đang tái tạo chỉ mục không khóa bảng (CONCURRENTLY): $TBL..."
    docker exec -i "$CONTAINER_NAME" psql -U "$DB_USER" -d "$DB_NAME" -c "REINDEX TABLE CONCURRENTLY $TBL;" || echo "⚠️ Lỗi khi REINDEX $TBL"
done

echo "=========================================================="
echo "HOÀN TẤT BẢO TRÌ CSDL VÀ CHỈ MỤC LÚC: $(date)"
echo "=========================================================="
