<?php

use App\Extensions\Cryptomus\System\Services\CryptomusService;
use App\Services\PaymentGateways\CoinbaseService;
use App\Services\PaymentGateways\CoingateService;
use App\Services\PaymentGateways\FreeService;
use App\Services\PaymentGateways\IyzicoService;
use App\Services\PaymentGateways\MidtransService;
use App\Services\PaymentGateways\PaddleService;
use App\Services\PaymentGateways\PayPalService;
use App\Services\PaymentGateways\PaystackService;
use App\Services\PaymentGateways\RazorpayService;
use App\Services\PaymentGateways\RevenueCatService;
use App\Services\PaymentGateways\SepayService;
use App\Services\PaymentGateways\StripeService;
use App\Services\PaymentGateways\TransferService;
use App\Services\PaymentGateways\YokassaService;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect'      => '/github/callback',
    ],
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => '/google/callback',
    ],
    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => '/facebook/callback',
    ],
    'apple' => [
        'client_id' => env('APPLE_BUNDLE_ID'),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),
    ],
    'recaptcha' => [
        'key'    => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways Services
    |--------------------------------------------------------------------------
    */

    'stripe' => [
        'class' => StripeService::class,
    ],

    'paypal' => [
        'class' => PayPalService::class,
    ],

    'paystack' => [
        'class' => PaystackService::class,
    ],

    'yokassa' => [
        'class' => YokassaService::class,
    ],

    'iyzico' => [
        'class' => IyzicoService::class,
    ],

    'razorpay' => [
        'class' => RazorpayService::class,
    ],

    'banktransfer' => [
        'class' => TransferService::class,
    ],

    'sepay' => [
        'class'           => SepayService::class,
        'usd_to_vnd_rate' => env('SEPAY_USD_TO_VND_RATE', 26800),
    ],

    'freeservice' => [
        'class' => FreeService::class,
    ],

    'revenuecat' => [
        'class' => RevenueCatService::class,
    ],

    'coinbase' => [
        'class' => CoinbaseService::class,
    ],

    'coingate' => [
        'class' => CoingateService::class,
    ],

    'paddle' => [
        'class' => PaddleService::class,
    ],

    'cryptomus' => [
        'class' => CryptomusService::class,
    ],

    'midtrans' => [
        'class' => MidtransService::class,
    ],
    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'from_address' => env('RESEND_FROM_ADDRESS', 'ai@aicenty.com'),
        'from_name' => env('RESEND_FROM_NAME', 'Aicenty'),
        'admin_recipients' => array_values(array_filter(array_map('trim', explode(',', env('RESEND_ADMIN_RECIPIENTS', ''))))),
    ],
    'homepage' => [
        'zoom_url' => env('HOMEPAGE_ZOOM_URL'),
        'zoom_passcode' => env('HOMEPAGE_ZOOM_PASSCODE'),
    ],
    'butler' => [
        'sepay_forward_key'   => env('BUTLER_SEPAY_FORWARD_KEY', ''),
        'sepay_inbound_key'   => env('BUTLER_SEPAY_INBOUND_KEY', ''),
        'sepay_webhook_token' => env('SEPAY_WEBHOOK_TOKEN', ''),
    ],
];
