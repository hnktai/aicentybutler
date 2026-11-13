@extends('panel.layout.app')
@section('title', __('Subscription Payment'))
@section('titlebar_actions', '')

@section('content')
    <div class="py-10">
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-sm-8 col-lg-8">
                    @include('panel.user.finance.coupon.index')

                    <form
                        id="sepay-form"
                        action="{{ route('dashboard.user.payment.subscription.checkout', ['gateway' => 'sepay']) }}"
                        method="post"
                    >
                        @csrf
                        <input
                            type="hidden"
                            name="planID"
                            value="{{ $plan->id }}"
                        >
                        <input
                            type="hidden"
                            name="orderID"
                            value="{{ $order_id }}"
                        >
                        <input
                            type="hidden"
                            name="gateway"
                            value="sepay"
                        >

                        @include('panel.user.finance.partials.sepay_qr')

                        <x-button
                            class="mt-4 w-full"
                            variant="primary"
                            type="submit"
                        >
                            {{ __('I have completed the bank transfer') }}
                        </x-button>
                    </form>

                    <p class="mt-4">{{ __('By purchasing you confirm our') }} <a href="{{ url('/') . '/terms' }}">{{ __('Terms and Conditions') }}</a></p>
                </div>
                <div class="col-sm-4 col-lg-4">
                    @include('panel.user.finance.partials.plan_card')
                </div>
            </div>
        </div>
    </div>
@endsection
