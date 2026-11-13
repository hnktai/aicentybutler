<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OdooCrmService
{
    protected string $url;
    protected string $db;
    protected int $uid;
    protected string $apiKey;
    protected int $teamId;
    protected int $tagId;

    public function __construct()
    {
        $this->url = rtrim((string) config('services.odoo.url', 'https://erp.hnkt.vn'), '/');
        $this->db = (string) config('services.odoo.db', 'hnkt_live');
        $this->uid = (int) config('services.odoo.uid', 2);
        $this->apiKey = (string) config('services.odoo.api_key', '60861bec7374d4f92d0c31277353c12374be0ec6');
        $this->teamId = (int) config('services.odoo.team_id', 11); // Sales Team: Aicenty - Dịch Vụ AI
        $this->tagId = (int) config('services.odoo.tag_id', 2);    // CRM Tag: Khách Hàng Aicenty
    }

    public function isConfigured(): bool
    {
        return !empty($this->url) && !empty($this->db) && !empty($this->apiKey);
    }

    public function createLead(array $data): ?int
    {
        try {
            $name = trim((string) ($data['name'] ?? 'Khách Hàng Aicenty'));
            $title = trim((string) ($data['title'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $date = (string) ($data['date'] ?? '');
            $time = (string) ($data['time'] ?? '');
            $problem = trim((string) ($data['problem'] ?? ''));
            $affiliateRef = trim((string) ($data['affiliate_ref'] ?? ''));
            $sourcePage = trim((string) ($data['source_page'] ?? 'aicenty.com'));

            $leadTitle = "[Aicenty Web] {$name}" . ($title ? " ({$title})" : "") . ($affiliateRef !== '' ? " [Ref: {$affiliateRef}]" : "");
            
            $descriptionHtml = "<p><strong>Khách hàng đăng ký tư vấn chuyển đổi số AI từ website aicenty.com ({$sourcePage}):</strong></p>"
                . "<ul>"
                . "<li><strong>Họ và tên:</strong> " . e($name) . "</li>"
                . "<li><strong>Chức danh / Vị trí:</strong> " . e($title) . "</li>"
                . "<li><strong>Email liên hệ:</strong> " . e($email) . "</li>"
                . "<li><strong>Số lượng thành viên gia đình:</strong> " . e($familyMembers) . "</li>"
                . "<li><strong>Số điện thoại:</strong> " . e($phone) . "</li>"
                . "<li><strong>Lịch hẹn mong muốn:</strong> " . e($date) . " (" . e($time) . ")</li>"
                . ($affiliateRef !== '' ? "<li><strong>Người giới thiệu (Affiliate):</strong> " . e($affiliateRef) . "</li>" : "")
                . "</ul>"
                . "<hr/>"
                . "<p><strong>Mô tả bài toán / Điểm nghẽn doanh nghiệp đang gặp:</strong></p>"
                . "<p style='background-color: #f8fafc; padding: 12px; border-left: 3px solid #3b82f6;'>" . nl2br(e($problem)) . "</p>";

            $payload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'crm.lead',
                        'create',
                        [[
                            'name' => $leadTitle,
                            'contact_name' => $name,
                            'function' => $title,
                            'email_from' => $email,
                            'phone' => $phone,
                            'team_id' => $this->teamId,
                            'tag_ids' => [[4, $this->tagId]],
                            'description' => $descriptionHtml,
                            'type' => 'opportunity',
                            'priority' => '2', // High priority
                        ]]
                    ]
                ]
            ];

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->asJson()
                ->post("{$this->url}/jsonrpc", $payload);

            if ($response->successful()) {
                $resData = $response->json();
                if (isset($resData['result'])) {
                    $leadId = (int) $resData['result'];
                    Log::info("[Odoo CRM Sync] Đã đẩy thành công Lead vào Odoo CRM erp.hnkt.vn: Lead ID {$leadId} ({$email})");

                    // Nếu có mã giới thiệu Affiliate, tìm Affiliate và tạo Referral ở trạng thái pending (chưa duyệt hoa hồng)
                    if ($affiliateRef !== '') {
                        $this->createAffiliateReferralForLead($affiliateRef, $leadId, $name, $phone, $sourcePage);
                    }

                    return $leadId;
                }
                Log::warning("[Odoo CRM Sync] Odoo trả về lỗi: " . json_encode($resData['error'] ?? 'Unknown'));
            } else {
                Log::error("[Odoo CRM Sync] HTTP request failed: " . $response->status() . " Body: " . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error("[Odoo CRM Sync] Ngoại lệ khi tạo Lead Odoo: " . $e->getMessage());
        }

        return null;
    }

    public function createAffiliateReferralForLead(string $affiliateRef, int $leadId, string $customerName, string $customerPhone, string $sourcePage = 'aicenty.com'): ?int
    {
        try {
            // 1. Tìm Affiliate theo mã code hoặc tên
            $searchPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'strive.affiliate.affiliate',
                        'search_read',
                        [[['code', '=', $affiliateRef]]],
                        ['fields' => ['id', 'name', 'code', 'program_id'], 'limit' => 1]
                    ]
                ]
            ];

            $searchRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchPayload);
            $affRecord = null;
            if ($searchRes->successful() && !empty($searchRes->json()['result'])) {
                $affRecord = $searchRes->json()['result'][0];
            } else {
                $searchNamePayload = [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'object',
                        'method' => 'execute_kw',
                        'args' => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'strive.affiliate.affiliate',
                            'search_read',
                            [[['name', 'ilike', $affiliateRef]]],
                            ['fields' => ['id', 'name', 'code', 'program_id'], 'limit' => 1]
                        ]
                    ]
                ];
                $searchNameRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchNamePayload);
                if ($searchNameRes->successful() && !empty($searchNameRes->json()['result'])) {
                    $affRecord = $searchNameRes->json()['result'][0];
                }
            }

            if (!$affRecord || empty($affRecord['program_id'])) {
                Log::warning("[Odoo Affiliate Lead] Không tìm thấy Affiliate hợp lệ với mã: {$affiliateRef}");
                return null;
            }

            $programId = (int) $affRecord['program_id'][0];
            $affiliateId = (int) $affRecord['id'];
            $affName = (string) $affRecord['name'];

            $referralPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'strive.affiliate.referral',
                        'create',
                        [[
                            'name' => "Lead: {$customerName} ({$customerPhone})",
                            'program_id' => $programId,
                            'affiliate_id' => $affiliateId,
                            'reference' => "LEAD/{$leadId}",
                            'description' => "Lead điền form tư vấn từ website aicenty.com ({$sourcePage}) qua giới thiệu của {$affName}",
                            'source' => 'website',
                            'status' => 'pending',
                            'date_created' => gmdate('Y-m-d H:i:s'),
                            'base_amount' => 0.0,
                            'commission_amount' => 0.0,
                        ]]
                    ]
                ]
            ];

            $refRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $referralPayload);
            if ($refRes->successful() && isset($refRes->json()['result'])) {
                $refId = (int) $refRes->json()['result'];
                Log::info("[Odoo Affiliate Lead] Đã ghi nhận thành công Affiliate Referral ID {$refId} cho Affiliate {$affName} ({$affiliateRef})");
                return $refId;
            }
            Log::warning("[Odoo Affiliate Lead] Odoo trả về lỗi khi tạo Referral: " . json_encode($refRes->json()['error'] ?? 'Unknown'));
        } catch (\Throwable $e) {
            Log::error("[Odoo Affiliate Lead] Ngoại lệ khi tạo Referral Odoo: " . $e->getMessage());
        }

        return null;
    }

    public function createPartnerRegistration(array $data): ?array
    {
        try {
            $name = trim((string) ($data['name'] ?? 'Đối Tác Aicenty'));
            $email = trim((string) ($data['email'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $program = trim((string) ($data['program'] ?? 'affiliate'));
            $role = trim((string) ($data['role'] ?? ''));
            $note = trim((string) ($data['note'] ?? ''));

            $programLabel = $program === 'consultant' 
                ? 'Consultant Partner (Tư Vấn & Đồng Hành - Hoa hồng tới 50%)' 
                : 'Affiliate Partner (Giới Thiệu - Hoa hồng tới 30%)';

            $partnerTagId = 3;

            $partnerId = null;
            $searchPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'res.partner',
                        'search_read',
                        [[['email', '=', $email]]],
                        ['fields' => ['id', 'name', 'category_id']]
                    ]
                ]
            ];

            $searchRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchPayload);
            if ($searchRes->successful() && !empty($searchRes->json()['result'])) {
                $existingPartner = $searchRes->json()['result'][0];
                $partnerId = (int) $existingPartner['id'];
                $updatePayload = [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'object',
                        'method' => 'execute_kw',
                        'args' => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'res.partner',
                            'write',
                            [
                                [$partnerId],
                                [
                                    'category_id' => [[4, $partnerTagId]],
                                    'function' => $role ?: ($existingPartner['function'] ?? ''),
                                    'comment' => "Đăng ký chương trình: {$programLabel}. Ghi chú: {$note}"
                                ]
                            ]
                        ]
                    ]
                ];
                Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $updatePayload);
            } else {
                $createPartnerPayload = [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'object',
                        'method' => 'execute_kw',
                        'args' => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'res.partner',
                            'create',
                            [[
                                'name' => $name,
                                'email' => $email,
                                'phone' => $phone,
                                'function' => $role,
                                'category_id' => [[4, $partnerTagId]],
                                'comment' => "Đăng ký đối tác bán hàng kinh doanh Aicenty. Chương trình: {$programLabel}. Ghi chú: {$note}"
                            ]]
                        ]
                    ]
                ];
                $createPartnerRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $createPartnerPayload);
                if ($createPartnerRes->successful() && isset($createPartnerRes->json()['result'])) {
                    $partnerId = (int) $createPartnerRes->json()['result'];
                }
            }

            $leadTitle = "[Đối Tác Bán Hàng] {$name} - " . ($program === 'consultant' ? 'Consultant' : 'Affiliate');
            $descriptionHtml = "<p><strong>Đơn đăng ký Đối Tác Bán Hàng Kinh Doanh Aicenty từ website aicenty.com/partner:</strong></p>"
                . "<ul>"
                . "<li><strong>Họ và tên:</strong> " . e($name) . "</li>"
                . "<li><strong>Email liên hệ:</strong> " . e($email) . "</li>"
                . "<li><strong>Số điện thoại (Zalo):</strong> " . e($phone) . "</li>"
                . "<li><strong>Chương trình đăng ký:</strong> <span style='color:#7c3aed; font-weight:bold;'>" . e($programLabel) . "</span></li>"
                . "<li><strong>Vai trò / Nghề nghiệp:</strong> " . e($role ?: 'Chưa cập nhật') . "</li>"
                . "<li><strong>Chính sách áp dụng:</strong> Hoa hồng trọn đời - Thanh toán định kỳ ngày 15 hàng tháng</li>"
                . "</ul>"
                . "<hr/>"
                . "<p><strong>Kinh nghiệm / Tập khách hàng / Đề xuất hợp tác:</strong></p>"
                . "<p style='background-color: #f1f5f9; padding: 12px; border-left: 3px solid #7c3aed;'>" . nl2br(e($note ?: 'Không có ghi chú thêm')) . "</p>";

            $leadData = [
                'name' => $leadTitle,
                'contact_name' => $name,
                'email_from' => $email,
                'phone' => $phone,
                'function' => $role,
                'team_id' => $this->teamId,
                'tag_ids' => [[4, $partnerTagId]],
                'description' => $descriptionHtml,
                'type' => 'opportunity',
                'priority' => '3',
            ];
            if ($partnerId) {
                $leadData['partner_id'] = $partnerId;
            }

            $leadPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'crm.lead',
                        'create',
                        [$leadData]
                    ]
                ]
            ];

            $leadRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $leadPayload);
            $leadId = null;
            if ($leadRes->successful() && isset($leadRes->json()['result'])) {
                $leadId = (int) $leadRes->json()['result'];
                Log::info("[Odoo Partner Sync] Đã tạo thành công Đối tác Bán hàng: Partner ID {$partnerId}, Lead ID {$leadId} ({$email})");
            }

            return [
                'partner_id' => $partnerId,
                'lead_id' => $leadId
            ];
        } catch (\Throwable $e) {
            Log::error("[Odoo Partner Sync] Ngoại lệ khi tạo Partner Odoo: " . $e->getMessage());
            return null;
        }
    }

    public function createButlerTrialLead(array $data): ?int
    {
        try {
            $name = trim((string) ($data['name'] ?? 'Phụ Huynh Aicenty Butler'));
            $email = trim((string) ($data['email'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $butlerName = trim((string) ($data['butler_name'] ?? 'Chưa đặt tên'));
            $childInfo = trim((string) ($data['child_info'] ?? 'Chưa cung cấp'));
            $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện gia đình'));
            $familyMembers = trim((string) ($data['family_members'] ?? 'Chưa cung cấp'));
            $note = trim((string) ($data['note'] ?? ''));
            $affiliateRef = trim((string) ($data['affiliate_ref'] ?? ''));

            $leadTitle = "[Aicenty Butler - 7 Ngày] {$name} ({$phone})" . ($affiliateRef !== '' ? " [Ref: {$affiliateRef}]" : "");

            $descriptionHtml = "<p><strong>Khách hàng đăng ký Trải nghiệm miễn phí Quản gia Gia đình Aicenty Butler (aicenty.com/butler):</strong></p>"
                . "<ul>"
                . "<li><strong>Họ và tên Gia Chủ:</strong> " . e($name) . "</li>"
                . "<li><strong>Số điện thoại / Zalo:</strong> " . e($phone) . "</li>"
                . "<li><strong>Email liên hệ:</strong> " . e($email) . "</li>"
                . "<li><strong>Tên Quản gia mong muốn đặt:</strong> " . e($butlerName) . "</li>"
                . "<li><strong>Lớp / Độ tuổi của các con:</strong> " . e($childInfo) . "</li>"
                . "<li><strong>Nhu cầu quán xuyến nhất:</strong> " . e($primaryConcern) . "</li>"
                . ($note !== '' ? "<li><strong>Ghi chú / Mong muốn:</strong> " . e($note) . "</li>" : "")
                . ($affiliateRef !== '' ? "<li><strong>Mã giới thiệu Affiliate:</strong> " . e($affiliateRef) . "</li>" : "")
                . "</ul>"
                . "<hr/>"
                . "<p><strong>Trạng thái:</strong> Đã chuyển hướng sang Thank You Page & kích hoạt chăm sóc Zalo Butler Guru.</p>";

            $payload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'crm.lead',
                        'create',
                        [[
                            'name' => $leadTitle,
                            'contact_name' => $name,
                            'email_from' => $email,
                            'phone' => $phone,
                            'team_id' => $this->teamId,
                            'tag_ids' => [[4, $this->tagId]],
                            'description' => $descriptionHtml,
                            'type' => 'opportunity',
                            'priority' => '3',
                        ]]
                    ]
                ]
            ];

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->asJson()
                ->post("{$this->url}/jsonrpc", $payload);

            if ($response->successful()) {
                $resData = $response->json();
                if (isset($resData['result'])) {
                    $leadId = (int) $resData['result'];
                    Log::info("[Odoo CRM Sync] Đã đẩy thành công Butler Trial Lead vào Odoo CRM erp.hnkt.vn: Lead ID {$leadId} ({$email})");
                    return $leadId;
                }
            }
            Log::warning("[Odoo CRM Sync] Odoo trả về lỗi khi tạo Butler Lead: " . $response->body());
            return null;
        } catch (\Throwable $e) {
            Log::error("[Odoo CRM Sync] Ngoại lệ khi đẩy Butler Lead vào Odoo: " . $e->getMessage());
            return null;
        }
    }

    public function createButlerPurchaseLead(array $data): ?int
    {
        try {
            $name = trim((string) ($data['name'] ?? 'Phụ Huynh Aicenty Butler'));
            $email = trim((string) ($data['email'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $butlerName = trim((string) ($data['butler_name'] ?? 'Chưa đặt tên'));
            $childInfo = trim((string) ($data['child_info'] ?? 'Chưa cung cấp'));
            $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện gia đình'));
            $note = trim((string) ($data['note'] ?? ''));
            $affiliateRef = trim((string) ($data['affiliate_ref'] ?? ''));
            $plan = trim((string) ($data['plan'] ?? '1m'));
            $planName = trim((string) ($data['plan_name'] ?? 'Gói 1 Tháng'));
            $amount = (float) ($data['amount'] ?? 500000);
            $isExistingTrial = !empty($data['is_existing_trial']);

            $amountFormatted = number_format($amount, 0, ',', '.') . ' VNĐ';

            if ($isExistingTrial) {
                $leadType = "NÂNG CẤP MUA LUÔN (Đang Dùng Thử)";
                $leadTitle = "[Aicenty Butler - NÂNG CẤP MUA LUÔN] {$name} ({$phone}) - Gói: {$planName} ({$amountFormatted})";
            } else {
                $leadType = "MUA TRỰC TIẾP";
                $leadTitle = "[Aicenty Butler - MUA TRỰC TIẾP] {$name} ({$phone}) - Gói: {$planName} ({$amountFormatted})";
            }

            if ($affiliateRef !== '') {
                $leadTitle .= " [Ref: {$affiliateRef}]";
            }

            $descriptionHtml = "<p><strong style='color:#0284c7; font-size:16px;'>ĐƠN ĐĂNG KÝ SỞ HỮU QUẢN GIA THỜI HIỆN ĐẠI (aicenty.com/butler)</strong></p>"
                . "<div style='background-color:#f0fdf4; border-left:4px solid #16a34a; padding:12px; margin-bottom:12px;'>"
                . "<p style='margin:0 0 6px;'><strong>Phân loại khách hàng:</strong> <span style='color:#15803d; font-weight:bold;'>{$leadType}</span></p>"
                . "<p style='margin:0 0 6px;'><strong>Gói dịch vụ chọn mua:</strong> <strong>{$planName}</strong></p>"
                . "<p style='margin:0 0 6px;'><strong>Giá trị thanh toán:</strong> <span style='font-size:15px; color:#b91c1c; font-weight:bold;'>{$amountFormatted}</span></p>"
                . "<p style='margin:0;'><strong>Cú pháp thanh toán VietQR:</strong> <code>SUB {$phone}</code></p>"
                . "</div>"
                . "<ul>"
                . "<li><strong>Họ và tên Gia Chủ:</strong> " . e($name) . "</li>"
                . "<li><strong>Số điện thoại / Zalo:</strong> " . e($phone) . "</li>"
                . "<li><strong>Email liên hệ:</strong> " . e($email) . "</li>"
                . "<li><strong>Tên Quản gia mong muốn đặt:</strong> " . e($butlerName) . "</li>"
                . "<li><strong>Lớp / Độ tuổi của các con:</strong> " . e($childInfo) . "</li>"
                . "<li><strong>Nhu cầu quán xuyến nhất:</strong> " . e($primaryConcern) . "</li>"
                . "<li><strong>Tài khoản thụ hưởng:</strong> MBBank 837006868 - NGUYEN THANH HUNG</li>"
                . ($note !== '' ? "<li><strong>Ghi chú / Mong muốn riêng:</strong> " . e($note) . "</li>" : "")
                . ($affiliateRef !== '' ? "<li><strong>Mã giới thiệu Affiliate:</strong> " . e($affiliateRef) . "</li>" : "")
                . "</ul>"
                . "<hr/>"
                . "<p><strong>Trạng thái:</strong> Đã chuyển hướng sang trang thanh toán VietQR SePay (/butler/payment). Hệ thống tự động kích hoạt tài khoản khi SePay bắn webhook xác nhận chuyển khoản.</p>";

            $payload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'crm.lead',
                        'create',
                        [[
                            'name' => $leadTitle,
                            'contact_name' => $name,
                            'email_from' => $email,
                            'phone' => $phone,
                            'team_id' => $this->teamId,
                            'tag_ids' => [[4, $this->tagId]],
                            'description' => $descriptionHtml,
                            'type' => 'opportunity',
                            'priority' => '3',
                            'expected_revenue' => $amount,
                        ]]
                    ]
                ]
            ];

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->asJson()
                ->post("{$this->url}/jsonrpc", $payload);

            if ($response->successful()) {
                $resData = $response->json();
                if (isset($resData['result'])) {
                    $leadId = (int) $resData['result'];
                    Log::info("[Odoo CRM Sync] Đã đẩy thành công Butler Purchase Lead vào Odoo CRM erp.hnkt.vn: Lead ID {$leadId} ({$email}, Plan: {$planName}, Amount: {$amount})");
                    return $leadId;
                }
            }
            Log::warning("[Odoo CRM Sync] Odoo trả về lỗi khi tạo Butler Purchase Lead: " . $response->body());
            return null;
        } catch (\Throwable $e) {
            Log::error("[Odoo CRM Sync] Ngoại lệ khi đẩy Butler Purchase Lead vào Odoo: " . $e->getMessage());
            return null;
        }
    }

    public function createButlerAffiliateReferral(string $affiliateRef, int $leadId, string $customerName, string $customerPhone, string $planName = 'Gói Quản Gia', float $amount = 0.0): ?int
    {
        try {
            $affiliateRef = trim($affiliateRef);
            if ($affiliateRef === '') {
                return null;
            }

            // 1. Tìm Affiliate theo mã hoặc tên
            $searchPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'strive.affiliate.affiliate',
                        'search_read',
                        [[['code', '=', $affiliateRef]]],
                        ['fields' => ['id', 'name', 'code', 'program_id'], 'limit' => 1]
                    ]
                ]
            ];

            $searchRes = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchPayload);
            $affRecord = null;
            if ($searchRes->successful() && !empty($searchRes->json()['result'])) {
                $affRecord = $searchRes->json()['result'][0];
            } else {
                $searchNamePayload = [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'object',
                        'method' => 'execute_kw',
                        'args' => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'strive.affiliate.affiliate',
                            'search_read',
                            [[['name', 'ilike', $affiliateRef]]],
                            ['fields' => ['id', 'name', 'code', 'program_id'], 'limit' => 1]
                        ]
                    ]
                ];
                $searchNameRes = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchNamePayload);
                if ($searchNameRes->successful() && !empty($searchNameRes->json()['result'])) {
                    $affRecord = $searchNameRes->json()['result'][0];
                }
            }

            if (!$affRecord || empty($affRecord['program_id'])) {
                \Illuminate\Support\Facades\Log::warning("[Odoo Affiliate Butler] Không tìm thấy Affiliate hợp lệ với mã: {$affiliateRef}");
                return null;
            }

            $programId = (int) $affRecord['program_id'][0];
            $affiliateId = (int) $affRecord['id'];
            $affName = (string) $affRecord['name'];

            // 2. Kiểm tra điều kiện: Hoa hồng 1 lần duy nhất cho khách hàng đầu tiên
            $cleanPhone = preg_replace('/[^0-9]/', '', $customerPhone);
            $existingRefPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'strive.affiliate.referral',
                        'search_count',
                        [[
                            ['reference', 'ilike', 'BUTLER/'],
                            ['name', 'ilike', $customerPhone],
                            ['status', '!=', 'cancelled']
                        ]]
                    ]
                ]
            ];
            $countRes = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $existingRefPayload);
            $existingCount = (int) ($countRes->json()['result'] ?? 0);

            if ($existingCount > 0) {
                \Illuminate\Support\Facades\Log::info("[Odoo Affiliate Butler] Khách hàng {$customerName} ({$customerPhone}) đã có đơn giới thiệu trước đó. Không tạo thêm hoa hồng (chính sách 1 lần duy nhất).");
                return null;
            }

            // Mức hoa hồng cố định: 300.000 VNĐ cho mỗi đơn giới thiệu đầu tiên thành công
            $commissionAmount = 300000.0;

            $baseAmt = $amount > 0 ? (float) $amount : 500000.0;
            $referralPayload = [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'strive.affiliate.referral',
                        'create',
                        [[
                            'name' => "Butler: {$customerName} ({$customerPhone}) - {$planName}",
                            'program_id' => $programId,
                            'affiliate_id' => $affiliateId,
                            'reference' => "BUTLER/{$leadId}/{$affiliateId}",
                            'description' => "Hoa hồng 1 lần giới thiệu đầu tiên: 300.000 VNĐ cho Aicenty Butler (aicenty.com/butler) - Gói: {$planName} (" . number_format($baseAmt, 0, ',', '.') . " VNĐ)",
                            'source' => 'website',
                            'status' => 'pending',
                            'date_created' => gmdate('Y-m-d H:i:s'),
                            'hnkt_commission_level' => 'direct',
                            'note' => "Chính sách Aicenty Butler: Hoa hồng cố định 300.000 VNĐ cho lần giới thiệu đầu tiên duy nhất cho tất cả các gói. Người giới thiệu: {$affName} ({$affiliateRef}).",
                            'line_ids' => [
                                [0, 0, [
                                    'name' => "Hoa hồng giới thiệu Aicenty Butler - {$planName}",
                                    'product_id' => 18,
                                    'rule_id' => 2,
                                    'quantity' => 1.0,
                                    'base_amount' => $baseAmt,
                                    'rate_type' => 'fixed',
                                    'flat_amount' => 300000.0,
                                    'commission_amount' => 300000.0,
                                ]]
                            ]
                        ]]
                    ]
                ]
            ];

            $refRes = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $referralPayload);
            if ($refRes->successful() && isset($refRes->json()['result'])) {
                $refId = (int) $refRes->json()['result'];
                \Illuminate\Support\Facades\Log::info("[Odoo Affiliate Butler] Đã tạo thành công Affiliate Referral ID {$refId} (Hoa hồng cố định: 300.000 VNĐ) cho Affiliate {$affName} ({$affiliateRef})");
                return $refId;
            }
            \Illuminate\Support\Facades\Log::warning("[Odoo Affiliate Butler] Odoo trả về lỗi: " . json_encode($refRes->json()['error'] ?? 'Unknown'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[Odoo Affiliate Butler] Lỗi khi tạo Referral Butler: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Cập nhật thông tin Onboarding vào Lead gốc trên Odoo CRM erp.hnkt.vn
     */
    public function updateButlerLeadOnboarding(array $data): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $familyName = trim((string) ($data['family_name'] ?? ('Gia Đình ' . $name)));
        $butlerName = trim((string) ($data['butler_name'] ?? 'Bác Phúc'));
        $childInfo = trim((string) ($data['child_info'] ?? 'Chưa rõ'));
        $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện'));
        $notes = trim((string) ($data['notes'] ?? ''));
        $now = now()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s');

        $onboardingHtml = "<div style='margin-top: 15px; padding: 12px; background: #f0fdf4; border-left: 4px solid #16a34a;'>"
            . "<h4 style='margin: 0 0 8px; color: #166534;'>HỒ SƠ ONBOARDING QUA ZALO BUTLER GURU ({$now}):</h4>"
            . "<p><strong>Tên Tổ Ấm:</strong> {$familyName}</p>"
            . "<p><strong>Quản Gia mong muốn:</strong> {$butlerName}</p>"
            . "<p><strong>Thông tin con cái & môn học:</strong> {$childInfo}</p>"
            . "<p><strong>Nhu cầu ưu tiên hàng đầu:</strong> {$primaryConcern}</p>"
            . ($notes !== '' ? "<p><strong>Ghi chú thêm:</strong> {$notes}</p>" : '')
            . "</div>";

        try {
            // 1. Search for lead by phone
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            $searchPayload = [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'params'  => [
                    'service' => 'object',
                    'method'  => 'execute_kw',
                    'args'    => [
                        $this->db,
                        $this->uid,
                        $this->apiKey,
                        'crm.lead',
                        'search',
                        [[['phone', 'ilike', $cleanPhone]]],
                        ['limit' => 1]
                    ]
                ]
            ];

            $searchRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $searchPayload);
            $leadId = null;
            if ($searchRes->successful()) {
                $resData = $searchRes->json();
                if (!empty($resData['result']) && is_array($resData['result'])) {
                    $leadId = (int) $resData['result'][0];
                }
            }

            if ($leadId) {
                // Fetch existing description
                $readPayload = [
                    'jsonrpc' => '2.0',
                    'method'  => 'call',
                    'params'  => [
                        'service' => 'object',
                        'method'  => 'execute_kw',
                        'args'    => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'crm.lead',
                            'read',
                            [[$leadId]],
                            ['fields' => ['description']]
                        ]
                    ]
                ];
                $readRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $readPayload);
                $oldDesc = '';
                if ($readRes->successful()) {
                    $rData = $readRes->json();
                    if (!empty($rData['result'][0]['description'])) {
                        $oldDesc = $rData['result'][0]['description'];
                    }
                }

                $updatedDesc = $oldDesc . "<br/>" . $onboardingHtml;

                // Write update
                $writePayload = [
                    'jsonrpc' => '2.0',
                    'method'  => 'call',
                    'params'  => [
                        'service' => 'object',
                        'method'  => 'execute_kw',
                        'args'    => [
                            $this->db,
                            $this->uid,
                            $this->apiKey,
                            'crm.lead',
                            'write',
                            [[$leadId], ['description' => $updatedDesc]]
                        ]
                    ]
                ];

                $writeRes = Http::withoutVerifying()->timeout(10)->asJson()->post("{$this->url}/jsonrpc", $writePayload);
                if ($writeRes->successful()) {
                    Log::info("[Odoo CRM Sync] Đã cập nhật Onboarding vào Lead ID {$leadId} thành công!");
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("[Odoo CRM Sync] Lỗi cập nhật onboarding Odoo: " . $e->getMessage());
            return false;
        }
    }
}
