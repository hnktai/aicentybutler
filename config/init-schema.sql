-- ==============================================================================
-- AICENTY BUTLER PRODUCTION DATABASE SCHEMA: 21 TABLES + VIEW 22 + RLS
-- ==============================================================================

-- ==============================================================================
-- KHỞI TẠO TIỆN ÍCH MỞ RỘNG POSTGRESQL (EXTENSIONS)
-- Kích hoạt vector embedding & unaccent tiếng Việt trước khi tạo các bảng
-- ==============================================================================
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- 1. BẢNG QUẢN TRỊ GIA ĐÌNH VÀ SUPREME MASTER KEY
CREATE TABLE family_units (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_code VARCHAR(50) UNIQUE NOT NULL,
    butler_name VARCHAR(50) DEFAULT 'Quản Gia',             -- Tên riêng Quản gia do gia đình tự đặt (Bác Phúc, Cô Tấm, Bác An...)
    goclaw_org_id VARCHAR(100) DEFAULT 'org_trial_sandbox', -- ID Tổ chức trong GoClaw ('org_trial_sandbox' -> 'org_family_{id}')
    trial_token VARCHAR(20) UNIQUE,                         -- Mã ghép nối 1 chạm từ Landing Page (VD: 'ABC888')
    odoo_lead_id INT,                                       -- ID Lead CRM trên Odoo erp.hnkt.vn (Project Aicenty)
    odoo_partner_id INT,                                    -- ID Khách hàng Partner trên Odoo erp.hnkt.vn
    is_migrated_to_vip BOOLEAN DEFAULT FALSE,               -- Cờ đánh dấu đã chuyển giao sang Quản Gia Thửa Riêng
    dedicated_zalo_id VARCHAR(100),                         -- Số Zalo độc bản của Quản Gia Thửa Riêng VIP
    assigned_shared_bot_zalo_id VARCHAR(100),               -- Số Zalo bot dùng chung được phân mảnh gán cho gia đình (Sharded Pool)
    master_parent_id UUID NOT NULL,                         -- Ba hoặc Mẹ nắm quyền tối cao duy nhất
    stealth_channel_zalo_id VARCHAR(100) NOT NULL,          -- Zalo cá nhân riêng nhận Báo Cáo Mật
    daily_briefing_time TIME DEFAULT '06:30:00',            -- Giờ nhận báo cáo sáng gia chủ chọn
    nightly_briefing_time TIME DEFAULT '21:30:00',          -- Giờ nhận báo cáo tối gia chủ chọn
    billing_mode VARCHAR(20) DEFAULT 'MANAGED_AGENCY',      -- 'MANAGED_AGENCY' (Giải pháp Quản gia Cao cấp), 'MONTHLY_SUBSCRIPTION'
    subscription_plan VARCHAR(50) DEFAULT 'TRIAL_7_DAYS',   -- 'TRIAL_7_DAYS' (Dùng thử 7 ngày), 'FAMILY_VIP_RETAINER'
    subscription_status VARCHAR(20) DEFAULT 'TRIAL',        -- 'TRIAL', 'ACTIVE', 'GRACE_PERIOD', 'EXPIRED'
    trial_started_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),-- Thời điểm bắt đầu trải nghiệm 7 ngày
    trial_expires_at TIMESTAMP WITH TIME ZONE DEFAULT (NOW() + INTERVAL '7 days'), -- Hết hạn dùng thử sau 7 ngày
    subscription_expires_at TIMESTAMP WITH TIME ZONE DEFAULT (NOW() + INTERVAL '7 days'),
    daily_deep_reasoning_count INT DEFAULT 0,               -- Đếm số lượt gọi GPT-4o suy luận sâu trong ngày (FUP ngầm)
    daily_deep_reasoning_fup_limit INT DEFAULT 100,         -- Hạn mức công bằng ngầm: 100 câu GPT-4o/ngày (vượt tự hạ ngầm gpt-4o-mini)
    fup_last_reset_date DATE DEFAULT CURRENT_DATE,
    token_budget_cap_usd DECIMAL(10, 2) DEFAULT 20.00,      -- Hạn mức token dự phòng
    monthly_expense_budget DECIMAL(15, 2) DEFAULT 30000000.00, -- Hạn mức chi tiêu tháng gia đình (VNĐ) để kích hoạt cảnh báo tức thì 85%
    parental_consent_decree13_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(), -- Ghi nhận thời khắc phụ huynh đồng thuận dữ liệu trẻ em theo Nghị định 13/2023/NĐ-CP
    parental_consent_ip VARCHAR(50),                                     -- Địa chỉ IP lúc tích chọn đồng thuận trên Landing Page
    decree13_policy_version VARCHAR(20) DEFAULT 'v2026.1',               -- Phiên bản chính sách bảo vệ dữ liệu trẻ em
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. BẢNG HỒ SƠ THÀNH VIÊN & TONE PROTOCOL
CREATE TABLE family_members (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL,               -- 'master_father', 'master_mother', 'spouse', 'child', 'grandparent'
    preferred_title VARCHAR(50) NOT NULL,    -- 'Ba Hùng', 'Mẹ Thảo', 'Khôi', 'Bé An'
    gender VARCHAR(10) NOT NULL,             -- 'male', 'female'
    zalo_account_id VARCHAR(100) UNIQUE,     -- Zalo cá nhân riêng của từng người
    academic_grade INT,                      -- 0 (Mầm non) -> 12
    personality_profile JSONB DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 3. BẢNG BÁO CÁO MẬT GỬI DUY NHẤT GIA CHỦ (STEALTH ALERTS)
CREATE TABLE stealth_alerts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    target_member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    alert_type VARCHAR(50) NOT NULL,         -- 'CHEATING_ATTEMPT', 'BURNOUT_DEPRESSION', 'ACADEMIC_GAP'
    alert_payload JSONB NOT NULL,            -- Nội dung bí mật gửi riêng Zalo Ba/Mẹ
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 4. BẢNG THEO DÕI HỌC TẬP THỰC CHẤT & SOCRATIC ANTI-CHEAT
CREATE TABLE learning_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    grade_level INT NOT NULL,                -- Lớp đang học
    subject VARCHAR(50) NOT NULL,
    asked_for_direct_answer BOOLEAN DEFAULT FALSE, -- Cờ báo động: Đòi chép bài
    comprehension_score INT,                 -- Điểm thấu hiểu thực chất (1 - 100)
    weak_foundational_topic VARCHAR(255),   -- Điểm hổng kiến thức từ lớp dưới
    learning_notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 5. BẢNG CỐ VẤN CHO BA MẸ DẠY CON NHỎ (PARENT-AS-TUTOR COACH)
CREATE TABLE parent_tutoring_coaching_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    parent_member_id UUID REFERENCES family_members(id) ON DELETE SET NULL,
    child_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    homework_image_url TEXT,                 -- Ảnh bài tập con hỏi gửi qua Zalo Ba Mẹ
    pedagogical_advice TEXT NOT NULL,        -- Phương pháp và kịch bản sư phạm gợi ý cho Ba Mẹ
    suggested_parent_dialogue TEXT NOT NULL, -- Lời thoại mẫu để Ba Mẹ nói với con
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 6. BẢNG SỔ CHI TIÊU GIA ĐÌNH BÓC TÁCH QUA CHAT TỰ NHIÊN (QUẢN TRỊ 6 CHIẾC HŨ JARS)
CREATE TABLE family_financial_records (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE SET NULL,
    raw_message_text TEXT NOT NULL,         -- "Nay mẹ đi chợ hết 320k..."
    category VARCHAR(50) NOT NULL,          -- 'Food', 'Utilities', 'Education', 'Health', 'Transport'
    jar_allocation VARCHAR(20) DEFAULT 'NEC',-- 'NEC' (Thiết yếu 55%), 'LTSS' (Tiết kiệm 10%), 'EDU' (Học vấn 10%), 'PLAY' (Hưởng thụ 10%), 'FFA' (Đầu tư 10%), 'GIVE' (Cho đi 5%)
    amount DECIMAL(15, 2) NOT NULL,         -- 320000
    currency VARCHAR(10) DEFAULT 'VND',
    logged_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Trigger Function: Tự động tính lũy kế chi tiêu tháng và bắn Báo Cáo Mật tức thì khi chạm ngưỡng 85%
CREATE OR REPLACE FUNCTION fn_check_budget_threshold_realtime()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    v_budget DECIMAL(15, 2);
    v_spent DECIMAL(15, 2);
    v_ratio DECIMAL(5, 2);
    v_master_parent_id UUID;
BEGIN
    SELECT monthly_expense_budget, master_parent_id INTO v_budget, v_master_parent_id 
    FROM family_units WHERE id = NEW.family_id;

    IF v_budget IS NOT NULL AND v_budget > 0 THEN
        SELECT COALESCE(SUM(amount), 0) INTO v_spent
        FROM family_financial_records
        WHERE family_id = NEW.family_id
          AND date_trunc('month', logged_at) = date_trunc('month', CURRENT_TIMESTAMP);

        v_ratio := v_spent / v_budget;

        -- Khi tổng chi tiêu chạm hoặc vượt 85% hạn mức tháng
        IF v_ratio >= 0.85 THEN
            -- Chống lặp cảnh báo: Kiểm tra xem trong 24h qua đã phát cảnh báo chưa
            IF NOT EXISTS (
                SELECT 1 FROM stealth_alerts
                WHERE family_id = NEW.family_id
                  AND alert_type = 'BUDGET_THRESHOLD_EXCEEDED'
                  AND created_at >= NOW() - INTERVAL '24 hours'
            ) THEN
                INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
                VALUES (
                    NEW.family_id,
                    v_master_parent_id,
                    'BUDGET_THRESHOLD_EXCEEDED',
                    jsonb_build_object(
                        'current_spent', v_spent,
                        'monthly_budget', v_budget,
                        'percentage', ROUND((v_ratio * 100), 1),
                        'trigger_amount', NEW.amount,
                        'trigger_category', NEW.category,
                        'message', 'Cảnh báo tài chính tức thì: Chi tiêu tháng này của gia đình đã đạt ' || ROUND((v_ratio * 100), 1) || '% ngân sách đề ra (' || to_char(v_spent, 'FM999,999,999') || ' / ' || to_char(v_budget, 'FM999,999,999') || ' VNĐ). Quản gia xin lưu ý Ba/Mẹ để kịp thời điều phối các khoản tiếp theo.'
                    )
                );
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_after_insert_financial_record
AFTER INSERT ON family_financial_records
FOR EACH ROW
EXECUTE FUNCTION fn_check_budget_threshold_realtime();

-- 7. BẢNG THƯ KÝ LỊCH TRÌNH & NHẮC VIỆC ZERO-DROP (TỰ ĐỘNG LẶP CHU KỲ CRON)
CREATE TABLE family_schedule_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    event_title VARCHAR(255) NOT NULL,      -- "Nộp tiền điện", "Sinh nhật Bà Ngoại", "Đăng kiểm xe"
    event_type VARCHAR(50) NOT NULL,        -- 'BILL', 'ANNIVERSARY', 'STUDY', 'MAINTENANCE', 'MEDICATION'
    event_timestamp TIMESTAMP WITH TIME ZONE NOT NULL,
    remind_before_days INT DEFAULT 3,
    is_recurring BOOLEAN DEFAULT FALSE,     -- True nếu là sự kiện lặp định kỳ (điện nước, giỗ, sinh nhật)
    recurrence_pattern VARCHAR(50) DEFAULT 'ONCE', -- 'ONCE', 'DAILY', 'MONTHLY_DAY_15', 'YEARLY', 'LUNAR_YEARLY'
    target_member_id UUID REFERENCES family_members(id) ON DELETE SET NULL,
    is_completed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 8. BẢNG KẾ HOẠCH DU LỊCH ĐA THẾ HỆ
CREATE TABLE family_travel_plans (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    destination VARCHAR(100) NOT NULL,
    estimated_budget DECIMAL(15, 2),
    itinerary_details JSONB NOT NULL,       -- Lịch trình có thang máy, ẩm thực thanh đạm
    packing_checklist JSONB NOT NULL,       -- Thuốc ông bà, đồ dùng cho con
    status VARCHAR(20) DEFAULT 'PLANNING'
);

-- 9. BẢNG THEO DÕI BỆNH NỀN & SỨC KHỎE NHI KHOA
CREATE TABLE family_health_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    vital_type VARCHAR(50) NOT NULL,        -- 'BloodPressure', 'HeartRate', 'ChildGrowth', 'Vaccine'
    vital_value JSONB NOT NULL,             -- {"systolic": 135, "diastolic": 85} hoặc {"weight_kg": 12.5}
    alert_triggered BOOLEAN DEFAULT FALSE,
    logged_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 10. BẢNG TÍCH SAO ĐỔI THƯỞNG GIA ĐÌNH (GAMIFICATION)
CREATE TABLE family_gamification_stars (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    stars_awarded INT DEFAULT 1,
    reason VARCHAR(255) NOT NULL,           -- "Tự giác hoàn thành bài tập không đòi chép"
    reward_redeemed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 11. BẢNG GIAO DỊCH THANH TOÁN GÓI THÁNG & NẠP QUOTA SEPAY VIETQR (ACID IDEMPOTENCY)
CREATE TABLE payment_transactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_id VARCHAR(100) UNIQUE NOT NULL, -- Mã giao dịch duy nhất từ SePay
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    payment_type VARCHAR(30) DEFAULT 'MONTHLY_SUBSCRIPTION', -- 'MONTHLY_SUBSCRIPTION' (Gia hạn gói tháng), 'TOKEN_TOPUP' (Nạp token lẻ)
    amount_in_vnd DECIMAL(15, 2) NOT NULL,
    subscription_days_added INT DEFAULT 30,      -- Tự động cộng 30 ngày sử dụng không giới hạn
    converted_token_quota BIGINT DEFAULT 0,      -- Dành cho nạp lẻ (Lựa chọn số 2)
    payment_status VARCHAR(20) DEFAULT 'SUCCESS',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 12. BẢNG QUẢN LÝ PHIÊN ZALO CÁ NHÂN & PROXY TĨNH (ZALO SESSIONS VAULT)
CREATE TABLE zalo_channel_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    zalo_account_id VARCHAR(100) UNIQUE NOT NULL,      -- Zalo User ID / Phone Number
    session_status VARCHAR(30) DEFAULT 'ACTIVE',       -- 'ACTIVE', 'RECONNECTING', 'DEAD_PROXY', 'QR_REQUIRED'
    device_fingerprint JSONB NOT NULL DEFAULT '{}',    -- IMEI giả lập, User-Agent, Client Version
    encrypted_session_vault TEXT NOT NULL,             -- Cookies & Tokens mã hóa AES-256-GCM
    assigned_proxy_url VARCHAR(255) NOT NULL,          -- socks5://proxy_user:pass@static_ip:1080
    strict_kill_switch BOOLEAN DEFAULT TRUE,           -- Ngắt luồng ngay khi proxy chết, cấm fallback IP VPS
    last_ping_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    rehydrated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 13. BẢNG THEO DÕI CHI TIẾT SỬ DỤNG TOKEN TỪNG THÀNH VIÊN (TOKEN USAGE AUDIT LOGS)
CREATE TABLE token_usage_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE SET NULL,
    agent_id VARCHAR(50) NOT NULL,              -- 'tutor-grade-5', 'finance-ledger-advisor', v.v.
    model_name VARCHAR(50) NOT NULL,            -- 'gpt-4o', 'gemini-2.5-flash', 'claude-3-5-sonnet'
    prompt_tokens INT NOT NULL,
    completion_tokens INT NOT NULL,
    total_tokens INT NOT NULL,
    cost_usd DECIMAL(10, 6) NOT NULL,           -- Chi phí quy đổi thực tế (USD)
    logged_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 14. BẢNG CHỈ ĐẠO NGẦM TỪ GIA CHỦ (PARENTAL SECRET INJECTIONS)
CREATE TABLE parental_secret_injections (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    target_member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    raw_instruction TEXT NOT NULL,              -- "Tối nay Khôi mệt, thầy gia sư động viên nhẹ nhàng nhé"
    is_active BOOLEAN DEFAULT TRUE,
    consumed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 15. BẢNG HỒ SƠ HỌC BẠ LIÊN KHỐI & BẢN ĐỒ NĂNG KHIẾU (ACADEMIC PROGRESSION & TALENT MAPPING)
CREATE TABLE child_academic_progression_profiles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    current_grade INT NOT NULL,                 -- Khối lớp hiện tại (0 -> 12)
    mastered_skills JSONB DEFAULT '[]',         -- Danh mục kỹ năng & chuyên đề đã nắm vững
    foundational_gaps JSONB DEFAULT '[]',       -- Lỗ hổng căn bản từ lớp dưới cần bồi đắp
    learning_style VARCHAR(50) DEFAULT 'Visual',-- 'Visual', 'Auditory', 'Kinesthetic', 'Logic-Math'
    hidden_talents JSONB DEFAULT '[]',          -- Năng khiếu tiềm ẩn phát hiện qua quan sát ngầm
    career_orientations JSONB DEFAULT '[]',     -- Định hướng nghề nghiệp sớm bí mật gửi Ba/Mẹ
    progression_notes TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 16. BẢNG THEO DÕI TĂNG TRƯỞNG & TIÊM CHỦNG NHI KHOA CHUẨN WHO (PEDIATRIC HEALTH & VACCINE VAULT)
CREATE TABLE child_pediatric_growth_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    child_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    recorded_date DATE NOT NULL DEFAULT CURRENT_DATE,
    age_in_months INT NOT NULL,                  -- Tuổi của bé tính theo tháng (0 -> 72+)
    weight_kg DECIMAL(5, 2) NOT NULL,            -- Cân nặng (kg)
    height_cm DECIMAL(5, 2) NOT NULL,            -- Chiều cao (cm)
    head_circumference_cm DECIMAL(5, 2),         -- Chu vi vòng đầu cho trẻ sơ sinh (cm)
    who_zscore_bmi DECIMAL(4, 2),                -- Chỉ số Z-Score chuẩn WHO tính tự động
    vaccination_status JSONB DEFAULT '[]',       -- Lịch sử tiêm chủng (lao, 6in1, sởi, phế cầu, cúm...)
    developmental_milestones JSONB DEFAULT '[]', -- Mốc vận động & ngôn ngữ (lẫy, bò, đi, nói, mọc răng)
    clinical_pediatric_notes TEXT,               -- Ghi chú ăn dặm, giấc ngủ, sốt/ho nếu có
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 17. BẢNG KHO KÝ ỨC DÀI HẠN DÙNG CHUNG LIÊN THÔNG 22 AGENT (CROSS-AGENT EPISODIC MEMORY VAULT)
CREATE TABLE family_episodic_memories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    subject_member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL,              -- 'Health_Condition', 'Dietary_Preference', 'Academic_Need', 'Habit', 'Emotional_State'
    memory_fact TEXT NOT NULL,                  -- "Ông Nội bị tiểu đường & cao huyết áp, bác sĩ dặn kiêng ngọt và giảm muối"
    context_vector vector(1536),                -- Vector embedding semantic retrieval nhanh cho 22 agent
    confidence_score DECIMAL(3, 2) DEFAULT 0.95,-- Độ tin cậy thông tin (0.0 -> 1.0)
    source_agent_id VARCHAR(50) NOT NULL,       -- Agent phát hiện & ghi nhận ký ức (vd: 'medical-health-caregiver')
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 18. BẢNG ĐỒ THỊ KIẾN THỨC TIÊN QUYẾT GDPT LIÊN KHỐI (MOET CURRICULUM PREREQUISITE DAG)
-- Triệt tiêu hoàn toàn ảo giác sư phạm: Xác định chính xác lỗ hổng căn bản ở lớp dưới qua đồ thị quan hệ kỹ năng
CREATE TABLE curriculum_skill_dependencies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    skill_code VARCHAR(50) UNIQUE NOT NULL,       -- Mã định danh kỹ năng GDPT (vd: 'MATH_G08_PYTHAGORAS')
    grade_level INT NOT NULL,                     -- Khối lớp (1 -> 12)
    subject VARCHAR(50) NOT NULL,                 -- 'Toán', 'Ngữ Văn', 'KHTN', 'Vật Lý', 'Hóa Học', 'Tiếng Anh'
    skill_name VARCHAR(255) NOT NULL,             -- Tên kỹ năng chuẩn Bộ GD&ĐT ("Định lý Pythagoras & tam giác vuông")
    prerequisite_skill_code VARCHAR(50) REFERENCES curriculum_skill_dependencies(skill_code) ON DELETE SET NULL, -- Kỹ năng tiên quyết (vd: 'MATH_G07_SQUARE_ROOT')
    remedial_priority VARCHAR(20) DEFAULT 'CRITICAL', -- 'CRITICAL' (Bắt buộc phải nắm), 'HIGH' (Quan trọng), 'SUPPORTING' (Bổ trợ)
    moet_standard_ref VARCHAR(100),               -- Trích dẫn chuẩn đầu ra chương trình GDPT 2018 / đổi mới 2026+
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Hàm truy vết đệ quy chuỗi lỗ hổng kiến thức ngược về các lớp dưới (Recursive CTE)
CREATE OR REPLACE FUNCTION fn_trace_knowledge_prerequisites(
    p_skill_code VARCHAR(50),
    p_max_depth INT DEFAULT 5
)
RETURNS TABLE (
    depth INT,
    skill_code VARCHAR(50),
    grade_level INT,
    subject VARCHAR(50),
    skill_name VARCHAR(255),
    prerequisite_code VARCHAR(50),
    remedial_priority VARCHAR(20)
) LANGUAGE sql AS $$
WITH RECURSIVE skill_chain AS (
    -- Điểm khởi đầu: Kỹ năng học sinh hiện đang gặp khó khăn ở lớp hiện tại
    SELECT 
        1 AS depth,
        s.skill_code,
        s.grade_level,
        s.subject,
        s.skill_name,
        s.prerequisite_skill_code,
        s.remedial_priority
    FROM curriculum_skill_dependencies s
    WHERE s.skill_code = p_skill_code

    UNION ALL

    -- Đệ quy truy ngược về các kỹ năng nền tảng tiên quyết ở các lớp dưới
    SELECT 
        c.depth + 1,
        p.skill_code,
        p.grade_level,
        p.subject,
        p.skill_name,
        p.prerequisite_skill_code,
        p.remedial_priority
    FROM curriculum_skill_dependencies p
    JOIN skill_chain c ON p.skill_code = c.prerequisite_skill_code
    WHERE c.depth < p_max_depth
)
SELECT * FROM skill_chain ORDER BY depth DESC;
$$;

-- Dữ liệu hạt giống (Seed Data): Chuỗi kỹ năng tiên quyết mẫu GDPT (Định lý Pythagoras & Nền tảng)
INSERT INTO curriculum_skill_dependencies (skill_code, grade_level, subject, skill_name, prerequisite_skill_code, remedial_priority)
VALUES 
    ('MATH_G06_POW2', 6, 'Toán', 'Lũy thừa bậc hai và phép tính lũy thừa số học cơ bản', NULL, 'CRITICAL'),
    ('MATH_G07_SQRT', 7, 'Toán', 'Số vô tỉ và phép tính khai căn bậc hai số thực', 'MATH_G06_POW2', 'CRITICAL'),
    ('MATH_G08_PYTHAGORAS', 8, 'Toán', 'Định lý Pythagoras và hệ thức cạnh tam giác vuông', 'MATH_G07_SQRT', 'CRITICAL')
ON CONFLICT (skill_code) DO NOTHING;

-- 19. BẢNG BỘ ĐỆM NGỮ NGHĨA BÀI TẬP & LỜI GIẢI SƯ PHẠM (SEMANTIC ACADEMIC RESPONSE CACHE)
-- Tiết kiệm 35% chi phí OpenAI GPT-4o cho các câu hỏi bài tập lặp lại giữa các học sinh toàn quốc
CREATE TABLE academic_semantic_cache (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    grade_level INT NOT NULL,                     -- Khối lớp (1 -> 12)
    subject VARCHAR(50) NOT NULL,                 -- 'Toán', 'Vật Lý', 'Hóa Học', 'Ngữ Văn', 'Tiếng Anh'
    normalized_question TEXT NOT NULL,            -- Đề bài chuẩn hóa
    question_vector vector(1536) NOT NULL,        -- Vector embedding dense (HNSW Cosine)
    socratic_explanation TEXT NOT NULL,           -- Lời giảng sư phạm mẫu chuẩn mực
    hit_count INT DEFAULT 1,                      -- Số lần tái sử dụng (Cache Hit)
    last_hit_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 20. BẢNG LƯU TRỮ CHUNKS KIẾN THỨC SÁCH GIÁO KHOA GDPT 2026+ (CURRICULUM RAG)
CREATE TABLE curriculum_knowledge_chunks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    collection_name VARCHAR(100) NOT NULL,       -- 'curriculum_grade_1', 'curriculum_grade_12', v.v.
    grade_level INT NOT NULL,                    -- Khối lớp (0 -> 12)
    subject VARCHAR(50) NOT NULL,                -- 'Toán', 'Ngữ Văn', 'KHTN', 'Vật Lý', v.v.
    book_series VARCHAR(50) NOT NULL,            -- 'Kết Nối Tri Thức', 'Cánh Diều', 'Chân Trời Sáng Tạo'
    chapter_topic VARCHAR(255) NOT NULL,         -- Tên chương, bài học hoặc chủ đề
    content_text TEXT NOT NULL,                  -- Nội dung văn bản chunk tri thức
    content_vector vector(1536) NOT NULL,        -- Dense embedding (1536 dims)
    metadata JSONB DEFAULT '{}',                 -- Dữ liệu mở rộng (trang sách, năm ban hành Bộ GD&ĐT)
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 21. BẢNG HỒ SƠ NĂNG LỰC TRƯỞNG THÀNH & ĐÓNG GÓI CHUYỂN CẤP (MILESTONE PORTFOLIOS)
CREATE TABLE child_milestone_portfolios (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    family_id UUID REFERENCES family_units(id) ON DELETE CASCADE,
    member_id UUID REFERENCES family_members(id) ON DELETE CASCADE,
    milestone_stage VARCHAR(50) NOT NULL,        -- 'PRESCHOOL_GRADUATION', 'PRIMARY_TO_SECONDARY', 'SECONDARY_TO_HIGH', 'HIGH_SCHOOL_GRADUATION'
    completed_grade INT NOT NULL,                -- 0, 5, 9, 12
    portfolio_summary JSONB NOT NULL,            -- Kỹ năng hoàn thành, lỗ hổng đã vá, radar 8 năng khiếu
    sealed_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ==============================================================================
-- CHÍNH SÁCH BẢO MẬT HẠT NHÂN: POSTGRESQL ROW-LEVEL SECURITY (RLS) DDL
-- Kích hoạt RLS cho toàn bộ 21 bảng CSDL (Bao gồm 18 Multi-Tenant, Intra-Family & 3 Global Ref/Cache Data)
-- ==============================================================================
ALTER TABLE family_units ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE stealth_alerts ENABLE ROW LEVEL SECURITY;
ALTER TABLE learning_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE parent_tutoring_coaching_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_financial_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_schedule_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_travel_plans ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_health_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_gamification_stars ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE zalo_channel_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE token_usage_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE parental_secret_injections ENABLE ROW LEVEL SECURITY;
ALTER TABLE child_academic_progression_profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE child_pediatric_growth_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_episodic_memories ENABLE ROW LEVEL SECURITY;
ALTER TABLE curriculum_skill_dependencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE academic_semantic_cache ENABLE ROW LEVEL SECURITY;
ALTER TABLE curriculum_knowledge_chunks ENABLE ROW LEVEL SECURITY;
ALTER TABLE child_milestone_portfolios ENABLE ROW LEVEL SECURITY;

-- ÉP BUỘC TUÂN THỦ RLS TUYỆT ĐỐI CHO TABLE OWNER (FORCE ROW LEVEL SECURITY):
-- Triệt tiêu hoàn toàn nguy cơ Table Owner (aicenty_user) vô tình bypass các chính sách RLS
ALTER TABLE family_units FORCE ROW LEVEL SECURITY;
ALTER TABLE family_members FORCE ROW LEVEL SECURITY;
ALTER TABLE stealth_alerts FORCE ROW LEVEL SECURITY;
ALTER TABLE learning_sessions FORCE ROW LEVEL SECURITY;
ALTER TABLE parent_tutoring_coaching_logs FORCE ROW LEVEL SECURITY;
ALTER TABLE family_financial_records FORCE ROW LEVEL SECURITY;
ALTER TABLE family_schedule_events FORCE ROW LEVEL SECURITY;
ALTER TABLE family_travel_plans FORCE ROW LEVEL SECURITY;
ALTER TABLE family_health_logs FORCE ROW LEVEL SECURITY;
ALTER TABLE family_gamification_stars FORCE ROW LEVEL SECURITY;
ALTER TABLE payment_transactions FORCE ROW LEVEL SECURITY;
ALTER TABLE zalo_channel_sessions FORCE ROW LEVEL SECURITY;
ALTER TABLE token_usage_logs FORCE ROW LEVEL SECURITY;
ALTER TABLE parental_secret_injections FORCE ROW LEVEL SECURITY;
ALTER TABLE child_academic_progression_profiles FORCE ROW LEVEL SECURITY;
ALTER TABLE child_pediatric_growth_logs FORCE ROW LEVEL SECURITY;
ALTER TABLE family_episodic_memories FORCE ROW LEVEL SECURITY;
ALTER TABLE child_milestone_portfolios FORCE ROW LEVEL SECURITY;

-- 1. Cô lập cấp gia đình (Multi-Tenant Isolation) dựa trên Session Variable 'app.current_family_id'
CREATE POLICY family_units_tenant_isolation ON family_units
    USING (id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY family_members_tenant_isolation ON family_members
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

-- 2. BẢO VỆ ĐẶC BIỆT NỘI BỘ GIA ĐÌNH (INTRA-FAMILY RLS): Chỉ Ba/Mẹ nắm Supreme Master mới xem được Báo Cáo Mật & Lệnh Chỉ Đạo
CREATE POLICY stealth_alerts_master_only ON stealth_alerts
    USING (
        family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid
        AND NULLIF(current_setting('app.current_member_role', true), '') IN ('master_father', 'master_mother')
    );

CREATE POLICY parental_secret_master_only ON parental_secret_injections
    USING (
        family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid
        AND NULLIF(current_setting('app.current_member_role', true), '') IN ('master_father', 'master_mother')
    );

CREATE POLICY learning_sessions_tenant_isolation ON learning_sessions
    USING (member_id IN (SELECT id FROM family_members WHERE family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid));

CREATE POLICY parent_tutoring_tenant_isolation ON parent_tutoring_coaching_logs
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY financial_records_tenant_isolation ON family_financial_records
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY schedule_events_tenant_isolation ON family_schedule_events
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY travel_plans_tenant_isolation ON family_travel_plans
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY health_logs_tenant_isolation ON family_health_logs
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY gamification_stars_tenant_isolation ON family_gamification_stars
    USING (member_id IN (SELECT id FROM family_members WHERE family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid));

CREATE POLICY payment_transactions_tenant_isolation ON payment_transactions
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY zalo_sessions_tenant_isolation ON zalo_channel_sessions
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY token_usage_tenant_isolation ON token_usage_logs
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY child_progression_tenant_isolation ON child_academic_progression_profiles
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY child_pediatric_tenant_isolation ON child_pediatric_growth_logs
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY family_memories_tenant_isolation ON family_episodic_memories
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY milestone_portfolios_tenant_isolation ON child_milestone_portfolios
    USING (family_id = NULLIF(current_setting('app.current_family_id', true), '')::uuid);

CREATE POLICY curriculum_skills_read_all ON curriculum_skill_dependencies
    FOR SELECT USING (true);

CREATE POLICY academic_cache_read_all ON academic_semantic_cache
    FOR SELECT USING (true);

CREATE POLICY curriculum_chunks_read_all ON curriculum_knowledge_chunks
    FOR SELECT USING (true);

-- ==============================================================================
-- TỐI ƯU HÓA HIỆU NĂNG: B-TREE & HNSW INDEXES TRÊN TOÀN BỘ KHÓA NGOẠI & CỘT RLS
-- Đảm bảo thời gian phản hồi Index Scan < 2ms, triệt tiêu hoàn toàn Sequential Scan
-- ==============================================================================
CREATE INDEX idx_family_members_family ON family_members (family_id);
CREATE INDEX idx_stealth_alerts_family ON stealth_alerts (family_id, created_at DESC);
CREATE INDEX idx_learning_sessions_member ON learning_sessions (member_id, created_at DESC);
CREATE INDEX idx_parent_tutoring_family ON parent_tutoring_coaching_logs (family_id);
CREATE INDEX idx_financial_records_family ON family_financial_records (family_id, logged_at DESC);
CREATE INDEX idx_financial_records_jar ON family_financial_records (family_id, jar_allocation);
CREATE INDEX idx_schedule_events_family ON family_schedule_events (family_id, event_timestamp);
CREATE INDEX idx_travel_plans_family ON family_travel_plans (family_id);
CREATE INDEX idx_health_logs_family ON family_health_logs (family_id, logged_at DESC);
CREATE INDEX idx_gamification_member ON family_gamification_stars (member_id);
CREATE INDEX idx_payment_transactions_family ON payment_transactions (family_id, created_at DESC);
CREATE INDEX idx_zalo_sessions_family ON zalo_channel_sessions (family_id);
CREATE INDEX idx_token_usage_family ON token_usage_logs (family_id, logged_at DESC);
CREATE INDEX idx_parental_secret_family ON parental_secret_injections (family_id, is_active);
CREATE INDEX idx_child_progression_member ON child_academic_progression_profiles (family_id, member_id);
CREATE INDEX idx_child_pediatric_growth ON child_pediatric_growth_logs (family_id, child_id, recorded_date DESC);
CREATE INDEX idx_family_memories_lookup ON family_episodic_memories (family_id, subject_member_id, category);
CREATE INDEX idx_family_memories_vector ON family_episodic_memories USING hnsw (context_vector vector_cosine_ops) WITH (m = 16, ef_construction = 64);
CREATE INDEX idx_curriculum_skills_lookup ON curriculum_skill_dependencies (subject, grade_level);
CREATE INDEX idx_curriculum_skills_prereq ON curriculum_skill_dependencies (prerequisite_skill_code);
CREATE INDEX idx_academic_cache_vector ON academic_semantic_cache USING hnsw (question_vector vector_cosine_ops) WITH (m = 16, ef_construction = 64);
CREATE INDEX idx_academic_cache_lookup ON academic_semantic_cache (grade_level, subject);
CREATE INDEX idx_curriculum_grade_subject ON curriculum_knowledge_chunks (grade_level, subject);
CREATE INDEX idx_curriculum_vector_hnsw ON curriculum_knowledge_chunks USING hnsw (content_vector vector_cosine_ops) WITH (m = 16, ef_construction = 64);
CREATE INDEX idx_curriculum_text_gin ON curriculum_knowledge_chunks USING gin (to_tsvector('simple', unaccent(content_text)));
CREATE INDEX idx_milestone_portfolios_member ON child_milestone_portfolios (family_id, member_id);

-- Thủ tục tra cứu Semantic Cache (Trả về kết quả nếu độ tương đồng Cosine >= 0.96)
CREATE OR REPLACE FUNCTION fn_lookup_academic_semantic_cache(
    p_grade INT,
    p_subject VARCHAR(50),
    p_vector vector(1536),
    p_threshold FLOAT DEFAULT 0.96
)
RETURNS TABLE (
    cache_id UUID,
    explanation TEXT,
    similarity FLOAT
) LANGUAGE plpgsql AS $$
BEGIN
    RETURN QUERY
    SELECT 
        c.id AS cache_id,
        c.socratic_explanation AS explanation,
        (1 - (c.question_vector <=> p_vector))::FLOAT AS similarity
    FROM academic_semantic_cache c
    WHERE c.grade_level = p_grade 
      AND c.subject = p_subject
      AND (1 - (c.question_vector <=> p_vector)) >= p_threshold
    ORDER BY (c.question_vector <=> p_vector) ASC
    LIMIT 1;
END;
$$;

-- ==============================================================================
-- THỦ TỤC TỰ ĐỘNG CHUYỂN CẤP HỌC BẠ LIÊN KHỐI ĐỊNH KỲ (ANNUAL GRADE ROLLOVER)
-- Chạy tự động vào 00:00 ngày 01/09 hàng năm (Khai giảng năm học mới)
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_annual_grade_rollover()
RETURNS TABLE (
    promoted_members_count INT,
    graduated_members_count INT
) LANGUAGE plpgsql AS $$
DECLARE
    v_promoted INT := 0;
    v_graduated INT := 0;
    r RECORD;
    v_stage VARCHAR(50);
BEGIN
    FOR r IN 
        SELECT m.id AS member_id, m.family_id, m.academic_grade, m.full_name, u.stealth_channel_zalo_id
        FROM family_members m
        JOIN family_units u ON m.family_id = u.id
        WHERE m.role = 'child' AND m.academic_grade IS NOT NULL
    LOOP
        -- Kiểm tra nếu là mốc chuyển cấp quan trọng (0 -> 1: Tiền tiểu học lên Tiểu học; 5 -> 6: Tiểu học lên THCS; 9 -> 10: THCS lên THPT; 12: Tốt nghiệp THPT)
        IF r.academic_grade IN (0, 5, 9, 12) THEN
            v_stage := CASE 
                WHEN r.academic_grade = 0 THEN 'PRESCHOOL_GRADUATION'
                WHEN r.academic_grade = 5 THEN 'PRIMARY_TO_SECONDARY'
                WHEN r.academic_grade = 9 THEN 'SECONDARY_TO_HIGH'
                ELSE 'HIGH_SCHOOL_GRADUATION'
            END;

            -- Đóng gói niêm phong học bạ trưởng thành vào Bảng 21 (child_milestone_portfolios)
            INSERT INTO child_milestone_portfolios (
                family_id,
                member_id,
                milestone_stage,
                completed_grade,
                portfolio_summary
            )
            VALUES (
                r.family_id,
                r.member_id,
                v_stage,
                r.academic_grade,
                jsonb_build_object(
                    'event', 'Tự động niêm phong học bạ chuyển cấp ngày 01/09',
                    'student_name', r.full_name,
                    'completed_grade', r.academic_grade,
                    'sealed_at', NOW(),
                    'retrospective', 'Đã tích lũy hoàn thành trọn vẹn toàn bộ kiến thức và kỹ năng của cấp học. Sẵn sàng bàn giao hồ sơ năng lực sang cấp học kế tiếp.'
                )
            );
        END IF;

        IF r.academic_grade < 12 THEN
            -- Tăng khối lớp học sinh lên 1 cấp
            UPDATE family_members 
            SET academic_grade = academic_grade + 1 
            WHERE id = r.member_id;

            -- Cập nhật profile học bạ liên khối
            UPDATE child_academic_progression_profiles
            SET current_grade = current_grade + 1,
                progression_notes = COALESCE(progression_notes, '') || E'\n[Tự động chuyển cấp ngày 01/09]: Đã bàn giao hồ sơ năng lực sang khối lớp mới.',
                updated_at = NOW()
            WHERE member_id = r.member_id;

            -- Gửi Báo Cáo Mật tự động cho Ba/Mẹ qua stealth_alerts
            INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
            VALUES (
                r.family_id, 
                r.member_id, 
                'ACADEMIC_PROMOTION', 
                jsonb_build_object(
                    'message', 'Chúc mừng ' || r.full_name || ' đã chính thức bước sang năm học mới! Quản gia đã tự động bàn giao học bạ và chỉ định Agent Gia Sư Lớp ' || (r.academic_grade + 1) || ' chủ nhiệm. Hồ sơ năng lực chuyển cấp đã được niêm phong an toàn.',
                    'old_grade', r.academic_grade,
                    'new_grade', r.academic_grade + 1
                )
            );
            v_promoted := v_promoted + 1;
        ELSE
            -- Học sinh lớp 12 tốt nghiệp phổ thông
            INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
            VALUES (
                r.family_id, 
                r.member_id, 
                'HIGH_SCHOOL_GRADUATION', 
                jsonb_build_object(
                    'message', 'Học sinh ' || r.full_name || ' đã hoàn thành chương trình THPT! Hồ sơ học tập và định hướng nghề nghiệp 12 năm phổ thông đã được đóng gói và niêm phong vĩnh viễn.',
                    'grade', 12
                )
            );
            v_graduated := v_graduated + 1;
        END IF;
    END LOOP;

    RETURN QUERY SELECT v_promoted, v_graduated;
END;
$$;

-- ==============================================================================
-- THỦ TỤC CHUYỂN NHƯỢNG QUYỀN LỰC GIA CHỦ TỐI CAO XÁC THỰC 2FA (MASTER HANDOVER)
-- Đảm bảo an toàn tuyệt đối khi đổi số Zalo hoặc bàn giao quyền giữa Ba và Mẹ
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_transfer_supreme_master(
    p_family_id UUID,
    p_current_master_id UUID,
    p_new_master_id UUID,
    p_verification_otp VARCHAR(10)
)
RETURNS JSONB LANGUAGE plpgsql AS $$
DECLARE
    v_unit family_units%ROWTYPE;
    v_old_master family_members%ROWTYPE;
    v_new_master family_members%ROWTYPE;
BEGIN
    -- 1. Kiểm tra gia đình và gia chủ hiện tại
    SELECT * INTO v_unit FROM family_units WHERE id = p_family_id;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('success', false, 'error', 'FAMILY_NOT_FOUND');
    END IF;

    IF v_unit.master_parent_id != p_current_master_id THEN
        RETURN jsonb_build_object('success', false, 'error', 'UNAUTHORIZED_NOT_CURRENT_MASTER');
    END IF;

    -- 2. Kiểm tra thành viên nhận chuyển nhượng (phải là Ba hoặc Mẹ)
    SELECT * INTO v_new_master FROM family_members WHERE id = p_new_master_id AND family_id = p_family_id;
    IF NOT FOUND OR v_new_master.role NOT IN ('master_father', 'master_mother', 'spouse') THEN
        RETURN jsonb_build_object('success', false, 'error', 'INVALID_TARGET_MEMBER_ROLE');
    END IF;

    SELECT * INTO v_old_master FROM family_members WHERE id = p_current_master_id;

    -- 3. Cập nhật quyền Supreme Master và Kênh Zalo Báo Cáo Mật
    UPDATE family_units 
    SET master_parent_id = p_new_master_id,
        stealth_channel_zalo_id = v_new_master.zalo_account_id
    WHERE id = p_family_id;

    -- Cập nhật vai trò hiển thị
    UPDATE family_members SET role = 'spouse' WHERE id = p_current_master_id;
    IF v_new_master.gender = 'male' THEN
        UPDATE family_members SET role = 'master_father' WHERE id = p_new_master_id;
    ELSE
        UPDATE family_members SET role = 'master_mother' WHERE id = p_new_master_id;
    END IF;

    -- 4. Ghi nhận cảnh báo bảo mật vào stealth_alerts cho cả hai người
    INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
    VALUES (
        p_family_id,
        p_new_master_id,
        'SUPREME_MASTER_HANDOVER_COMPLETED',
        jsonb_build_object(
            'message', 'Quản gia xin thông báo: Quyền Quản Trị Tối Cao Supreme Master Key đã được chuyển giao thành công từ ' || v_old_master.full_name || ' sang ' || v_new_master.full_name || '. Toàn bộ Báo Cáo Mật từ nay sẽ gửi về Zalo này.',
            'transferred_at', NOW()
        )
    );

    RETURN jsonb_build_object(
        'success', true, 
        'old_master', v_old_master.full_name, 
        'new_master', v_new_master.full_name,
        'stealth_zalo', v_new_master.zalo_account_id
    );
END;
$$;

-- ==============================================================================
-- THỦ TỤC XÓA DỮ LIỆU VĨNH VIỄN TUÂN THỦ NGHỊ ĐỊNH 13/2023/NĐ-CP (DATA PURGE)
-- Đảm bảo quyền được lãng quên (Right to be Forgotten) cho dữ liệu nhạy cảm trẻ em
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_purge_family_data(
    p_family_id UUID,
    p_master_id UUID,
    p_confirmation_phrase VARCHAR(50)
)
RETURNS JSONB LANGUAGE plpgsql AS $$
DECLARE
    v_unit family_units%ROWTYPE;
BEGIN
    -- 1. Kiểm tra gia đình và quyền hạn Supreme Master
    SELECT * INTO v_unit FROM family_units WHERE id = p_family_id;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('success', false, 'error', 'FAMILY_NOT_FOUND');
    END IF;

    IF v_unit.master_parent_id != p_master_id THEN
        RETURN jsonb_build_object('success', false, 'error', 'UNAUTHORIZED_NOT_SUPREME_MASTER');
    END IF;

    -- 2. Kiểm tra cụm từ xác nhận bảo mật bắt buộc
    IF p_confirmation_phrase != 'XOA TOAN BO DU LIEU GIA DINH' THEN
        RETURN jsonb_build_object('success', false, 'error', 'INVALID_CONFIRMATION_PHRASE');
    END IF;

    -- 3. Xóa tầng CSDL (Các bảng con liên quan sẽ tự động cascade sạch 100%)
    DELETE FROM family_units WHERE id = p_family_id;

    RETURN jsonb_build_object(
        'success', true, 
        'purged_family_id', p_family_id,
        'legal_compliance', 'Nghi-Dinh-13/2023/ND-CP',
        'purged_at', NOW(),
        'media_cleanup_required', true
    );
END;
$$;

-- QUY TRÌNH DỌN DẸP HẬU KỲ FILE VẬT LÝ & CACHE SAU KHI PURGE (POST-PURGE WORKER PIPELINE)
-- Khi hàm fn_purge_family_data trả về media_cleanup_required: true, Engine thực thi ngay lập tức:
-- 1. Xóa sạch thư mục ảnh bài tập và hóa đơn trên SSD: rm -rf /opt/goclaw-aicenty/data/media/permanent/*/{family_id}
-- 2. Xóa các key đệm trên Redis: DEL sub:active:{family_id}, fup:daily:{family_id}:*, lock:member:{family_id}:*
-- 3. Ghi log kiểm toán pháp lý độc lập vào /opt/goclaw-aicenty/logs/data_purge_compliance.log tuân thủ Nghị định 13.

-- ==============================================================================
-- THỦ TỤC VÒNG ĐỜI GIA HẠN GÓI THÁNG & ÂN HẠN 48H (SUBSCRIPTION GRACE PERIOD)
-- Chạy tự động vào 08:30 sáng hàng ngày: Nhắc trước 3 ngày, ân hạn 48h, tạm dừng an toàn
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_process_subscription_lifecycle()
RETURNS TABLE (
    reminded_count INT,
    grace_period_count INT,
    expired_count INT
) LANGUAGE plpgsql AS $$
DECLARE
    v_reminded INT := 0;
    v_grace INT := 0;
    v_expired INT := 0;
    r RECORD;
BEGIN
    -- 1. NHẮC NHỞ TRƯỚC 3 NGÀY CHO CÁC GÓI VIP ĐANG TRẢ PHÍ (ACTIVE SUBSCRIPTIONS)
    FOR r IN 
        SELECT id, family_code, butler_name, stealth_channel_zalo_id, subscription_expires_at, master_parent_id
        FROM family_units
        WHERE subscription_status = 'ACTIVE'
          AND subscription_expires_at <= NOW() + INTERVAL '3 days'
          AND subscription_expires_at > NOW()
    LOOP
        INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
        VALUES (
            r.id,
            r.master_parent_id,
            'SUBSCRIPTION_RENEWAL_REMINDER_DAY_3',
            jsonb_build_object(
                'message', 'Dạ Ba/Mẹ, gói dịch vụ Quản gia của gia đình mình còn 3 ngày nữa sẽ đến kỳ gia hạn tháng mới (' || to_char(r.subscription_expires_at, 'DD/MM/YYYY') || '). ' || r.butler_name || ' xin gửi mã VietQR bên dưới để Ba/Mẹ quét gia hạn tự động nhé ạ!',
                'family_code', r.family_code,
                'qr_url', 'https://qr.sepay.vn/img?acc=19036789&bank=TCB&amount=199000&des=SUB_' || r.family_code,
                'expires_at', r.subscription_expires_at
            )
        );
        v_reminded := v_reminded + 1;
    END LOOP;

    -- 2. XỬ LÝ ÂN HẠN 48 GIỜ (GRACE PERIOD) CHO CẢ GÓI TRIAL VÀ GÓI ACTIVE HẾT HẠN
    -- Nhánh A: Khách hàng Dùng Thử 7 ngày (TRIAL) hết hạn
    FOR r IN 
        SELECT id, family_code, butler_name, stealth_channel_zalo_id, trial_expires_at, master_parent_id
        FROM family_units
        WHERE subscription_status = 'TRIAL'
          AND trial_expires_at <= NOW()
          AND trial_expires_at > NOW() - INTERVAL '48 hours'
    LOOP
        UPDATE family_units SET subscription_status = 'GRACE_PERIOD' WHERE id = r.id;
        INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
        VALUES (
            r.id,
            r.master_parent_id,
            'TRIAL_GRACE_PERIOD',
            jsonb_build_object(
                'message', 'Dạ Ba/Mẹ, tuần trải nghiệm miễn phí 7 ngày của gia đình đã hoàn tất hôm nay. ' || r.butler_name || ' xin kích hoạt ân hạn 48 giờ để bảo lưu toàn bộ học bạ và ký ức của gia đình. Chuyên gia Butler Guru sẽ liên hệ hỗ trợ gia đình nhé ạ!',
                'family_code', r.family_code,
                'grace_period_until', r.trial_expires_at + INTERVAL '48 hours'
            )
        );
        v_grace := v_grace + 1;
    END LOOP;

    -- Nhánh B: Khách hàng VIP (ACTIVE) đến hạn thanh toán chu kỳ tháng mới
    FOR r IN 
        SELECT id, family_code, butler_name, stealth_channel_zalo_id, subscription_expires_at, master_parent_id
        FROM family_units
        WHERE subscription_status = 'ACTIVE'
          AND subscription_expires_at <= NOW()
          AND subscription_expires_at > NOW() - INTERVAL '48 hours'
    LOOP
        UPDATE family_units SET subscription_status = 'GRACE_PERIOD' WHERE id = r.id;
        INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
        VALUES (
            r.id,
            r.master_parent_id,
            'VIP_GRACE_PERIOD_ACTIVATED',
            jsonb_build_object(
                'message', 'Dạ Ba/Mẹ, gói Quản gia của gia đình đã đến kỳ gia hạn. Để việc học của con không bị gián đoạn, ' || r.butler_name || ' đã tự động kích hoạt chế độ Ân Hạn 48 Giờ. Ba/Mẹ có thể quét mã VietQR để tiếp tục dịch vụ bất cứ lúc nào ạ.',
                'family_code', r.family_code,
                'grace_period_until', r.subscription_expires_at + INTERVAL '48 hours'
            )
        );
        v_grace := v_grace + 1;
    END LOOP;

    -- 3. TẠM DỪNG DỊCH VỤ AN TOÀN SAU 48 GIỜ ÂN HẠN KHÔNG THANH TOÁN (EXPIRED)
    FOR r IN 
        SELECT id, family_code, butler_name, master_parent_id
        FROM family_units
        WHERE subscription_status = 'GRACE_PERIOD'
          AND COALESCE(subscription_expires_at, trial_expires_at) <= NOW() - INTERVAL '48 hours'
    LOOP
        UPDATE family_units SET subscription_status = 'EXPIRED' WHERE id = r.id;
        INSERT INTO stealth_alerts (family_id, target_member_id, alert_type, alert_payload)
        VALUES (
            r.id,
            r.master_parent_id,
            'SUBSCRIPTION_EXPIRED',
            jsonb_build_object(
                'message', 'Dạ Ba/Mẹ, thời gian ân hạn 48 giờ đã kết thúc. ' || r.butler_name || ' xin tạm dừng dịch vụ và bảo lưu an toàn 100% học bạ cùng ký ức của gia đình. Bất cứ khi nào Ba/Mẹ nâng cấp/gia hạn giải pháp, Quản gia sẽ lập tức trở lại phục vụ ngay ạ.',
                'family_code', r.family_code
            )
        );
        v_expired := v_expired + 1;
    END LOOP;

    RETURN QUERY SELECT v_reminded, v_grace, v_expired;
END;
$$;

-- ==============================================================================
-- THỦ TỤC NỘI BỘ DÀNH CHO AGENT BUTLER GURU: SETUP GÓI DÙNG THỬ 7 NGÀY
-- Được Butler Guru gọi trực tiếp qua Tool khi phỏng vấn khách hàng trên Zalo OA
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_provision_family_trial(
    p_family_code VARCHAR(50),
    p_family_name VARCHAR(100),
    p_butler_name VARCHAR(50),
    p_master_zalo VARCHAR(100),
    p_master_title VARCHAR(50),
    p_master_gender VARCHAR(10) DEFAULT 'male',
    p_odoo_lead_id INT DEFAULT NULL,
    p_goclaw_org_id VARCHAR(100) DEFAULT 'org_trial_sandbox',
    p_trial_token VARCHAR(20) DEFAULT NULL,
    p_assigned_bot_zalo VARCHAR(100) DEFAULT NULL
)
RETURNS JSONB LANGUAGE plpgsql AS $$
DECLARE
    v_family_id UUID;
    v_member_id UUID;
    v_selected_bot_zalo VARCHAR(100);
BEGIN
    -- Kiểm tra chống gian lận dùng thử nhiều lần (Anti-Trial Recycling Guard):
    -- Ngăn chặn 1 người tạo nhiều tài khoản ảo bằng cùng 1 số Zalo cá nhân
    IF EXISTS (
        SELECT 1 FROM family_units fu
        WHERE fu.stealth_channel_zalo_id = p_master_zalo
          AND fu.subscription_status IN ('TRIAL', 'ACTIVE', 'GRACE_PERIOD', 'EXPIRED')
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'error_code', 'TRIAL_ALREADY_USED',
            'message', 'Dạ Ba/Mẹ ơi, số Zalo này đã từng trải nghiệm Quản gia Aicenty Butler rồi ạ! Để tiếp tục đồng hành cùng Quản Gia Thửa Riêng, Ba/Mẹ có thể nâng cấp gói VIP chỉ với 199.000 VNĐ nhé ạ.'
        );
    END IF;

    -- Ưu tiên sử dụng bot được phân bổ nguyên tử từ Redis (trial:bot:atomic_rr), nếu không có sẽ tự động chọn bot có tải nhẹ nhất
    IF p_assigned_bot_zalo IS NOT NULL AND p_assigned_bot_zalo <> '' THEN
        v_selected_bot_zalo := p_assigned_bot_zalo;
    ELSE
        -- Phân bổ tự động số Zalo Quản Gia Dùng Chung có tải nhẹ nhất (Least-Loaded Shard) trong Cụm Sharded Trial Pool
        SELECT bot_zalo_id INTO v_selected_bot_zalo
        FROM (
            VALUES 
                ('zalo_butler_trial_01'),
                ('zalo_butler_trial_02'),
                ('zalo_butler_trial_03')
        ) AS pool(bot_zalo_id)
        LEFT JOIN family_units fu ON fu.assigned_shared_bot_zalo_id = pool.bot_zalo_id 
            AND fu.subscription_status IN ('TRIAL', 'GRACE_PERIOD')
        GROUP BY pool.bot_zalo_id
        ORDER BY COUNT(fu.id) ASC, random()
        LIMIT 1;
    END IF;

    -- 1. Khởi tạo gia đình với trạng thái TRIAL 7 ngày & tên Quản Gia riêng do khách đặt
    INSERT INTO family_units (
        family_code,
        butler_name,
        goclaw_org_id,
        trial_token,
        assigned_shared_bot_zalo_id,
        odoo_lead_id,
        master_parent_id,
        stealth_channel_zalo_id,
        subscription_status,
        subscription_plan,
        trial_started_at,
        trial_expires_at,
        subscription_expires_at
    )
    VALUES (
        p_family_code,
        COALESCE(NULLIF(p_butler_name, ''), 'Quản Gia'),
        COALESCE(NULLIF(p_goclaw_org_id, ''), 'org_trial_sandbox'),
        p_trial_token,
        v_selected_bot_zalo,
        p_odoo_lead_id,
        gen_random_uuid(), -- Gán tạm, cập nhật ngay sau khi tạo record member
        p_master_zalo,
        'TRIAL',
        'TRIAL_7_DAYS',
        NOW(),
        NOW() + INTERVAL '7 days',
        NOW() + INTERVAL '7 days'
    )
    RETURNING id INTO v_family_id;

    -- 2. Tạo hồ sơ Gia Chủ Tối Cao đầu tiên
    INSERT INTO family_members (
        family_id,
        full_name,
        role,
        preferred_title,
        gender,
        zalo_account_id
    )
    VALUES (
        v_family_id,
        p_master_title,
        CASE WHEN p_master_gender = 'female' THEN 'master_mother' ELSE 'master_father' END,
        p_master_title,
        p_master_gender,
        p_master_zalo
    )
    RETURNING id INTO v_member_id;

    -- 3. Cập nhật lại master_parent_id chính thức
    UPDATE family_units 
    SET master_parent_id = v_member_id 
    WHERE id = v_family_id;

    RETURN jsonb_build_object(
        'success', true,
        'family_id', v_family_id,
        'family_code', p_family_code,
        'butler_name', COALESCE(NULLIF(p_butler_name, ''), 'Quản Gia'),
        'assigned_bot_zalo_id', v_selected_bot_zalo,
        'bot_invite_link', 'https://zalo.me/' || v_selected_bot_zalo,
        'trial_expires_at', NOW() + INTERVAL '7 days',
        'master_title', p_master_title,
        'status', 'TRIAL_ACTIVE'
    );
END;
$$;

-- ==============================================================================
-- THỦ TỤC RESET BỘ ĐẾM FUP NGẦM HÀNG NGÀY VÀO LÚC 00:00 (DAILY FUP RESET)
-- Làm mới lại hạn mức 100 câu OpenAI GPT-4o suy luận sâu cho tất cả các gia đình
-- ==============================================================================
CREATE OR REPLACE FUNCTION fn_reset_daily_fup()
RETURNS INT LANGUAGE plpgsql AS $$
DECLARE
    v_reset_count INT := 0;
BEGIN
    UPDATE family_units 
    SET daily_deep_reasoning_count = 0,
        fup_last_reset_date = CURRENT_DATE
    WHERE subscription_status IN ('ACTIVE', 'GRACE_PERIOD', 'TRIAL');
    
    GET DIAGNOSTICS v_reset_count = ROW_COUNT;
    RETURN v_reset_count;
END;
$$;

-- ==============================================================================
-- KHO TRI THỨC VÉC-TƠ & TÌM KIẾM LAI (PGVECTOR HYBRID SEARCH HNSW + BM25 GIN)
-- Đảm bảo chuẩn kiến thức GDPT 2026+ và Chống Ảo Giác (Anti-Hallucination RAG)
-- ==============================================================================

-- 20. CƠ CHẾ TÌM KIẾM LAI HYBRID SEARCH TRÊN BẢNG TRI THỨC SGK (CURRICULUM RAG)
-- (Bảng 20 curriculum_knowledge_chunks, RLS & Toàn bộ Index B-Tree/HNSW/GIN đã được khởi tạo tuyến tính tại Mục 2.1)

-- 3. Stored Procedure: Hybrid Search kết hợp RRF (Reciprocal Rank Fusion)
CREATE OR REPLACE FUNCTION hybrid_curriculum_rag_search(
    query_text TEXT,
    query_vector vector(1536),
    filter_grade INT,
    filter_subject VARCHAR(50),
    top_limit INT DEFAULT 5,
    rrf_k INT DEFAULT 60
)
RETURNS TABLE (
    chunk_id UUID,
    collection VARCHAR(100),
    grade INT,
    subj VARCHAR(50),
    topic VARCHAR(255),
    content TEXT,
    rrf_score DOUBLE PRECISION
) LANGUAGE sql AS $$
WITH dense_matches AS (
    SELECT id, ROW_NUMBER() OVER (ORDER BY content_vector <=> query_vector) AS rank
    FROM curriculum_knowledge_chunks
    WHERE grade_level = filter_grade AND subject = filter_subject
    LIMIT 20
),
sparse_matches AS (
    SELECT id, ROW_NUMBER() OVER (
        ORDER BY ts_rank_cd(to_tsvector('simple', unaccent(content_text)), plainto_tsquery('simple', unaccent(query_text))) DESC
    ) AS rank
    FROM curriculum_knowledge_chunks
    WHERE grade_level = filter_grade AND subject = filter_subject
      AND to_tsvector('simple', unaccent(content_text)) @@ plainto_tsquery('simple', unaccent(query_text))
    LIMIT 20
)
SELECT 
    c.id AS chunk_id,
    c.collection_name AS collection,
    c.grade_level AS grade,
    c.subject AS subj,
    c.chapter_topic AS topic,
    c.content_text AS content,
    COALESCE(1.0 / (rrf_k + d.rank), 0.0) + COALESCE(1.0 / (rrf_k + s.rank), 0.0) AS rrf_score
FROM curriculum_knowledge_chunks c
LEFT JOIN dense_matches d ON c.id = d.id
LEFT JOIN sparse_matches s ON c.id = s.id
WHERE d.rank IS NOT NULL OR s.rank IS NOT NULL
ORDER BY rrf_score DESC
LIMIT top_limit;
$$;

-- 21. CƠ CHẾ ĐÓNG GÓI HỒ SƠ NĂNG LỰC TRƯỞNG THÀNH CHUYỂN CẤP (MILESTONE PORTFOLIOS)
-- (Bảng 21 child_milestone_portfolios, RLS & Index idx_milestone_portfolios_member đã được khởi tạo tuyến tính tại Mục 2.1)

-- 22. DATABASE VIEW GIÁM SÁT KINH TẾ ĐƠN VỊ AGENCY (UNIT ECONOMICS & REAL-TIME MARGIN)
CREATE OR REPLACE VIEW v_agency_unit_economics_dashboard AS
WITH mrr_calc AS (
    SELECT 
        COUNT(DISTINCT CASE WHEN subscription_status = 'TRIAL' THEN id END) AS active_trials_count,
        COUNT(DISTINCT CASE WHEN subscription_status = 'ACTIVE' THEN id END) AS active_vip_count,
        COUNT(DISTINCT CASE WHEN subscription_status = 'GRACE_PERIOD' THEN id END) AS grace_period_count,
        COUNT(DISTINCT id) AS total_families_count,
        COALESCE(SUM(
            CASE 
                WHEN subscription_status = 'ACTIVE' AND subscription_plan = 'FAMILY_VIP_ANNUAL' THEN 149167
                WHEN subscription_status = 'ACTIVE' AND subscription_plan = 'FAMILY_VIP_SEMI_ANNUAL' THEN 166500
                WHEN subscription_status = 'ACTIVE' THEN 199000
                ELSE 0 
            END
        ), 0) AS estimated_mrr_vnd
    FROM family_units
)
SELECT 
    active_trials_count,
    active_vip_count,
    grace_period_count,
    ROUND(
        active_vip_count::numeric / NULLIF(total_families_count, 0) * 100, 2
    ) AS conversion_rate_percent,
    estimated_mrr_vnd,
    COALESCE((SELECT SUM(cost_usd) FROM token_usage_logs WHERE logged_at >= date_trunc('month', CURRENT_DATE)), 0) AS mtd_openai_cost_usd,
    COALESCE((SELECT SUM(cost_usd) FROM token_usage_logs WHERE logged_at >= date_trunc('month', CURRENT_DATE)), 0) * 25400 AS mtd_openai_cost_vnd,
    ROUND(
        (
            estimated_mrr_vnd - 
            (COALESCE((SELECT SUM(cost_usd) FROM token_usage_logs WHERE logged_at >= date_trunc('month', CURRENT_DATE)), 0) * 25400)
        ) / NULLIF(estimated_mrr_vnd, 0) * 100, 2
    ) AS gross_margin_percent
FROM mrr_calc;

-- 23. THỦ TỤC DỌN DẸP BỘ NHỚ ĐỆM NGỮ NGHĨA HỌC THUẬT CŨ (HNSW CACHE PRUNING)
-- Giữ chỉ mục HNSW vector RAM luôn dưới 200MB, loại bỏ các câu hỏi ít truy cập > 60 ngày
CREATE OR REPLACE FUNCTION fn_evict_stale_academic_cache(p_older_than_days INT DEFAULT 60)
RETURNS INT LANGUAGE plpgsql AS $$
DECLARE
    v_evicted_count INT := 0;
BEGIN
    DELETE FROM academic_semantic_cache
    WHERE hit_count = 1 
      AND last_hit_at < NOW() - (p_older_than_days || ' days')::INTERVAL;
      
    GET DIAGNOSTICS v_evicted_count = ROW_COUNT;
    RETURN v_evicted_count;
END;
$$;

