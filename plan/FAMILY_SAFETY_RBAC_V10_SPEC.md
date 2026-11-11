# FAMILY SAFETY RBAC V10 SPECIFICATION
**Trạng thái 12/11/2026:** Đây là mục tiêu thiết kế, chưa phải chứng nhận kiểm soát đang chạy. API family/SIM hiện bị khóa công khai; invariant một owner, phân quyền trẻ em và safety gate cần được kiểm thử trước khi mở bán. Xem `PHASE0_STATUS.md`.

**Phiên bản:** Trigger V10 (Bulletproof Multi-Tenant)
**Nguyên tắc Vàng:** AI MỞ TÀI KHOẢN ĐẦU TIÊN LÀ GIA CHỦ TỐI CAO (FIRST-REGISTERED ACCOUNT = SUPREME OWNER).

---

## 1. Nguyên Tắc Phân Định Gia Chủ Tối Cao (Supreme Household Ownership)
- **Ai đăng ký tài khoản / mở dịch vụ đầu tiên (kể cả Người Vợ, Người Chồng hay Bất kỳ thành viên nào):**
  - Mặc định trở thành **Gia Chủ Tối Cao duy nhất** của Tổ Ấm (`v_owner_uid` / `quangia_pending_handover`).
  - Sở hữu toàn quyền quản trị kỹ thuật và cấu hình Quản Gia (`*`, `*`, `allow`).
  - Là người duy nhất có quyền thay đổi tên Quản Gia, nếp nhà, lịch biểu hay phân quyền cho các thành viên khác.

---

## 2. Phân Cấp 4 Vai Trò Tổ Ấm Tuyệt Đối (Strict 4-Tier RBAC)
1. **Gia Chủ Tối Cao (Người mở tài khoản đầu tiên):**
   - Nắm giữ toàn bộ quyền cấu hình Quản Gia (`agent_config_permissions` = allow).
   - Được Quản Gia phục vụ chuẩn mực theo danh xưng khai báo (Anh, Chị, Bác, Mẹ, Ba...).
2. **Bạn Đời / Phu Nhân / Phu Quân (`wife`, `husband`, `spouse`):**
   - Nếu Người Chồng mở tài khoản trước -> Người Vợ là Phu Nhân / Bạn Đời.
   - Nếu Người Vợ mở tài khoản trước -> Người Vợ là Gia Chủ Tối Cao, Người Chồng là Bạn Đời / Phu Quân.
   - **Quyền hạn của Bạn Đời:** Toàn quyền tâm sự, trao đổi việc nhà, nhắc việc, chăm sóc con cái, kiểm tra thực đơn dinh dưỡng.
   - **Khóa an toàn:** Tuyệt đối KHÔNG có quyền thay đổi kỹ thuật hệ thống (0 `agent_config_permissions`), tự động xóa `BOOTSTRAP.md` để không bao giờ chạy lại onboarding hay cướp handover của Gia Chủ.
3. **Con Cái (`child`):**
   - Được kèm cặp học tập theo phương pháp gợi mở **Socratic** (tự tư duy, không giải hộ).
   - 0 quyền cấu hình hệ thống.
4. **Thành Viên Khác (`member`):**
   - Thành viên gia đình (Read-only Member).

---

## 3. Dedicated SIM & Auto-Healing Protection
- Khi Gia Chủ nhắn tin vào Dedicated SIM kèm SĐT xác thực -> Tự động nhận diện và claim Gia Chủ, cấp full quyền quản trị.
- Nếu Người Bạn Đời (hoặc con cái) nhắn tin trước vào Dedicated SIM:
  - Tự động ghi nhận vào `quangia_family_members` đúng vai trò.
  - Thu hồi toàn bộ quyền cấu hình và xóa `BOOTSTRAP.md`.
  - Không bao giờ cướp quyền hay trigger lại quá trình đăng ký mới.
