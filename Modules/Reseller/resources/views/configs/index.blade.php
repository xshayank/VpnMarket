<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('کانفیگ‌های من') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 space-y-3 md:space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.dashboard')" />
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if (session('warning'))
                <div class="mb-4 bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">هشدار!</strong>
                    <span class="block sm:inline">{{ session('warning') }}</span>
                </div>
            @endif

            <div class="flex justify-end mb-3 md:mb-4">
                <a href="{{ route('reseller.configs.create') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center text-sm md:text-base">
                    ایجاد کانفیگ جدید
                </a>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px]">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr class="text-right">
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100">نام کاربری</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100 hidden sm:table-cell">محدودیت ترافیک</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100 hidden md:table-cell">مصرف شده</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100 hidden lg:table-cell">مصرف قبلی</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100 hidden sm:table-cell">تاریخ انقضا</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100">وضعیت</th>
                                <th class="px-2 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-700 dark:text-gray-100">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="text-right">
                            @forelse ($configs as $config)
                                <tr class="border-b dark:border-gray-700">
                                    <td class="px-2 md:px-4 py-3 text-gray-900 dark:text-gray-100">
                                        <div class="break-words max-w-[150px] md:max-w-none">
                                            <div class="text-xs md:text-base font-medium">{{ $config->external_username }}</div>
                                            @if ($config->comment)
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 italic">{{ $config->comment }}</div>
                                            @endif
                                            {{-- Mobile-only: show key info --}}
                                            <div class="text-xs text-gray-600 dark:text-gray-400 mt-1 sm:hidden space-y-0.5">
                                                <div>محدودیت: {{ round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2) }} GB</div>
                                                <div class="md:hidden">مصرف: {{ round($config->usage_bytes / (1024 * 1024 * 1024), 2) }} GB</div>
                                                @if ($config->getSettledUsageBytes() > 0)
                                                    <div class="lg:hidden text-gray-500">مصرف قبلی: {{ round($config->getSettledUsageBytes() / (1024 * 1024 * 1024), 2) }} GB</div>
                                                @endif
                                                <div>انقضا: {{ $config->expires_at->format('Y-m-d') }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-2 md:px-4 py-3 text-xs md:text-base text-gray-900 dark:text-gray-100 hidden sm:table-cell">{{ round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2) }} GB</td>
                                    <td class="px-2 md:px-4 py-3 text-xs md:text-base text-gray-900 dark:text-gray-100 hidden md:table-cell">{{ round($config->usage_bytes / (1024 * 1024 * 1024), 2) }} GB</td>
                                    <td class="px-2 md:px-4 py-3 text-xs md:text-base text-gray-500 dark:text-gray-400 hidden lg:table-cell">
                                        @if ($config->getSettledUsageBytes() > 0)
                                            {{ round($config->getSettledUsageBytes() / (1024 * 1024 * 1024), 2) }} GB
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-2 md:px-4 py-3 text-xs md:text-base text-gray-900 dark:text-gray-100 hidden sm:table-cell">{{ $config->expires_at->format('Y-m-d') }}</td>
                                    <td class="px-2 md:px-4 py-3">
                                        <span class="px-2 py-1 rounded text-xs {{ $config->status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100' }}">
                                            {{ $config->status }}
                                        </span>
                                    </td>
                                    <td class="px-2 md:px-4 py-3">
                                        <div class="flex flex-col sm:flex-row gap-1 sm:gap-2">
                                            @if ($config->subscription_url)
                                                <button 
                                                    onclick="copyToClipboard('{{ $config->subscription_url }}')" 
                                                    class="px-2 md:px-3 py-2 md:py-1 bg-blue-500 text-white rounded hover:bg-blue-600 text-xs md:text-sm min-h-[40px] sm:min-h-0"
                                                    title="کپی لینک سابسکریپشن">
                                                    کپی
                                                </button>
                                                <button 
                                                    onclick="showQRCode('{{ $config->subscription_url }}')" 
                                                    class="px-2 md:px-3 py-2 md:py-1 bg-purple-500 text-white rounded hover:bg-purple-600 text-xs md:text-sm min-h-[40px] sm:min-h-0"
                                                    title="نمایش QR Code">
                                                    QR
                                                </button>
                                            @endif
                                            <a href="{{ route('reseller.configs.edit', $config) }}" 
                                                class="w-full sm:w-auto px-2 md:px-3 py-2 md:py-1 bg-indigo-500 text-white rounded hover:bg-indigo-600 text-xs md:text-sm min-h-[40px] sm:min-h-0 flex items-center justify-center"
                                                title="ویرایش محدودیت‌ها">
                                                ویرایش
                                            </a>
                                            <form action="{{ route('reseller.configs.resetUsage', $config) }}" method="POST" class="w-full sm:w-auto" 
                                                onsubmit="return confirm('آیا از بازنشانی مصرف ترافیک اطمینان دارید؟ مقدار مصرف شده به حساب شما منتقل خواهد شد.')">
                                                @csrf
                                                <button type="submit" class="w-full px-2 md:px-3 py-2 md:py-1 bg-teal-500 text-white rounded hover:bg-teal-600 text-xs md:text-sm min-h-[40px] sm:min-h-0">
                                                    ریست ترافیک
                                                </button>
                                            </form>
                                            @if ($config->isActive())
                                                <form action="{{ route('reseller.configs.disable', $config) }}" method="POST" class="w-full sm:w-auto">
                                                    @csrf
                                                    <button type="submit" class="w-full px-2 md:px-3 py-2 md:py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600 text-xs md:text-sm min-h-[40px] sm:min-h-0">
                                                        غیرفعال
                                                    </button>
                                                </form>
                                            @elseif ($config->isDisabled())
                                                <form action="{{ route('reseller.configs.enable', $config) }}" method="POST" class="w-full sm:w-auto">
                                                    @csrf
                                                    <button type="submit" class="w-full px-2 md:px-3 py-2 md:py-1 bg-green-500 text-white rounded hover:bg-green-600 text-xs md:text-sm min-h-[40px] sm:min-h-0">
                                                        فعال
                                                    </button>
                                                </form>
                                            @endif
                                            <form action="{{ route('reseller.configs.destroy', $config) }}" method="POST" class="w-full sm:w-auto" 
                                                onsubmit="return confirm('آیا مطمئن هستید؟')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full px-2 md:px-3 py-2 md:py-1 bg-red-500 text-white rounded hover:bg-red-600 text-xs md:text-sm min-h-[40px] sm:min-h-0">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-sm md:text-base text-gray-500 dark:text-gray-400">
                                        هیچ کانفیگی وجود ندارد.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                {{ $configs->links() }}
            </div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" onclick="closeQRModal()">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg max-w-md w-full mx-4" onclick="event.stopPropagation()">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">QR Code</h3>
                <button onclick="closeQRModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div id="qrCodeContainer" class="flex justify-center bg-white p-4 rounded"></div>
        </div>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('لینک سابسکریپشن کپی شد!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }

        function showQRCode(url) {
            if (!window.QRCode) {
                console.error('QRCode library is not available');
                alert('امکان نمایش QR کد وجود ندارد. لطفا صفحه را مجددا بارگذاری کنید.');
                return;
            }

            const container = document.getElementById('qrCodeContainer');
            container.innerHTML = '';

            new QRCode(container, {
                text: url,
                width: 256,
                height: 256,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
            
            document.getElementById('qrModal').classList.remove('hidden');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }
    </script>
</x-app-layout>
