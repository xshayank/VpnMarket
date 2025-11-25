<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('مدیریت کلیدهای API') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 space-y-3 md:space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.dashboard')" label="بازگشت به داشبورد" />

            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            {{-- Show newly created API key --}}
            @if (session('new_api_key'))
                <div class="mb-4 bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-4 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold block mb-2">کلید API شما (فقط یکبار نمایش داده می‌شود!):</strong>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <code class="bg-gray-800 text-green-400 px-3 py-2 rounded font-mono text-sm break-all flex-1" id="api-key-display">{{ session('new_api_key') }}</code>
                        <button type="button" onclick="copyApiKey()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                            کپی کلید
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-yellow-700">این کلید را در جای امنی ذخیره کنید. پس از بستن این صفحه، دیگر قابل مشاهده نخواهد بود.</p>
                </div>
            @endif

            {{-- Create new API key form --}}
            <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">ایجاد کلید API جدید</h3>
                
                <form action="{{ route('reseller.api-keys.store') }}" method="POST" class="space-y-4">
                    @csrf
                    
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نام کلید</label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="مثال: کلید پروداکشن">
                        @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">دسترسی‌ها (Scopes)</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach ($scopes as $scope)
                                <label class="flex items-center gap-2 p-2 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}" 
                                        {{ in_array($scope, old('scopes', [])) ? 'checked' : '' }}
                                        class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $scope }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('scopes')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="expires_at" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاریخ انقضا (اختیاری)</label>
                            <input type="datetime-local" name="expires_at" id="expires_at" value="{{ old('expires_at') }}"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('expires_at')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="ip_whitelist" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">محدودیت IP (اختیاری)</label>
                            <input type="text" name="ip_whitelist" id="ip_whitelist" value="{{ old('ip_whitelist') }}"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="مثال: 192.168.1.1, 10.0.0.1">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">آی‌پی‌ها را با کاما جدا کنید</p>
                            @error('ip_whitelist')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm md:text-base">
                            ایجاد کلید API
                        </button>
                    </div>
                </form>
            </div>

            {{-- List existing API keys --}}
            <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">کلیدهای API موجود</h3>
                
                @if ($keys->isEmpty())
                    <p class="text-gray-500 dark:text-gray-400">هیچ کلید API‌ای ایجاد نشده است.</p>
                @else
                    <div class="overflow-x-auto -mx-3 md:mx-0">
                        <table class="w-full min-w-[800px]">
                            <thead>
                                <tr class="border-b dark:border-gray-700">
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">نام</th>
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">دسترسی‌ها</th>
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">تاریخ انقضا</th>
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">آخرین استفاده</th>
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">وضعیت</th>
                                    <th class="text-right px-2 md:px-4 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($keys as $key)
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900 dark:text-gray-100">
                                            {{ $key->name }}
                                            <br>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ Str::limit($key->id, 8, '...') }}</span>
                                        </td>
                                        <td class="py-3 px-2 md:px-4 text-xs text-gray-900 dark:text-gray-100">
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($key->scopes ?? [] as $scope)
                                                    <span class="px-1.5 py-0.5 bg-blue-100 dark:bg-blue-800 text-blue-800 dark:text-blue-100 rounded text-xs">
                                                        {{ $scope }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900 dark:text-gray-100">
                                            @if ($key->expires_at)
                                                {{ $key->expires_at->format('Y-m-d H:i') }}
                                                @if ($key->expires_at->isPast())
                                                    <span class="text-red-600 text-xs">(منقضی)</span>
                                                @endif
                                            @else
                                                <span class="text-gray-500">بدون انقضا</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900 dark:text-gray-100">
                                            @if ($key->last_used_at)
                                                {{ $key->last_used_at->diffForHumans() }}
                                            @else
                                                <span class="text-gray-500">هرگز</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-2 md:px-4">
                                            @if ($key->revoked)
                                                <span class="px-2 py-1 rounded text-xs bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100">باطل شده</span>
                                            @elseif ($key->expires_at && $key->expires_at->isPast())
                                                <span class="px-2 py-1 rounded text-xs bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100">منقضی</span>
                                            @else
                                                <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">فعال</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-2 md:px-4">
                                            <div class="flex gap-2">
                                                @if (!$key->revoked)
                                                    <form action="{{ route('reseller.api-keys.revoke', $key->id) }}" method="POST" class="inline" 
                                                        onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید این کلید را باطل کنید؟')">
                                                        @csrf
                                                        <button type="submit" class="px-2 py-1 bg-yellow-600 text-white rounded text-xs hover:bg-yellow-700">
                                                            باطل کردن
                                                        </button>
                                                    </form>
                                                @endif
                                                <form action="{{ route('reseller.api-keys.destroy', $key->id) }}" method="POST" class="inline"
                                                    onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید این کلید را حذف کنید؟')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-2 py-1 bg-red-600 text-white rounded text-xs hover:bg-red-700">
                                                        حذف
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- API Documentation --}}
            <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">راهنمای استفاده از API</h3>
                
                <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
                    <div>
                        <h4 class="font-semibold mb-2">احراز هویت</h4>
                        <p>برای استفاده از API، کلید API خود را در هدر <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">Authorization</code> قرار دهید:</p>
                        <pre class="mt-2 bg-gray-100 dark:bg-gray-700 p-3 rounded overflow-x-auto text-xs"><code>Authorization: Bearer YOUR_API_KEY</code></pre>
                    </div>

                    <div>
                        <h4 class="font-semibold mb-2">نقاط پایانی (Endpoints)</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b dark:border-gray-600">
                                        <th class="text-right py-2 px-2">متد</th>
                                        <th class="text-right py-2 px-2">آدرس</th>
                                        <th class="text-right py-2 px-2">دسترسی مورد نیاز</th>
                                        <th class="text-right py-2 px-2">توضیح</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b dark:border-gray-600">
                                        <td class="py-2 px-2"><code class="bg-green-100 dark:bg-green-800 px-1 rounded">GET</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/panels</code></td>
                                        <td class="py-2 px-2">panels:list</td>
                                        <td class="py-2 px-2">لیست پنل‌های در دسترس</td>
                                    </tr>
                                    <tr class="border-b dark:border-gray-600">
                                        <td class="py-2 px-2"><code class="bg-green-100 dark:bg-green-800 px-1 rounded">GET</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/configs</code></td>
                                        <td class="py-2 px-2">configs:read</td>
                                        <td class="py-2 px-2">لیست کانفیگ‌ها</td>
                                    </tr>
                                    <tr class="border-b dark:border-gray-600">
                                        <td class="py-2 px-2"><code class="bg-green-100 dark:bg-green-800 px-1 rounded">GET</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/configs/{name}</code></td>
                                        <td class="py-2 px-2">configs:read</td>
                                        <td class="py-2 px-2">مشاهده یک کانفیگ</td>
                                    </tr>
                                    <tr class="border-b dark:border-gray-600">
                                        <td class="py-2 px-2"><code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">POST</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/configs</code></td>
                                        <td class="py-2 px-2">configs:create</td>
                                        <td class="py-2 px-2">ایجاد کانفیگ جدید</td>
                                    </tr>
                                    <tr class="border-b dark:border-gray-600">
                                        <td class="py-2 px-2"><code class="bg-yellow-100 dark:bg-yellow-800 px-1 rounded">PUT</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/configs/{name}</code></td>
                                        <td class="py-2 px-2">configs:update</td>
                                        <td class="py-2 px-2">ویرایش کانفیگ</td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 px-2"><code class="bg-red-100 dark:bg-red-800 px-1 rounded">DELETE</code></td>
                                        <td class="py-2 px-2"><code>/api/v1/configs/{name}</code></td>
                                        <td class="py-2 px-2">configs:delete</td>
                                        <td class="py-2 px-2">حذف کانفیگ</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h4 class="font-semibold mb-2">مثال ایجاد کانفیگ</h4>
                        <pre class="bg-gray-100 dark:bg-gray-700 p-3 rounded overflow-x-auto text-xs"><code>curl -X POST {{ url('/api/v1/configs') }} \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "panel_id": 1,
    "traffic_limit_gb": 10,
    "expires_days": 30,
    "comment": "کانفیگ تست"
  }'</code></pre>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function copyApiKey() {
            const keyDisplay = document.getElementById('api-key-display');
            const text = keyDisplay.textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('کلید API کپی شد!');
            }).catch(() => {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('کلید API کپی شد!');
            });
        }
    </script>
</x-app-layout>
