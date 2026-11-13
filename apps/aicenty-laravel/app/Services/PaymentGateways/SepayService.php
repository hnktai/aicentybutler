<?php

namespace App\Services\PaymentGateways;

use App\Actions\CreateActivity;
use App\Actions\EmailPaymentConfirmation;
use App\Enums\Plan\FrequencyEnum;
use App\Models\Coupon;
use App\Models\GatewayProducts;
use App\Models\Gateways;
use App\Models\Plan;
use App\Models\SepayTransaction;
use App\Models\Setting;
use App\Models\Usage;
use App\Models\User;
use App\Models\UserOrder;
use App\Services\Common\MenuService;
use App\Services\PaymentGateways\Contracts\CreditUpdater;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Cashier\Subscription as Subscriptions;
use RuntimeException;

class SepayService
{
    use CreditUpdater;

    protected static string $GATEWAY_CODE = 'sepay';

    protected static string $GATEWAY_NAME = 'Sepay Bank Transfer';

    public static function saveAllProducts()
    {
        Gateways::where('code', self::$GATEWAY_CODE)->where('is_active', 1)->first() ?? abort(404);

        try {
            Plan::query()
                ->where('active', 1)
                ->each(static fn ($plan) => self::saveProduct($plan));
        } catch (Exception $ex) {
            Log::error(self::$GATEWAY_CODE . '-> saveAllProducts(): ' . $ex->getMessage());

            return back()->with(['message' => $ex->getMessage(), 'type' => 'error']);
        }
    }

    public static function getPlansPriceIdsForMigration(): null
    {
        return null;
    }

    public static function getUsersCustomerIdsForMigration(Subscriptions $subscription): null
    {
        return null;
    }

    public static function saveProduct($plan)
    {
        try {
            GatewayProducts::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'gateway_code' => self::$GATEWAY_CODE],
                [
                    'plan_name'     => $plan->name,
                    'gateway_title' => self::$GATEWAY_NAME,
                    'product_id'    => 'SEP-' . strtoupper(Str::random(13)),
                    'price_id'      => 'Not Needed',
                ]
            );
        } catch (Exception $ex) {
            Log::error(self::$GATEWAY_CODE . '-> saveProduct(): ' . $ex->getMessage());

            return back()->with(['message' => $ex->getMessage(), 'type' => 'error']);
        }
    }

    public static function subscribe($plan): View|RedirectResponse
    {
        return self::paymentPage($plan, 'subscription');
    }

    public static function prepaid($plan): View|RedirectResponse
    {
        return self::paymentPage($plan, 'prepaid');
    }

    public static function subscribeCheckout(Request $request, $referral = null)
    {
        return self::waitingRedirect();
    }

    public static function prepaidCheckout(Request $request, $referral = null)
    {
        return self::waitingRedirect();
    }

    public static function getSubscriptionStatus($incomingUserId = null): bool
    {
        $userId = $incomingUserId ?? Auth::id();

        if (! $userId) {
            return false;
        }

        return Subscriptions::query()
            ->where('user_id', $userId)
            ->where('paid_with', self::$GATEWAY_CODE)
            ->where('stripe_status', 'sepay_approved')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->exists();
    }

    public static function getSubscriptionDaysLeft(): int
    {
        $sub = getCurrentActiveSubscription(Auth::id());

        if (! $sub?->ends_at) {
            return 0;
        }

        return Carbon::now()->diffInDays(Carbon::parse($sub->ends_at), false);
    }

    public static function subscribeCancel($internalUser = null)
    {
        $user = $internalUser ?? Auth::user();
        $activeSub = getCurrentActiveSubscription($user?->id);

        if ($activeSub && $activeSub->paid_with === self::$GATEWAY_CODE) {
            $plan = Plan::query()->find($activeSub->plan_id);
            if ($plan && $user) {
                self::creditDecreaseCancelPlan($user, $plan);
            }

            $activeSub->stripe_status = 'sepay_cancelled';
            $activeSub->ends_at = Carbon::now();
            $activeSub->save();
            Cache::forget('current_active_subscription_user_' . $user?->id);

            return back()->with(['message' => __('Your subscription is cancelled succesfully.'), 'type' => 'success']);
        }

        return back()->with(['message' => __('Could not find active subscription. Nothing changed!'), 'type' => 'error']);
    }

    public static function checkIfTrial(): bool
    {
        return false;
    }

    public static function getSubscriptionRenewDate(): ?string
    {
        $activeSub = getCurrentActiveSubscription(Auth::id());

        return $activeSub?->ends_at ? Carbon::parse($activeSub->ends_at)->format('F jS, Y') : null;
    }

    public static function cancelSubscribedPlan($subscription, $planId): bool
    {
        try {
            $subscription = $subscription instanceof Subscriptions
                ? $subscription
                : Subscriptions::query()->find($subscription);

            if (! $subscription) {
                return false;
            }

            $user = User::query()->find($subscription->user_id);
            $plan = Plan::query()->find($planId);

            if ($user && $plan) {
                self::creditDecreaseCancelPlan($user, $plan);
            }

            $subscription->stripe_status = 'sepay_cancelled';
            $subscription->ends_at = Carbon::now();
            $subscription->save();
            Cache::forget('current_active_subscription_user_' . $subscription->user_id);

            return true;
        } catch (Exception $th) {
            Log::error(self::$GATEWAY_CODE . ' cancelSubscribedPlan(): ' . $th->getMessage());

            return false;
        }
    }

    public static function handleWebhook(Request $request): JsonResponse
    {
        try {
            $gateway = self::gateway();
            $body = $request->getContent();

            if (! self::validSignature($request, $body, (string) $gateway->webhook_secret)) {
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
            }

            $payload = json_decode($body, true);

            if (! is_array($payload)) {
                return response()->json(['success' => false, 'message' => 'Invalid JSON'], 400);
            }

            self::processWebhookPayload($payload, $gateway);

            return response()->json(['success' => true]);
        } catch (Exception $th) {
            Log::error(self::$GATEWAY_CODE . ' webhook: ' . $th->getMessage(), [
                'payload' => $payload ?? null,
            ]);

            return response()->json(['success' => false, 'message' => 'Webhook processing failed'], 500);
        }
    }

    public static function createWebhook(): null
    {
        return null;
    }

    private static function paymentPage(Plan $plan, string $type): View|RedirectResponse
    {
        $gateway = self::gateway();

        if (blank($gateway->live_client_id) || blank($gateway->live_app_id)) {
            return back()->with([
                'message' => __('Please configure Sepay bank code and bank account number before enabling this gateway.'),
                'type'    => 'error',
            ]);
        }

        try {
            $payment = self::createPendingPayment($plan, $gateway, $type);

            return view(
                'panel.user.finance.' . ($type === 'subscription' ? 'subscription' : 'prepaid') . '.' . self::$GATEWAY_CODE,
                $payment
            );
        } catch (Exception $th) {
            Log::error(self::$GATEWAY_CODE . '-> paymentPage(): ' . $th->getMessage());

            return back()->with(['message' => Str::before($th->getMessage(), ':'), 'type' => 'error']);
        }
    }

    private static function createPendingPayment(Plan $plan, Gateways $gateway, string $type): array
    {
        $user = Auth::user();
        $coupon = checkCouponInRequest();
        $amounts = self::amounts($plan, $gateway, $coupon);
        $orderId = self::makeOrderId();
        $settings = Setting::getCache();
        $qrUrl = self::qrUrl($gateway, $amounts['qr_amount'], $orderId);
        $payload = [
            'sepay' => [
                'status'          => 'waiting',
                'qr_url'          => $qrUrl,
                'coupon_code'     => $coupon?->code,
                'coupon_discount' => $coupon?->discount,
            ],
        ];

        DB::beginTransaction();

        try {
            if ($type === 'subscription') {
                Subscriptions::query()->create([
                    'user_id'       => $user->id,
                    'name'          => $plan->id,
                    'stripe_id'     => $orderId,
                    'stripe_status' => 'sepay_waiting',
                    'stripe_price'  => 'Not Needed',
                    'quantity'      => 1,
                    'trial_ends_at' => null,
                    'ends_at'       => self::endsAtFor($plan),
                    'tax_rate'      => $gateway->tax,
                    'tax_value'     => $amounts['tax_value'],
                    'coupon'        => $coupon?->discount,
                    'total_amount'  => $amounts['total'],
                    'plan_id'       => $plan->id,
                    'paid_with'     => self::$GATEWAY_CODE,
                    'auto_renewal'  => 0,
                ]);
            }

            UserOrder::query()->create([
                'order_id'           => $orderId,
                'plan_id'            => $plan->id,
                'user_id'            => $user->id,
                'type'               => $type === 'subscription' ? 'subscription' : 'prepaid',
                'payment_type'       => self::$GATEWAY_CODE,
                'price'              => $amounts['total'],
                'affiliate_earnings' => ($amounts['total'] * $settings->affiliate_commission_percentage) / 100,
                'status'             => 'Waiting',
                'country'            => $user->country ?? 'Unknown',
                'tax_rate'           => $gateway->tax,
                'tax_value'          => $amounts['tax_value'],
                'payload'            => $payload,
            ]);

            DB::commit();
        } catch (Exception $th) {
            DB::rollBack();

            throw $th;
        }

        CreateActivity::for($user, __('initiated a Sepay bank transfer payment.'), $plan->name . ' ' . __('Plan'));

        return [
            'plan'               => $plan,
            'gateway'            => $gateway,
            'order_id'           => $orderId,
            'qrUrl'              => $qrUrl,
            'amount'             => $amounts['total'],
            'qrAmount'           => $amounts['qr_amount'],
            'taxRate'            => $gateway->tax,
            'taxValue'           => $amounts['tax_value'],
            'newDiscountedPrice' => $amounts['sub_total'],
            'coupon'             => $coupon,
        ];
    }

    private static function processWebhookPayload(array $payload, Gateways $gateway): void
    {
        $transactionId = (string) data_get($payload, 'id', data_get($payload, 'referenceCode', ''));

        if (blank($transactionId)) {
            throw new Exception('Sepay transaction id is missing.');
        }

        DB::beginTransaction();

        $transaction = SepayTransaction::query()
            ->where('sepay_transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();

        if ($transaction && $transaction->status === 'approved') {
            DB::commit();

            return;
        }

        $orderId = self::extractOrderId($payload);
        $amountIn = (float) data_get($payload, 'transferAmount', data_get($payload, 'amount_in', 0));
        $amountOut = (float) data_get($payload, 'amount_out', 0);
        $transferType = strtolower((string) data_get($payload, 'transferType', ''));

        $transaction = SepayTransaction::query()->updateOrCreate(
            ['sepay_transaction_id' => $transactionId],
            [
                'order_id'       => $orderId,
                'reference_code' => data_get($payload, 'referenceCode'),
                'gateway'        => data_get($payload, 'gateway'),
                'account_number' => data_get($payload, 'accountNumber'),
                'sub_account'    => data_get($payload, 'subAccount'),
                'transfer_type'  => $transferType,
                'amount_in'      => $amountIn,
                'amount_out'     => $amountOut,
                'content'        => data_get($payload, 'content'),
                'status'         => 'received',
                'payload'        => $payload,
            ]
        );

        if ($transferType !== '' && $transferType !== 'in') {
            $transaction->update(['status' => 'ignored_outgoing']);
            DB::commit();

            return;
        }

        if (filled($gateway->live_app_id) && filled(data_get($payload, 'accountNumber')) && (string) $gateway->live_app_id !== (string) data_get($payload, 'accountNumber')) {
            $transaction->update(['status' => 'account_mismatch']);
            DB::commit();

            return;
        }

        $order = UserOrder::query()
            ->where('payment_type', self::$GATEWAY_CODE)
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            $txContent = (string) data_get($payload, 'content');
            $hasButlerMatch = preg_match('/(?:SUB|DK|VIP)\s*(?:\+?84|0)?[.\s-]*([35789](?:[.\s-]*[0-9]){8})/i', $txContent)
                || preg_match('/(?:\b|[^0-9])(?:\+?84|0)([35789][0-9]{8})(?:\b|[^0-9])/', ' ' . $txContent . ' ');

            if ($hasButlerMatch) {
                Log::info('[SepayService] Detected Butler VIP subscription payment: ' . $txContent . ', forwarding to ButlerTrialController::sepayWebhook');
                try {
                    $butlerReq = new Request($payload);
                    $fwdKey = (string) (config('services.butler.sepay_forward_key') ?: env('BUTLER_SEPAY_FORWARD_KEY', ''));
                    if ($fwdKey !== '') {
                        $butlerReq->headers->set('Authorization', 'Apikey ' . $fwdKey);
                    }
                    $btc = app(\App\Http\Controllers\ButlerTrialController::class);
                    $odooService = app(\App\Services\OdooCrmService::class);
                    $automationService = app(\App\Services\ButlerAutomationService::class);
                    $mailer = app(\App\Services\ButlerTrialMailer::class);

                    $btc->sepayWebhook($butlerReq, $odooService, $automationService, $mailer);

                    $transaction->update([
                        'status'  => 'approved',
                        'payload' => array_merge(is_array($payload) ? $payload : [], ['butler_activated' => true]),
                    ]);
                    DB::commit();

                    return;
                } catch (\Throwable $be) {
                    Log::error('[SepayService] Butler VIP forward error: ' . $be->getMessage());
                }
            }

            $transaction->update(['status' => 'unmatched']);
            DB::commit();

            return;
        }

        if ($amountIn + 0.0001 < (float) $order->price) {
            $transaction->update(['status' => 'underpaid']);
            $order->update([
                'payload' => array_merge($order->payload ?? [], [
                    'sepay_last_webhook' => $payload,
                    'sepay_error'        => 'underpaid',
                ]),
            ]);
            DB::commit();

            return;
        }

        if (in_array($order->status, ['Approved', 'Success', 'PAID'], true)) {
            $transaction->update(['status' => 'duplicate']);
            DB::commit();

            return;
        }

        self::approveOrder($order, $payload);
        $transaction->update(['status' => 'approved']);

        DB::commit();
    }

    private static function approveOrder(UserOrder $order, array $payload): void
    {
        $plan = $order->plan;
        $user = $order->user;

        if (! $plan || ! $user) {
            throw new Exception('Sepay order is missing user or plan.');
        }

        if ($order->type === 'subscription') {
            $subscription = Subscriptions::query()->where('stripe_id', $order->order_id)->first();

            if ($subscription) {
                $subscription->stripe_status = 'sepay_approved';
                $subscription->ends_at = self::endsAtFor($plan);
                $subscription->auto_renewal = 0;
                $subscription->save();
            }
        }

        $payloadData = $order->payload ?? [];
        $couponCode = data_get($payloadData, 'sepay.coupon_code');

        if ($couponCode) {
            $coupon = Coupon::query()->where('code', $couponCode)->first();
            $coupon?->usersUsed()->syncWithoutDetaching([$user->id]);
        }

        $payloadData['sepay_last_webhook'] = $payload;
        $payloadData['sepay']['status'] = 'approved';

        $order->status = 'Approved';
        $order->payload = $payloadData;
        $order->save();

        self::creditIncreaseSubscribePlan($user, $plan);
        Usage::getSingle()->updateSalesCount((float) $order->price);
        Cache::forget('current_active_subscription_user_' . $user->id);
        app(MenuService::class)->regenerate();

        CreateActivity::for($user, __('Purchased with approved Sepay bank transfer'), $plan->name . ' ' . __('Plan'));

        try {
            EmailPaymentConfirmation::create($user, $plan)->send();
        } catch (Exception $th) {
            Log::warning(self::$GATEWAY_CODE . ' confirmation email failed: ' . $th->getMessage());
        }
    }

    private static function amounts(Plan $plan, Gateways $gateway, $coupon = null): array
    {
        $planPrice = self::amountInVnd($plan);
        $subTotal = $planPrice;

        if ($coupon) {
            $subTotal -= ($planPrice * ((float) $coupon->discount / 100));
            $subTotal = max($subTotal, 0);
        }

        $taxValue = taxToVal($planPrice, (float) $gateway->tax);
        $total = $subTotal + $taxValue;

        return [
            'sub_total' => $subTotal,
            'tax_value' => $taxValue,
            'total'     => $total,
            'qr_amount' => (int) round($total),
        ];
    }

    private static function amountInVnd(Plan $plan): float
    {
        $amount = (float) $plan->price;
        $currency = strtoupper((string) ($plan->currency ?: 'VND'));

        if ($currency === 'VND') {
            return $amount;
        }

        $rate = (float) config('services.sepay.usd_to_vnd_rate', 26800);
        if ($rate <= 0) {
            throw new RuntimeException('Sepay USD to VND exchange rate is not configured.');
        }

        return $amount * $rate;
    }

    private static function qrUrl(Gateways $gateway, int $amount, string $orderId): string
    {
        $query = [
            'acc'      => preg_replace('/[^A-Za-z0-9]/', '', (string) $gateway->live_app_id),
            'bank'     => (string) $gateway->live_client_id,
            'amount'   => $amount,
            'des'      => $orderId,
            'template' => 'compact',
            'showinfo' => 'true',
            'fullacc'  => 'true',
        ];

        if (filled($gateway->bank_account_details)) {
            $query['holder'] = self::asciiLabel($gateway->bank_account_details);
        }

        $storeName = setting('site_name', 'AICENTY');
        if ($storeName) {
            $query['store'] = self::asciiLabel($storeName);
        }

        return 'https://vietqr.app/img?' . http_build_query($query);
    }

    private static function validSignature(Request $request, string $body, string $secret): bool
    {
        // 1. Hỗ trợ xác thực đa năng qua Header API Key / Bearer Token của SePay
        $authHeader = (string) ($request->header('Authorization') ?? $request->header('x-api-key') ?? '');
        $apiToken = (string) env('SEPAY_WEBHOOK_TOKEN', '');
        $provided = preg_replace('/^(?:Apikey|Bearer)\s+/i', '', trim($authHeader));
        if (($apiToken !== '' && hash_equals($apiToken, $provided))
            || ($secret !== '' && hash_equals($secret, $provided))) {
            return true;
        }

        // 2. Hỗ trợ xác thực chữ ký điện tử HMAC SHA-256
        if (blank($secret)) {
            return false;
        }

        $signature = (string) $request->header('X-SePay-Signature');
        $timestamp = (string) $request->header('X-SePay-Timestamp');

        if (blank($signature) || blank($timestamp)) {
            return false;
        }

        $timestampValue = (int) $timestamp;
        if ($timestampValue > 2000000000) {
            $timestampValue = (int) floor($timestampValue / 1000);
        }

        if ($timestampValue > 0 && abs(time() - $timestampValue) > 300) {
            return false;
        }

        $hash = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $expected = 'sha256=' . $hash;

        return hash_equals($expected, $signature) || hash_equals($hash, $signature);
    }

    private static function extractOrderId(array $payload): ?string
    {
        $code = strtoupper((string) data_get($payload, 'code', ''));
        if (preg_match('/AIC[A-Z0-9]{8,24}/', $code, $matches)) {
            return $matches[0];
        }

        $content = strtoupper((string) data_get($payload, 'content', ''));
        if (preg_match('/AIC[A-Z0-9]{8,24}/', $content, $matches)) {
            return $matches[0];
        }

        return blank($code) ? null : $code;
    }

    private static function endsAtFor(Plan $plan): Carbon
    {
        return match ($plan->frequency) {
            FrequencyEnum::YEARLY->value, FrequencyEnum::LIFETIME_YEARLY->value => Carbon::now()->addYears(1),
            FrequencyEnum::MONTHLY->value, FrequencyEnum::LIFETIME_MONTHLY->value => Carbon::now()->addMonths(1),
            default => Carbon::now()->addDays(30),
        };
    }

    private static function makeOrderId(): string
    {
        return 'AIC' . strtoupper(Str::random(12));
    }

    private static function asciiLabel(string $value): string
    {
        $value = strtoupper(Str::ascii($value));
        $value = preg_replace('/[^A-Z0-9 ]/', '', $value);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $value)), 80, '');
    }

    private static function gateway(): Gateways
    {
        $gateway = Gateways::query()->where('code', self::$GATEWAY_CODE)->where('is_active', 1)->first();

        if (! $gateway) {
            throw new RuntimeException('Sepay payment gateway is not active.');
        }

        return $gateway;
    }

    private static function waitingRedirect()
    {
        return redirect()->route('dashboard.user.payment.succesful')->with([
            'message' => __('We are waiting for your Sepay bank transfer. Your plan will be activated automatically after the transaction is confirmed.'),
            'type'    => 'success',
        ]);
    }
}
