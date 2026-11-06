import asyncio
import json
import websockets

async def send_zalo_msg():
    uri = "ws://127.0.0.1:18895/ws"
    async with websockets.connect(uri) as ws:
        connect_req = {
            "type": "req",
            "id": "conn-1",
            "method": "connect",
            "params": {
                "token": "aicenty_butler_token_9f8d2e1a4b7c3d0e",
                "user_id": "system"
            }
        }
        await ws.send(json.dumps(connect_req))
        resp = await ws.recv()
        print("Connect resp:", resp)

        msg_text = """🎩 [AICENTY BUTLER] XIN CHÀO GIA ĐÌNH HỒNG HOÀNG!

Hệ thống xác nhận gia đình mình đã kích hoạt thành công Gói Quản Gia Riêng 1 Tháng (Aicenty Butler Standard).

✨ Tổ ấm của Anh/Chị đã được khởi tạo hoàn tất trên Hệ điều hành Quản gia:
• Tên tổ chức: Gia Đình Hong Hoang (Mã: honghoang)
• Đội ngũ: Trọn bộ 24 AI Agent Chuyên Trách (Gia sư Socratic Lớp 1-12 & Luyện thi, Cố vấn Dinh dưỡng, Quản gia Tài chính, Lịch trình, Bác Phúc tư vấn tâm lý...) đã sẵn sàng túc trực 24/7!

👉 Cổng đăng nhập & điều hành trực tuyến: https://butler.aicenty.com
(Tài khoản: honghoang.striver@gmail.com)

Butler Guru sẽ luôn túc trực tại kênh Zalo này để hỗ trợ Anh/Chị kết nối và bàn giao quản gia cho từng thành viên trong gia đình!"""

        send_req = {
            "type": "req",
            "id": "send-1",
            "method": "send",
            "params": {
                "channel": "butlerguru",
                "to": "1107116763138576671",
                "message": msg_text
            }
        }
        await ws.send(json.dumps(send_req))
        send_resp = await ws.recv()
        print("Send resp:", send_resp)

asyncio.run(send_zalo_msg())
