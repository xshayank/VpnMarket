<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('ایجاد کانفیگ جدید') }}
            </h2>
        </div>
    </x-slot>

    <script>
        // Define Alpine component factory before x-data initialization
        window.configForm = function(panels, initialPanelId) {
            return {
                panels: panels || [],
                selectedPanelId: initialPanelId ? String(initialPanelId) : '',
                nodeSelections: [],
                serviceSelections: [],
                maxClients: {{ old('max_clients', 1) }},
                
                get selectedPanel() {
                    if (!this.selectedPanelId) return null;
                    return this.panels.find(p => String(p.id) === String(this.selectedPanelId)) || null;
                },
                
                init() {
                    // Watch for panel changes
                    this.$watch('selectedPanelId', (newValue, oldValue) => {
                        if (newValue !== oldValue) {
                            // Clear selections when switching panels
                            this.nodeSelections = [];
                            this.serviceSelections = [];
                            
                            // Reset max_clients to default
                            if (this.selectedPanel && this.selectedPanel.panel_type !== 'eylandoo') {
                                this.maxClients = 1;
                            }
                        }
                    });
                },
                
                async refreshPanelData(panelId) {
                    if (!panelId) return;
                    
                    try {
                        const response = await fetch(`/reseller/panels/${panelId}/data`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        
                        if (!response.ok) {
                            throw new Error('Failed to fetch panel data');
                        }
                        
                        const data = await response.json();
                        
                        // Update the panel data in the panels array
                        const panelIndex = this.panels.findIndex(p => p.id === panelId);
                        if (panelIndex !== -1) {
                            this.panels[panelIndex] = data;
                            
                            // Clear selections since data has changed
                            this.nodeSelections = [];
                            this.serviceSelections = [];
                        }
                    } catch (error) {
                        console.error('Error refreshing panel data:', error);
                        alert('خطا در دریافت اطلاعات پنل. لطفا دوباره تلاش کنید.');
                    }
                }
            };
        };
    </script>

    <div class="py-6 md:py-12" x-data="configForm(@js($panelsForJs), {{ $prefillPanelId ?? 'null' }})"
        <div class="max-w-3xl mx-auto px-3 sm:px-6 lg:px-8">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.configs.index')" />
            
            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-3 md:p-6 text-right">
                <form action="{{ route('reseller.configs.store') }}" method="POST">
                    @csrf

                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">انتخاب پنل</label>
                        <select name="panel_id" x-model="selectedPanelId" required dir="rtl"
                            class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm md:text-base text-right pr-10 md:pr-8">
                            <option value="">-- انتخاب کنید --</option>
                            @foreach ($panels as $panel)
                                <option value="{{ $panel->id }}">{{ $panel->name }} ({{ $panel->panel_type }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div class="mb-4 md:mb-0">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">محدودیت ترافیک (GB)</label>
                            <input type="number" name="traffic_limit_gb" step="0.1" min="0.1" required 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: 10">
                        </div>

                        <div class="mb-4 md:mb-0">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">مدت اعتبار (روز)</label>
                            <input type="number" name="expires_days" min="1" required 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: 30">
                        </div>
                    </div>

                    <!-- Max clients field for Eylandoo -->
                    <template x-if="selectedPanel && selectedPanel.panel_type === 'eylandoo'">
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                                حداکثر تعداد کلاینت‌های همزمان
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="max_clients" min="1" max="100" x-model="maxClients"
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: 2" required>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">تعداد کلاینت‌هایی که می‌توانند به طور همزمان متصل شوند (فقط برای پنل Eylandoo)</p>
                        </div>
                    </template>

                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">توضیحات (اختیاری - حداکثر 200 کاراکتر)</label>
                        <input type="text" name="comment" maxlength="200" 
                            class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                            placeholder="توضیحات کوتاه درباره این کانفیگ">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">می‌توانید توضیحات کوتاهی برای شناسایی بهتر این کانفیگ وارد کنید</p>
                    </div>

                    @can('configs.set_prefix')
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">پیشوند سفارشی (اختیاری)</label>
                            <input type="text" name="prefix" maxlength="50" 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: myprefix"
                                pattern="[a-zA-Z0-9_-]+">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">نام نهایی: prefix_resellerId_cfg_configId (فقط حروف انگلیسی، اعداد، خط تیره و زیرخط مجاز است)</p>
                        </div>
                    @endcan

                    @can('configs.set_custom_name')
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">نام سفارشی کامل (اختیاری)</label>
                            <input type="text" name="custom_name" maxlength="100" 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: custom_username"
                                pattern="[a-zA-Z0-9_-]+">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">این نام به طور کامل جایگزین نام خودکار می‌شود (فقط حروف انگلیسی، اعداد، خط تیره و زیرخط مجاز است)</p>
                        </div>
                    @endcan

                    <!-- Marzneshin Services selection - Shown for Marzneshin panels -->
                    <template x-if="selectedPanel && selectedPanel.panel_type === 'marzneshin'">
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">سرویس‌های Marzneshin (اختیاری)</label>
                            <template x-if="selectedPanel.services && selectedPanel.services.length > 0">
                                <div class="space-y-3">
                                    <template x-for="service in selectedPanel.services" :key="service.id">
                                        <label class="flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0">
                                            <input type="checkbox" name="service_ids[]" :value="service.id" 
                                                x-model="serviceSelections"
                                                class="w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                            <span x-text="`${service.name} (ID: ${service.id})`"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!selectedPanel.services || selectedPanel.services.length === 0">
                                <div class="p-3 bg-gray-100 dark:bg-gray-700 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        هیچ سرویسی برای این پنل تعریف نشده است.
                                    </p>
                                    <button type="button" @click="refreshPanelData(selectedPanel.id)" 
                                        class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                        🔄 دریافت دوباره
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Eylandoo Nodes selection - Shown for Eylandoo panels -->
                    <template x-if="selectedPanel && selectedPanel.panel_type === 'eylandoo'">
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                                نودهای Eylandoo (اختیاری)
                            </label>
                            <template x-if="selectedPanel.nodes && selectedPanel.nodes.length > 0">
                                <div class="space-y-3">
                                    <template x-for="node in selectedPanel.nodes" :key="node.id">
                                        <label class="flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0">
                                            <input type="checkbox" name="node_ids[]" :value="node.id" 
                                                x-model="nodeSelections"
                                                class="w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                            <span x-text="`${node.name} (ID: ${node.id})`"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!selectedPanel.nodes || selectedPanel.nodes.length === 0">
                                <div class="p-3 bg-gray-100 dark:bg-gray-700 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        هیچ نودی برای این پنل یافت نشد. کانفیگ بدون محدودیت نود ایجاد خواهد شد.
                                    </p>
                                    <button type="button" @click="refreshPanelData(selectedPanel.id)" 
                                        class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                        🔄 دریافت دوباره
                                    </button>
                                </div>
                            </template>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                <span x-show="selectedPanel.nodes && selectedPanel.nodes.some(n => n.is_default)">
                                    نودهای پیش‌فرض (1 و 2) نمایش داده شده‌اند. در صورت نیاز می‌توانید نودهای دیگر را در پنل تنظیم کنید.
                                </span>
                                <span x-show="!selectedPanel.nodes || !selectedPanel.nodes.some(n => n.is_default)">
                                    انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.
                                </span>
                            </p>
                        </div>
                    </template>

                    <div class="flex flex-col sm:flex-row gap-3 md:gap-4 mt-6">
                        <button type="submit" class="w-full sm:w-auto px-4 py-3 md:py-2 h-12 md:h-10 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm md:text-base font-medium">
                            ایجاد کانفیگ
                        </button>
                        <a href="{{ route('reseller.configs.index') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 h-12 md:h-10 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center text-sm md:text-base font-medium flex items-center justify-center">
                            انصراف
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
