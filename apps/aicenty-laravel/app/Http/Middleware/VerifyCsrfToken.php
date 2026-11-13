<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    use ChatbotCsrf;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/zalo-oa/*',
        'api/butler/onboarding-complete',
        'api/butler/family-member/add',
        'api/butler/dedicated-sim/register',
        'api/sepay/webhook',
        'api/v1/billing/webhook/sepay',
        'webhooks/sepay',
        'butler/register',
        'butler/purchase',
        'homepage/contact',
        'partner/register',
        'butler/register',
        // '*',
        'pdf/getContent',
        'stripe/*',
        'webhooks/*',
        'dashboard/*',
        'dashboard/user/payment/iyzico/*',
        'chatbot/*',
        'generator/webhook/fal-ai',
        'dashboard/admin/config/more',
        'translations/lang/update-all',
        'social-media/*',
        'chatbot/instagram/*',
    ];
}
