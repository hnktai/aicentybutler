<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\HomepageContactController;
use App\Http\Controllers\PartnerContactController;
use App\Http\Controllers\ButlerTrialController;

Route::get('/', [PageController::class, 'index'])->name('home');
Route::get('contact', [PageController::class, 'contact'])->name('contact');

Route::get('partner', function () { return response()->file(public_path('partner/index.html'), ['Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0']); });
Route::post('partner/register', [PartnerContactController::class, 'store'])->middleware('throttle:10,1')->name('partner.register');

Route::get('butler-affiliate', function () { 
    return response()->file(public_path('butler-affiliate/index.html'), [
        'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ]); 
});

Route::get('butler', function () { 
    return response()->file(public_path('butler/index.html'), [
        'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ]); 
});
Route::get('butler/terms', function () { 
    return response()->file(public_path('butler/terms.html'), [
        'Content-Type' => 'text/html; charset=UTF-8'
    ]);
});

Route::get('butler/privacy-policy', function () { 
    return response()->file(public_path('butler/privacy-policy.html'), [
        'Content-Type' => 'text/html; charset=UTF-8'
    ]);
});

Route::get('butler/thank-you', function () { 
    return response()->file(public_path('butler/thank-you.html'), [
        'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ]); 
});
Route::get('butler/payment', function () { 
    return response()->file(public_path('butler/payment.html'), [
        'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0'
    ]); 
});

Route::post('butler/register', [ButlerTrialController::class, 'store'])->middleware('throttle:10,1')->name('butler.register');
Route::post('butler/purchase', [ButlerTrialController::class, 'purchase'])->middleware('throttle:10,1')->name('butler.purchase');

Route::post('homepage/contact', [HomepageContactController::class, 'store'])->middleware('throttle:5,1')->name('homepage.contact');
Route::redirect('pricing', '/#pricing')->name('pricing');

Route::controller(PageController::class)->group(function () {
    Route::get('{slug}', 'show')->name('page.show');
});


Route::post('api/butler/onboarding-complete', [ButlerTrialController::class, 'completeOnboarding'])->middleware('throttle:30,1')->name('butler.onboarding.complete');
Route::post('api/sepay/webhook', [ButlerTrialController::class, 'sepayWebhook'])->middleware('throttle:60,1')->name('butler.sepay.webhook');
Route::get('api/butler/payment-status', [ButlerTrialController::class, 'checkPaymentStatus'])->middleware('throttle:30,1')->name('butler.payment.status');
Route::post('webhooks/sepay', [ButlerTrialController::class, 'sepayWebhook'])->middleware('throttle:60,1')->name('butler.sepay.webhook.webhooks');
Route::post('api/v1/billing/webhook/sepay', [ButlerTrialController::class, 'sepayWebhook'])->middleware('throttle:60,1')->name('butler.sepay.webhook.billing');

Route::post('api/butler/family-member/add', [ButlerTrialController::class, 'addFamilyMember'])->name('butler.family.add');
Route::post('api/butler/dedicated-sim/register', [ButlerTrialController::class, 'registerDedicatedSim'])->name('butler.sim.register');

// --- ZALO OA INTEGRATION ROUTES (MULTI-PRODUCT & ONBOARDING) ---
Route::prefix('api/zalo-oa')->group(function () {
    Route::get('oauth/redirect', [App\Http\Controllers\ZaloOAController::class, 'oauthRedirect'])->name('zalo.oa.redirect');
    Route::get('oauth/callback', [App\Http\Controllers\ZaloOAController::class, 'oauthCallback'])->name('zalo.oa.callback');
    Route::get('status', [App\Http\Controllers\ZaloOAController::class, 'status'])->name('zalo.oa.status');
    Route::match(['get', 'post'], 'webhook', [App\Http\Controllers\ZaloOAController::class, 'webhook'])->name('zalo.oa.webhook');
});
