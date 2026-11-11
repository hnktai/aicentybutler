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
