<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ZaloOAService
{
    protected string $appId;
    protected string $secretKey;
    protected string $redirectUri;
    protected string $tokenStoragePath;

    public function __construct()
    {
        $this->appId = (string) config('services.zalo.app_id', env('ZALO_OA_APP_ID', '2145957057911454748'));
        $this->secretKey = (string) config('services.zalo.secret_key', env('ZALO_OA_SECRET_KEY', ''));
        $this->redirectUri = (string) config('services.zalo.redirect_uri', env('ZALO_OA_REDIRECT_URI', 'https://aicenty.com/api/zalo-oa/oauth/callback'));
        $this->tokenStoragePath = storage_path('app/zalo_oa_token.json');
    }

    /**
     * Sinh link Authorization OAuth để Admin Zalo OA cấp quyền
     */
    public function getAuthorizationUrl(): string
    {
        $params = [
            'app_id'       => $this->appId,
            'redirect_uri' => $this->redirectUri,
        ];
        return 'https://oauth.zaloapp.com/v4/oa/permission?' . http_build_query($params);
    }

    /**
     * Đổi mã authorization code lấy access_token và refresh_token
     */
    public function exchangeAuthCode(string $code): array
    {
        try {
            $response = Http::asForm()
                ->withHeaders(['secret_key' => $this->secretKey])
                ->post('https://oauth.zaloapp.com/v4/oa/access_token', [
                    'code'          => $code,
                    'app_id'        => $this->appId,
                    'grant_type'    => 'authorization_code',
                ]);

            $data = $response->json();
            if (!empty($data['access_token'])) {
                $this->saveTokenData($data);
                Log::info('[ZaloOA] Đổi code lấy token thành công', ['oa_id' => $data['oa_id'] ?? null]);
                return ['success' => true, 'data' => $data];
            }

            Log::error('[ZaloOA] Đổi code thất bại', ['response' => $data]);
            return ['success' => false, 'error' => $data['error_description'] ?? ($data['message'] ?? 'Unknown error')];
        } catch (\Throwable $e) {
            Log::error('[ZaloOA] Ngoại lệ exchangeAuthCode: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tự động làm mới Access Token bằng Refresh Token
     */
    public function refreshAccessToken(): ?string
    {
        $tokens = $this->loadTokenData();
        if (empty($tokens['refresh_token'])) {
            Log::error('[ZaloOA] Không tìm thấy refresh_token để làm mới.');
            return null;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['secret_key' => $this->secretKey])
                ->post('https://oauth.zaloapp.com/v4/oa/access_token', [
                    'refresh_token' => $tokens['refresh_token'],
                    'app_id'        => $this->appId,
                    'grant_type'    => 'refresh_token',
                ]);

            $data = $response->json();
            if (!empty($data['access_token'])) {
                $this->saveTokenData($data);
                Log::info('[ZaloOA] Tự động làm mới access_token thành công.');
                return $data['access_token'];
            }

            Log::error('[ZaloOA] Làm mới access_token thất bại', ['response' => $data]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[ZaloOA] Ngoại lệ refreshAccessToken: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Lấy Access Token còn hiệu lực (tự refresh nếu sắp hết hạn)
     */
    public function getValidAccessToken(): ?string
    {
        $tokens = $this->loadTokenData();
        if (empty($tokens['access_token'])) {
            return null;
        }

        $expiresAt = $tokens['expires_at'] ?? 0;
        // Nếu còn dưới 10 phút là hết hạn thì refresh ngay
        if (time() >= ($expiresAt - 600)) {
            return $this->refreshAccessToken();
        }

        return $tokens['access_token'];
    }

    /**
     * Lấy thông tin người dùng từ Zalo OA (Tên, Ảnh đại diện) - Hỗ trợ chuẩn V3 mới nhất
     */
    public function getUserProfile(string $userIdByOa): ?array
    {
        $token = $this->getValidAccessToken();
        if (!$token) {
            return null;
        }

        // 1. Thử qua V3 chuẩn mới nhất
        try {
            $response = Http::withHeaders(['access_token' => $token])
                ->get('https://openapi.zalo.me/v3.0/oa/user/detail', [
                    'data' => json_encode(['user_id' => $userIdByOa])
                ]);

            $json = $response->json();
            if (($json['error'] ?? 1) === 0 && !empty($json['data'])) {
                return $json['data'];
            }
        } catch (\Throwable $e) {
            Log::warning('[ZaloOA V3] Không lấy được profile user ' . $userIdByOa . ': ' . $e->getMessage());
        }

        // 2. Dự phòng V2
        try {
            $response = Http::withHeaders(['access_token' => $token])
                ->get('https://openapi.zalo.me/v2.0/oa/getprofile', [
                    'data' => json_encode(['user_id' => $userIdByOa])
                ]);

            $json = $response->json();
            if (($json['error'] ?? 1) === 0 && !empty($json['data'])) {
                return $json['data'];
            }
        } catch (\Throwable $e) {
            Log::warning('[ZaloOA V2] Không lấy được profile user ' . $userIdByOa . ': ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Gửi tin nhắn phản hồi CSKH (tối đa 48h từ lúc khách nhắn)
     */
    public function sendTextMessage(string $userIdByOa, string $text): array
    {
        $token = $this->getValidAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Chưa cấu hình hoặc token hết hạn'];
        }

        try {
            $payload = [
                'recipient' => [
                    'user_id' => $userIdByOa,
                ],
                'message' => [
                    'text' => $text,
                ],
            ];

            $response = Http::withHeaders([
                'access_token' => $token,
                'Content-Type' => 'application/json',
            ])->post('https://openapi.zalo.me/v3.0/oa/message/cs', $payload);

            $data = $response->json();
            if (($data['error'] ?? 1) === 0) {
                return ['success' => true, 'data' => $data];
            }

            Log::error('[ZaloOA] Gửi tin nhắn thất bại', ['response' => $data, 'user_id' => $userIdByOa]);
            return ['success' => false, 'error' => $data['message'] ?? 'Gửi tin thất bại'];
        } catch (\Throwable $e) {
            Log::error('[ZaloOA] Ngoại lệ sendTextMessage: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lưu trữ Token vào file JSON bảo mật
     */
    protected function saveTokenData(array $newData): void
    {
        $existing = $this->loadTokenData();
        $expiresIn = (int) ($newData['expires_in'] ?? 90000);

        $merged = array_merge($existing, $newData, [
            'expires_at' => time() + $expiresIn,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        File::ensureDirectoryExists(dirname($this->tokenStoragePath));
        File::put($this->tokenStoragePath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Đọc Token từ file JSON
     */
    public function loadTokenData(): array
    {
        if (File::exists($this->tokenStoragePath)) {
            $json = json_decode(File::get($this->tokenStoragePath), true);
            return is_array($json) ? $json : [];
        }
        return [];
    }

    /**
     * Gửi tin nhắn ZNS (Zalo Notification Service) chủ động đến số điện thoại.
     * Yêu cầu template đã được duyệt trên Zalo Cloud (zalo.cloud / ZBS).
     */
    public function sendZNS(string $phone, string $templateId, array $templateData = [], ?string $trackingId = null): array
    {
        $tokens = $this->loadTokenData();
        $accessToken = $tokens['access_token'] ?? null;
        if (empty($accessToken)) {
            Log::error('[ZaloOA ZNS] Không có Access Token để gửi ZNS');
            return ['error' => -1, 'message' => 'Missing access token'];
        }

        // Chuẩn hóa số điện thoại Việt Nam sang 84xxxxxxxxx
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '84' . substr($cleanPhone, 1);
        }

        $payload = [
            'phone'         => $cleanPhone,
            'template_id'   => $templateId,
            'template_data' => $templateData,
        ];
        if ($trackingId) {
            $payload['tracking_id'] = $trackingId;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'access_token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://business.openapi.zalo.me/message/template', $payload);

            $data = $response->json();
            Log::info("[ZaloOA ZNS] Gửi ZNS tới {$cleanPhone} (Template: {$templateId}): ", $data ?? []);
            return $data ?? ['error' => -1, 'message' => 'Empty response'];
        } catch (\Throwable $e) {
            Log::error('[ZaloOA ZNS] Ngoại lệ khi gửi ZNS: ' . $e->getMessage());
            return ['error' => -999, 'message' => $e->getMessage()];
        }
    }

}
