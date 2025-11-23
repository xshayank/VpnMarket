<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            @if($chargeMode === 'wallet')
                شارژ کیف پول
            @else
                خرید ترافیک
            @endif
        </h2>
    </x-slot>

    @php
        $cardToCardEnabled = $cardToCardEnabled ?? true;
        $availableMethods = $availableMethods ?? [];
        $starsefarEnabled = $starsefarEnabled ?? ($starsefarSettings['enabled'] ?? false);
        $tetraSettings = $tetraSettings ?? ['enabled' => false, 'min_amount' => 10000];
        $methodCount = count($availableMethods);
        $tetraMinAmount = (int) ($tetraSettings['min_amount'] ?? 10000);
        $tetraHasOldContext = old('tetra98_context');
        $tetraDefaultAmount = $tetraHasOldContext ? (int) old('amount', $tetraMinAmount) : $tetraMinAmount;
        $tetraDefaultPhone = $tetraHasOldContext ? old('phone', '') : '';
        $defaultMethod = $defaultMethod
            ?? ($cardToCardEnabled
                ? 'card'
                : ($starsefarEnabled
                    ? 'starsefar'
                    : (($tetraSettings['enabled'] ?? false) ? 'tetra98' : null)));
    @endphp

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white/50 dark:bg-gray-800/50 backdrop-blur-xl overflow-hidden shadow-2xl sm:rounded-2xl">
                <div
                    class="p-6 md:p-8 text-gray-900 dark:text-gray-100 text-right space-y-8"
                    x-data="walletChargePage(@js([
                        'chargeMode' => $chargeMode ?? 'wallet',
                        'isFirstTopup' => $isFirstTopup ?? false,
                        'minAmount' => $minAmount ?? 50000,
                        'minAmountGb' => $minAmountGb ?? 50,
                        'trafficPricePerGb' => $trafficPricePerGb ?? 750,
                        'starsefarMinAmount' => (int) ($starsefarSettings['min_amount'] ?? 25000),
                        'starsefarEnabled' => (bool) $starsefarEnabled,
                        'cardEnabled' => (bool) $cardToCardEnabled,
                        'tetraEnabled' => (bool) ($tetraSettings['enabled'] ?? false),
                        'tetraMinAmount' => $tetraMinAmount,
                        'tetraDefaultAmount' => $tetraDefaultAmount,
                        'tetraDefaultPhone' => $tetraDefaultPhone,
                        'csrfToken' => csrf_token(),
                        'availableMethods' => array_values($availableMethods),
                        'defaultMethod' => $defaultMethod,
                    ]))"
                    x-init="init()"
                >
                    {{-- نمایش موجودی/ترافیک فعلی --}}
                    <div class="text-center">
                        @if($chargeMode === 'wallet')
                            <p class="text-sm text-gray-500 dark:text-gray-400">موجودی کیف پول</p>
                            <p class="font-bold text-3xl text-green-500 mt-1">
                                {{ number_format($walletBalance ?? 0) }}
                                <span class="text-lg font-normal">تومان</span>
                            </p>
                            @if($isFirstTopup)
                                <p class="text-xs text-amber-400 mt-2">⚠️ برای فعال‌سازی حداقل {{ number_format($minAmount) }} تومان شارژ کنید</p>
                            @endif
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">ترافیک موجود</p>
                            <p class="font-bold text-3xl text-blue-500 mt-1">
                                {{ number_format($trafficTotalGb ?? 0, 2) }}
                                <span class="text-lg font-normal">گیگابایت</span>
                            </p>
                            <p class="text-xs text-gray-400 mt-1">مصرف شده: {{ number_format($trafficUsedGb ?? 0, 2) }} GB</p>
                            @if($isFirstTopup)
                                <p class="text-xs text-amber-400 mt-2">⚠️ برای فعال‌سازی حداقل {{ number_format($minAmountGb) }} گیگابایت خریداری کنید</p>
                            @endif
                        @endif
                    </div>

                    {{-- انتخاب روش پرداخت --}}
                    <div class="space-y-3">
                        <h3 class="text-lg font-medium text-center">انتخاب روش پرداخت</h3>
                        @php
                            $gridColumnsClass = match (true) {
                                $methodCount >= 3 => 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
                                $methodCount === 2 => 'grid-cols-1 sm:grid-cols-2',
                                default => 'grid-cols-1',
                            };
                        @endphp

                        @if ($methodCount === 0)
                            <div class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4 text-center">
                                در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً دوباره تلاش کنید.
                            </div>
                        @else
                            <div class="grid gap-3 {{ $gridColumnsClass }}">
                                @if($cardToCardEnabled)
                                    <button
                                        type="button"
                                        @click="selectMethod('card')"
                                        :class="method === 'card' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-credit-card class="w-6 h-6" />
                                            <span class="font-semibold">کارت به کارت</span>
                                        </div>
                                    </button>
                                @endif

                                @if($starsefarEnabled)
                                    <button
                                        type="button"
                                        @click="selectMethod('starsefar')"
                                        :class="method === 'starsefar' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-sparkles class="w-6 h-6" />
                                            <span class="font-semibold">درگاه پرداخت (استارز تلگرام)</span>
                                        </div>
                                    </button>
                                @endif

                                @if($tetraSettings['enabled'])
                                    <button
                                        type="button"
                                        @click="selectMethod('tetra98')"
                                        :class="method === 'tetra98' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-device-phone-mobile class="w-6 h-6" />
                                            <span class="font-semibold">درگاه پرداخت (Tetra98)</span>
                                        </div>
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div
                        x-show="renderErrorMessage"
                        x-transition.opacity
                        class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 space-y-3"
                        x-cloak
                    >
                        <p class="text-sm leading-6" x-text="renderErrorMessage"></p>
                        <div class="flex justify-center">
                            <button
                                type="button"
                                @click="retryRender()"
                                class="px-4 py-2 text-sm font-semibold bg-red-100 hover:bg-red-200 text-red-800 rounded-lg transition"
                            >
                                تلاش مجدد برای نمایش فرم
                            </button>
                        </div>
                    </div>

                    {{-- کارت به کارت --}}
                    @if($cardToCardEnabled)
                        <div x-show="method === 'card'" x-transition.opacity x-cloak class="space-y-6">
                            <h3 class="text-lg font-semibold text-center">پرداخت از طریق کارت به کارت</h3>

                        @if ($errors->any())
                            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif

                        @if($cardDetails['number'] || $cardDetails['holder'] || $cardDetails['instructions'])
                            <div class="bg-white/70 dark:bg-gray-900/40 border border-indigo-200 dark:border-indigo-500/30 rounded-xl p-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">اطلاعات کارت</span>
                                    <x-heroicon-o-shield-check class="w-5 h-5 text-indigo-500" />
                                </div>
                                @if($cardDetails['number'])
                                    <div class="font-mono text-lg tracking-widest text-center">{{ $cardDetails['number'] }}</div>
                                @endif
                                @if($cardDetails['holder'])
                                    <p class="text-sm text-center text-gray-600 dark:text-gray-300">نام صاحب حساب: {{ $cardDetails['holder'] }}</p>
                                @endif
                                @if($cardDetails['instructions'])
                                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{!! nl2br(e($cardDetails['instructions'])) !!}</p>
                                @endif
                            </div>
                        @endif

                        <form
                            method="POST"
                            action="{{ route('wallet.charge.create') }}"
                            enctype="multipart/form-data"
                            class="space-y-6"
                        >
                            @csrf
                            
                            @if($chargeMode === 'wallet')
                                {{-- Wallet Mode: Amount in Toman --}}
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <button type="button" @click="cardAmount = 50000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۵۰,۰۰۰</button>
                                    <button type="button" @click="cardAmount = 100000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۱۰۰,۰۰۰</button>
                                    <button type="button" @click="cardAmount = 250000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۲۵۰,۰۰۰</button>
                                </div>

                                <div class="relative">
                                    <label for="amount" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">
                                        مبلغ شارژ (تومان) - حداقل {{ number_format($minAmount) }}
                                    </label>
                                    <input
                                        id="amount"
                                        name="amount"
                                        x-model="cardAmount"
                                        type="number"
                                        class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                        :placeholder="'حداقل ' + {{ $minAmount }}"
                                        min="{{ $minAmount }}"
                                        required
                                    >
                                </div>
                            @else
                                {{-- Traffic Mode: Amount in GB --}}
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <button type="button" @click="trafficGb = 50" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۵۰ GB</button>
                                    <button type="button" @click="trafficGb = 100" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۱۰۰ GB</button>
                                    <button type="button" @click="trafficGb = 250" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۲۵۰ GB</button>
                                </div>

                                <div class="relative space-y-2">
                                    <label for="traffic_gb" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">
                                        مقدار ترافیک (گیگابایت) - حداقل {{ number_format($minAmountGb) }}
                                    </label>
                                    <input
                                        id="traffic_gb"
                                        name="traffic_gb"
                                        x-model.number="trafficGb"
                                        type="number"
                                        step="1"
                                        class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                        :placeholder="'حداقل ' + {{ $minAmountGb }}"
                                        min="{{ $minAmountGb }}"
                                        required
                                    >

                                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-3 space-y-1 text-center">
                                        <p class="text-sm text-blue-800 dark:text-blue-300">
                                            نرخ هر گیگابایت: {{ number_format($trafficPricePerGb) }} تومان
                                        </p>
                                        <p class="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                            مبلغ قابل پرداخت: <span x-text="trafficAmountToman().toLocaleString('fa-IR')"></span> تومان
                                        </p>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-4">
                                <label for="proof" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    آپلود رسید پرداخت <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="proof"
                                    name="proof"
                                    type="file"
                                    accept="image/*"
                                    required
                                    class="block w-full text-sm text-gray-500 dark:text-gray-400
                                        file:mr-4 file:py-2 file:px-4
                                        file:rounded-lg file:border-0
                                        file:text-sm file:font-semibold
                                        file:bg-indigo-50 file:text-indigo-700
                                        hover:file:bg-indigo-100
                                        dark:file:bg-gray-700 dark:file:text-gray-300
                                        dark:hover:file:bg-gray-600"
                                >
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">فرمت‌های مجاز: JPEG, PNG, WEBP, JPG - حداکثر 4 مگابایت</p>
                            </div>

                            <div>
                                <button type="submit" class="w-full flex items-center justify-center px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold rounded-lg shadow-lg hover:scale-105 transform transition-transform duration-300">
                                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                    <span>ثبت و ادامه جهت پرداخت</span>
                                </button>
                            </div>
                        </form>
                        </div>
                    @endif

                    {{-- درگاه استارز --}}
                    @if($starsefarEnabled)
                        @include('wallet.partials.starsefar-form', ['starsefarSettings' => $starsefarSettings])
                    @else
                        <div x-show="method === 'starsefar'" class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4" x-cloak>
                            درگاه استارز در حال حاضر غیر فعال است.
                        </div>
                    @endif

                    @if($tetraSettings['enabled'])
                        @include('wallet.partials.tetra98-form', [
                            'tetraSettings' => $tetraSettings,
                            'tetraMinAmount' => $tetraMinAmount,
                            'tetraHasOldContext' => $tetraHasOldContext,
                        ])
                    @else
                        <div x-show="method === 'tetra98'" class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4" x-cloak>
                            درگاه Tetra98 در حال حاضر غیر فعال است.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        function walletChargePage(config) {
            return {
                config,
                method: null,
                availableMethods: Array.isArray(config.availableMethods) ? config.availableMethods : [],
                chargeMode: config.chargeMode || 'wallet',
                cardAmount: @json(old('amount', '')),
                trafficGb: @json(old('traffic_gb', '')),
                starAmount: '',
                starPhone: '',
                tetraAmount: config.tetraDefaultAmount || '',
                tetraPhone: config.tetraDefaultPhone || '',
                starStatusMessage: '',
                starStatusType: 'info',
                isInitiating: false,
                pollTimer: null,
                renderErrorMessage: '',
                lastRenderErrorReason: null,
                lastSuccessfulMethod: null,
                normalizeDigits(value) {
                    if (typeof value !== 'string') {
                        return value ?? '';
                    }

                    const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
                    const arabicDigits = '٠١٢٣٤٥٦٧٨٩';

                    return value
                        .replace(/[۰-۹]/g, (digit) => persianDigits.indexOf(digit))
                        .replace(/[٠-٩]/g, (digit) => arabicDigits.indexOf(digit));
                },
                toNumber(value) {
                    const normalized = this.normalizeDigits(value);
                    const numeric = Number(normalized);

                    return Number.isFinite(numeric) ? numeric : NaN;
                },
                clampStarAmount() {
                    const min = Number(config.starsefarMinAmount) || 0;
                    const numeric = this.toNumber(this.starAmount);

                    this.starAmount = Number.isFinite(numeric) ? Math.max(numeric, min) : min;
                },
                isStarAmountInvalid() {
                    const min = Number(config.starsefarMinAmount) || 0;
                    const numeric = this.toNumber(this.starAmount);

                    return !Number.isFinite(numeric) || numeric < min;
                },
                init() {
                    this.method = this.resolveInitialMethod(config.defaultMethod);

                    // Set default amounts based on mode
                    if (this.chargeMode === 'wallet') {
                        if (this.cardAmount === null || this.cardAmount === '') {
                            this.cardAmount = config.minAmount || 50000;
                        }
                    } else {
                        if (this.trafficGb === null || this.trafficGb === '') {
                            this.trafficGb = config.minAmountGb || 50;
                        }
                    }

                    if (config.starsefarMinAmount) {
                        this.clampStarAmount();
                    }
                    if (this.starPhone === null) {
                        this.starPhone = '';
                    }
                    if (!this.tetraAmount && config.tetraMinAmount) {
                        this.tetraAmount = config.tetraMinAmount;
                    }
                    this.$watch('method', (value) => {
                        this.evaluateMethodState(value);
                    });
                    this.evaluateMethodState(this.method);
                },
                resolveInitialMethod(preferred) {
                    if (preferred && this.availableMethods.includes(preferred)) {
                        return preferred;
                    }

                    if (this.availableMethods.length > 0) {
                        return this.availableMethods[0];
                    }

                    return null;
                },
                evaluateMethodState(value) {
                    if (this.availableMethods.length === 0) {
                        this.handleRenderError('empty_methods');
                        return;
                    }

                    if (!value) {
                        this.handleRenderError('empty_selection');
                        return;
                    }

                    if (!this.availableMethods.includes(value)) {
                        this.handleRenderError('unavailable_method', value);
                        return;
                    }

                    this.renderErrorMessage = '';
                    this.lastRenderErrorReason = null;
                    this.lastSuccessfulMethod = value;
                    this.logMethodEvent('method_ready', value);
                },
                selectMethod(method) {
                    if (!this.availableMethods.includes(method)) {
                        this.handleRenderError('unavailable_method', method);
                        return;
                    }
                    if (
                        (method === 'card' && !config.cardEnabled) ||
                        (method === 'starsefar' && !config.starsefarEnabled) ||
                        (method === 'tetra98' && !config.tetraEnabled)
                    ) {
                        this.handleRenderError('disabled_method', method);
                        return;
                    }
                    this.renderErrorMessage = '';
                    this.lastRenderErrorReason = null;
                    this.method = method;
                    this.logMethodEvent('method_selected', method);
                    if (method !== 'starsefar') {
                        this.clearStarsefarFeedback();
                    } else {
                        this.clampStarAmount();
                    }
                    if (method === 'tetra98' && (!this.tetraAmount || Number(this.tetraAmount) < config.tetraMinAmount)) {
                        this.tetraAmount = config.tetraMinAmount;
                    }
                },
                retryRender() {
                    const fallback = (this.lastSuccessfulMethod && this.availableMethods.includes(this.lastSuccessfulMethod))
                        ? this.lastSuccessfulMethod
                        : (this.availableMethods[0] ?? null);

                    if (!fallback) {
                        this.handleRenderError('empty_methods');
                        return;
                    }

                    this.renderErrorMessage = '';
                    this.lastRenderErrorReason = null;
                    this.logMethodEvent('retry_method', fallback);
                    this.method = fallback;
                },
                trafficAmountToman() {
                    const parsedGb = this.toNumber(this.trafficGb);
                    const gb = Number.isFinite(parsedGb) ? parsedGb : 0;
                    const rate = Number(config.trafficPricePerGb) || 0;

                    return Math.max(0, Math.round(gb * rate));
                },
                handleRenderError(reason, method = null) {
                    if (this.lastRenderErrorReason === reason && this.renderErrorMessage) {
                        return;
                    }

                    let message = 'خطایی در نمایش فرم پرداخت رخ داد. لطفاً دوباره تلاش کنید یا روش دیگری را انتخاب کنید.';

                    if (reason === 'empty_methods') {
                        message = 'هیچ روش پرداختی در حال حاضر فعال نیست. لطفاً بعداً دوباره تلاش کنید.';
                    } else if (reason === 'empty_selection') {
                        message = 'برای ادامه، لطفاً یکی از روش‌های پرداخت را انتخاب کنید.';
                    } else if (reason === 'unavailable_method' || reason === 'disabled_method') {
                        message = 'روش پرداخت انتخاب شده در دسترس نیست. لطفاً روش دیگری را انتخاب کنید یا روی تلاش مجدد کلیک نمایید.';
                    }

                    this.renderErrorMessage = message;
                    this.lastRenderErrorReason = reason;

                    if (typeof console !== 'undefined' && console.error) {
                        console.error('[wallet-charge] methodRenderError', {
                            reason,
                            method,
                            availableMethods: this.availableMethods,
                        });
                    }
                },
                logMethodEvent(event, method) {
                    if (typeof console !== 'undefined' && console.info) {
                        console.info('[wallet-charge] methodEvent', {
                            event,
                            method,
                            availableMethods: this.availableMethods,
                        });
                    }
                },
                clearStarsefarFeedback() {
                    this.starStatusMessage = '';
                    this.starStatusType = 'info';
                    if (this.pollTimer) {
                        clearInterval(this.pollTimer);
                        this.pollTimer = null;
                    }
                },
                async initiateStarsefar() {
                    this.clearStarsefarFeedback();
                    this.isInitiating = true;

                    try {
                        const payload = {
                            phone: this.starPhone || null,
                        };

                        if (this.chargeMode === 'wallet') {
                            payload.amount = this.starAmount;
                        } else {
                            payload.traffic_gb = Number(this.trafficGb || 0);
                        }

                        const response = await fetch('{{ route('wallet.charge.starsefar.initiate') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': config.csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || 'خطا در ایجاد لینک پرداخت');
                        }

                        window.open(data.link, '_blank');
                        this.starStatusType = 'info';
                        this.starStatusMessage = 'لینک پرداخت در پنجره جدید باز شد. لطفاً پرداخت را تکمیل کنید.';
                        this.pollStatus(data.statusEndpoint);
                    } catch (error) {
                        this.starStatusType = 'error';
                        this.starStatusMessage = error.message || 'خطایی رخ داد. دوباره تلاش کنید.';
                    } finally {
                        this.isInitiating = false;
                    }
                },
                pollStatus(url) {
                    if (this.pollTimer) {
                        clearInterval(this.pollTimer);
                    }

                    const checkStatus = async () => {
                        try {
                            const response = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json',
                                },
                            });

                            if (!response.ok) {
                                return;
                            }

                            const data = await response.json();
                            if (data.status === 'paid') {
                                this.starStatusType = 'success';
                                this.starStatusMessage = 'پرداخت با موفقیت تایید شد و کیف پول شما شارژ گردید.';
                                clearInterval(this.pollTimer);
                                this.pollTimer = null;
                            }
                        } catch (error) {
                            console.error('poll error', error);
                        }
                    };

                    checkStatus();
                    this.pollTimer = setInterval(checkStatus, 5000);
                },
            };
        }
    </script>
</x-app-layout>
