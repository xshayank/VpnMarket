<div x-show="method === 'starsefar'" x-transition.opacity x-cloak class="space-y-6">
    <h3 class="text-lg font-semibold text-center">درگاه پرداخت (استارز تلگرام)</h3>

    <div class="bg-blue-50 dark:bg-sky-900/30 border border-blue-200 dark:border-sky-700 rounded-xl p-4 text-sm text-blue-800 dark:text-sky-100 space-y-2">
        @if(($chargeMode ?? 'wallet') === 'traffic')
            <p>مقدار ترافیک را به گیگابایت وارد کنید. مبلغ پرداختی بر اساس نرخ هر گیگابایت محاسبه و به تومان پرداخت می‌شود.</p>
            <p>حداقل خرید ترافیک: {{ number_format($minAmountGb ?? 0) }} گیگابایت. نرخ هر گیگابایت: {{ number_format($trafficPricePerGb ?? 750) }} تومان.</p>
        @else
            <p>مبلغ را فقط به تومان وارد کنید. حداقل مبلغ: {{ number_format($starsefarSettings['min_amount']) }} تومان.</p>
        @endif
        <p>پس از ساخت لینک پرداخت، صفحه پرداخت در پنجره جدید باز می‌شود. پس از اتمام پرداخت منتظر تایید خودکار بمانید.</p>
    </div>

    <template x-if="starStatusMessage">
        <div :class="{
                'bg-green-100 border-green-300 text-green-800': starStatusType === 'success',
                'bg-yellow-100 border-yellow-300 text-yellow-800': starStatusType === 'info',
                'bg-red-100 border-red-300 text-red-800': starStatusType === 'error'
            }"
             class="border rounded-xl p-4 text-sm">
            <p x-text="starStatusMessage"></p>
        </div>
    </template>

    <form class="space-y-6" @submit.prevent="initiateStarsefar">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="relative">
                @if(($chargeMode ?? 'wallet') === 'traffic')
                    <label for="starsefar-traffic" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">مقدار ترافیک (گیگابایت)</label>
                    <input
                        id="starsefar-traffic"
                        type="number"
                        step="1"
                        min="{{ (int) ($minAmountGb ?? 0) }}"
                        x-model.number="trafficGb"
                        class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-blue-200 dark:border-blue-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="مثلاً 250"
                        required
                    >
                    <p class="mt-2 text-xs text-center text-blue-700 dark:text-sky-200">
                        مبلغ قابل پرداخت: <span x-text="trafficAmountToman().toLocaleString('fa-IR')"></span> تومان
                    </p>
                @else
                    <label for="starsefar-amount" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">مبلغ (تومان)</label>
                    <input
                        id="starsefar-amount"
                        type="number"
                        min="{{ (int) $starsefarSettings['min_amount'] }}"
                        x-model.number="starAmount"
                        class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-blue-200 dark:border-blue-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="حداقل {{ number_format($starsefarSettings['min_amount']) }}"
                        required
                    >
                @endif
            </div>

            <div class="relative">
                <label for="starsefar-phone" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">شماره تماس (اختیاری)</label>
                <input
                    id="starsefar-phone"
                    type="text"
                    x-model="starPhone"
                    class="block mt-1 w-full p-4 text-base text-center bg-transparent dark:bg-gray-700/50 border-2 border-blue-200 dark:border-blue-500 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="مثلاً 09121234567"
                >
            </div>
        </div>

        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>درگاه: استارز تلگرام</span>
            <span>لینک پس از ساخت به صورت خودکار باز می‌شود.</span>
        </div>

        <div>
            <button
                type="submit"
                class="w-full flex items-center justify-center px-4 py-3 bg-gradient-to-r from-indigo-500 to-purple-600 text-white font-semibold rounded-lg shadow-lg hover:scale-105 transform transition-transform duration-300 disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="isInitiating || starsefarRequirementNotMet()"
            >
                <svg
                    x-show="!isInitiating"
                    class="w-5 h-5 ml-2"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <svg
                    x-show="isInitiating"
                    class="w-5 h-5 ml-2 animate-spin"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3.5-3.5L12 0v4a8 8 0 018 8h-4l3.5 3.5L24 12h-4a8 8 0 01-8 8v-4l-3.5 3.5L12 24v-4a8 8 0 01-8-8z"></path>
                </svg>
                <span x-text="isInitiating ? 'در حال ساخت لینک...' : 'ساخت لینک پرداخت'">ساخت لینک پرداخت</span>
            </button>

            <div
                x-show="starsefarRequirementNotMet()"
                x-transition.opacity
                x-cloak
                class="mt-2 text-xs text-amber-800 dark:text-amber-100 bg-amber-50 dark:bg-amber-900/40 border border-amber-200 dark:border-amber-700 rounded-lg p-3 text-center"
            >
                حداقل پرداخت برای استفاده از استارز {{ number_format($starsefarSettings['min_amount']) }} تومان است.
                برای خرید ترافیک نیز مبلغ نهایی باید حداقل به همین میزان برسد.
            </div>
        </div>
    </form>
</div>
