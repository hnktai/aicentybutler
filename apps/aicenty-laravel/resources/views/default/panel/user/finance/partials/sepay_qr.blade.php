@php
    $bankCode = $gateway->live_client_id;
    $accountNumber = $gateway->live_app_id;
    $accountHolder = $gateway->bank_account_details;
@endphp

<div class="rounded-lg border p-4 md:p-6">
    <div class="grid gap-6 md:grid-cols-[320px_1fr]">
        <div class="flex justify-center">
            <img
                class="h-auto w-full max-w-[300px] rounded-lg border bg-white p-2"
                src="{{ $qrUrl }}"
                alt="{{ __('Sepay payment QR code') }}"
            >
        </div>

        <div class="space-y-4">
            <div>
                <p class="mb-1 text-sm opacity-70">{{ __('Amount to transfer') }}</p>
                <p class="m-0 text-2xl font-bold text-primary">{{ number_format($qrAmount) }} VND</p>
            </div>

            <div>
                <p class="mb-1 text-sm opacity-70">{{ __('Transfer content') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <code class="rounded-md bg-foreground/5 px-3 py-2 text-base font-semibold">{{ $order_id }}</code>
                    <button
                        class="btn btn-sm btn-outline-primary"
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $order_id }}'); toastr.success('{{ __('Copied') }}')"
                    >
                        {{ __('Copy') }}
                    </button>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <p class="mb-1 text-sm opacity-70">{{ __('Bank') }}</p>
                    <p class="m-0 font-medium">{{ $bankCode }}</p>
                </div>
                <div>
                    <p class="mb-1 text-sm opacity-70">{{ __('Account Number') }}</p>
                    <p class="m-0 font-medium">{{ $accountNumber }}</p>
                </div>
                @if ($accountHolder)
                    <div class="sm:col-span-2">
                        <p class="mb-1 text-sm opacity-70">{{ __('Account Holder') }}</p>
                        <p class="m-0 font-medium">{{ $accountHolder }}</p>
                    </div>
                @endif
            </div>

            <x-alert>
                <p class="mb-0">
                    {!! $gateway->bank_account_other ?: __('Please scan the QR code or transfer the exact amount shown. Keep the transfer content unchanged so the system can reconcile the payment and activate your plan automatically after the bank confirms the transaction.') !!}
                </p>
            </x-alert>
        </div>
    </div>
</div>
