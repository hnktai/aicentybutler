# AICENTY BUTLER - PRODUCTION OPERATIONS & INCIDENT RESPONSE SOP
**Trạng thái 12/11/2026:** Containment đang hoạt động; checkout tạm dừng và `/healthz` trả degraded do Butler Guru chưa kết nối. Mục tiêu xử lý 60 giây bên dưới chưa có đo lường/on-call/restore drill, không được xem là SLA đã đạt. Xem `PHASE0_STATUS.md`.

**Mục tiêu:** Đảm bảo hệ thống đạt độ sẵn sàng cao nhất (High Availability), thu tiền mượt mà 24/7, xử lý sự cố tức thì trong 60 giây.

---

## 1. Bản Đồ Dịch Vụ Cốt Lõi (Architecture Topology)
- **Web Frontend (Port 80/443 - Caddy Container):** Phục vụ `https://aicenty.com/butler`, `payment.html`, `thank-you.html`.
- **Payment Ingestion Daemon (Port 18896 - aicenty-sepay-webhook.service):** Nhận webhook SePay, gọi DB stored procedure, forward tới Laravel.
- **GoClaw Engine (Port 18895 - aicenty-butler-engine container):** Động cơ xử lý Agent, Zalo Personal & OA Gateway.
- **Database (Port 5435 - aicenty-butler-postgres container):** Lưu trữ Handovers, RBAC Permissions, Memory Context.
- **Cache & Idempotency (Port 6380 - aicenty-butler-redis container):** Chống duplicate giao dịch ngân hàng trong 7 ngày.

---

## 2. Playbook Ứng Phó Sự Cố Nhanh (Incident Runbook)

### Kịch bản 1: Khách hàng chuyển khoản nhưng màn hình web chưa tự chuyển sang Thành Công
1. **Kiểm tra nhật ký SePay Webhook Daemon:**
   `journalctl -u aicenty-sepay-webhook.service -n 50 --no-pager`
2. **Kiểm tra API kiểm tra trạng thái thanh toán:**
   `curl -s "https://aicenty.com/api/butler/payment-status?phone={SĐT}"`
3. **Kích hoạt bù thủ công (nếu lỗi mạng ngân hàng):**
   Chạy lệnh tinker:
   `php /var/www/aicenty/artisan tinker --execute="\$btc = app(\App\Http\Controllers\ButlerTrialController::class); ..."`

### Kịch bản 2: SIM riêng của khách hàng mất kết nối Zalo
1. **Kiểm tra trạng thái Zalo Keep-alive Daemon:**
   `systemctl status aicenty-zalo-keepalive.service`
2. **Kiểm tra log keepalive:**
   `tail -n 50 /var/log/aicenty_zalo_keepalive.log`
3. **Quét lại mã QR Zalo cho SIM riêng:**
   Gọi script kích hoạt đăng nhập lại tại `/opt/goclaw-aicenty/scripts/`.

---

## 3. Lịch Trình Tự Động Định Kỳ (Automated Cron Schedule)
- `00:00 VN`: Reset giới hạn tin nhắn hàng ngày (`fn_reset_daily_fup`).
- `02:00 VN`: Đồng bộ Ký ức VIP chuyên sâu (`butler:sync-vip-memories`).
- `03:00 VN`: Sao lưu cơ sở dữ liệu dự phòng (`backup-local-db.sh`).
- `07:00 VN`: Gửi bản tin chào buổi sáng (`--type=morning`).
- `09:30 VN`: Gửi tin nhắn đồng hành dùng thử (`--type=trial`).
- `17:00 VN`: Gửi cảnh báo học tập nhẹ nhàng cho con (`--type=study_alert`).
- `20:00 VN`: Đề xuất báo cáo tuần/tháng kín cho Ba Mẹ (`--type=report_offer`).

---

## 4. Quy Trình Vận Hành Khẩn Cấp Khi Sai Lệch Nội Dung Thanh Toán (Mục tiêu xử lý 3 Phút)
Khi khách hàng chuyển khoản từ `50.000 VNĐ` trở lên nhưng ghi thiếu hoặc sai cú pháp `SUB <SĐT>`, hàm `ButlerTrialController::sepayWebhook()` sẽ trả trạng thái `manual_review_needed` và kích hoạt `ButlerAutomationService::sendOpsAlert()`:
1. **Nhận cảnh báo khẩn:** Đội vận hành kiểm tra log `[ButlerAutomation][OPS_ALERT]`, email cảnh báo từ Resend, hoặc thông báo nội bộ kèm mã giao dịch `txId`, số tiền và nội dung chuyển khoản gốc.
2. **Tra cứu khách hàng:** Đối chiếu tên người chuyển/thời điểm giao dịch với bảng `quangia_pending_handover` trên Postgres (`port 5435`, DB `aicenty_butler_db`).
3. **Liên hệ & kích hoạt bù:** Liên hệ xác nhận đúng SĐT của Gia Chủ và tiến hành kích hoạt/cộng ngày VIP tương ứng (chú ý giữ nguyên `PHASE0_STATUS.md` nếu hệ thống đang tạm dừng nhận đơn mới).
4. **Thông báo bàn giao:** Đảm bảo Quản Gia gửi xác nhận kích hoạt gói thành công cho đúng Zalo UID Gia Chủ Tối Cao.

---

## 5. Kế Hoạch Ứng Cứu Kênh Zalo SIM & Dự Phòng Kênh Zalo OA
1. **Giám sát liên tục:** Dịch vụ `aicenty-zalo-keepalive.service` kiểm tra trạng thái thực tế `channels.status` của GoClaw Engine và báo `degraded` qua `/healthz` nếu phát hiện kênh (`quangia`, `butlerguru`, hoặc SIM riêng gia đình) ngừng chạy.
2. **Dự phòng gửi tin qua Zalo OA:** Đối với các thông báo giao dịch/nhắc hạn quan trọng, hệ thống hỗ trợ gửi song song hoặc fallback qua `ZaloOAService` (khi có `zalo_oa_user_id`) để không làm gián đoạn trải nghiệm của Gia Chủ khi SIM cá nhân bảo trì.
3. **Khôi phục kết nối SIM:** Khi phát hiện kênh Zalo SIM ngắt kết nối, kỹ thuật viên kiểm tra log `journalctl -u aicenty-zalo-keepalive.service` và đăng nhập/quét lại mã QR cho đúng `channel_instances` bị gián đoạn.

---

## 6. Chính Sách Không Hoàn Tiền & Cơ Chế Cộng Bù Ngày Sử Dụng
- **Chính sách thanh toán:** Hiện tại hệ thống **chưa áp dụng chính sách hoàn tiền** sau khi tài nguyên Quản Gia AI riêng (Dedicated Tenant/Agent & bộ nhớ gia đình) đã được khởi tạo và kích hoạt.
- **Bảo vệ quyền lợi khách hàng:** Khách hàng được khuyến nghị kiểm tra kỹ gói dịch vụ trên trang thanh toán trước khi chuyển khoản. Trong trường hợp phát sinh gián đoạn kỹ thuật từ phía hạ tầng Aicenty Butler làm ảnh hưởng thời gian sử dụng, bộ phận vận hành sẽ thực hiện **cộng bù ngày sử dụng** tương ứng vào `vip_until` / `expires_at` của gia đình.
