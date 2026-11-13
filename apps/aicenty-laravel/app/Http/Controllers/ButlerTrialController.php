<?php



declare(strict_types=1);



namespace App\Http\Controllers;



use App\Services\ButlerTrialMailer;

use App\Services\OdooCrmService;
use App\Services\ButlerAutomationService;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;



class ButlerTrialController extends Controller

{

    public function store(
        Request $request,
        OdooCrmService $odooService,
        ButlerTrialMailer $mailer,
        \App\Services\ButlerAutomationService $automationService
    ): JsonResponse

    {

        $data = $request->validate([

            'name'            => ['required', 'string', 'max:120'],

            'email'           => ['required', 'email:rfc', 'max:255'],

            'phone'           => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{8,40}$/'],

            'family_members'  => ['nullable', 'string', 'max:120'],

            'butler_name'     => ['nullable', 'string', 'max:120'],

            'child_info'      => ['nullable', 'string', 'max:150'],

            'primary_concern' => ['nullable', 'string', 'max:255'],

            'note'            => ['nullable', 'string', 'max:3000'],

            'consent'         => ['nullable'],

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
        // 2. Tự động lưu thông tin vào GoClaw DB (user_agent_profiles với key phone_<sđt>) và bắn thông báo nhóm hỗ trợ HNKT
        try {
            $automationService->saveOnboardingProfile($data);
            $automationService->notifyZaloGroupHNKT($data);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[ButlerTrialController] Automation profile & Zalo notify failed: " . $e->getMessage());
        }

        // 3. Gửi email thông báo cho Admin (hnkt@hnkt.vn, ai@aicenty.com, striverle@gmail.com) và Phụ huynh
        try {
            $mailer->send($data);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[ButlerTrialController] Mailer send failed: " . $e->getMessage());
        }
$query = http_build_query([
            'name'        => $data['name'] ?? '',
            'phone'       => $data['phone'] ?? '',
            'butler_name' => $data['butler_name'] ?? 'Quản Gia Riêng Gia Đình',
        ]);

        return response()->json([
            'status'       => 'success',
            'redirect_url' => '/butler/thank-you?' . $query,
            'message'      => 'Chúc mừng Quý Gia Chủ đã đăng ký sở hữu Quản Gia Thời Hiện Đại thành công! Đang chuyển hướng sang trang kết nối và bàn giao...',
        ]);

    }



    public function purchase(

        Request $request,

        OdooCrmService $odooService,

        ButlerTrialMailer $mailer

    ): JsonResponse

    {

        $data = $request->validate([

            'name'               => ['required', 'string', 'max:120'],

            'email'              => ['required', 'email:rfc', 'max:255'],

            'phone'              => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{8,40}$/'],

            'family_members'     => ['nullable', 'string', 'max:120'],

            'plan'               => ['required', 'string', 'in:1m,3m,6m,12m'],

            'butler_name'        => ['nullable', 'string', 'max:120'],

            'child_info'         => ['nullable', 'string', 'max:150'],

            'primary_concern'    => ['nullable', 'string', 'max:255'],

            'note'               => ['nullable', 'string', 'max:3000'],

            'is_existing_trial'  => ['nullable'],

            'consent'            => ['nullable'],

            'affiliate_ref'      => ['nullable', 'string', 'max:100'],

        ]);



        $plans = [

            '1m' => ['name' => 'Gói 1 Tháng Tiêu Chuẩn', 'amount' => 500000],

            '3m' => ['name' => 'Gói 3 Tháng', 'amount' => 1200000],

            '6m' => ['name' => 'Gói 6 Tháng (Được Chọn Nhiều Nhất)', 'amount' => 2100000],

            '12m' => ['name' => 'Gói 12 Tháng (Trọn Năm Học - Tiết Kiệm Nhất)', 'amount' => 3600000],

        ];



        $planKey = $data['plan'];

        $data['plan_name'] = $plans[$planKey]['name'];

        $data['amount'] = $plans[$planKey]['amount'];

        $data['is_existing_trial'] = !empty($data['is_existing_trial']);



        if (empty($data['affiliate_ref'])) {

            $cookieRef = $request->cookie('strive_affiliate_ref') ?? $request->input('ref');

            if ($cookieRef) {

                $data['affiliate_ref'] = trim((string) $cookieRef);

            }

        }



        // 1. Đẩy thông tin khách hàng mua vào Odoo CRM erp.hnkt.vn

        try {

            $odooService->createButlerPurchaseLead($data);

        } catch (\Throwable $e) {

            report($e);

            Log::error("[ButlerTrialController] Odoo purchase sync failed: " . $e->getMessage());

        }



        // 2. Gửi email thông báo cho Admin

        try {

            $mailer->sendPurchaseNotification($data);

        } catch (\Throwable $e) {

            report($e);

            Log::error("[ButlerTrialController] Mailer purchase send failed: " . $e->getMessage());

        }



        $query = http_build_query([

            'name'        => $data['name'],

            'phone'       => $data['phone'],

            'email'       => $data['email'],

            'plan'        => $planKey,

            'plan_name'   => $data['plan_name'],

            'amount'      => $data['amount'],

            'butler_name' => $data['butler_name'] ?? 'Quản Gia Riêng Gia Đình',

            'is_upgrade'  => $data['is_existing_trial'] ? '1' : '0',

        ]);



        return response()->json([

            'status'       => 'success',

            'redirect_url' => '/butler/payment?' . $query,

            'message'      => 'Đăng ký thành công! Đang chuyển hướng sang trang thanh toán SePay VietQR...',

        ]);

    }




    /**
     * API nhận dữ liệu Onboarding hoàn tất từ Butler Guru Zalo.
     */
    public function completeOnboarding(
        Request $request,
        OdooCrmService $odooService,
        \App\Services\ButlerAutomationService $automationService,
        ButlerTrialMailer $mailer
    ): JsonResponse
    {
        if (!$this->isValidButlerSecret($request, ['BUTLER_SEPAY_FORWARD_KEY', 'BUTLER_SEPAY_INBOUND_KEY', 'SEPAY_WEBHOOK_TOKEN', 'SEPAY_WEBHOOK_TOKEN'])) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'phone'           => ['required', 'string'],
            'name'            => ['nullable', 'string', 'max:120'],
            'email'           => ['nullable', 'string', 'max:255'],
            'family_name'     => ['nullable', 'string', 'max:150'],
            'butler_name'     => ['nullable', 'string', 'max:100'],
            'child_info'      => ['nullable', 'string', 'max:500'],
            'primary_concern' => ['nullable', 'string', 'max:500'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'zalo_user_id'    => ['nullable', 'string', 'max:100'],
        ]);

        // 1. Cập nhật vào Odoo CRM Lead gốc
        $odooUpdated = false;
        try {
            $odooUpdated = $odooService->updateButlerLeadOnboarding($data);
        } catch (\Throwable $e) {
            Log::error('[ButlerTrialController] Odoo onboarding sync failed: ' . $e->getMessage());
        }

        // 2. Tự động Tạo Tenant riêng, Clone 24 Agents, Tạo User Web Portal & Bắn thông tin Zalo + Email
        $provisionResult = [];
        try {
            $provisionResult = $automationService->provisionTrialTenantAndUser($data, $mailer);
        } catch (\Throwable $e) {
            Log::error('[ButlerTrialController] Trial provision failed: ' . $e->getMessage());
        }

        return response()->json([
            'status'         => 'success',
            'odoo_updated'   => $odooUpdated,
            'tenant_created' => !empty($provisionResult['tenant_id']),
            'tenant_id'      => $provisionResult['tenant_id'] ?? null,
            'agent_id'       => $provisionResult['agent_id'] ?? null,
            'portal_email'   => $provisionResult['email'] ?? null,
            'message'        => 'Tổ chức Quản Gia riêng cho gia đình đã được tự động khởi tạo thành công!',
            'demo_zalo_link' => 'https://zalo.me/0961592763',
        ]);
    }

    /**
     * Webhook nhận thông báo thanh toán SePay VietQR tự động và kích hoạt VIP Quản Gia tức thì.
     */
    private function isValidButlerSecret(Request $request, array $envVarNames): bool
    {
        $authHeader = trim((string) $request->header('Authorization', ''));
        $token = '';
        if (stripos($authHeader, 'Apikey ') === 0) {
            $token = trim(substr($authHeader, 7));
        } elseif (stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
        } else {
            $token = trim((string) $request->header('X-Butler-Internal-Key', ''));
        }
        if ($token === '') {
            return false;
        }
        $configMap = [
            'BUTLER_SEPAY_FORWARD_KEY' => 'services.butler.sepay_forward_key',
            'BUTLER_SEPAY_INBOUND_KEY' => 'services.butler.sepay_inbound_key',
            'SEPAY_WEBHOOK_TOKEN'      => 'services.butler.sepay_webhook_token',
        ];
        foreach ($envVarNames as $varName) {
            $cfgPath = $configMap[$varName] ?? null;
            $expected = trim((string) (($cfgPath ? config($cfgPath) : null) ?: env($varName, '')));
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }

    public function sepayWebhook(
        Request $request,
        OdooCrmService $odooService,
        \App\Services\ButlerAutomationService $automationService,
        ButlerTrialMailer $mailer
    ): JsonResponse
    {
        // 0. Xác thực Apikey bắt buộc (hỗ trợ key forward nội bộ từ daemon 18896 hoặc key inbound trực tiếp từ SePay)
        if (!$this->isValidButlerSecret($request, ['BUTLER_SEPAY_FORWARD_KEY', 'BUTLER_SEPAY_INBOUND_KEY', 'SEPAY_WEBHOOK_TOKEN'])) {
            Log::warning('[ButlerTrialController] Rejected unauthenticated SePay webhook call from IP: ' . $request->ip());
            return response()->json(['status' => 'unauthorized', 'message' => 'Unauthorized'], 401);
        }

        $all = $request->all();
        $content = (string) ($request->input('content') ?? $request->input('transactionContent') ?? $request->input('description') ?? $request->input('body') ?? '');
        $amount = (float) ($request->input('transferAmount') ?? $request->input('amountIn') ?? $request->input('amount') ?? 0);

        Log::info('[ButlerTrialController] SePay webhook authorized & received: ' . json_encode($all, JSON_UNESCAPED_UNICODE));

        // Kiểm tra giao dịch tiền vào & đúng số tài khoản MBBank 837006868
        $transferType = strtolower(trim((string) ($request->input('transferType') ?? '')));
        if ($transferType !== 'in') {
            Log::warning('[ButlerTrialController] Bỏ qua webhook không phải giao dịch tiền vào (transferType=' . $transferType . ')');
            return response()->json(['status' => 'ignored_transfer_type'], 422);
        }

        $accNum = trim((string) ($request->input('accountNumber') ?? $request->input('account_number') ?? ''));
        if ($accNum !== '837006868') {
            Log::warning('[ButlerTrialController] Bỏ qua webhook với số tài khoản không khớp/rỗng: ' . $accNum);
            return response()->json(['status' => 'ignored_invalid_account'], 422);
        }

        // Kiểm tra chống trùng lặp giao dịch nguyên tử (Atomic Cache::add - Bảo vệ 7 ngày)
        $txId = trim((string) ($request->input('id') ?? $request->input('referenceCode') ?? ''));
        if ($txId === '') {
            return response()->json(['status' => 'invalid_transaction_id'], 422);
        }
        $cacheKey = "butler_sepay_processed_{$txId}";
        if (!\Illuminate\Support\Facades\Cache::add($cacheKey, true, now()->addDays(7))) {
            Log::info("[ButlerTrialController] Bỏ qua webhook trùng lặp {$txId} (Atomic idempotency hit).");
            return response()->json(['status' => 'duplicate_ignored']);
        }

        // Trích xuất số điện thoại khách hàng thông minh (hỗ trợ SUB/DK/VIP, +84/84/0, dấu chấm, dấu cách, mã ngân hàng)
        $phone = null;
        if (preg_match('/(?:SUB|DK|VIP)\s*(?:\+?84|0)?[.\s-]*([35789](?:[.\s-]*[0-9]){8})/i', $content, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);
            $phone = '0' . $digits;
        } elseif (preg_match('/(?:\b|[^0-9])(?:\+?84|0)([35789][0-9]{8})(?:\b|[^0-9])/', ' ' . $content . ' ', $matches)) {
            $phone = '0' . $matches[1];
        }

        if ($phone && $amount >= 350000) {
            try {
                // 1. Xác định gói cước & thời hạn chuẩn xác tuyệt đối theo số tiền thực nhận
                if ($amount >= 3000000) {
                    $planName = 'Gói 12 Tháng (Trọn Năm Học)';
                    $days = 365;
                } elseif ($amount >= 1800000) {
                    $planName = 'Gói 6 Tháng Toàn Diện';
                    $days = 180;
                } elseif ($amount >= 1000000) {
                    $planName = 'Gói 3 Tháng Định Hình Nếp Sống';
                    $days = 90;
                } else {
                    $planName = 'Gói 1 Tháng Tiêu Chuẩn';
                    $days = 30;
                }

                $db = \Illuminate\Support\Facades\DB::connection('butler_pgsql');

                // 2. Tra cứu profile khách hàng TOÀN DIỆN (quangia_pending_handover -> user_agent_profiles)
                $handover = $db->table('quangia_pending_handover')
                    ->where('phone', $phone)
                    ->orderByDesc('created_at')
                    ->first();

                $profile = $db->table('user_agent_profiles')
                    ->where('tenant_id', '0193a5b0-7000-7000-8000-000000000001')
                    ->where(function ($q) use ($phone) {
                        $q->where('user_id', 'phone_' . $phone)
                          ->orWhere('user_id', $phone)
                          ->orWhereRaw("metadata->>'phone' = ?", [$phone]);
                    })
                    ->first();

                $meta = [];
                if ($profile && !empty($profile->metadata)) {
                    $meta = is_string($profile->metadata) ? json_decode($profile->metadata, true) : (array) $profile->metadata;
                }

                // Bảo toàn quyền lợi khách hàng: Nếu đang còn hạn VIP thì CỘNG DỒN NỐI TIẾP thời hạn (Renewal/Extension)
                $currentExpire = null;
                if (!empty($meta['vip_until'])) {
                    try {
                        $parsed = \Carbon\Carbon::parse($meta['vip_until']);
                        if ($parsed->isFuture()) $currentExpire = $parsed;
                    } catch (\Throwable $e) {}
                }

                $baseDate = $currentExpire ?: now();
                $expireAt = $baseDate->copy()->addDays($days);
                $expireDateStr = $expireAt->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y');

                $customerName = $handover?->name ?? ($meta['name'] ?? ('Gia Chủ ' . substr($phone, -4)));
                $customerEmail = $handover?->email ?? ($meta['email'] ?? ($phone . '@aicenty.com'));
                $butlerName = $handover?->butler_name ?? ($meta['butler_name'] ?? 'Quản Gia Aicenty');
                $familyName = $handover?->family_name ?? ($meta['family_name'] ?? ('Gia Đình ' . $customerName));
                
                // Nhận diện Zalo UID thực tế (15+ số) để giao tiếp an toàn, không bị Error 114
                $realZaloUid = $handover?->claimed_zalo_personal_id ?? ($meta['zalo_user_id'] ?? null);
                if (empty($realZaloUid) && $profile && strlen((string)$profile->user_id) >= 15 && ctype_digit((string)$profile->user_id)) {
                    $realZaloUid = (string) $profile->user_id;
                }
                $zaloOaId = $handover?->zalo_oa_user_id ?? ($meta['zalo_oa_user_id'] ?? null);

                // 3. Tạo/Cập nhật tài khoản Web Portal cho gia đình
                $plainPassword = \Illuminate\Support\Str::random(10);
                $existingUser = \App\Models\User::where('email', $customerEmail)->orWhere('phone', $phone)->first();
                if (!$existingUser) {
                    \App\Models\User::create([
                        'name'           => $customerName,
                        'email'          => $customerEmail,
                        'phone'          => $phone,
                        'password'       => \Illuminate\Support\Facades\Hash::make($plainPassword),
                        'type'           => 'user',
                        'status'         => 'active',
                        'trial_ends_at'  => $expireAt,
                    ]);
                } else {
                    $plainPassword = '(Giữ nguyên mật khẩu hiện tại của bạn)';
                    $existingUser->update([
                        'trial_ends_at' => $expireAt,
                        'status'        => 'active',
                    ]);
                }

                // 4. Tạo Tenant độc lập & Nhân Bản Ký Ức Đa Tầng (Dual-Synchronized Memory Handover)
                $syncResult = $automationService->replicateAndSyncFamilyMemory(
                    $phone,
                    $customerName,
                    $butlerName,
                    $realZaloUid ?: ('phone_' . $phone),
                    [
                        'plan_name'   => $planName,
                        'expire_date' => $expireDateStr,
                        'days'        => $days,
                    ]
                );
                $tenantId = $syncResult['tenant_id'] ?? ($handover?->dedicated_tenant_id ?? null);
                $masterButlerId = $syncResult['agent_id'] ?? ($handover?->dedicated_agent_id ?? null);

                // Cập nhật ngược lại vào quangia_pending_handover để bảo đảm liên kết dữ liệu xuyên suốt
                if ($handover && $tenantId) {
                    $db->table('quangia_pending_handover')
                        ->where('id', $handover->id)
                        ->update([
                            'dedicated_tenant_id' => $tenantId,
                            'dedicated_agent_id'  => $masterButlerId,
                        ]);
                }

                // 4b. Cập nhật trạng thái VIP trong tenants & profiles & crons
                if ($tenantId) {
                    $tenantRow = $db->table('tenants')->where('id', $tenantId)->first();
                    $tSettings = json_decode((string)($tenantRow?->settings ?? '{}'), true) ?: [];
                    $tSettings['is_vip'] = true;
                    $tSettings['is_trial'] = false;
                    $tSettings['plan_name'] = $planName;
                    $tSettings['expires_at'] = $expireAt->toIso8601String();
                    $db->table('tenants')->where('id', $tenantId)->update(['settings' => json_encode($tSettings, JSON_UNESCAPED_UNICODE)]);

                    // Bật cron jobs sáng 7h00 & tối 21h30 cho Tenant (chống Error 114)
                    if (!empty($realZaloUid) && strlen((string)$realZaloUid) >= 15 && ctype_digit((string)$realZaloUid)) {
                        $db->table('cron_jobs')
                            ->where('tenant_id', $tenantId)
                            ->where(function ($q) use ($phone) {
                                $q->where('name', 'like', "%{$phone}%")
                                  ->orWhere('name', 'like', 'proactive-%');
                            })
                            ->update([
                                'enabled'    => true,
                                'deliver_to' => (string) $realZaloUid,
                                'updated_at' => now(),
                            ]);
                    }
                }

                // Cập nhật profile trên Master
                $db->table('user_agent_profiles')
                    ->where('tenant_id', '0193a5b0-7000-7000-8000-000000000001')
                    ->where(function ($q) use ($phone, $realZaloUid) {
                        $q->where('user_id', 'phone_' . $phone)
                          ->orWhere('user_id', $phone);
                        if (!empty($realZaloUid)) {
                            $q->orWhere('user_id', (string)$realZaloUid);
                        }
                    })
                    ->update([
                        'metadata' => json_encode(array_merge($meta, [
                            'is_vip'       => true,
                            'vip_plan'     => $planName,
                            'vip_until'    => $expireAt->toIso8601String(),
                            'paid_amount'  => $amount,
                            'upgraded_at'  => now()->toIso8601String(),
                        ]), JSON_UNESCAPED_UNICODE)
                    ]);

                // 5. Gửi email kích hoạt dịch vụ thành công cho khách & admin
                try {
                    $tenantSlug = $tenantRow?->slug ?? '';
                    $mailer->sendSubscriptionActivated([
                        'name'        => $customerName,
                        'email'       => $customerEmail,
                        'phone'       => $phone,
                        'plan_name'   => $planName,
                        'amount'      => $amount,
                        'expire_date' => $expireDateStr,
                        'password'    => $plainPassword,
                        'tenant_slug' => $tenantSlug,
                    ]);
                } catch (\Throwable $me) {
                    Log::warning('[ButlerTrialController] Mailer warning: ' . $me->getMessage());
                }

                // 6. Bắn tin Zalo chúc mừng & thông báo thời hạn (Đảm bảo dùng Zalo UID thật chống Error 114)
                $zaloMsg = "🎉 CHÚC MỪNG QUÝ GIA CHỦ ĐÃ KÍCH HOẠT THÀNH CÔNG {$planName}!\n\n"
                    . "🎩 Quản gia riêng: {$butlerName}\n"
                    . "⏳ Thời hạn phục vụ: đến ngày {$expireDateStr}\n"
                    . "📱 Quản gia riêng đã được nhân bản vào tổ chức độc lập của gia đình và sẵn sàng đồng hành 24/7.\n"
                    . "Toàn bộ lịch sử học tập, nhật ký của các con đã được bảo lưu nguyên vẹn.\n\n"
                    . "Chúc gia đình mình có những trải nghiệm tuyệt vời cùng Aicenty Butler!";

                $activeNotifyChannel = $automationService->resolveActiveZaloChannel('butlerguru');
                $activeNotifyAgent = $automationService->resolveAgentForChannel($activeNotifyChannel);

                // Gửi qua Zalo Cá Nhân nếu có UID thật
                if (!empty($realZaloUid) && strlen((string)$realZaloUid) >= 15 && ctype_digit((string)$realZaloUid)) {
                    $db->table('cron_jobs')->insert([
                        'id'               => (string) \Illuminate\Support\Str::uuid(),
                        'agent_id'         => $activeNotifyAgent,
                        'user_id'          => 'system',
                        'name'             => 'congrat-purchase-' . $phone . '-' . time(),
                        'enabled'          => true,
                        'schedule_kind'    => 'at',
                        'run_at'           => now(),
                        'next_run_at'      => now()->addSeconds(10),
                        'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $zaloMsg], JSON_UNESCAPED_UNICODE),
                        'delete_after_run' => true,
                        'tenant_id'        => '0193a5b0-7000-7000-8000-000000000001',
                        'deliver'          => true,
                        'deliver_channel'  => $activeNotifyChannel,
                        'deliver_to'       => (string) $realZaloUid,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }

                // Gửi qua Zalo OA nếu có Zalo OA ID
                if (!empty($zaloOaId)) {
                    try {
                        $oaService = app(\App\Services\ZaloOAService::class);
                        $oaService->sendTextMessage((string) $zaloOaId, $zaloMsg);
                    } catch (\Throwable $oae) {
                        Log::warning('[ButlerTrialController] Zalo OA notification warning: ' . $oae->getMessage());
                    }
                }

                // 7. Lên lịch hẹn giờ tự động nhắc hết hạn (trước 3 ngày)
                if (!empty($realZaloUid) && strlen((string)$realZaloUid) >= 15 && ctype_digit((string)$realZaloUid)) {
                    $remind3DaysAt = $expireAt->copy()->subDays(3);
                    if ($remind3DaysAt->isFuture()) {
                        $remindJobName = 'remind-expire-3days-' . $phone;
                        $db->table('cron_jobs')->updateOrInsert(
                            [
                                'agent_id'  => $activeNotifyAgent,
                                'tenant_id' => '0193a5b0-7000-7000-8000-000000000001',
                                'name'      => $remindJobName,
                            ],
                            [
                                'user_id'          => 'system',
                                'enabled'          => true,
                                'schedule_kind'    => 'at',
                                'run_at'           => $remind3DaysAt,
                                'next_run_at'      => $remind3DaysAt,
                                'payload'          => json_encode(['kind' => 'agent_turn', 'message' => "Dạ em chào Ba/Mẹ! Gói Quản gia của gia đình mình còn 3 ngày nữa (đến ngày {$expireDateStr}) là hết hạn thuê bao. Ba/Mẹ có thể quét mã VietQR để gia hạn liên tục cho con học tập nhé ạ!"], JSON_UNESCAPED_UNICODE),
                                'delete_after_run' => true,
                                'deliver'          => true,
                                'deliver_channel'  => $activeNotifyChannel,
                                'deliver_to'       => (string) $realZaloUid,
                                'updated_at'       => now(),
                            ]
                        );
                    }
                }

                // 8. Bắn thông báo nhận tiền thật vào Group Admin HNKT
                try {
                    $automationService->notifyZaloGroupHNKT([
                        'name'           => $customerName,
                        'phone'          => $phone,
                        'email'          => $customerEmail,
                        'family_members' => $familyName,
                        'butler_name'    => "ĐÃ THANH TOÁN {$planName} (" . number_format($amount) . "đ)",
                    ]);
                } catch (\Throwable $ne) {}

                Log::info("[ButlerTrialController] SePay full VIP provisioning completed for {$phone}, tenant: {$tenantId}");
            } catch (\Throwable $e) {
                Log::error('[ButlerTrialController] SePay provisioning failed: ' . $e->getMessage());
            }
        } elseif ($amount >= 50000) {
            try {
                $automationService->sendOpsAlert([
                    'transaction_id' => $txId ?: ('TX_' . time()),
                    'amount'         => $amount,
                    'content'        => $content,
                    'account_number' => $accNum ?: '837006868',
                    'gateway'        => (string) $request->input('gateway', 'MBBank'),
                ]);
            } catch (\Throwable $ae) {
                Log::warning('[ButlerTrialController] sendOpsAlert failed: ' . $ae->getMessage());
            }

            return response()->json([
                'status'  => 'manual_review_needed',
                'message' => 'Payment requires manual review (missing phone number or below minimum plan amount)',
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function addFamilyMember(Request $request, ButlerAutomationService $automationService)
    {
        if (!$this->isValidButlerSecret($request, ['BUTLER_SEPAY_FORWARD_KEY', 'BUTLER_SEPAY_INBOUND_KEY', 'SEPAY_WEBHOOK_TOKEN'])) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Unauthorized'], 401);
        }
        $request->validate([
            'primary_phone' => 'required|string',
            'name'          => 'required|string',
            'role'          => 'nullable|string',
            'phone'         => 'nullable|string',
            'zalo_user_id'  => 'nullable|string',
        ]);

        try {
            $result = $automationService->addFamilyMember(
                $request->input('primary_phone'),
                [
                    'name'         => $request->input('name'),
                    'role'         => $request->input('role', 'member'),
                    'phone'        => $request->input('phone'),
                    'zalo_user_id' => $request->input('zalo_user_id'),
                ]
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[ButlerTrialController] addFamilyMember failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * API Đăng ký cấu hình SIM Riêng cho Tenant gia đình (BYO-SIM)
     */
    public function registerDedicatedSim(Request $request, ButlerAutomationService $automationService)
    {
        if (!$this->isValidButlerSecret($request, ['BUTLER_SEPAY_FORWARD_KEY', 'BUTLER_SEPAY_INBOUND_KEY', 'SEPAY_WEBHOOK_TOKEN'])) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Unauthorized'], 401);
        }
        $request->validate([
            'tenant_id'  => 'required|string',
            'sim_phone'  => 'required|string',
            'network'    => 'nullable|string',
        ]);

        try {
            $result = $automationService->registerDedicatedSimChannel(
                $request->input('tenant_id'),
                $request->input('sim_phone'),
                [
                    'network' => $request->input('network', 'Viettel'),
                    'status'  => 'ready_for_qr',
                ]
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[ButlerTrialController] registerDedicatedSim failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function checkPaymentStatus(Request $request): JsonResponse
    {
        $phone = preg_replace('/\D/', '', (string) $request->input('phone', ''));
        if (strlen($phone) >= 9 && !str_starts_with($phone, '0')) {
            $phone = '0' . $phone;
        }

        if (empty($phone)) {
            return response()->json(['success' => false, 'message' => 'Số điện thoại không hợp lệ'], 400);
        }

        $db = \Illuminate\Support\Facades\DB::connection('butler_pgsql');
        $handover = $db->table('quangia_pending_handover')
            ->where('phone', $phone)
            ->orWhereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$phone])
            ->orderByDesc('created_at')
            ->first();

        $profile = $db->table('user_agent_profiles')
            ->where(function ($q) use ($phone) {
                $q->where('user_id', 'phone_' . $phone)
                  ->orWhere('user_id', $phone)
                  ->orWhereRaw("metadata->>'phone' = ?", [$phone]);
            })
            ->first();

        $meta = [];
        if ($profile && !empty($profile->metadata)) {
            $meta = is_string($profile->metadata) ? json_decode($profile->metadata, true) : (array) $profile->metadata;
        }

        $isVip = false;
        $vipUntil = $meta['vip_until'] ?? null;
        $planName = $meta['vip_plan'] ?? 'Gói VIP Tiêu Chuẩn';

        if (!empty($vipUntil)) {
            try {
                $dt = \Carbon\Carbon::parse($vipUntil);
                if ($dt->isFuture()) {
                    $isVip = true;
                }
            } catch (\Throwable $e) {}
        }

        if (!$isVip && $handover && !empty($handover->dedicated_tenant_id)) {
            $tenant = $db->table('tenants')->where('id', $handover->dedicated_tenant_id)->first();
            if ($tenant && !empty($tenant->settings)) {
                $tSettings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : (array) $tenant->settings;
                if (!empty($tSettings['is_vip'])) {
                    $isVip = true;
                }
            }
        }

        return response()->json([
            'success'   => true,
            'is_paid'   => $isVip,
            'plan_name' => $isVip ? $planName : null,
            'phone'     => $phone,
        ]);
    }

}
