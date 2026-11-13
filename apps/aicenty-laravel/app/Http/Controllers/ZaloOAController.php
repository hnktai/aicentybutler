<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ZaloOAService;
use App\Services\ButlerAutomationService;
use App\Services\ButlerTrialMailer;
use App\Services\OdooCrmService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ZaloOAController extends Controller
{
    protected ZaloOAService $zaloService;
    protected ButlerAutomationService $automationService;

    public function __construct(ZaloOAService $zaloService, ButlerAutomationService $automationService)
    {
        $this->zaloService = $zaloService;
        $this->automationService = $automationService;
    }

    public function oauthRedirect()
    {
        $url = $this->zaloService->getAuthorizationUrl();
        return redirect()->away($url);
    }

    public function oauthCallback(Request $request)
    {
        $code = $request->query('code');
        if (empty($code)) {
            return response()->json(['success' => false, 'error' => 'Không nhận được mã code từ Zalo OA.'], 400);
        }

        $res = $this->zaloService->exchangeAuthCode($code);
        if ($res['success']) {
            return response()->make("
                <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
                    <h2 style='color: #16a34a;'>✅ Kết nối Zalo Official Account Thành Công!</h2>
                    <p>Token đã được lưu an toàn vào hệ thống Aicenty. Bạn có thể đóng tab này và bắt đầu sử dụng.</p>
                    <p style='color: #64748b;'>OA ID: " . ($res['data']['oa_id'] ?? 'N/A') . " | Expires in: " . ($res['data']['expires_in'] ?? 'N/A') . "s</p>
                    <a href='/admin' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 8px;'>Về trang quản trị</a>
                </div>
            ");
        }

        return response()->make("
            <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
                <h2 style='color: #dc2626;'>❌ Lỗi Kết Nối Zalo OA</h2>
                <p>" . ($res['error'] ?? 'Không rõ nguyên nhân') . "</p>
                <a href='/api/zalo-oa/oauth/redirect' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 8px;'>Thử lại</a>
            </div>
        ", 400);
    }

    public function status(): JsonResponse
    {
        $tokens = $this->zaloService->loadTokenData();
        $hasToken = !empty($tokens['access_token']);
        $expiresAt = $tokens['expires_at'] ?? 0;
        $isExpired = time() >= $expiresAt;

        return response()->json([
            'connected'   => $hasToken,
            'is_expired'  => $isExpired,
            'expires_at'  => $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null,
            'oa_id'       => $tokens['oa_id'] ?? '97838442689718827',
            'app_id'      => env('ZALO_OA_APP_ID', '2145957057911454748'),
            'updated_at'  => $tokens['updated_at'] ?? null,
            'oa_link'     => 'https://zalo.me/97838442689718827',
        ]);
    }

    public function webhook(Request $request)
    {
        if ($request->has('challenge')) {
            $ch = $request->input('challenge');
            return response($ch, 200)->header('Content-Type', 'text/plain');
        }

        $event = $request->input('event_name');
        Log::info('[ZaloOA Webhook] Event: ' . $event, ['payload' => $request->all()]);

        if (empty($event)) {
            return response()->json(['error' => 0, 'message' => 'no_event']);
        }

        $userId = (string) ($request->input('sender.id') ?? $request->input('user_id_by_app') ?? '');
        if (empty($userId) || $userId === '97838442689718827') {
            return response()->json(['error' => 0, 'message' => 'missing_or_oa_self_user_id']);
        }

        // Lấy thông tin họ tên từ Zalo Profile
        $profile = $this->zaloService->getUserProfile($userId);
        $customerName = $profile['display_name'] ?? '';

        // Tự động nhận diện tên khách hàng từ DB/Lead đã đăng ký trên web
        $customerName = $this->resolveCustomerName($userId, $customerName);

        if ($event === 'follow') {
            $genderData = $this->detectGenderAndHonorific($customerName);
            $hon = $genderData['honorific'];
            $quangiaLink = "https://zalo.me/0961592763";

            $welcomeMsg = "Chào {$hon}! Bấm vào đây để kết nối với Quản Gia riêng của gia đình ạ: 👉 {$quangiaLink}";

            $this->zaloService->sendTextMessage($userId, $welcomeMsg);
            return response()->json(['error' => 0, 'message' => 'welcome_sent']);
        }

        if ($event === 'user_send_text') {
            $text = trim((string) $request->input('message.text', ''));
            $this->processUserTextMessage($userId, $customerName, $text);
            return response()->json(['error' => 0, 'message' => 'text_processed']);
        }

        return response()->json(['error' => 0, 'message' => 'event_ignored']);
    }

    /**
     * Zalo OA: Kênh chuyên Chăm Sóc Khách Hàng, Hỗ Trợ Kỹ Thuật & Điều Hướng Quản Gia AI
     */
    protected function processUserTextMessage(string $userId, string $customerName, string $text): void
    {
        // Nhận diện lại tên nếu chưa có
        if (empty($customerName) || $customerName === 'Gia Chủ') {
            $customerName = $this->resolveCustomerName($userId, $customerName);
        }

        $genderData = $this->detectGenderAndHonorific($customerName, $text);
        $hon = $genderData['honorific']; // "Gia chủ Hồng Hoàng", "Anh Hùng", "Chị Thảo"... hoặc "Gia chủ"
        $lower = mb_strtolower($text, 'UTF-8');
        $quangiaLink = "https://zalo.me/0961592763";

        // Chỉ nhận SĐT khi khách hàng CHỦ ĐỘNG KHAI BÁO SĐT của chính mình (chống cướp nhầm SĐT người khác khi nhắc tới trong câu chuyện)
        $cleanPhoneInput = preg_replace('/[\s.-]/', '', $text);
        $isExplicitPhoneDeclaration = false;
        $matchedPhone = '';

        if (preg_match('/^(?:\+?84|0)[35789][0-9]{8}$/', $cleanPhoneInput)) {
            $isExplicitPhoneDeclaration = true;
            $matchedPhone = $cleanPhoneInput;
        } elseif (preg_match('/^(?:sđt|sdt|số điện thoại|số của mình|số của tôi|số em|sđt em)\s*[:=\-\s]?\s*((?:\+?84|0)[35789][0-9]{8})$/iu', $cleanPhoneInput, $m)) {
            $isExplicitPhoneDeclaration = true;
            $matchedPhone = $m[1];
        }

        if ($isExplicitPhoneDeclaration && !empty($matchedPhone)) {
            $raw = preg_replace('/[^0-9]/', '', $matchedPhone);
            if (str_starts_with($raw, '84') && strlen($raw) === 11) {
                $raw = '0' . substr($raw, 2);
            }
            $phone = $raw;
            Cache::put("zalo_oa_phone_{$userId}", $phone, now()->addDays(30));

            try {
                $db = DB::connection('butler_pgsql');
                // Chỉ cập nhật nếu handover này chưa có ai liên kết hoặc đúng là user này
                $db->table('quangia_pending_handover')
                    ->where('phone', $phone)
                    ->where(function($q) use ($userId) {
                        $q->whereNull('zalo_oa_user_id')->orWhere('zalo_oa_user_id', $userId);
                    })
                    ->update(['zalo_oa_user_id' => $userId]);
            } catch (\Throwable $e) {
                Log::warning('[ZaloOA] Failed to link phone in handover: ' . $e->getMessage());
            }

            $nameFromPhone = $this->lookupNameByPhone($phone);
            if ($nameFromPhone) {
                $customerName = $nameFromPhone;
                $genderData = $this->detectGenderAndHonorific($customerName, $text);
                $hon = $genderData['honorific'];
            }

            $reply = "Dạ Quản Gia đã lưu SĐT {$phone} của gia đình mình rồi ạ! Bấm vào đây để kết nối với Quản Gia riêng ạ: 👉 {$quangiaLink}";

            $this->zaloService->sendTextMessage($userId, $reply);
            return;
        }

        // Nếu khách hỏi về các gói dịch vụ (1 tháng, 3 tháng, 6 tháng, 12 tháng) hoặc bảng giá
        if (str_contains($lower, 'gói') || str_contains($lower, 'bảng giá') || str_contains($lower, 'chi phí') || str_contains($lower, 'bao nhiêu') || str_contains($lower, 'gia hạn')) {
            $reply = "Gói Quản Gia AI: 1T 500k | 3T 1.2tr | 6T 2.1tr | 12T 3.6tr.\n"
                . "Chi tiết: https://aicenty.com/butler#bang-gia | Chat Quản Gia: {$quangiaLink}";

            $this->zaloService->sendTextMessage($userId, $reply);
            return;
        }

        // Mặc định: điều hướng siêu ngắn sang Zalo Quản Gia AI
        $reply = "Chào {$hon}! Bấm vào đây để chat trực tiếp với Quản Gia riêng của gia đình ạ: 👉 {$quangiaLink}";

        $this->zaloService->sendTextMessage($userId, $reply);
    }

    /**
     * Tự động tra cứu tên khách hàng từ SĐT, Profile GoClaw hoặc Lead vừa đăng ký web
     */
    protected function resolveCustomerName(string $userId, string $currentName): string
    {
        $clean = trim($currentName);
        $clean = preg_replace('/^(?:gia\s*chủ\s*)+/iu', '', $clean);
        $clean = preg_replace('/^(?:gia\s*chu\s*)+/iu', '', $clean);
        if (!empty($clean) && !in_array(mb_strtolower($clean, 'UTF-8'), ['gia chủ', 'gia chu', 'khách hàng', 'khach hang', 'bạn', 'ban', 'user'], true)) {
            return $clean;
        }

        // 0. ƯU TIÊN 0: Tra trực tiếp trong quangia_pending_handover theo zalo_oa_user_id
        try {
            $db = DB::connection('butler_pgsql');
            $h = $db->table('quangia_pending_handover')
                ->where('zalo_oa_user_id', $userId)
                ->orderByDesc('created_at')
                ->first();
            if ($h && !empty($h->name)) {
                Cache::put("zalo_oa_phone_{$userId}", $h->phone, now()->addDays(30));
                return $h->name;
            }
        } catch (\Throwable $e) {}

        // Tuyệt đối không đoán mò từ lead web để tránh nhầm lẫn danh tính khách hàng

        // 2. Kiểm tra xem userId này đã liên kết SĐT trong Cache chưa
        $cachedPhone = Cache::get("zalo_oa_phone_{$userId}");
        if ($cachedPhone) {
            $nameFromDb = $this->lookupNameByPhone($cachedPhone);
            if ($nameFromDb) return $nameFromDb;
        }

        // 3. Tra trong PostgreSQL user_agent_profiles theo zalo_user_id
        try {
            $db = DB::connection('butler_pgsql');
            $row = $db->table('user_agent_profiles')
                ->where('user_id', $userId)
                ->orWhereRaw("metadata->>'zalo_user_id' = ?", [$userId])
                ->first();
            if ($row && !empty($row->metadata)) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                if (!empty($meta['name']) && !in_array(mb_strtolower($meta['name'], 'UTF-8'), ['gia chủ', 'gia chu'], true)) {
                    if (!empty($meta['phone'])) {
                        Cache::put("zalo_oa_phone_{$userId}", $meta['phone'], now()->addDays(30));
                    }
                    return $meta['name'];
                }
            }
        } catch (\Throwable $e) {}

        return '';
    }

    protected function lookupNameByPhone(string $phone): ?string
    {
        try {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (str_starts_with($cleanPhone, '84') && strlen($cleanPhone) === 11) {
                $cleanPhone = '0' . substr($cleanPhone, 2);
            }
            $db = DB::connection('butler_pgsql');

            // 1. Tra trong quangia_pending_handover
            $hRow = $db->table('quangia_pending_handover')
                ->where('phone', $cleanPhone)
                ->orderByDesc('created_at')
                ->first();
            if ($hRow && !empty($hRow->name)) {
                return $hRow->name;
            }

            // 2. Tra trong user_agent_profiles
            $row = $db->table('user_agent_profiles')
                ->where('user_id', 'phone_' . $cleanPhone)
                ->orWhere('user_id', $cleanPhone)
                ->first();
            if ($row && !empty($row->metadata)) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                if (!empty($meta['name']) && !in_array(mb_strtolower($meta['name'], 'UTF-8'), ['gia chủ', 'gia chu'], true)) {
                    return $meta['name'];
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }

    protected function detectGenderAndHonorific(string $baseName, string $text = ''): array
    {
        $cleanName = trim($baseName);

        // Xóa sạch tiền tố "Gia chủ", "Gia Chủ" nếu có từ trước để chống lặp từ
        $cleanName = preg_replace('/^(?:gia\s*chủ\s*)+/iu', '', $cleanName);
        $cleanName = preg_replace('/^(?:gia\s*chu\s*)+/iu', '', $cleanName);
        $cleanName = trim($cleanName);

        // Nếu rỗng hoặc là từ mặc định không có tên riêng: chỉ xưng "Gia chủ" (TUYỆT ĐỐI KHÔNG lặp từ "Gia chủ Gia Chủ")
        if (empty($cleanName) || in_array(mb_strtolower($cleanName, 'UTF-8'), ['gia chủ', 'gia chu', 'khách hàng', 'khach hang', 'bạn', 'ban', 'user'], true)) {
            return ['gender' => 'chua_ro', 'honorific' => 'Gia chủ', 'clean_name' => ''];
        }

        $lowerName = mb_strtolower($cleanName, 'UTF-8');
        $lowerText = mb_strtolower($text, 'UTF-8');

        // 1. Kiểm tra tiền tố ngay trong Tên Zalo (nếu tên là "Mẹ Sóc", "Ba Bon", "Chị Mai", "Anh Nam"...)
        if (preg_match('/^(?:ba|bố|cha|anh|chú)\s+(.+)$/iu', $cleanName, $m)) {
            $clean = trim($m[1]);
            $title = preg_match('/^(?:ba|bố|cha)/iu', $cleanName) ? 'Ba' : 'Anh';
            return ['gender' => 'nam', 'honorific' => "{$title} {$clean}", 'clean_name' => $clean];
        }
        if (preg_match('/^(?:mẹ|má|chị|cô)\s+(.+)$/iu', $cleanName, $m)) {
            $clean = trim($m[1]);
            $title = preg_match('/^(?:mẹ|má)/iu', $cleanName) ? 'Mẹ' : 'Chị';
            return ['gender' => 'nu', 'honorific' => "{$title} {$clean}", 'clean_name' => $clean];
        }

        // 2. Kiểm tra xưng hô tự xưng chuẩn xác trong tin nhắn (chỉ bắt khi tự xưng bản thân, không bắt lời chúc/lời cảm ơn)
        if (preg_match('/^(?:tôi là ba|mình là ba|anh là ba|ba đây|bố đây)\b|\b(?:bố cháu|ba cháu|gọi ba là được)\b/iu', $lowerText)) {
            return ['gender' => 'nam', 'honorific' => "Ba {$cleanName}", 'clean_name' => $cleanName];
        }
        if (preg_match('/^(?:tôi là mẹ|mình là mẹ|chị là mẹ|mẹ đây|má đây)\b|\b(?:mẹ cháu|má cháu|gọi mẹ là được)\b/iu', $lowerText)) {
            return ['gender' => 'nu', 'honorific' => "Mẹ {$cleanName}", 'clean_name' => $cleanName];
        }
        if (preg_match('/^(?:tôi là anh|mình là anh|anh đây)\b|\bgọi anh là được\b/iu', $lowerText)) {
            return ['gender' => 'nam', 'honorific' => "Anh {$cleanName}", 'clean_name' => $cleanName];
        }
        if (preg_match('/^(?:tôi là chị|mình là chị|chị đây)\b|\bgọi chị là được\b/iu', $lowerText)) {
            return ['gender' => 'nu', 'honorific' => "Chị {$cleanName}", 'clean_name' => $cleanName];
        }

        // 3. Phân tích tên tiếng Việt chuẩn xác theo Đệm và Tên chính (TUYỆT ĐỐI KHÔNG dùng Họ từ đầu tiên để đoán giới tính)
        $words = preg_split('/\s+/u', $lowerName, -1, PREG_SPLIT_NO_EMPTY);

        // Đệm bất biến chứng minh giới tính (Thị -> Nữ, Văn/Hữu/Đình/Bá -> Nam)
        if (in_array('thị', $words, true)) {
            return ['gender' => 'nu', 'honorific' => "Chị {$cleanName}", 'clean_name' => $cleanName];
        }
        if (in_array('văn', $words, true) || in_array('hữu', $words, true) || in_array('đình', $words, true) || in_array('bá', $words, true)) {
            return ['gender' => 'nam', 'honorific' => "Anh {$cleanName}", 'clean_name' => $cleanName];
        }

        // Danh sách tên trung tính / phi giới tính (Unisex - có thể là nam hoặc nữ, tuyệt đối không võ đoán!)
        $unisexTokens = ['hoàng', 'hồng', 'an', 'bình', 'hà', 'linh', 'giang', 'xuân', 'khánh', 'minh', 'hải', 'châu', 'thanh', 'mai', 'tâm', 'quân', 'hậu', 'diệp', 'ngọc', 'phương', 'nam', 'hân'];

        $lastWord = !empty($words) ? end($words) : '';

        // Nếu toàn bộ tên hoặc tên chính rơi vào nhóm trung tính (ví dụ "Hồng Hoàng", "Bảo An", "Thuỳ Linh", "Hà", "Khánh")
        $hasUnisex = in_array($lastWord, $unisexTokens, true);
        if (count($words) >= 2) {
            $prevWord = $words[count($words) - 2];
            if (in_array($prevWord, ['hồng', 'hoàng'], true) && in_array($lastWord, ['hoàng', 'hồng'], true)) {
                $hasUnisex = true; // Trường hợp Hồng Hoàng hoặc Hoàng Hồng
            }
        }

        if (!$hasUnisex) {
            $pureFemaleGivens = [
                'hằng', 'nga', 'hoa', 'huệ', 'hương', 'hạnh', 'lan', 'liễu', 'loan', 
                'my', 'ngân', 'nhung', 'oanh', 'phượng', 'quyên', 'quỳnh', 'thảo', 
                'thoa', 'thúy', 'thủy', 'tiên', 'trang', 'trinh', 'trúc', 'tuyết', 'uyên', 
                'vân', 'yến', 'thư', 'dung', 'huyền', 'vy', 'trâm', 'nhi', 
                'bích', 'hiền', 'đào', 'thắm', 'sen', 'hường', 'gấm'
            ];

            $pureMaleGivens = [
                'hùng', 'dũng', 'cường', 'thắng', 'long', 'phong', 'nam', 
                'sơn', 'tùng', 'tuấn', 'huy', 'đạt', 'khoa', 'lâm', 'phúc', 
                'trọng', 'thành', 'kiên', 'trung', 'hiếu', 'nghĩa', 'việt', 
                'thái', 'tài', 'vinh', 'thịnh', 'đức', 'vũ', 'chung', 'đăng', 'toàn', 'bách', 
                'tiến', 'khôi', 'trí', 'hưng', 'đôn', 'hiển', 'quang', 'nguyên', 
                'bằng', 'chí', 'hiệp', 'luân', 'thao', 'thiện', 'thụy', 'vương'
            ];

            if (in_array($lastWord, $pureFemaleGivens, true)) {
                return ['gender' => 'nu', 'honorific' => "Chị {$cleanName}", 'clean_name' => $cleanName];
            }
            if (in_array($lastWord, $pureMaleGivens, true)) {
                return ['gender' => 'nam', 'honorific' => "Anh {$cleanName}", 'clean_name' => $cleanName];
            }
        }

        // Tên trung tính / chưa rõ ràng: xưng hô trang trọng "Gia chủ [Tên]"
        return ['gender' => 'chua_ro', 'honorific' => "Gia chủ {$cleanName}", 'clean_name' => $cleanName];
    }

    /**
     * Tạo nội dung USER.md chuẩn hóa theo từng khách hàng
     */
    private function generateUserMdContent(array $profile): string
    {
        $name = $profile['name'] ?? 'Quý Gia Chủ';
        $gender = $profile['gender'] ?? 'chua_ro';
        $honorific = $profile['honorific'] ?? ('Gia chủ ' . $name);
        $phone = $profile['phone'] ?? '';
        $email = $profile['email'] ?? '';
        $familyName = $profile['family_name'] ?? ('Gia Đình ' . $name);
        $butlerName = $profile['butler_name'] ?? 'Bác Phúc';
        $childInfo = $profile['child_info'] ?? 'Các con';
        $tenantId = $profile['dedicated_tenant_id'] ?? '';

        $genderRule = match ($gender) {
            'nam'   => "Nam (Gia chủ là Nam. Tuyệt đối KHÔNG xưng cô hay mẹ, KHÔNG xưng chị).",
            'nu'    => "Nữ (Gia chủ là Nữ. Tuyệt đối KHÔNG xưng chú, anh hay bố).",
            default => "Chưa xác nhận (Tuyệt đối KHÔNG đoán mò giới tính).",
        };

        return "# USER.md - Hồ Sơ Gia Chủ & Tổ Ấm\n"
            . "- **Họ tên gia chủ:** " . $name . "\n"
            . "- **Giới tính:** " . $genderRule . "\n"
            . "- **Danh xưng xưng hô chuẩn:** " . $honorific . "\n"
            . "- **Số điện thoại:** " . $phone . "\n"
            . "- **Email:** " . $email . "\n"
            . "- **Tên Tổ Ấm:** " . $familyName . "\n"
            . "- **Tên Quản Gia riêng:** " . $butlerName . "\n"
            . "- **Các thành viên nhí cần đồng hành & học tập:** " . $childInfo . "\n"
            . "- **Phong cách phục vụ & Xưng hô:** Quản gia xưng là \"" . $butlerName . "\" (hoặc Quản gia) và gọi gia chủ chuẩn mực là \"" . $honorific . "\". Áp dụng phương pháp Socratic, lễ phép, tận tâm, chu đáo.\n"
            . "- **Tổ chức / Tenant ID Aicenty Butler:** " . $tenantId . "\n"
            . "\n"
            . "## QUY TẮC BẮT ĐẦU VÀ CHÀO ĐÓN (RẤT QUAN TRỌNG):\n"
            . "1. Gia chủ đã hoàn tất quá trình cài đặt & onboarding trên Zalo Doanh Nghiệp (Zalo OA). Mọi thông tin về tên Quản gia, tên Tổ ấm và các con đã được thiết lập đầy đủ.\n"
            . "2. Tuyệt đối KHÔNG thực hiện phỏng vấn làm quen lại, KHÔNG hỏi khảo sát, KHÔNG hỏi múi giờ (múi giờ mặc định là GMT+7 Việt Nam).\n"
            . "3. Ngay trong tin nhắn đầu tiên khi gia chủ nhắn đến, Quản gia hãy lập tức xưng hô đúng danh xưng \"" . $honorific . "\", tự giới thiệu đúng tên Quản gia \"" . $butlerName . "\", nhắc đến Tổ ấm \"" . $familyName . "\" và thông tin các con (\"" . $childInfo . "\"), bày tỏ sự vinh dự và sẵn sàng đồng hành, nhận việc của gia đình ngay lập tức!\n";
    }
}
