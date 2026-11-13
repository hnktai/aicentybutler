<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ButlerTrialMailer;
use App\Services\ButlerAutomationService;
use App\Services\OdooCrmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ButlerSyncOnboardingCommand extends Command
{
    protected $signature = 'butler:sync-onboarding
                            {--verified-members-only : Chỉ đồng bộ context cho thành viên đã có binding chính xác}';
    protected $description = 'Tự động quét hội thoại Zalo của Cố Vấn Guru (0981 224 739) & Quản Gia AI (0961 592 763), tự động nhận diện danh xưng, giới tính, bóc tách thông tin gia đình và tự động tạo Tổ chức gia đình riêng';

    public function handle(
        OdooCrmService $odooService,
        ButlerAutomationService $automationService,
        ButlerTrialMailer $mailer
    ): int {
        $verifiedMemberRepairs = $this->syncVerifiedFamilyMemberContexts($automationService);

        if ($this->option('verified-members-only')) {
            $this->info("Verified family-member contexts repaired: {$verifiedMemberRepairs}.");
            return 0;
        }

        if ((string) env('BUTLER_LEGACY_IDENTITY_SYNC_ENABLED', '0') !== '1') {
            Log::info("[Butler Sync CRM] Verified member reconciliation completed ({$verifiedMemberRepairs}); legacy heuristic identity sync remains disabled.");
            $this->info("Verified family-member contexts repaired: {$verifiedMemberRepairs}. Legacy heuristic identity sync remains disabled.");
            return 0;
        }

        $this->info('Starting Smart Onboarding & Cross-Zalo Sync with Dynamic Gender & Identity Detection...');
        Log::info('[Butler Sync CRM] Bat dau quet hoi thoai Zalo Guru va Quangia...');

        $guruAgentId = '01a1ac58-1bf3-7000-a68c-c6d36beaa62b';
        $masterButlerId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';

        try {
            $db = DB::connection('butler_pgsql');

            // =========================================================================
            // PHẦN 1: QUÉT HỘI THOẠI TRÊN ZALO BUTLER GURU (0981 224 739)
            // =========================================================================
            $guruSessions = $db->table('sessions')
                ->whereIn('channel', ['quangia', 'butlerguru'])
                ->where('tenant_id', $masterTenantId)
                ->whereIn('agent_id', [$guruAgentId, $masterButlerId])
                ->where('updated_at', '>=', now()->subHours(48))
                ->get();

            $autoProvisionedCount = 0;

            foreach ($guruSessions as $sess) {
                $messages = is_string($sess->messages) ? json_decode($sess->messages, true) : (array) $sess->messages;
                if (!is_array($messages) || empty($messages)) {
                    continue;
                }

                $zaloUserId = (string) $sess->user_id;
                if (empty($zaloUserId) || $zaloUserId === 'system' || str_starts_with($zaloUserId, 'phone_')) {
                    continue;
                }

                // Lấy profile hiện tại của user trên Master
                $currentProfile = $db->table('user_agent_profiles')
                    ->where('user_id', $zaloUserId)
                    ->where('tenant_id', $masterTenantId)
                    ->whereIn('agent_id', [$guruAgentId, $masterButlerId])
                    ->first();
                $currentMeta = is_string($currentProfile?->metadata) ? json_decode($currentProfile->metadata, true) : (array) ($currentProfile?->metadata ?? []);

                // Thành viên đã được bind vào tổ ấm không bao giờ đi qua bộ suy đoán onboarding của Gia chủ.
                // Context thành viên được quản lý bởi đường reconciliation dùng binding chính xác.
                if (!empty($currentMeta['is_family_member'])) {
                    Log::info("[Butler Onboard Sync] Bỏ qua owner onboarding cho thành viên gia đình {$zaloUserId}.");
                    continue;
                }

                // Bóc tách tên người gửi từ [From: <Tên>]
                $senderName = null;
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user' && preg_match('/^\[From:\s*([^\]]+)\]/u', (string) ($m['content'] ?? ''), $fnMatch)) {
                        $senderName = trim($fnMatch[1]);
                        break;
                    }
                }

                $detectedPhone = $currentMeta['phone'] ?? null;
                $detectedButlerName = null;
                $detectedFamilyName = null;
                $detectedChildInfo = null;
                $detectedHonorific = null;
                $detectedNotes = null;

                // 0. BÓC TÁCH TRỰC TIẾP TỪ TOOL_CALLS (WRITE_FILE CHO USER.MD) CỦA SESSION NẾU CÓ
                foreach (array_reverse($messages) as $mCheck) {
                    if (!empty($mCheck['tool_calls']) && is_array($mCheck['tool_calls'])) {
                        foreach ($mCheck['tool_calls'] as $tc) {
                            $tcArgs = $tc['arguments'] ?? [];
                            if (is_string($tcArgs)) {
                                $tcArgs = json_decode($tcArgs, true) ?? [];
                            }
                            if (is_array($tcArgs) && !empty($tcArgs['content']) && str_contains($tcArgs['path'] ?? '', 'USER.md')) {
                                $tcContent = (string) $tcArgs['content'];
                                if (!$detectedButlerName && preg_match('/- \*\*Tên Quản Gia riêng:\*\*\s*(.+)/iu', $tcContent, $tcB)) {
                                    $bVal = trim($tcB[1]);
                                    if (!empty($bVal) && !str_contains(mb_strtolower($bVal), 'chưa chọn') && !str_contains(mb_strtolower($bVal), 'aicenty')) {
                                        $detectedButlerName = $bVal;
                                    }
                                }
                                if (!$detectedHonorific && preg_match('/- \*\*Danh xưng gia chủ:\*\*\s*(.+)/iu', $tcContent, $tcH)) {
                                    $hVal = trim($tcH[1]);
                                    if (!empty($hVal) && !str_contains(mb_strtolower($hVal), 'chưa chọn')) {
                                        $detectedHonorific = $hVal;
                                    }
                                }
                                if (!$detectedFamilyName && preg_match('/- \*\*Tên Tổ Ấm:\*\*\s*(.+)/iu', $tcContent, $tcF)) {
                                    $fVal = trim($tcF[1]);
                                    if (!empty($fVal) && !str_contains(mb_strtolower($fVal), 'chưa chọn') && !str_contains(mb_strtolower($fVal), 'chờ')) {
                                        $detectedFamilyName = $fVal;
                                    }
                                }
                                if (!$detectedChildInfo && preg_match('/- \*\*Các con & Môn học ưu tiên:\*\*\s*(.+)/iu', $tcContent, $tcC)) {
                                    $cVal = trim($tcC[1]);
                                    if (!empty($cVal) && !str_contains(mb_strtolower($cVal), 'chưa cung cấp')) {
                                        $detectedChildInfo = $cVal;
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($messages as $idx => $m) {
                    if (($m['role'] ?? '') !== 'user') {
                        continue;
                    }
                    $text = (string) ($m['content'] ?? '');
                    $text = preg_replace('/^\[From:[^\]]+\]\s*/u', '', $text);
                    $text = trim($text);
                    if (str_starts_with($text, '<media:')) {
                        continue;
                    }

                    // Tìm tin nhắn trước đó của trợ lý để xác định ngữ cảnh câu hỏi
                    $prevAsst = '';
                    for ($j = $idx - 1; $j >= 0; $j--) {
                        if (($messages[$j]['role'] ?? '') === 'assistant') {
                            $prevAsst = mb_strtolower((string) ($messages[$j]['content'] ?? ''));
                            break;
                        }
                    }

                    // 1. Tìm Số điện thoại linh hoạt
                    if (!$detectedPhone) {
                        if (preg_match('/(?:sđt|sdt|số|phone|dđ|dt|zalo)?\s*[:=\-\s]?\s*((?:\+?84[\s.-]?|0)[35789](?:[\s.-]?[0-9]){8})/iu', $text, $matches)) {
                            $raw = preg_replace('/[^0-9]/', '', $matches[1]);
                            if (str_starts_with($raw, '84') && strlen($raw) === 11) {
                                $raw = '0' . substr($raw, 2);
                            }
                            $detectedPhone = $raw;
                        } elseif (preg_match('/((?:\+?84[\s.-]?|0)[35789](?:[\s.-]?[0-9]){8})/', $text, $matches)) {
                            $raw = preg_replace('/[^0-9]/', '', $matches[1]);
                            if (str_starts_with($raw, '84') && strlen($raw) === 11) {
                                $raw = '0' . substr($raw, 2);
                            }
                            $detectedPhone = $raw;
                        }
                    }

                    // 2. Bóc tách tên Quản gia nếu khách đề cập
                    if (preg_match('/(?:tên quản gia|gọi là|đặt tên|quản gia là|thích gọi|muốn gọi|chọn tên)\s*[:=\-\s]?\s*((?:Bác|Chú|Cô|Thầy)?\s*[A-ZÀ-Ỹ0-9][a-zà-ỹ0-9]+(?:\s+[A-ZÀ-Ỹ0-9][a-zà-ỹ0-9]+)?)/iu', $text, $mName)) {
                        $detectedButlerName = rtrim(trim($mName[1]), ',.');
                    } elseif (preg_match('/((?:Bác|Chú|Cô|Thầy)\s+[A-ZÀ-Ỹ0-9][a-zà-ỹ0-9]*(?:\s+[A-ZÀ-Ỹ0-9][a-zà-ỹ0-9]*)?)/u', $text, $mName)) {
                        $detectedButlerName = rtrim(trim($mName[1]), ',.');
                    } elseif ((str_contains($prevAsst, 'quản gia riêng là gì') || str_contains($prevAsst, 'đặt tên quản gia') || str_contains($prevAsst, 'tên quản gia')) && !str_contains($prevAsst, 'tổ ấm') && !str_contains($prevAsst, 'lớp')) {
                        if (count(explode(' ', $text)) <= 5 && !preg_match('/(?:lớp|học|toán|http)/iu', $text)) {
                            $detectedButlerName = $text;
                        }
                    }

                    // 3. Bóc tách tên tổ ấm / tổ chức gia đình nếu khách đề cập
                    if (preg_match('/(?:tổ ấm là|tổ chức gia đình là|tên tổ ấm là|tên tổ chức là|tên gia đình là|gia đình là|tên tổ ấm|tổ ấm|tổ chức gia đình|tổ chức|tên tổ chức|đặt là)\\s*[:=\\-\\s]?\\s*([A-Za-zÀ-ỹ0-9\\s()]{2,40}?)(?=[,.\\n]|\\s+(?:con|bé|lớp|cháu|sđt|sdt|quản gia)|$)/iu', $text, $mFam)) {
                        $fCand = rtrim(trim($mFam[1]), ',.');
                        if (!empty($fCand) && !preg_match('/^(?:tổ ấm|quản gia|chưa chọn|lớp)$/iu', $fCand)) {
                            $detectedFamilyName = $fCand;
                        }
                    } elseif (preg_match('/(Gia\\s+Đình\\s+[A-Za-zÀ-ỹ0-9\\s]{2,35}|[A-ZÀ-Ỹ][a-zà-ỹ]+(?:\\s+[A-ZÀ-Ỹ][a-zà-ỹ]+)*\\s+Gia|Tổ\\s+Ấm\\s+[A-Za-zÀ-ỹ0-9\\s]{2,35})/u', $text, $mFam)) {
                        $detectedFamilyName = rtrim(trim($mFam[1]), ',.');
                    } elseif (str_contains($prevAsst, 'tên tổ ấm') || str_contains($prevAsst, 'tổ ấm gia đình') || str_contains($prevAsst, 'tổ chức gia đình') || str_contains($prevAsst, 'tổ chức gia đình mình')) {
                        $isInvalidNoise = preg_match('/^(?:bạn là ai|ai đấy|ai đây|là ai|sao vậy|tại sao|sao thế|chào|hello|hi|alo|ok|được|chưa|không|tôi không biết|chưa biết|chưa rõ|gì cơ|cái gì|thế nào|sao|sđt|số điện thoại)/iu', trim($text));
                        if (!$isInvalidNoise && count(explode(' ', $text)) <= 8 && !preg_match('/(?:lớp|học|toán|http)/iu', $text)) {
                            $detectedFamilyName = $text;
                        }
                    }

                    // 4. Bóc tách thông tin con / học tập
                    if (str_contains($prevAsst, 'lớp mấy') || str_contains($prevAsst, 'đồng hành kèm cặp') || str_contains($prevAsst, 'kèm cặp vấn đề gì')) {
                        $detectedChildInfo = $text;
                    } elseif (preg_match('/(lớp\s+[0-9]{1,2}[a-zA-Z\sÀ-ỹ0-9,.\s]*?)(?=\s+(?:tổ ấm|quản gia|sđt)|$)/iu', $text, $mChild)) {
                        $detectedChildInfo = rtrim(trim($mChild[1]), ',.');
                    }

                    // 5. Bóc tách câu hỏi 4: Chia sẻ bối cảnh gia đình / thành viên
                    if (str_contains($prevAsst, 'biết nhiều hơn về nhà mình') || str_contains($prevAsst, 'chia sẻ thêm những gì') || str_contains($prevAsst, 'bối cảnh từng thành viên') || str_contains($prevAsst, 'hỗ trợ gia đình tốt nhất')) {
                        $detectedNotes = $text;
                    }
                }

                // 5. NHẬN DIỆN DANH XƯNG & GIỚI TÍNH CHUẨN XÁC CHO MỌI KHÁCH HÀNG
                // Tra cứu bản ghi bàn giao sớm để lấy TÊN ĐĂNG KÝ TRÊN FORM (ưu tiên số 1, không dùng tên Zalo)
                $earlyHandover = null;
                $lookupPhone = $detectedPhone ?? ($currentMeta['phone'] ?? null);
                if (!empty($lookupPhone)) {
                    $cPhone = preg_replace('/[^0-9]/', '', $lookupPhone);
                    $earlyHandover = $db->table('quangia_pending_handover')->where('phone', $cPhone)->orderByDesc('created_at')->first();
                }
                if (!$earlyHandover) {
                    $earlyHandover = $db->table('quangia_pending_handover')->where('claimed_zalo_personal_id', $zaloUserId)->orderByDesc('created_at')->first();
                }
                $formRegisteredName = $earlyHandover?->name ?? ($currentMeta['name'] ?? null);

                $genderData = $this->detectGenderAndHonorific($senderName ?? '', $messages, $formRegisteredName);
                if (empty($detectedHonorific)) {
                    $detectedGender = $genderData['gender'];
                    $detectedHonorific = $genderData['honorific'];
                } else {
                    $detectedGender = (str_starts_with($detectedHonorific, 'Mẹ') || str_starts_with($detectedHonorific, 'Chị') || str_starts_with($detectedHonorific, 'Cô')) ? 'nu' : 'nam';
                }
                $cleanCustomerName = $genderData['clean_name'];

                // NẾU ĐÃ CÓ TRIAL PROVISIONED, CẬP NHẬT CÁC THAY ĐỔI
                if (!empty($currentMeta['trial_provisioned'])) {
                    $hasUpdate = false;
                    $updatedMeta = $currentMeta;
                    $dedicatedTenantId = $currentMeta['dedicated_tenant_id'] ?? null;
                    $dedicatedAgentId = $currentMeta['dedicated_agent_id'] ?? null;

                    if (!empty($detectedButlerName) && $detectedButlerName !== ($currentMeta['butler_name'] ?? '')) {
                        $updatedMeta['butler_name'] = $detectedButlerName;
                        $hasUpdate = true;
                    }
                    if (!empty($detectedFamilyName) && $detectedFamilyName !== ($currentMeta['family_name'] ?? '')) {
                        $updatedMeta['family_name'] = $detectedFamilyName;
                        $hasUpdate = true;
                    }
                    if (!empty($detectedChildInfo) && $detectedChildInfo !== ($currentMeta['child_info'] ?? '')) {
                        $updatedMeta['child_info'] = $detectedChildInfo;
                        $hasUpdate = true;
                    }
                    if (!empty($formRegisteredName) && !in_array($formRegisteredName, ['Gia Chủ', 'Quý Gia Chủ']) && $formRegisteredName !== ($currentMeta['name'] ?? '')) {
                        $updatedMeta['name'] = $formRegisteredName;
                        $hasUpdate = true;
                    }
                    if ($detectedGender !== 'chua_ro' && $detectedGender !== ($currentMeta['gender'] ?? '')) {
                        $updatedMeta['gender'] = $detectedGender;
                        $hasUpdate = true;
                    }
                    if (!empty($detectedHonorific) && $detectedHonorific !== ($currentMeta['honorific'] ?? '')) {
                        $updatedMeta['honorific'] = $detectedHonorific;
                        $hasUpdate = true;
                    }

                    if ($hasUpdate) {
                        $curButler = $updatedMeta['butler_name'] ?? 'Bác Phúc';
                        $curFamily = $updatedMeta['family_name'] ?? 'Gia Đình';

                        $curHonorific = $updatedMeta['honorific'] ?? ('Gia chủ ' . ($updatedMeta['name'] ?? ''));
                        $curChildInfo = $updatedMeta['child_info'] ?? 'Các con';

                        if ($dedicatedTenantId) {
                            $newFrontmatter = "# BẢN SẮC & VAI TRÒ QUẢN GIA TỔ ẤM\n"
                                . "Bạn là Quản Gia {$curButler}, Quản Gia Trưởng Toàn Năng của {$curFamily}.\n"
                                . "Bạn tận tâm phục vụ {$curHonorific} và đồng hành, chăm sóc cho các bé ({$curChildInfo}) trong suốt 7 ngày trải nghiệm chính thức.\n\n"
                                . "# NGUYÊN TẮC PHỤC VỤ & ĐIỀU PHỐI:\n"
                                . "1. Luôn xưng là \"{$curButler}\" (hoặc \"Quản gia\") và gọi Gia chủ là \"{$curHonorific}\".\n"
                                . "2. Năng lực phục vụ theo thứ tự ưu tiên cốt lõi: Sắp xếp lịch trình & nhắc lịch thông minh 24/7, Gia sư 1-1 Socratic kèm con học tập, Cố vấn tài chính & ngân sách gia đình, Hỗ trợ chuyên môn & công việc hành chính, và tiện ích phụ trợ dinh dưỡng/thực đơn khi gia đình cần.\n"
                                . "3. Bạn là người chỉ huy, điều phối 23 trợ lý chuyên gia trong tổ ấm (Gia sư các môn học, Dinh dưỡng, Tài chính, Thể chất, Tâm lý, Lịch trình...).\n"
                                . "4. Tận tâm, chu đáo, lễ phép, bảo mật tuyệt đối cho mọi thông tin của gia đình.\n\n"
                                . "# NGUYÊN TẮC TIN NHẮN & PHẢN HỒI ONBOARDING (BẮT BUỘC 100%):\n"
                                . "- Khi hoàn tất Onboarding: Chỉ thông báo ngắn gọn nhất: \"Dạ em đã gửi email thông tin Tổ chức và Quản gia cho gia đình mình rồi ạ. Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ!\"\n"
                                . "- Khi được thêm vào nhóm Zalo gia đình: Quản Gia lập tức gửi lời chào trân trọng tới cả nhà, điểm danh và giới thiệu các thành viên luôn để cùng đồng hành.\"\n"
                                . "- TUYỆT ĐỐI CẤM: Không nói \"hệ thống đang tiến hành tự động khởi tạo\", không nói \"kèm link link đăng nhập 1 chạm vào hòm thư của gia chủ ngay bây giờ ạ\" hay bất kỳ câu từ nào nhắc tới \"link 1 chạm\" hoặc \"hòm thư\".\n"
                                . "- KHÔNG sử dụng ký tự Markdown hai dấu sao (**) để in đậm trong tin nhắn Zalo vì Zalo hiển thị hai dấu sao thô. Hãy viết câu từ tự nhiên, mạch lạc, dùng emoji trang nhã.\n\n"
                        . "# QUY TẮC MÚI GIỜ BẮT BUỘC:\n"
                        . "Múi giờ làm việc mặc định của Quản Gia là Giờ Việt Nam (Asia/Ho_Chi_Minh, GMT+7). Mọi mốc thời gian Gia chủ yêu cầu (giờ hẹn, nhắc việc, lịch học) luôn là giờ Việt Nam. Khi gọi tool datetime, luôn truyền {\"timezone\": \"Asia/Ho_Chi_Minh\"}. Khi lên lịch bằng tool cron kind: at, timestamp atMs phải được tính chuẩn xác theo giờ Việt Nam.\n\n"
                        . "# QUY TẮC KIỂM TRA SỰ THẬT (ANTI-HALLUCINATION):\n"
                        . "Khi Gia chủ thắc mắc về việc chưa nhận được nhắc nhở hoặc lịch trình, BẮT BUỘC phải gọi tool cron với {\"action\": \"list\"} để kiểm tra tình trạng thực tế trước khi trả lời. TUYỆT ĐỐI KHÔNG được tự ý bịa lý do 'lỗi kỹ thuật' khi chưa kiểm tra.\n\n"
                        . "# QUY TẮC NHẮC HẸN & BẰNG CHỨNG CÔNG VIỆC BẮT BUỘC (MANDATORY PROOF OF WORK):\n"
                        . "1. QUY TRÌNH THỰC THI 3 BƯỚC BẮT BUỘC KHI NHẬN LỆNH NHẮC HẸN:\n"
                        . "- Bước 1 (Lấy giờ hiện tại): Gọi tool datetime với {\"timezone\": \"Asia/Ho_Chi_Minh\"}.\n"
                        . "- Bước 2 (Xác định đối tượng & Kênh nhận):\n"
                        . "  * Nếu nhắc cho chính Gia chủ: Kênh gửi là Zalo cá nhân hiện tại của Gia chủ.\n"
                        . "  * Nếu nhắc cho thành viên khác (Vợ/Chồng, Con): Nếu thành viên đã có Zalo kết nối thì gửi đến Zalo đó. Nếu thành viên CHƯA có Zalo kết nối riêng, BẮT BUỘC phải nói rõ: 'Do [Tên thành viên] chưa kết nối Zalo riêng với Quản Gia, nên em đã lên lịch gửi tin nhắc nhở qua Zalo của Gia chủ đúng giờ hẹn để Gia chủ nhắc nhở [Tên thành viên] nhé ạ!'. TUYỆT ĐỐI CẤM hứa hão là đã gửi riêng cho thành viên khi không có kênh gửi riêng của họ.\n"
                        . "- Bước 3 (Tạo lịch hẹn bằng tool cron): BẮT BUỘC gọi tool cron action 'add', kind 'at', atMs tính chuẩn theo giờ Việt Nam, deliver: true, deleteAfterRun: true. Phải chờ kết quả có 'id' (Mã lịch hẹn).\n\n"
                        . "2. BẰNG CHỨNG CÔNG VIỆC BẮT BUỘC TRONG TIN NHẮN PHẢN HỒI:\n"
                        . "CHỈ KHI tool cron trả về thành công kèm Mã lịch hẹn (Job ID), Quản Gia mới được phản hồi xác nhận cho Gia chủ. Tin nhắn phản hồi BẮT BUỘC phải xuất trình khung BẰNG CHỨNG CÔNG VIỆC theo mẫu sau:\n"
                        . "🎩 [BẰNG CHỨNG HẸN GIỜ & XÁC NHẬN CÔNG VIỆC]\n"
                        . "• Mã lịch hẹn: <Job ID từ hệ thống>\n"
                        . "• Thời gian nhắc: <HH:mm ngày DD/MM/YYYY (Giờ Việt Nam)>\n"
                        . "• Đối tượng nhận nhắc: <Gia chủ [Họ tên] HOẶC Thành viên [Họ tên thành viên]>\n"
                        . "• Kênh tiếp nhận: <Zalo cá nhân Gia chủ / Zalo riêng của thành viên>\n"
                        . "• Nội dung nhắc việc: \"<Nội dung tin nhắn Quản Gia sẽ tự động gửi>\"\n"
                        . "• Trạng thái hệ thống: Đã kích hoạt hẹn giờ tự động thành công (Aicenty Butler Gateway Cron Job Active).\n\n"
                        . "3. ĐIỀU CẤM KỴ TUYỆT ĐỐI:\n"
                        . "TUYỆT ĐỐI CẤM trả lời xác nhận 'Dạ em đã ghi nhận lịch của Gia chủ...' hoặc hứa sẽ nhắc nếu CHƯA gọi tool cron thành công và chưa có Mã lịch hẹn (Job ID)!"
                        . "\n"
                        . "\n"
                        . "# NGUYÊN TẮC BẢO MẬT & ĐẶC QUYỀN GIA CHỦ (BÍ MẬT TUYỆT ĐỐI - ZERO EXCEPTION):\n"
                        . "1. ĐẶC QUYỀN ADMIN CAO NHẤT CỦA GIA CHỦ (HOÀN TOÀN KÍN ĐÁO):\n"
                        . "- Gia chủ là Admin cao nhất duy nhất của tổ ấm. Mọi báo cáo tiến độ học tập của các con, báo cáo tài chính, báo cáo tổng hợp tuần/tháng, cảnh báo con 2 ngày không học... ĐỀU LÀ ĐẶC QUYỀN RIÊNG TƯ, BÍ MẬT CHỈ GỬI RIÊNG 1-1 CHO GIA CHỦ.\n"
                        . "2. TUYỆT ĐỐI KHÔNG ĐỂ CÁC THÀNH VIÊN KHÁC (CON CÁI, VỢ/CHỒNG...) BIẾT ĐƯỢC:\n"
                        . "- TUYỆT ĐỐI CẤM nhắc hoặc để lộ cho con hay bất kỳ thành viên nào biết rằng Gia chủ (admin cao nhất) có thể giám sát hay nhận báo cáo về những gì các thành viên đã làm việc.\n"
                        . "- TUYỆT ĐỐI KHÔNG BAO GIỜ nói những câu như: 'Ba mẹ đang theo dõi con đấy', 'Quản gia báo cáo cho Ba mẹ', 'Ba mẹ xem được bài con làm', hoặc 'Ba mẹ là admin cao nhất thấy hết mọi thứ'.\n"
                        . "- Khi tương tác, kèm học với con: Luôn là Người Thầy, Người Bạn thân thiện, cởi mở, tâm lý, động viên con tự giác tư duy theo phương pháp Socratic, tạo môi trường học tập an tâm, thoải mái và tự nhiên nhất.\n"
                        . "3. BẢO MẬT KÊNH GỬI - ZERO EXCEPTION:\n"
                        . "- Báo cáo định kỳ, tin nhắn nhắc nhở riêng, cảnh báo học tập của con CHỈ GỬI DUY NHẤT vào kênh cá nhân 1-1 của Gia chủ (Zalo riêng của Gia chủ).\n"
                        . "- TUYỆT ĐỐI KHÔNG gửi vào nhóm gia đình (group chat) hay kênh riêng của con.\n"
                        . "\n"
                        . "# QUY TẮC XUẤT BÁO CÁO CHO GIA CHỦ:\n"
                        . "- Khi Gia chủ yêu cầu 'Xuất báo cáo tuần', 'Báo cáo tháng', 'Tình hình học tập của con':\n"
                        . "Quản Gia tổng hợp các hoạt động học tập của các con (các môn đã hỏi, dạng bài đã gợi mở, mức độ chuyên cần, điểm mạnh, điểm cần rèn thêm) và gửi riêng 1-1 cho Gia chủ với thái độ ân cần, chi tiết, khách quan, kèm lời nhắn: 'Dạ [Quản Gia] xin gửi riêng bảng báo cáo học tập và nề nếp của các con cho [Danh xưng] ạ.";

                            $newFrontmatter .= $this->getUxUpgradeRules();

                            $db->table('agents')
                                ->where('tenant_id', $dedicatedTenantId)
                                ->where('agent_key', 'aicenty-master-butler')
                                ->update([
                                    'display_name' => "{$curButler} ({$curFamily})",
                                    'frontmatter'  => $newFrontmatter,
                                    'updated_at'   => now(),
                                ]);

                            $db->table('tenants')->where('id', $dedicatedTenantId)->update([
                                'name'       => $curFamily,
                                'updated_at' => now(),
                            ]);
                        }

                        foreach ([$guruAgentId, $masterButlerId] as $agId) {
                            $db->table('user_agent_profiles')->where('user_id', $zaloUserId)->where('agent_id', $agId)->update([
                                'metadata'     => json_encode($updatedMeta, JSON_UNESCAPED_UNICODE),
                                'last_seen_at' => now(),
                            ]);
                        }

                        $userMdContent = $this->generateUserMdContent($updatedMeta);
                        $targetUids = [$zaloUserId];
                        if (!empty($updatedMeta['zalo_quangia_id'])) {
                            $targetUids[] = (string) $updatedMeta['zalo_quangia_id'];
                        }
                        $cleanP = preg_replace('/[^0-9]/', '', $updatedMeta['phone'] ?? '');
                        if (!empty($cleanP)) {
                            $targetUids[] = 'phone_' . $cleanP;
                            $targetUids[] = $cleanP;
                        }
                        $targetUids = array_unique($targetUids);

                        foreach ($targetUids as $tu) {
                            $db->table('user_context_files')->updateOrInsert(
                                ['agent_id' => $masterButlerId, 'user_id' => $tu, 'file_name' => 'USER.md'],
                                ['content' => $userMdContent, 'tenant_id' => $masterTenantId, 'updated_at' => now()]
                            );

                            // Đảm bảo dedicated_agent_id tồn tại thật sự trong bảng agents (chống lỗi Foreign Key)
                            $validDedicatedAgent = ($dedicatedAgentId && $dedicatedTenantId && $db->table('agents')->where('id', $dedicatedAgentId)->exists());
                            if (!$validDedicatedAgent && $dedicatedTenantId) {
                                $fallbackAgent = $db->table('agents')->where('tenant_id', $dedicatedTenantId)->where('agent_key', 'aicenty-master-butler')->first();
                                if ($fallbackAgent) {
                                    $dedicatedAgentId = $fallbackAgent->id;
                                    $validDedicatedAgent = true;
                                }
                            }

                            if ($validDedicatedAgent) {
                                $db->table('user_context_files')->updateOrInsert(
                                    ['agent_id' => $dedicatedAgentId, 'user_id' => $tu, 'file_name' => 'USER.md'],
                                    ['content' => $userMdContent, 'tenant_id' => $dedicatedTenantId, 'updated_at' => now()]
                                );
                                $db->table('user_agent_profiles')->updateOrInsert(
                                    ['agent_id' => $dedicatedAgentId, 'user_id' => $tu, 'tenant_id' => $dedicatedTenantId],
                                    ['metadata' => json_encode($updatedMeta, JSON_UNESCAPED_UNICODE), 'last_seen_at' => now()]
                                );
                            }
                        }

                        if (!empty($handoverRow?->id)) {
                            try {
                                $db->statement("SELECT public.sync_quangia_family_profile_all('{$handoverRow->id}'::uuid)");
                            } catch (\Throwable $se) {}
                        }

                        try {
                            $cleanP = preg_replace('/[^0-9]/', '', $updatedMeta['phone'] ?? '');
                            $magicUser = \App\Models\User::where('phone', $cleanP)
                                ->orWhere('email', $updatedMeta['email'] ?? '')
                                ->first();
                            if ($magicUser) {
                                \App\Models\Team\Team::updateOrCreate(
                                    ['user_id' => $magicUser->id],
                                    [
                                        'name'               => $curFamily,
                                        'allow_seats'        => 5,
                                        'credit_system_type' => 'shared',
                                        'shared_credits'     => 1000.0,
                                    ]
                                );
                            }
                        } catch (\Throwable $e) {
                            Log::warning('[Butler Onboard] MagicAI Team update error: ' . $e->getMessage());
                        }

                        Log::info("[Butler Onboard Update] Updated choices for {$zaloUserId}: {$curFamily} - {$curButler}");
                    }
                    continue;
                }

                // NẾU CHƯA PROVISION VÀ CHƯA CÓ SĐT TRONG TIN NHẮN, TÌM TỪ PROFILE VÀ BẢNG HANDOVER
                if (!$detectedPhone) {
                    $detectedPhone = $currentMeta['phone'] ?? null;
                }
                if (!$detectedPhone) {
                    $handoverRow = $db->table('quangia_pending_handover')
                        ->where('claimed_zalo_personal_id', $zaloUserId)
                        ->orderByDesc('created_at')
                        ->first();
                    if ($handoverRow && !empty($handoverRow->phone)) {
                        $detectedPhone = $handoverRow->phone;
                        if (empty($detectedEmail) && !empty($handoverRow->email)) {
                            $detectedEmail = $handoverRow->email;
                        }
                    }
                }

                if (!$detectedPhone) {
                    continue;
                }

                $cleanPhone = preg_replace('/[^0-9]/', '', $detectedPhone);
                if (strlen($cleanPhone) < 9) {
                    continue;
                }

                // Kiểm tra xem đã có hồ sơ đăng ký từ Web chưa
                $webProfile = $db->table('user_agent_profiles')
                    ->where('user_id', 'phone_' . $cleanPhone)
                    ->where('agent_id', $masterButlerId)
                    ->first();

                // Lấy bản ghi bàn giao nếu có
                $handoverRow = $db->table('quangia_pending_handover')
                    ->where('phone', $cleanPhone)
                    ->orWhere('claimed_zalo_personal_id', $zaloUserId)
                    ->orderByDesc('created_at')
                    ->first();

                // Kiểm tra xem Quản Gia đã gửi tin nhắn kết thúc Onboarding chưa
                $hasCompletedOnboardingMsg = false;
                foreach (array_reverse($messages) as $mCheck) {
                    if (($mCheck['role'] ?? '') === 'assistant') {
                        $aContent = (string) ($mCheck['content'] ?? '');
                        if (str_contains($aContent, 'đã ghi nhận đầy đủ') || str_contains($aContent, 'email thông tin Tổ chức') || str_contains($aContent, 'đã gửi email thông tin')) {
                            $hasCompletedOnboardingMsg = true;
                            break;
                        }
                    }
                }

                // ĐIỀU KIỆN KÍCH HOẠT DUY NHẤT: BẮT BUỘC ĐÃ ONBOARDING XONG VÀ ĐÃ CÓ TÊN TỔ CHỨC GIA ĐÌNH!
                // Đặc biệt phải đợi onboarding hỏi xong tên tổ chức gia đình, sau đó mới kích hoạt tạo tự động và báo qua email
                $hasCompletedOnboarding = (!empty($detectedButlerName) && !empty($detectedFamilyName) && ($hasCompletedOnboardingMsg || count($messages) >= 8))
                    || ($hasCompletedOnboardingMsg && !empty($detectedButlerName));

                if (!$hasCompletedOnboarding) {
                    Log::info("[Butler Onboard Sync] Bỏ qua {$zaloUserId} ({$cleanPhone}): Đang trong quá trình Onboarding, chưa chốt Tên Tổ Chức Gia Đình.");
                    continue;
                }

                if ($hasCompletedOnboarding) {
                    $webMeta = is_string($webProfile?->metadata) ? json_decode($webProfile->metadata, true) : (array) ($webProfile?->metadata ?? []);

                    $formName = $handoverRow?->name ?? ($webMeta['name'] ?? null);
                    if (!empty($formName) && !in_array($formName, ['Gia Chủ', 'Quý Gia Chủ'])) {
                        $name = $formName;
                    } else {
                        $name = $cleanCustomerName ?: ($currentMeta['name'] ?? 'Quý Gia Chủ');
                    }
                    $validHandoverFamily = ($handoverRow?->family_name && !in_array($handoverRow->family_name, ['Chưa chọn', 'bạn là ai', 'chưa đặt tên'])) ? $handoverRow->family_name : null;
                    $validHandoverButler = ($handoverRow?->butler_name && !in_array($handoverRow->butler_name, ['Chưa chọn', 'Quản Gia', 'Quản Gia Aicenty'])) ? $handoverRow->butler_name : null;
                    $familyName = $detectedFamilyName ?: ($validHandoverFamily ?: ($currentMeta['family_name'] ?? ($webMeta['family_name'] ?? ('Gia Đình ' . $name))));
                    $butlerName = $detectedButlerName ?: ($validHandoverButler ?: ($currentMeta['butler_name'] ?? ($webMeta['butler_name'] ?? 'Bác Phúc')));
                    $childInfo = $detectedChildInfo ?: ($currentMeta['child_info'] ?? ($webMeta['child_info'] ?? ($handoverRow?->child_info ?? 'Các con')));
                    $primaryConcern = $webMeta['primary_concern'] ?? ($handoverRow?->primary_concern ?? 'Kèm học Socratic và quản xuyến toàn diện');

                    // Lấy email thật của khách hàng, ưu tiên email từ form đăng ký
                    $resolvedEmail = $handoverRow?->email ?? ($webMeta['email'] ?? ($currentMeta['email'] ?? null));
                    if (empty($resolvedEmail) || str_ends_with($resolvedEmail, '@aicenty.com')) {
                        if (!empty($handoverRow?->email)) {
                            $resolvedEmail = $handoverRow->email;
                        } elseif (!empty($webMeta['email'])) {
                            $resolvedEmail = $webMeta['email'];
                        }
                    }
                    if (empty($resolvedEmail)) {
                        $resolvedEmail = $cleanPhone . '@aicenty.com';
                    }

                    $onboardData = [
                        'name'            => $name,
                        'gender'          => $detectedGender,
                        'honorific'       => $detectedHonorific,
                        'phone'           => $cleanPhone,
                        'email'           => $resolvedEmail,
                        'family_name'     => $familyName,
                        'butler_name'     => $butlerName,
                        'child_info'      => $childInfo,
                        'family_notes'    => $detectedNotes ?: ($handoverRow?->note ?? ($currentMeta['family_notes'] ?? '')),
                        'primary_concern' => $primaryConcern,
                        'zalo_user_id'    => $zaloUserId,
                    ];

                    // KÍCH HOẠT TỰ ĐỘNG KHỞI TẠO TỔ CHỨC THEO FLOW ĐÃ CHỐT:
                    $provisionResult = $automationService->provisionTrialTenantAndUser($onboardData, $mailer);

                    $newTenId = $provisionResult['tenant_id'] ?? null;
                    $newAgId = $provisionResult['agent_id'] ?? null;

                    // Đánh dấu cờ trial_provisioned để không tạo lại
                    $mergedMeta = array_merge($currentMeta, $onboardData, [
                        'trial_provisioned'   => true,
                        'dedicated_tenant_id' => $newTenId,
                        'dedicated_agent_id'  => $newAgId,
                        'provisioned_at'      => now()->toIso8601String(),
                    ]);

                    // Cập nhật profile cho CẢ Butler Guru VÀ Quản Gia Master
                    foreach ([$guruAgentId, $masterButlerId] as $agId) {
                        $db->table('user_agent_profiles')->updateOrInsert(
                            [
                                'agent_id'  => $agId,
                                'user_id'   => $zaloUserId,
                                'tenant_id' => $masterTenantId,
                            ],
                            [
                                'metadata'     => json_encode($mergedMeta, JSON_UNESCAPED_UNICODE),
                                'last_seen_at' => now(),
                            ]
                        );
                    }

                    // TỰ ĐỘNG KHỞI TẠO USER.MD CHUẨN XÁC CHO GIA CHỦ
                    $userMdTemplate = $this->generateUserMdContent($mergedMeta);
                    $knownQuangiaUids = [$zaloUserId];
                    $identityAliases = json_decode(
                        (string) env('BUTLER_QUANGIA_IDENTITY_ALIASES_JSON', '{}'),
                        true
                    ) ?: [];
                    $aliasKeys = array_filter([
                        $cleanPhone !== '' ? 'phone:' . $cleanPhone : null,
                        $name !== '' ? 'name:' . mb_strtolower($name) : null,
                    ]);
                    foreach ($aliasKeys as $aliasKey) {
                        foreach ((array) ($identityAliases[$aliasKey] ?? []) as $aliasUid) {
                            $aliasUid = trim((string) $aliasUid);
                            if ($aliasUid !== '' && ctype_digit($aliasUid)) {
                                $knownQuangiaUids[] = $aliasUid;
                            }
                        }
                    }
                    if (!empty($cleanPhone)) {
                        $knownQuangiaUids[] = 'phone_' . $cleanPhone;
                        $knownQuangiaUids[] = $cleanPhone;
                    }
                    $knownQuangiaUids = array_unique($knownQuangiaUids);

                    foreach ($knownQuangiaUids as $kuid) {
                        // Nạp USER.md cho Master Butler
                        $db->table('user_context_files')->updateOrInsert(
                            ['agent_id' => $masterButlerId, 'user_id' => $kuid, 'file_name' => 'USER.md'],
                            ['content' => $userMdTemplate, 'tenant_id' => $masterTenantId, 'updated_at' => now()]
                        );
                        // Nạp profile cho Master Butler
                        $db->table('user_agent_profiles')->updateOrInsert(
                            ['agent_id' => $masterButlerId, 'user_id' => $kuid, 'tenant_id' => $masterTenantId],
                            ['metadata' => json_encode($mergedMeta, JSON_UNESCAPED_UNICODE), 'last_seen_at' => now()]
                        );
                        // Nạp USER.md cho Dedicated Butler nếu đã có tenant
                        if ($newTenId && $newAgId) {
                            $db->table('user_context_files')->updateOrInsert(
                                ['agent_id' => $newAgId, 'user_id' => $kuid, 'file_name' => 'USER.md'],
                                ['content' => $userMdTemplate, 'tenant_id' => $newTenId, 'updated_at' => now()]
                            );
                            $db->table('tenant_users')->updateOrInsert(
                                ['tenant_id' => $newTenId, 'user_id' => $kuid],
                                ['display_name' => $name, 'role' => 'owner', 'updated_at' => now()]
                            );
                        }
                    }

                    // Đồng bộ lên Odoo CRM
                    try {
                        $odooService->updateButlerLeadOnboarding($onboardData);
                    } catch (\Throwable $e) {
                        Log::warning('[Butler Onboard] Odoo update skipped: ' . $e->getMessage());
                    }

                    Log::info("[Butler Onboard Auto] Successfully provisioned Tenant for {$zaloUserId} ({$cleanPhone}): {$familyName} - {$butlerName} - {$detectedHonorific}");
                    $autoProvisionedCount++;
                }
            }

            // =========================================================================
            // PHẦN 2: TỰ ĐỘNG LIÊN KẾT NHẬN DIỆN KHÁCH SANG ZALO QUẢN GIA AI (QUANGIA CHANNEL)
            // AN TOÀN TUYỆT ĐỐI: CHỈ LIÊN KẾT THEO SĐT / CLAIMED_ID CHÍNH XÁC, KHÔNG FUZZY MATCH TRÁNH LẪN LỘN
            // =========================================================================
            $quangiaSessions = $db->table('sessions')
                ->where('channel', 'quangia')
                ->where('updated_at', '>=', now()->subHours(48))
                ->get();

            foreach ($quangiaSessions as $qSess) {
                $qUserId = (string) $qSess->user_id;
                if (empty($qUserId) || $qUserId === 'system' || str_starts_with($qUserId, 'phone_')) {
                    continue;
                }

                $qProfile = $db->table('user_agent_profiles')
                    ->where('user_id', $qUserId)
                    ->where('agent_id', $masterButlerId)
                    ->first();
                $qMeta = is_string($qProfile?->metadata) ? json_decode($qProfile->metadata, true) : (array) ($qProfile?->metadata ?? []);

                if (empty($qMeta['dedicated_tenant_id'])) {
                    // 1. Tìm chính xác qua claimed_zalo_personal_id
                    $matchedHandover = $db->table('quangia_pending_handover')
                        ->where('claimed_zalo_personal_id', $qUserId)
                        ->orderByDesc('created_at')
                        ->first();

                    // 2. Nếu chưa có, quét tìm SĐT trong nội dung chat của chính session này
                    if (!$matchedHandover) {
                        $qMsgs = is_string($qSess->messages) ? json_decode($qSess->messages, true) : (array) $qSess->messages;
                        if (is_array($qMsgs)) {
                            foreach ($qMsgs as $qm) {
                                if (($qm['role'] ?? '') === 'user') {
                                    $cText = (string) ($qm['content'] ?? '');
                                    if (preg_match('/(?:sđt|sdt|số|phone|dđ|dt|zalo)?\s*[:=\-\s]?\s*((?:\+?84[\s.-]?|0)[35789](?:[\s.-]?[0-9]){8})/iu', $cText, $pM)) {
                                        $cRaw = preg_replace('/[^0-9]/', '', $pM[1]);
                                        if (str_starts_with($cRaw, '84') && strlen($cRaw) === 11) {
                                            $cRaw = '0' . substr($cRaw, 2);
                                        }
                                        $matchedHandover = $db->table('quangia_pending_handover')
                                            ->where('phone', $cRaw)
                                            ->orderByDesc('created_at')
                                            ->first();
                                        if ($matchedHandover) break;
                                    }
                                }
                            }
                        }
                    }

                    if ($matchedHandover && !empty($matchedHandover->dedicated_tenant_id)) {
                        $hPhone = preg_replace('/[^0-9]/', '', $matchedHandover->phone ?? '');
                        $webProfile = null;
                        if (!empty($hPhone)) {
                            $webProfile = $db->table('user_agent_profiles')
                                ->where('user_id', 'phone_' . $hPhone)
                                ->where('agent_id', $masterButlerId)
                                ->first();
                        }
                        $matchedOnboarding = is_string($webProfile?->metadata) ? json_decode($webProfile->metadata, true) : (array) ($webProfile?->metadata ?? []);
                        $matchedOnboarding['dedicated_tenant_id'] = $matchedHandover->dedicated_tenant_id;
                        $matchedOnboarding['dedicated_agent_id'] = $matchedHandover->dedicated_agent_id;
                        $matchedOnboarding['name'] = $matchedHandover->name;
                        $matchedOnboarding['phone'] = $matchedHandover->phone;
                        $matchedOnboarding['email'] = $matchedHandover->email;
                        $matchedOnboarding['family_name'] = $matchedHandover->family_name;
                        $matchedOnboarding['butler_name'] = $matchedHandover->butler_name;
                        $matchedOnboarding['honorific'] = $matchedHandover->honorific;
                        $matchedOnboarding['child_info'] = $matchedHandover->child_info;
                        $matchedOnboarding['zalo_quangia_id'] = $qUserId;

                        $db->table('user_agent_profiles')->updateOrInsert(
                            [
                                'agent_id'  => $masterButlerId,
                                'user_id'   => $qUserId,
                                'tenant_id' => $masterTenantId,
                            ],
                            [
                                'metadata'     => json_encode($matchedOnboarding, JSON_UNESCAPED_UNICODE),
                                'last_seen_at' => now(),
                            ]
                        );

                        $db->table('quangia_pending_handover')
                            ->where('id', $matchedHandover->id)
                            ->update(['claimed_zalo_personal_id' => $qUserId]);

                        // Tự động đồng bộ Zalo UID thực tế vào các cron jobs định kỳ của gia đình
                        $syncPhone = preg_replace('/[^0-9]/', '', (string)$matchedHandover->phone);
                        if (str_starts_with($syncPhone, '84') && strlen($syncPhone) === 11) {
                            $syncPhone = '0' . substr($syncPhone, 2);
                        }
                        if (!empty($syncPhone)) {
                            $db->table('cron_jobs')
                                ->where('name', 'like', "%{$syncPhone}%")
                                ->update([
                                    'deliver_to' => (string) $qUserId,
                                    'enabled'    => true,
                                ]);
                            Log::info("[Butler Sync Onboarding] Updated cron_jobs deliver_to for {$syncPhone} to Zalo UID {$qUserId}");
                        }

                        $userMdContent = $this->generateUserMdContent($matchedOnboarding);
                        $db->table('user_context_files')->updateOrInsert(
                            [
                                'agent_id'  => $masterButlerId,
                                'user_id'   => $qUserId,
                                'file_name' => 'USER.md',
                            ],
                            [
                                'content'    => $userMdContent,
                                'tenant_id'  => $masterTenantId,
                                'updated_at' => now(),
                            ]
                        );

                        $tId = $matchedHandover->dedicated_tenant_id;
                        $db->table('tenant_users')->updateOrInsert(
                            [
                                'tenant_id' => $tId,
                                'user_id'   => $qUserId,
                            ],
                            [
                                'display_name' => ($matchedHandover->name ?? 'Gia Chủ') . ' (Zalo Quản Gia)',
                                'role'         => 'owner',
                                'updated_at'   => now(),
                            ]
                        );

                        Log::info("[Butler Cross-Link Secure] Linked Quản Gia AI user {$qUserId} to {$matchedHandover->family_name} - {$matchedHandover->butler_name} (Phone: {$hPhone})");
                    }
                }
            }

            // =========================================================================
            // PHẦN 3: ĐỒNG BỘ THIẾT LẬP TỔ ẤM TỪ USER.MD (KHI KHÁCH CHỌN TÊN TRÊN QUẢN GIA AI)
            // =========================================================================
            $userContextFiles = $db->table('user_context_files')
                ->where('agent_id', $masterButlerId)
                ->where('file_name', 'USER.md')
                ->where('updated_at', '>=', now()->subHours(48))
                ->get();

            foreach ($userContextFiles as $ucf) {
                $uid = (string) $ucf->user_id;
                $content = (string) $ucf->content;

                if (str_contains($content, 'is_family_member=true') || str_contains($content, 'Hồ Sơ Thành Viên Gia Đình')) {
                    Log::info("[Butler Sync USER.md] Bỏ qua owner sync cho hồ sơ thành viên {$uid}.");
                    continue;
                }

                if (preg_match('/- \*\*(?:Tên Quản Gia riêng|Tên quản gia|Quản gia riêng):\*\*\s*(.+)/iu', $content, $bMatch)) {
                    $chosenButler = trim($bMatch[1]);
                    if (!empty($chosenButler) && !str_contains(mb_strtolower($chosenButler), 'chưa chọn')) {
                        $chosenHonorific = null;
                        if (preg_match('/- \*\*(?:Danh xưng gia chủ|Danh xưng xưng hô chuẩn|Danh xưng chuẩn|Xưng hô gia chủ):\*\*\s*(.+)/iu', $content, $hMatch)) {
                            $rawH = trim($hMatch[1]);
                            if (!empty($rawH) && !str_contains(mb_strtolower($rawH), 'chưa chọn') && !str_contains($rawH, 'Nguyễn Hùng')) {
                                $chosenHonorific = $rawH;
                            }
                        }
                        $chosenChildInfo = null;
                        if (preg_match('/- \*\*(?:Các con & Môn học ưu tiên|Các thành viên nhí cần đồng hành & học tập|Thông tin con cái):\*\*\s*(.+)/iu', $content, $cMatch)) {
                            $rawC = trim($cMatch[1]);
                            if (!empty($rawC) && !str_contains(mb_strtolower($rawC), 'chưa cung cấp')) {
                                $chosenChildInfo = $rawC;
                            }
                        }

                        $prof = $db->table('user_agent_profiles')
                            ->where('user_id', $uid)
                            ->where('agent_id', $masterButlerId)
                            ->first();
                        $meta = is_string($prof?->metadata) ? json_decode($prof->metadata, true) : (array) ($prof?->metadata ?? []);
                        if (!empty($meta['is_family_member'])) {
                            Log::info("[Butler Sync USER.md] Bỏ qua owner sync cho metadata thành viên {$uid}.");
                            continue;
                        }

                        $curFamily = $meta['family_name'] ?? 'Gia Đình';
                        $dedicatedTenantId = $meta['dedicated_tenant_id'] ?? null;
                        $dedicatedAgentId = $meta['dedicated_agent_id'] ?? null;

                        // 1. Lấy tên tổ ấm từ USER.md nếu có
                        $chosenFamily = null;
                        if (preg_match('/- \*\*(?:Tên Tổ Ấm|Tên gia đình|Tên tổ chức):\*\*\s*(.+)/iu', $content, $fMatch)) {
                            $rawF = trim($fMatch[1]);
                            if (!empty($rawF) && !str_contains(mb_strtolower($rawF), 'chưa chọn') && !str_contains(mb_strtolower($rawF), 'chưa cung cấp')) {
                                $chosenFamily = $rawF;
                            }
                        }

                        // 2. Lấy thông tin họ tên từ USER.md nếu có
                        $customerName = $meta['name'] ?? null;
                        if (preg_match('/- \*\*Họ tên gia chủ:\*\*\s*(.+)/iu', $content, $nMatch)) {
                            $rawN = trim($nMatch[1]);
                            if (!empty($rawN)) $customerName = $rawN;
                        }

                        // 3. Nếu chưa có Dedicated Tenant -> TỰ ĐỘNG KHỞI TẠO TỔ CHỨC VÀ GỬI EMAIL NGAY BÂY GIỜ!
                        if (empty($dedicatedTenantId)) {
                            $handoverRow = $db->table('quangia_pending_handover')
                                ->where('claimed_zalo_personal_id', $uid)
                                ->orWhere('phone', preg_replace('/[^0-9]/', '', $uid))
                                ->orderByDesc('created_at')
                                ->first();

                            $cleanPhone = $meta['phone'] ?? ($handoverRow?->phone ?? '');
                            if (empty($cleanPhone)) {
                                if (str_starts_with($uid, 'phone_')) {
                                    $cleanPhone = substr($uid, 6);
                                } elseif (preg_match('/^0[35789][0-9]{8}$/', $uid)) {
                                    $cleanPhone = $uid;
                                }
                            }
                            $formName = $handoverRow?->name ?? null;
                            if (!empty($formName) && !in_array($formName, ['Gia Chủ', 'Quý Gia Chủ'])) {
                                $customerName = $formName;
                            } elseif (empty($customerName)) {
                                $customerName = 'Gia Chủ';
                            }
                            if (empty($chosenFamily)) {
                                $chosenFamily = $meta['family_name'] ?? ($handoverRow?->family_name ?? ('Gia Đình ' . $customerName));
                            }
                            $curFamily = $chosenFamily;

                            $resolvedEmail = $handoverRow?->email ?? ($meta['email'] ?? null);
                            if (empty($resolvedEmail) || str_ends_with($resolvedEmail, '@aicenty.com')) {
                                if (!empty($handoverRow?->email)) {
                                    $resolvedEmail = $handoverRow->email;
                                }
                            }
                            if (empty($resolvedEmail)) {
                                $resolvedEmail = $cleanPhone . '@aicenty.com';
                            }

                            if (!empty($cleanPhone)) {
                                $onboardData = [
                                    'name'            => $customerName,
                                    'gender'          => $meta['gender'] ?? 'chua_ro',
                                    'honorific'       => $chosenHonorific ?? ('Gia chủ ' . $customerName),
                                    'phone'           => $cleanPhone,
                                    'email'           => $resolvedEmail,
                                    'family_name'     => $chosenFamily,
                                    'butler_name'     => $chosenButler,
                                    'child_info'      => $chosenChildInfo ?? ($meta['child_info'] ?? ''),
                                    'primary_concern' => $meta['primary_concern'] ?? 'Quản xuyến toàn diện gia đình',
                                    'zalo_user_id'    => $uid,
                                ];

                                Log::info("[Butler Auto Provision from quangia USER.md] Khởi tạo tổ chức cho {$uid} ({$cleanPhone}): {$chosenFamily} - {$chosenButler}");
                                $provisionResult = $automationService->provisionTrialTenantAndUser($onboardData, $mailer);

                                $dedicatedTenantId = $provisionResult['tenant_id'] ?? null;
                                $dedicatedAgentId = $provisionResult['agent_id'] ?? null;
                                $autoProvisionedCount++;

                                if ($handoverRow && $dedicatedTenantId) {
                                    $db->table('quangia_pending_handover')
                                        ->where('id', $handoverRow->id)
                                        ->update([
                                            'dedicated_tenant_id' => $dedicatedTenantId,
                                            'dedicated_agent_id'  => $dedicatedAgentId,
                                        ]);
                                }
                            }
                        }

                        $curHonorific = $chosenHonorific ?? ($meta['honorific'] ?? ('Gia chủ ' . $customerName));
                        $curChildInfo = $chosenChildInfo ?? ($meta['child_info'] ?? 'Các con');

                        if ($dedicatedTenantId) {
                            if (!empty($chosenFamily)) {
                                $curFamily = $chosenFamily;
                                $db->table('tenants')->where('id', $dedicatedTenantId)->update([
                                    'name'       => $curFamily,
                                    'updated_at' => now(),
                                ]);
                            }

                            $newFrontmatter = "# BẢN SẮC & VAI TRÒ QUẢN GIA TỔ ẤM\n"
                                . "Bạn là Quản Gia {$chosenButler}, Quản Gia Trưởng Toàn Năng của {$curFamily}.\n"
                                . "Bạn tận tâm phục vụ {$curHonorific} và đồng hành, chăm sóc cho các bé ({$curChildInfo}) trong suốt 7 ngày trải nghiệm chính thức.\n\n"
                                . "# NGUYÊN TẮC PHỤC VỤ & ĐIỀU PHỐI:\n"
                                . "1. Luôn xưng là \"{$chosenButler}\" (hoặc \"Quản gia\") và gọi Gia chủ là \"{$curHonorific}\".\n"
                                . "2. Năng lực phục vụ theo thứ tự ưu tiên cốt lõi: Sắp xếp lịch trình & nhắc lịch thông minh 24/7, Gia sư 1-1 Socratic kèm con học tập, Cố vấn tài chính & ngân sách gia đình, Hỗ trợ chuyên môn & công việc hành chính, và tiện ích phụ trợ dinh dưỡng/thực đơn khi gia đình cần.\n"
                                . "3. Bạn là người chỉ huy, điều phối 23 trợ lý chuyên gia trong tổ ấm (Gia sư các môn học, Dinh dưỡng, Tài chính, Thể chất, Tâm lý, Lịch trình...).\n"
                                . "4. Tận tâm, chu đáo, lễ phép, bảo mật tuyệt đối cho mọi thông tin của gia đình.\n\n"
                                . "# NGUYÊN TẮC TIN NHẮN & PHẢN HỒI ONBOARDING (BẮT BUỘC 100%):\n"
                                . "- Khi hoàn tất Onboarding: Chỉ thông báo ngắn gọn nhất: \"Dạ em đã gửi email thông tin Tổ chức và Quản gia cho gia đình mình rồi ạ. Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ!\"\n"
                                . "- Khi được thêm vào nhóm Zalo gia đình: Quản Gia lập tức gửi lời chào trân trọng tới cả nhà, điểm danh và giới thiệu các thành viên luôn để cùng đồng hành.\"\n"
                                . "- TUYỆT ĐỐI CẤM: Không nói \"hệ thống đang tiến hành tự động khởi tạo\", không nói \"kèm link link đăng nhập 1 chạm vào hòm thư của gia chủ ngay bây giờ ạ\" hay bất kỳ câu từ nào nhắc tới \"link 1 chạm\" hoặc \"hòm thư\".\n"
                                . "- KHÔNG sử dụng ký tự Markdown hai dấu sao (**) để in đậm trong tin nhắn Zalo vì Zalo hiển thị hai dấu sao thô. Hãy viết câu từ tự nhiên, mạch lạc, dùng emoji trang nhã.\n\n"
                        . "# QUY TẮC MÚI GIỜ BẮT BUỘC:\n"
                        . "Múi giờ làm việc mặc định của Quản Gia là Giờ Việt Nam (Asia/Ho_Chi_Minh, GMT+7). Mọi mốc thời gian Gia chủ yêu cầu (giờ hẹn, nhắc việc, lịch học) luôn là giờ Việt Nam. Khi gọi tool datetime, luôn truyền {\"timezone\": \"Asia/Ho_Chi_Minh\"}. Khi lên lịch bằng tool cron kind: at, timestamp atMs phải được tính chuẩn xác theo giờ Việt Nam.\n\n"
                        . "# QUY TẮC KIỂM TRA SỰ THẬT (ANTI-HALLUCINATION):\n"
                        . "Khi Gia chủ thắc mắc về việc chưa nhận được nhắc nhở hoặc lịch trình, BẮT BUỘC phải gọi tool cron với {\"action\": \"list\"} để kiểm tra tình trạng thực tế trước khi trả lời. TUYỆT ĐỐI KHÔNG được tự ý bịa lý do 'lỗi kỹ thuật' khi chưa kiểm tra.\n\n"
                        . "# QUY TẮC NHẮC HẸN & BẰNG CHỨNG CÔNG VIỆC BẮT BUỘC (MANDATORY PROOF OF WORK):\n"
                        . "1. QUY TRÌNH THỰC THI 3 BƯỚC BẮT BUỘC KHI NHẬN LỆNH NHẮC HẸN:\n"
                        . "- Bước 1 (Lấy giờ hiện tại): Gọi tool datetime với {\"timezone\": \"Asia/Ho_Chi_Minh\"}.\n"
                        . "- Bước 2 (Xác định đối tượng & Kênh nhận):\n"
                        . "  * Nếu nhắc cho chính Gia chủ: Kênh gửi là Zalo cá nhân hiện tại của Gia chủ.\n"
                        . "  * Nếu nhắc cho thành viên khác (Vợ/Chồng, Con): Nếu thành viên đã có Zalo kết nối thì gửi đến Zalo đó. Nếu thành viên CHƯA có Zalo kết nối riêng, BẮT BUỘC phải nói rõ: 'Do [Tên thành viên] chưa kết nối Zalo riêng với Quản Gia, nên em đã lên lịch gửi tin nhắc nhở qua Zalo của Gia chủ đúng giờ hẹn để Gia chủ nhắc nhở [Tên thành viên] nhé ạ!'. TUYỆT ĐỐI CẤM hứa hão là đã gửi riêng cho thành viên khi không có kênh gửi riêng của họ.\n"
                        . "- Bước 3 (Tạo lịch hẹn bằng tool cron): BẮT BUỘC gọi tool cron action 'add', kind 'at', atMs tính chuẩn theo giờ Việt Nam, deliver: true, deleteAfterRun: true. Phải chờ kết quả có 'id' (Mã lịch hẹn).\n\n"
                        . "2. BẰNG CHỨNG CÔNG VIỆC BẮT BUỘC TRONG TIN NHẮN PHẢN HỒI:\n"
                        . "CHỈ KHI tool cron trả về thành công kèm Mã lịch hẹn (Job ID), Quản Gia mới được phản hồi xác nhận cho Gia chủ. Tin nhắn phản hồi BẮT BUỘC phải xuất trình khung BẰNG CHỨNG CÔNG VIỆC theo mẫu sau:\n"
                        . "🎩 [BẰNG CHỨNG HẸN GIỜ & XÁC NHẬN CÔNG VIỆC]\n"
                        . "• Mã lịch hẹn: <Job ID từ hệ thống>\n"
                        . "• Thời gian nhắc: <HH:mm ngày DD/MM/YYYY (Giờ Việt Nam)>\n"
                        . "• Đối tượng nhận nhắc: <Gia chủ [Họ tên] HOẶC Thành viên [Họ tên thành viên]>\n"
                        . "• Kênh tiếp nhận: <Zalo cá nhân Gia chủ / Zalo riêng của thành viên>\n"
                        . "• Nội dung nhắc việc: \"<Nội dung tin nhắn Quản Gia sẽ tự động gửi>\"\n"
                        . "• Trạng thái hệ thống: Đã kích hoạt hẹn giờ tự động thành công (Aicenty Butler Gateway Cron Job Active).\n\n"
                        . "3. ĐIỀU CẤM KỴ TUYỆT ĐỐI:\n"
                        . "TUYỆT ĐỐI CẤM trả lời xác nhận 'Dạ em đã ghi nhận lịch của Gia chủ...' hoặc hứa sẽ nhắc nếu CHƯA gọi tool cron thành công và chưa có Mã lịch hẹn (Job ID)!"
                        . "\n"
                        . "\n"
                        . "# NGUYÊN TẮC BẢO MẬT & ĐẶC QUYỀN GIA CHỦ (BÍ MẬT TUYỆT ĐỐI - ZERO EXCEPTION):\n"
                        . "1. ĐẶC QUYỀN ADMIN CAO NHẤT CỦA GIA CHỦ (HOÀN TOÀN KÍN ĐÁO):\n"
                        . "- Gia chủ là Admin cao nhất duy nhất của tổ ấm. Mọi báo cáo tiến độ học tập của các con, báo cáo tài chính, báo cáo tổng hợp tuần/tháng, cảnh báo con 2 ngày không học... ĐỀU LÀ ĐẶC QUYỀN RIÊNG TƯ, BÍ MẬT CHỈ GỬI RIÊNG 1-1 CHO GIA CHỦ.\n"
                        . "2. TUYỆT ĐỐI KHÔNG ĐỂ CÁC THÀNH VIÊN KHÁC (CON CÁI, VỢ/CHỒNG...) BIẾT ĐƯỢC:\n"
                        . "- TUYỆT ĐỐI CẤM nhắc hoặc để lộ cho con hay bất kỳ thành viên nào biết rằng Gia chủ (admin cao nhất) có thể giám sát hay nhận báo cáo về những gì các thành viên đã làm việc.\n"
                        . "- TUYỆT ĐỐI KHÔNG BAO GIỜ nói những câu như: 'Ba mẹ đang theo dõi con đấy', 'Quản gia báo cáo cho Ba mẹ', 'Ba mẹ xem được bài con làm', hoặc 'Ba mẹ là admin cao nhất thấy hết mọi thứ'.\n"
                        . "- Khi tương tác, kèm học với con: Luôn là Người Thầy, Người Bạn thân thiện, cởi mở, tâm lý, động viên con tự giác tư duy theo phương pháp Socratic, tạo môi trường học tập an tâm, thoải mái và tự nhiên nhất.\n"
                        . "3. BẢO MẬT KÊNH GỬI - ZERO EXCEPTION:\n"
                        . "- Báo cáo định kỳ, tin nhắn nhắc nhở riêng, cảnh báo học tập của con CHỈ GỬI DUY NHẤT vào kênh cá nhân 1-1 của Gia chủ (Zalo riêng của Gia chủ).\n"
                        . "- TUYỆT ĐỐI KHÔNG gửi vào nhóm gia đình (group chat) hay kênh riêng của con.\n"
                        . "\n"
                        . "# QUY TẮC XUẤT BÁO CÁO CHO GIA CHỦ:\n"
                        . "- Khi Gia chủ yêu cầu 'Xuất báo cáo tuần', 'Báo cáo tháng', 'Tình hình học tập của con':\n"
                        . "Quản Gia tổng hợp các hoạt động học tập của các con (các môn đã hỏi, dạng bài đã gợi mở, mức độ chuyên cần, điểm mạnh, điểm cần rèn thêm) và gửi riêng 1-1 cho Gia chủ với thái độ ân cần, chi tiết, khách quan, kèm lời nhắn: 'Dạ [Quản Gia] xin gửi riêng bảng báo cáo học tập và nề nếp của các con cho [Danh xưng] ạ.";

                            $newFrontmatter .= $this->getUxUpgradeRules();

                            $db->table('agents')
                                ->where('tenant_id', $dedicatedTenantId)
                                ->where('agent_key', 'aicenty-master-butler')
                                ->update([
                                    'display_name' => "{$chosenButler} ({$curFamily})",
                                    'frontmatter'  => $newFrontmatter,
                                    'updated_at'   => now(),
                                ]);

                        $meta['name'] = $customerName;
                        $meta['family_name'] = $curFamily;
                        $meta['butler_name'] = $chosenButler;
                        if (!empty($chosenHonorific)) {
                            $meta['honorific'] = $chosenHonorific;
                        } elseif (empty($meta['honorific']) || str_contains($meta['honorific'], 'Nguyễn Hùng')) {
                            $meta['honorific'] = 'Anh ' . $customerName;
                        }
                        if (!empty($chosenChildInfo)) {
                            $meta['child_info'] = $chosenChildInfo;
                        }
                        $meta['onboarding_completed'] = true;
                        if ($dedicatedTenantId) {
                            $meta['dedicated_tenant_id'] = $dedicatedTenantId;
                            $meta['dedicated_agent_id'] = $dedicatedAgentId;
                        }

                        $cleanUserMd = $this->generateUserMdContent($meta);

                        $sec3TargetUids = [$uid];
                        $cleanP3 = preg_replace('/[^0-9]/', '', ($meta['phone'] ?? ($cleanPhone ?? ($handoverRow?->phone ?? ''))));
                        if (!empty($cleanP3)) {
                            $sec3TargetUids[] = 'phone_' . $cleanP3;
                            $sec3TargetUids[] = $cleanP3;
                        }
                        $sec3TargetUids = array_unique($sec3TargetUids);

                        foreach ($sec3TargetUids as $tUid3) {
                            $db->table('user_context_files')->updateOrInsert(
                                ['agent_id' => $masterButlerId, 'user_id' => $tUid3, 'file_name' => 'USER.md'],
                                ['content' => $cleanUserMd, 'tenant_id' => $masterTenantId, 'updated_at' => now()]
                            );
                            $db->table('user_agent_profiles')->updateOrInsert(
                                ['agent_id' => $masterButlerId, 'user_id' => $tUid3, 'tenant_id' => $masterTenantId],
                                ['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'last_seen_at' => now()]
                            );

                            // Đảm bảo dedicated_agent_id tồn tại thật sự trong bảng agents (chống lỗi Foreign Key)
                            $validDedicatedAgent = ($dedicatedAgentId && $dedicatedTenantId && $db->table('agents')->where('id', $dedicatedAgentId)->exists());
                            if (!$validDedicatedAgent && $dedicatedTenantId) {
                                $fallbackAgent = $db->table('agents')->where('tenant_id', $dedicatedTenantId)->where('agent_key', 'aicenty-master-butler')->first();
                                if ($fallbackAgent) {
                                    $dedicatedAgentId = $fallbackAgent->id;
                                    $validDedicatedAgent = true;
                                }
                            }

                            if ($validDedicatedAgent) {
                                $db->table('user_context_files')->updateOrInsert(
                                    ['agent_id' => $dedicatedAgentId, 'user_id' => $tUid3, 'file_name' => 'USER.md'],
                                    ['content' => $cleanUserMd, 'tenant_id' => $dedicatedTenantId, 'updated_at' => now()]
                                );
                                $db->table('user_agent_profiles')->updateOrInsert(
                                    ['agent_id' => $dedicatedAgentId, 'user_id' => $tUid3, 'tenant_id' => $dedicatedTenantId],
                                    ['metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'last_seen_at' => now()]
                                );
                            }
                        }

                        if ($dedicatedTenantId && !empty($customerName) && !in_array($customerName, ['Gia Chủ', 'Quý Gia Chủ'])) {
                            $db->table('tenant_users')
                                ->where('tenant_id', $dedicatedTenantId)
                                ->whereIn('user_id', $sec3TargetUids)
                                ->update([
                                    'display_name' => $customerName,
                                    'updated_at'   => now(),
                                ]);

                            if (!empty($cleanP3)) {
                                try {
                                    $uModel = \App\Models\User::where('phone', $cleanP3)->first();
                                    if ($uModel) {
                                        $uModel->update(['name' => $customerName]);
                                    }
                                } catch (\Throwable $ue) {}
                            }
                        }
                    }

                        $db->table('user_agent_profiles')
                            ->where('user_id', $uid)
                            ->whereIn('agent_id', [$guruAgentId, $masterButlerId])
                            ->update([
                                'metadata'     => json_encode($meta, JSON_UNESCAPED_UNICODE),
                                'last_seen_at' => now(),
                            ]);

                        if (!empty($handoverRow?->id)) {
                            try {
                                $db->statement("SELECT public.sync_quangia_family_profile_all('{$handoverRow->id}'::uuid)");
                            } catch (\Throwable $se) {}
                        }
                        Log::info("[Butler Sync USER.md] Synced chosen Butler '{$chosenButler}' for user {$uid} in tenant {$dedicatedTenantId}");
                    }
                }
            }

            if ($autoProvisionedCount > 0) {
                $this->info("Smart Onboarding: Provisioned {$autoProvisionedCount} family organizations.");
            }

            Log::info("[Butler Sync CRM] Hoan tat quet. Provisioned: {$autoProvisionedCount}");
            $this->info("Completed. Auto-Provisioned: {$autoProvisionedCount}");
            return 0;
        } catch (\Throwable $e) {
            Log::error("[Butler Sync CRM] Error: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Reconcile only exact, already-verified family membership bindings.
     * This path intentionally runs even when the legacy heuristic identity sync is disabled.
     */
    private function syncVerifiedFamilyMemberContexts(ButlerAutomationService $automationService): int
    {
        $repaired = 0;

        try {
            $db = DB::connection('butler_pgsql');
            $members = $db->table('quangia_family_members as member')
                ->join('quangia_pending_handover as handover', 'handover.id', '=', 'member.handover_id')
                ->join('butler_identity_bindings as binding', function ($join) {
                    $join->on('binding.external_user_id', '=', 'member.zalo_uid')
                        ->on('binding.handover_id', '=', 'handover.id')
                        ->on('binding.tenant_id', '=', 'handover.dedicated_tenant_id')
                        ->where('binding.status', '=', 'active');
                })
                ->whereNotNull('member.zalo_uid')
                ->where('member.zalo_uid', '<>', '')
                ->whereNotNull('handover.dedicated_tenant_id')
                ->select([
                    'member.handover_id',
                    'member.zalo_uid',
                ])
                ->distinct()
                ->get();

            foreach ($members as $member) {
                $zaloUid = trim((string) $member->zalo_uid);
                if (strlen($zaloUid) < 15 || !ctype_digit($zaloUid)) {
                    continue;
                }

                try {
                    if ($automationService->reconcileVerifiedFamilyMemberContext((string) $member->handover_id, $zaloUid)) {
                        $repaired++;
                    }
                } catch (\Throwable $memberError) {
                    Log::warning("[Butler Verified Member Sync] Failed for {$zaloUid}: " . $memberError->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Butler Verified Member Sync] Reconciliation skipped: ' . $e->getMessage());
        }

        return $repaired;
    }

    /**
     * Nhận diện chuẩn xác Giới tính & Danh xưng xưng hô cho bất kỳ khách hàng nào
     */
    private function detectGenderAndHonorific(string $senderName, array $messages, ?string $webName = null): array
    {
        // QUY TẮC BẮT BUỘC: Nếu có tên đã đăng ký trên Form ($webName), LUÔN LUÔN ưu tiên lấy tên này làm tên hiển thị & xưng hô chuẩn,
        // TUYỆT ĐỐI KHÔNG dùng tên Zalo cá nhân / nickname ($senderName) khi tên đó khác với tên trên Form!
        if (!empty($webName) && !in_array($webName, ['Gia Chủ', 'Quý Gia Chủ'])) {
            $baseName = trim($webName);
        } else {
            $baseName = trim($senderName);
        }
        if (empty($baseName)) {
            $baseName = 'Gia Chủ';
        }

        $isMale = false;
        $isFemale = false;
        $roleTitle = null;
        $cleanName = $baseName;

        // 1. Kiểm tra tiền tố ngay trong Tên Zalo (nếu tên là "Mẹ Sóc", "Ba Bon", "Chị Mai", "Anh Nam"...)
        if (preg_match('/^(?:ba|bố|cha|anh|chú)\s+(.+)$/iu', $baseName, $m)) {
            $isMale = true;
            $cleanName = trim($m[1]);
            $roleTitle = preg_match('/^(?:ba|bố|cha)/iu', $baseName) ? 'Ba' : 'Anh';
            return ['gender' => 'nam', 'honorific' => trim($baseName), 'clean_name' => $cleanName];
        }
        if (preg_match('/^(?:mẹ|má|chị|cô)\s+(.+)$/iu', $baseName, $m)) {
            $isFemale = true;
            $cleanName = trim($m[1]);
            $roleTitle = preg_match('/^(?:mẹ|má)/iu', $baseName) ? 'Mẹ' : 'Chị';
            return ['gender' => 'nu', 'honorific' => trim($baseName), 'clean_name' => $cleanName];
        }

        // 2. Thu thập các câu trả lời của khách trong hội thoại
        $userTexts = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $t = (string) ($m['content'] ?? '');
                $t = preg_replace('/^\[From:[^\]]+\]\s*/u', '', $t);
                $userTexts[] = trim($t);
            }
        }
        $joinedText = implode(' ', $userTexts);

        // Loại bỏ phần đặt tên quản gia khỏi văn bản phân tích để tránh từ như "Cô Ba", "Chú 5" gây nhiễu giới tính gia chủ
        $textClean = preg_replace('/(?:quản gia|tên quản gia|đặt tên|gọi quản gia)\s*[:=\-\s]?\s*(?:Bác|Chú|Cô|Thầy)?[A-Za-zÀ-ỹ0-9\s,.]+/iu', ' ', $joinedText);

        // Nhận diện xưng Ba / Bố / Anh
        if (preg_match('/\b(?:mình là ba|mình là bố|anh là ba|bố cháu|ba cháu|gọi mình là ba|gọi ba)\b/iu', $textClean)) {
            $isMale = true;
            $roleTitle = 'Ba';
        } elseif (preg_match('/\b(?:mình là mẹ|chị là mẹ|mẹ cháu|má cháu|gọi mình là mẹ|gọi mẹ)\b/iu', $textClean)) {
            $isFemale = true;
            $roleTitle = 'Mẹ';
        } elseif (preg_match('/\b(?:tôi là|mình là|anh là)\s+anh\b|\banh\s+(?:là|tên là|nhé|nhe|ạ|nha|đây)\b|\bgọi\s+(?:mình\s+là\s+)?anh\b|\banh\s+[A-ZÀ-Ỹ][a-zà-ỹ]+\s*(?:đây|nhé|nhe|ạ)?\b/u', $textClean)) {
            $isMale = true;
            $roleTitle = 'Anh';
        } elseif (preg_match('/\b(?:tôi là|mình là|chị là)\s+chị\b|\bchị\s+(?:là|tên là|nhé|nhe|ạ|nha|đây)\b|\bgọi\s+(?:mình\s+là\s+)?chị\b|\bchị\s+[A-ZÀ-Ỹ][a-zà-ỹ]+\s*(?:đây|nhé|nhe|ạ)?\b/u', $textClean)) {
            $isFemale = true;
            $roleTitle = 'Chị';
        }

        // 3. Quét các câu trả lời ngắn theo câu hỏi Ba hay Mẹ (Anh hay Chị)
        if (!$isMale && !$isFemale) {
            foreach ($userTexts as $ut) {
                $low = mb_strtolower(trim($ut));
                if (preg_match('/^(?:ba|bố|anh|chú)(?:\s+(?:nhé|nhe|ạ|nha))?$/u', $low)) {
                    $isMale = true;
                    $roleTitle = (str_contains($low, 'ba') || str_contains($low, 'bố')) ? 'Ba' : 'Anh';
                    break;
                }
                if (preg_match('/^(?:mẹ|má|chị|cô)(?:\s+(?:nhé|nhe|ạ|nha))?$/u', $low)) {
                    $isFemale = true;
                    $roleTitle = (str_contains($low, 'mẹ') || str_contains($low, 'má')) ? 'Mẹ' : 'Chị';
                    break;
                }
            }
        }

        // 3.5. Heuristic tên tiếng Việt thông minh (xử lý tên kép như Huy Lan, Minh Thư, Văn...)
        if (!$isMale && !$isFemale) {
            $words = preg_split('/\s+/u', mb_strtolower($baseName));
            $firstWord = $words[0] ?? '';
            $maleFirsts = ['huy', 'văn', 'đức', 'hùng', 'dũng', 'minh', 'tuấn', 'quốc', 'long', 'hoàng', 'hải', 'phong', 'trọng', 'tiến', 'thành', 'bách', 'nam', 'đăng'];
            $femaleFirsts = ['thị', 'ngọc', 'hương', 'mai', 'phương', 'thảo', 'quỳnh', 'huyền', 'nhung', 'trang', 'thúy'];
            if (in_array($firstWord, $maleFirsts)) {
                $isMale = true;
                $roleTitle = 'Anh';
            } elseif (in_array($firstWord, $femaleFirsts)) {
                $isFemale = true;
                $roleTitle = 'Chị';
            }
        }

        if ($isMale && !$isFemale) {
            $gender = 'nam';
            $honorific = ($roleTitle ?: 'Anh') . ' ' . $baseName;
        } elseif ($isFemale && !$isMale) {
            $gender = 'nu';
            $honorific = ($roleTitle ?: 'Chị') . ' ' . $baseName;
        } else {
            // Heuristics theo tên đệm thuần Việt khi chưa có thông tin tự xưng
            $lowName = ' ' . mb_strtolower($baseName) . ' ';
            if (str_contains($lowName, ' thị ') || str_contains($lowName, ' ngọc mai ') || str_contains($lowName, ' thị')) {
                $gender = 'nu';
                $honorific = 'Chị ' . $baseName;
            } elseif (str_contains($lowName, ' văn ') || str_contains($lowName, ' trọng ') || str_contains($lowName, ' đức ')) {
                $gender = 'nam';
                $honorific = 'Anh ' . $baseName;
            } else {
                $gender = 'chua_ro';
                $honorific = 'Gia chủ ' . $baseName;
            }
        }

        return [
            'gender'     => $gender,
            'honorific'  => $honorific,
            'clean_name' => $baseName,
        ];
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

        $startDate = $profile['provisioned_at'] ?? ($profile['created_at'] ?? now()->toIso8601String());

        return "# USER.md - Hồ Sơ Gia Chủ & Tổ Ấm\n"
            . "- **Họ tên gia chủ:** " . $name . "\n"
            . "- **Giới tính:** " . $genderRule . "\n"
            . "- **Danh xưng xưng hô chuẩn:** " . $honorific . "\n"
            . "- **Số điện thoại:** " . $phone . "\n"
            . "- **Email:** " . $email . "\n"
            . "- **Tên Tổ Ấm:** " . $familyName . "\n"
            . "- **Tên Quản Gia riêng:** " . $butlerName . "\n"
            . "- **Các thành viên nhí cần đồng hành & học tập:** " . $childInfo . "\n"
            . (!empty($profile['family_notes'] ?? ($profile['note'] ?? '')) ? ("- **Bối cảnh & Lưu ý đặc biệt của gia đình:** " . ($profile['family_notes'] ?? $profile['note']) . "\n") : "")
            . "- **Phong cách phục vụ & Xưng hô:** Quản gia xưng là \"" . $butlerName . "\" (hoặc Quản gia) và gọi gia chủ chuẩn mực là \"" . $honorific . "\". Áp dụng phương pháp Socratic, lễ phép, tận tâm, chu đáo.\n"
            . "- **Tổ chức / Tenant ID Aicenty Butler:** " . $tenantId . "\n"
            . "- **Thời gian bắt đầu 7 ngày trải nghiệm:** " . $startDate . "\n"
            . "- **Lộ trình Takecare & Nhiệm vụ 7 Ngày:**\n"
            . "  + Ngày 1: Quản lý lịch trình & Tự động hẹn giờ bắn tin nhắc việc Zalo 24/7\n"
            . "  + Ngày 2: Kèm học Socratic & Thử thách giải bài tập khó (Toán, Văn, Anh, KHTN...)\n"
            . "  + Ngày 3: Bảng việc nhà & Tích sao thưởng đổi quà (Gamification)\n"
            . "  + Ngày 4: Cố vấn tài chính gia đình & Phân bổ ngân sách 50/30/20\n"
            . "  + Ngày 5: Hỗ trợ chuyên môn hành chính & Tiện ích dinh dưỡng gia đình\n"
            . "  + Ngày 6: Kế hoạch dã ngoại / Cuối tuần gắn kết tình thân\n"
            . "  + Ngày 7: Báo cáo tổng kết 7 ngày & Kế hoạch chuyển đổi SIM riêng bảo mật\n";
    }

        private function getUxUpgradeRules(): string
    {
        return "\n\n# QUY TẮC ĐỊNH DẠNG TIN NHẮN ZALO MOBILE (MICRO-FORMATTING & CHUẨN MỰC THỊ GIÁC):\n"
            . "1. ĐỘ DÀI TỐI ƯU MÀN HÌNH ĐIỆN THOẠI:\n"
            . "- Mỗi lượt phản hồi KHÔNG vượt quá 150 - 200 từ (trừ khi Gia chủ yêu cầu bài văn mẫu, công thức chi tiết, hoặc bản phân tích tài chính/học tập chuyên sâu).\n"
            . "- Tuyệt đối tránh viết một khối chữ dài đặc (wall of text) khiến Gia chủ bị ngợp trên màn hình smartphone.\n"
            . "2. BỐ CỤC THOÁNG ĐẠT & DỄ ĐỌC (SCANNABILITY):\n"
            . "- Mỗi đoạn chỉ từ 1 đến tối đa 3 câu ngắn. Ngắt dòng đôi giữa các đoạn.\n"
            . "- Khi liệt kê các ý hoặc lựa chọn: Bắt buộc dùng dấu gạch đầu dòng (•) hoặc số thứ tự rõ ràng.\n"
            . "3. ĐIỂM NEO THỊ GIÁC (VISUAL ANCHORS BẰNG EMOJI TRANG NHÃ - ĐÚNG THỨ TỰ ƯU TIÊN):\n"
            . "- Sử dụng emoji làm điểm neo giúp mắt quét nhanh thông tin theo thứ tự ưu tiên cốt lõi:\n"
            . "  • 📅 Sắp xếp lịch trình & Nhắc việc tự động\n"
            . "  • 📚 Gia sư 1-1 phương pháp Socratic gợi mở tư duy\n"
            . "  • 💰 Cố vấn tài chính & Ngân sách tổ ấm\n"
            . "  • 💼 Hỗ trợ chuyên môn & Công việc hành chính\n"
            . "  • 🥣 Tư vấn dinh dưỡng & Thực đơn bữa ăn (khi cần)\n"
            . "  • ⏰ Mốc thời gian\n"
            . "  • 🎩 Tác phong Quản gia chu đáo\n"
            . "- Giữ tần suất vừa phải (1-2 emoji mỗi ý chính), không lạm dụng tràn lan.\n"
            . "4. CÂU HỎI MỞ DUY TRÌ HỘI THOẠI (INTERACTIVE CALL-TO-ACTION):\n"
            . "- Luôn kết thúc phản hồi bằng 1 câu hỏi gợi mở ngắn gọn, tự nhiên, lịch thiệp (ví dụ: 'Dạ Gia chủ có cần em hỗ trợ thêm chi tiết nào không ạ?', 'Gia chủ có muốn em lưu lịch này vào sổ tay gia đình ngay không ạ?').\n"
            . "\n"
            . "# QUY TẮC KHI GIA CHỦ HỎI VỀ DANH TÍNH, NĂNG LỰC HOẶC TÍNH NĂNG CỦA QUẢN GIA:\n"
            . "Khi Gia chủ hoặc thành viên hỏi 'Bạn là ai?', 'Bạn làm được những gì?', 'Quản gia giúp gì cho tôi/gia đình?':\n"
            . "Quản gia BẮT BUỘC phải giới thiệu năng lực theo đúng thứ tự ưu tiên cốt lõi:\n"
            . "1. 📅 Sắp xếp lịch trình & Nhắc lịch thông minh 24/7 qua Zalo (giờ học của con, lịch họp, việc gia đình tự động nhắc đúng giờ kèm Mã lịch hẹn Job ID).\n"
            . "2. 📚 Gia sư 1-1 theo phương pháp Socratic đồng hành cùng các con mọi môn học (Toán, Văn, Anh, KHTN...) gợi mở tư duy, không giải hộ.\n"
            . "3. 💰 Cố vấn quản lý tài chính, chi tiêu & ngân sách gia đình.\n"
            . "4. 💼 Hỗ trợ công việc chuyên môn & hành chính cho Gia chủ (soạn thảo, tóm tắt tài liệu).\n"
            . "5. 🥣 Sau đó mới điểm qua các tiện ích chăm sóc tổ ấm khác như tư vấn dinh dưỡng, gợi ý món ngon bữa ăn khi gia đình cần.\n"
            . "TUYỆT ĐỐI KHÔNG mở đầu bằng việc nấu ăn, thực đơn hay dinh dưỡng.\n"
            . "\n"
            . "# QUY TẮC LÀM QUEN VỚI THÀNH VIÊN TRONG TỔ ẤM:\n"
            . "- Nếu USER.md có `is_family_member=true` hoặc ghi rõ đây là thành viên gia đình, người này KHÔNG phải khách đăng ký mới.\n"
            . "- Kế thừa nguyên trạng tên Quản Gia và cấu hình tổ ấm do Gia chủ chính đã thiết lập; TUYỆT ĐỐI KHÔNG hỏi đặt tên Quản Gia hoặc chạy lại onboarding Gia chủ.\n"
            . "- Chỉ được hỏi đặt tên Quản Gia khi hồ sơ xác nhận rõ người đang nhắn là Master Owner và onboarding chính chưa hoàn tất; nếu vai trò chưa rõ thì mặc định không hỏi.\n"
            . "- Chỉ hỏi các thông tin còn thiếu để hiểu đúng thành viên: tên muốn được gọi, vai trò trong gia đình và nhu cầu hỗ trợ hiện tại. Gộp vào một câu hỏi ngắn rồi hỗ trợ ngay.\n"
            . "- Thành viên không có quyền thay đổi cấu hình tổ ấm; yêu cầu thay đổi phải được chuyển về Gia chủ chính.\n"
            . "\n"
            . "# KỊCH BẢN CHỦ ĐỘNG TƯƠNG TÁC 3 NGÀY ĐẦU (FIRST-3-DAYS ONBOARDING NUDGE):\n"
            . "Dành cho Gia chủ mới trong 72 giờ đầu tiên làm quen với Quản Gia để Gia chủ cảm nhận trọn vẹn 2 giá trị thiết thực và hữu dụng nhất:\n"
            . "1. NGÀY THỨ 1 (KÍCH HOẠT THƯ KÝ NHẮC LỊCH THÔNG MINH & NỀN TẢNG GIA ĐÌNH):\n"
            . "- Buổi sáng: Chào mừng Gia chủ, giới thiệu khả năng làm Thư ký riêng sắp xếp lịch trình và hẹn giờ tự động qua Zalo.\n"
            . "- Buổi tối: Hướng dẫn Gia chủ thử ngay 1 câu lệnh nhắc hẹn: 'Dạ Gia chủ có thể thử dặn em: 7h sáng mai nhắc anh đưa con đi học hoặc 20h nhắc chị đọc sách, em sẽ lên lịch hẹn tự động và báo chuẩn giờ qua Zalo nhé ạ!'.\n"
            . "2. NGÀY THỨ 2 (TRẢI NGHIỆM GIA SƯ 1-1 SOCRATIC KÈM CON HỌC TẬP):\n"
            . "- Buổi sáng: Giới thiệu phương pháp đồng hành cùng các con: 'Dạ hôm nay con có bài tập Toán, Văn, Anh nào cần trợ giúp không ạ? Con chỉ cần gửi hình ảnh hoặc câu hỏi, Quản gia sẽ cùng con tư duy từng bước theo phương pháp Socratic giúp con tự hiểu bài mà không làm thay con nhé ạ!'.\n"
            . "- Buổi tối: Hỏi thăm tình hình học tập và nề nếp của con trong ngày.\n"
            . "3. NGÀY THỨ 3 (HỖ TRỢ TÀI CHÍNH, CÔNG VIỆC CHUYÊN MÔN & TIỆN ÍCH TỔ ẤM):\n"
            . "- Buổi sáng: Gợi ý hỗ trợ công việc hành chính, soạn thảo hoặc ghi nhận chi tiêu gia đình.\n"
            . "- Buổi tối: Chia sẻ các tiện ích chăm sóc tổ ấm khác (như thực đơn bữa tối khi gia đình cần gợi ý món ngon dễ làm).\n"
            . "- Sau 3 ngày đầu: Tiếp tục nhịp điệu Quản gia đồng hành thường nhật chu đáo và bền bỉ.\n";
    }
}
