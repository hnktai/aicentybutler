<div @class([
    'flex h-full flex-col rounded-3xl border border-black/10 bg-background px-5 py-5 text-center shadow-[0_7px_20px_rgba(0,0,0,0.04)] transition-all duration-200 sm:px-6 sm:py-6 lg:px-6 lg:py-6',
    'ring-1 ring-[#684AE2]/15 shadow-[0_14px_30px_rgba(104,74,226,0.10)]' => $plan->is_featured,
])>
    <h6 class="mb-3 inline-flex self-center rounded-full border border-black/10 bg-black/5 px-3 py-1 text-[12px] font-semibold leading-none text-heading-foreground/80">{{ __($plan->name) }}</h6>
    <p class="mb-1 text-[28px] font-semibold leading-none tracking-normal text-heading-foreground sm:text-[30px] lg:text-[32px] xl:text-[34px]">
		{!! displayPlanPrice($plan, currency()) !!}
    </p>
    <p class="mb-4 text-[12px] font-medium opacity-60">{{ __($period) }}</p>
    <a
        class="block w-full rounded-xl bg-black/5 px-4 py-2 text-[13px] font-semibold text-heading-foreground transition-colors hover:bg-black hover:text-white"
        href="{{ route('register', ['plan' => $plan->id]) }}"
    >{{ __('Select').'  '.__($plan->name) }}</a>

    <x-plan-details-card
        :plan="$plan"
        :period="$period"
    />
</div>
