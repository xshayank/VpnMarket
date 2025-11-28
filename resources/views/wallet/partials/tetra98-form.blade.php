<div x-show="method === 'tetra98'" x-transition.opacity x-cloak class="space-y-6">
    <h3 class="text-lg font-semibold text-center">درگاه پرداخت (Tetra98)</h3>

    @php
        $tetraDefaultPhoneConfigured = $tetraSettings['default_phone_configured'] ?? false;
    @endphp

    <div class="bg-sky-50 dark:bg-sky-900/30 border border-sky-200 dark:border-sky-700 rounded-xl p-4 text-sm text-sky-800 dark:text-sky-100 space-y-2">
        @if(($chargeMode ?? 'wallet') === 'traffic')
            <p>مقدار ترافیک را به گیگابایت وارد کنید. مبلغ پرداختی به تومان محاسبه و برای درگاه ارسال می‌شود.</p>
            <p>حداقل خرید ترافیک: {{ number_format($minAmountGb ?? 0) }} گیگابایت. نرخ هر گیگابایت: {{ number_format($trafficPricePerGb ?? 750) }} تومان.</p>
        @else
            @if($tetraDefaultPhoneConfigured)
                <p>شماره موبایل اختیاری است. در صورت خالی گذاشتن، از شماره پیش‌فرض تنظیم شده استفاده می‌شود.</p>
            @else
                <p>شماره موبایل وارد شده باید با <strong>09</strong> شروع شده و ۱۱ رقم باشد.</p>
            @endif
        @endif
        <p>پس از تکمیل فرم، به صفحه پرداخت Tetra98 هدایت می‌شوید. لطفاً تا تأیید پرداخت منتظر بمانید.</p>
    </div>

    @if (session('tetra98_error'))
        <div class="bg-red-100 border border-red-300 text-red-800 rounded-xl p-4 text-sm">
            {{ session('tetra98_error') }}
        </div>
    @endif

    @if ($errors->has('tetra98'))
        <div class="bg-red-100 border border-red-300 text-red-800 rounded-xl p-4 text-sm">
            {{ $errors->first('tetra98') }}
        </div>
    @endif

    @if ($tetraHasOldContext)
        <div class="space-y-2">
            @error('amount')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            @error('phone')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <form class="space-y-6" method="POST" action="{{ route('wallet.charge.tetra98.initiate') }}">
        @csrf
        <input type="hidden" name="tetra98_context" value="1">

        <div class="relative space-y-2">
            @if(($chargeMode ?? 'wallet') === 'traffic')
                <label for="tetra98-traffic" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">
                    مقدار ترافیک (گیگابایت)
                </label>
                <input
                    id="tetra98-traffic"
                    name="traffic_gb"
                    type="number"
                    step="1"
                    min="{{ (int) ($minAmountGb ?? 0) }}"
                    x-model.number="trafficGb"
                    value="{{ $tetraHasOldContext ? old('traffic_gb', $minAmountGb ?? 0) : ($minAmountGb ?? 0) }}"
                    class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-sky-200 dark:border-sky-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="مثلاً 250"
                    required
                >
                <p class="text-xs text-center text-sky-800 dark:text-sky-100">
                    مبلغ قابل پرداخت: <span x-text="trafficAmountToman().toLocaleString('fa-IR')"></span> تومان
                </p>
            @else
                <label for="tetra98-amount" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">
                    مبلغ (تومان)
                </label>
                <input
                    id="tetra98-amount"
                    name="amount"
                    type="number"
                    min="{{ $tetraMinAmount }}"
                    x-model.number="tetraAmount"
                    value="{{ $tetraHasOldContext ? old('amount', $tetraMinAmount) : $tetraMinAmount }}"
                    class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-sky-200 dark:border-sky-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="حداقل {{ number_format($tetraMinAmount) }}"
                    required
                >
            @endif
        </div>

        <div class="relative">
            <label for="tetra98-phone" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">
                شماره موبایل خریدار @if($tetraDefaultPhoneConfigured)<span class="text-green-600">(اختیاری برای Tetra98)</span>@endif
            </label>
            <input
                id="tetra98-phone"
                name="phone"
                type="text"
                inputmode="numeric"
                pattern="^09\d{9}$"
                autocomplete="tel"
                x-model="tetraPhone"
                value="{{ $tetraHasOldContext ? old('phone', '') : '' }}"
                class="block mt-1 w-full p-4 text-base text-center bg-transparent dark:bg-gray-700/50 border-2 border-sky-200 dark:border-sky-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                placeholder="مثلاً 09121234567"
                @if(!$tetraDefaultPhoneConfigured) required @endif
            >
            @if($tetraDefaultPhoneConfigured)
                <p class="mt-2 text-xs text-green-600 dark:text-green-400 text-center">اختیاری - در صورت خالی گذاشتن، از شماره پیش‌فرض تنظیم شده استفاده می‌شود.</p>
            @else
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400 text-center">این شماره برای هماهنگی پرداخت در Tetra98 استفاده می‌شود.</p>
            @endif
        </div>

        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>درگاه: Tetra98</span>
            <span>پس از پرداخت، نتیجه به صورت خودکار بررسی می‌شود.</span>
        </div>

        <div>
            <button
                type="submit"
                class="w-full flex items-center justify-center px-4 py-3 bg-gradient-to-r from-indigo-500 to-blue-600 text-white font-semibold rounded-lg shadow-lg hover:scale-105 transform transition-transform duration-300"
            >
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <span>انتقال به درگاه Tetra98</span>
            </button>
        </div>
    </form>
</div>
