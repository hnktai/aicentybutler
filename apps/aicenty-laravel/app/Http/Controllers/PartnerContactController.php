<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OdooCrmService;
use App\Services\PartnerRegistrationMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerContactController extends Controller
{
    public function store(
        Request $request, 
        OdooCrmService $odooService, 
        PartnerRegistrationMailer $mailer
    ): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email:rfc', 'max:255'],
            'phone'   => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{8,40}$/'],
            'program' => ['required', 'in:affiliate,consultant'],
            'role'    => ['nullable', 'string', 'max:120'],
            'note'    => ['nullable', 'string', 'max:3000'],
            'consent' => ['required', 'accepted'],
        ]);

        // 1. Tạo đối tác trên Odoo ERP erp.hnkt.vn
        try {
            $odooService->createPartnerRegistration($data);
        } catch (\Throwable $e) {
            report($e);
        }

        // 2. Gửi email thông báo cho striverle@gmail.com, hnkt@hnkt.vn và xác nhận cho đối tác
        try {
            $mailer->send($data);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Chúc mừng bạn đã đăng ký trở thành Đối tác bán hàng Aicenty thành công! Email xác nhận đã được gửi đến hộp thư của bạn. Đội ngũ phát triển đối tác của Aicenty sẽ liên hệ trực tiếp qua Zalo/Điện thoại trong vòng 24 giờ làm việc để bàn giao bộ tài liệu Partner Kit.',
        ]);
    }
}
