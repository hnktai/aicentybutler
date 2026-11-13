# AICENTY BUTLER BILLING RUNBOOK - TẠM DỪNG
**Trạng thái 12/11/2026:** NO-GO thu tiền công khai. Trang QR, đăng ký mới và webhook public đang tạm khóa. Không dùng các bước trong tài liệu này để kích hoạt thanh toán hoặc mở lại endpoint. Xem `PHASE0_STATUS.md` và chỉ mở lại sau khi billing state machine, xác thực SePay, đối soát và kiểm thử được phê duyệt.

**Phiên bản:** 1.0 (bản mô tả lịch sử, chưa được nghiệm thu production)
**Mục tiêu:** Vận hành thu phí tự động, đối soát 24/7 và kích hoạt dịch vụ Quản Gia Aicenty tức thì cho khách hàng.

---

## 1. Kiến Trúc Luồng Thanh Toán (End-to-End Flow)
1. **Khách hàng thanh toán:**
   - Quét mã VietQR qua App ngân hàng tại `https://aicenty.com/butler/payment` hoặc qua link trong tin nhắn Drip Zalo.
   - Nội dung chuyển khoản chuẩn: `DK <SĐT>` hoặc `SUB <SĐT>` hoặc `VIP <SĐT>`.
   - Tài khoản thụ hưởng chính thức: **MBBank - 837006868**.
2. **SePay Webhook Ingestion (Port 18896):**
   - Daemon `/opt/goclaw-aicenty/scripts/sepay-webhook-service.py` tiếp nhận webhook ngay lập tức.
   - Gọi Stored Procedure `fn_process_sepay_webhook` trong Postgres DB để lưu trữ giao dịch và kiểm tra tính hợp lệ/chống trùng lặp.
3. **Laravel Forwarding & Tenant Activation (`/api/sepay/webhook`):**
   - Xác thực Header `Authorization: Apikey ...`
   - Kiểm tra Idempotency Cache (7 ngày) tại Redis.
   - Nhận diện gói cước theo số tiền:
     - `>= 3.000.000 VNĐ`: Gói 12 Tháng (365 ngày).
     - `>= 1.800.000 VNĐ`: Gói 6 Tháng (180 ngày).
     - `>= 1.000.000 VNĐ`: Gói 3 Tháng (90 ngày).
     - `>= 350.000 VNĐ`: Gói 1 Tháng (30 ngày).
   - **Bảo toàn ngày dùng cũ:** Tự động cộng dồn thời hạn nếu hạn VIP cũ còn hiệu lực.
   - Tạo/Cập nhật tài khoản Web Portal cho Gia Chủ.
   - Nhân bản Ký Ức Đa Tầng sang Dedicated Tenant (`replicateAndSyncFamilyMemory`).
   - Gửi tin nhắn xác nhận kích hoạt thành công qua Zalo/Email.

---

## 2. Kiểm Tra Sức Khỏe Định Kỳ (Health Check & SLA)
- Webhook Daemon: `http://127.0.0.1:18896/healthz`
- GoClaw Engine: `http://127.0.0.1:18895/health`
- Redis: Cổng 6380 (Auth protected)
- Database: Postgres `aicenty_butler_db` (Port 5435)
