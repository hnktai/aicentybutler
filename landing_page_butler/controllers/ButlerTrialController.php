<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ButlerTrialMailer;
use App\Services\OdooCrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ButlerTrialController extends Controller
{
    public function store(
        Request $request,
        OdooCrmService $odooService,
        ButlerTrialMailer $mailer
    ): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:120'],
            'email'           => ['required', 'email:rfc', 'max:255'],
            'phone'           => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{8,40}$/'],
            'child_info'      => ['required', 'string', 'max:150'],
            'primary_concern' => ['required', 'string', 'max:150'],
            'note'            => ['nullable', 'string', 'max:3000'],
            'consent'         => ['required', 'accepted'],
            'affiliate_ref'   => ['nullable', 'string', 'max:100'],
        ]);

        if (empty($data['affiliate_ref'])) {
            $cookieRef = $request->cookie('strive_affiliate_ref') ?? $request->input('ref');
            if ($cookieRef) {
                $data['affiliate_ref'] = trim((string) $cookieRef);
            }
        }

        // 1. Đẩy thông tin khách hàng vào Odoo CRM erp.hnkt.vn
        try {
            $odooService->createButlerTrialLead($data);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[ButlerTrialController] Odoo sync failed: " . $e->getMessage());
        }

        // 2. Gửi email thông báo cho Admin (hnkt@hnkt.vn, ai@aicenty.com, striverle@gmail.com) và Phụ huynh
        try {
            $mailer->send($data);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[ButlerTrialController] Mailer send failed: " . $e->getMessage());
        }

        return response()->json([
            'status'       => 'success',
            'redirect_url' => '/butler/thank-you',
            'message'      => 'Chúc mừng Quý phụ huynh đã đăng ký trải nghiệm Quản gia Aicenty Butler thành công! Đang chuyển hướng sang trang hướng dẫn kích hoạt...',
        ]);
    }
}
