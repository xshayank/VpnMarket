<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} - ثبت نام</title>

    <link rel="stylesheet" href="{{ asset('themes/auth/dragon/css/style.css') }}">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="dragon-auth-body">

<div class="embers-container">
    @for ($i = 0; $i < 20; $i++)
        <div class="ember"></div>
    @endfor
</div>

<div class="auth-card">

    <div class="auth-logo">{{ $settings->get('auth_brand_name', 'ARV') }}</div>
    <h2 class="auth-title">ایجاد حساب کاربری جدید</h2>

    <form method="POST" action="{{ route('register') }}" x-data="registrationForm(@js($panels))">
        @csrf

        @if(request()->has('ref'))
            <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
        @endif

        <div class="input-group">
            <input id="name" class="input-field" type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="نام کامل">
        </div>

        <div class="input-group">
            <input id="email" class="input-field" type="email" name="email" value="{{ old('email') }}" required placeholder="ایمیل">
        </div>

        <div class="input-group">
            <input id="password" class="input-field" type="password" name="password" required placeholder="رمز عبور">
        </div>

        <div class="input-group">
            <input id="password_confirmation" class="input-field" type="password" name="password_confirmation" required placeholder="تکرار رمز عبور">
        </div>

        {{-- Reseller Type Selection --}}
        <div class="input-group">
            <label class="input-label">نوع حساب ریسلر:</label>
            <select name="reseller_type" class="input-field" x-model="resellerType" required>
                <option value="">انتخاب کنید...</option>
                <option value="wallet">کیف پول (۱۵۰,۰۰۰ تومان حداقل شارژ اول)</option>
                <option value="traffic">ترافیک (۲۵۰ گیگابایت حداقل خرید اول)</option>
            </select>
        </div>

        {{-- Panel Selection --}}
        <div class="input-group">
            <label class="input-label">انتخاب پنل اصلی:</label>
            <select name="primary_panel_id" class="input-field" x-model="selectedPanelId" @change="onPanelChange" required>
                <option value="">انتخاب کنید...</option>
                @foreach($panels as $panel)
                    <option value="{{ $panel->id }}" data-panel-type="{{ $panel->panel_type }}">{{ $panel->name }} ({{ ucfirst($panel->panel_type) }})</option>
                @endforeach
            </select>
        </div>

        {{-- Eylandoo Nodes Selection (conditionally shown) --}}
        <div class="input-group" x-show="selectedPanelType === 'eylandoo' && availableNodes.length > 0" x-cloak>
            <label class="input-label">انتخاب نودها (اختیاری):</label>
            <div style="max-height: 150px; overflow-y: auto; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 8px; padding: 8px;">
                <template x-for="node in availableNodes" :key="node.id">
                    <label style="display: block; margin: 4px 0;">
                        <input type="checkbox" name="selected_nodes[]" :value="node.id" style="margin-left: 8px;">
                        <span x-text="node.name"></span>
                    </label>
                </template>
            </div>
            <p class="text-xs text-gray-400 mt-1">اگر انتخاب نکنید، تمام نودهای پیش‌فرض اختصاص می‌یابد</p>
        </div>

        {{-- Marzneshin Services Selection (conditionally shown) --}}
        <div class="input-group" x-show="selectedPanelType === 'marzneshin' && availableServices.length > 0" x-cloak>
            <label class="input-label">انتخاب سرویس‌ها (اختیاری):</label>
            <div style="max-height: 150px; overflow-y: auto; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 8px; padding: 8px;">
                <template x-for="service in availableServices" :key="service.id">
                    <label style="display: block; margin: 4px 0;">
                        <input type="checkbox" name="selected_services[]" :value="service.id" style="margin-left: 8px;">
                        <span x-text="service.name"></span>
                    </label>
                </template>
            </div>
            <p class="text-xs text-gray-400 mt-1">اگر انتخاب نکنید، تمام سرویس‌های پیش‌فرض اختصاص می‌یابد</p>
        </div>

        {{-- Display errors if any --}}
        @if($errors->any())
            <div class="mt-2 text-danger small" style="color: #ff7675; font-size: 0.8rem;">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="input-group mt-4">
            <button type="submit" class="btn-submit">ثبت نام</button>
        </div>

        <hr class="separator">

        <div class="register-link">
            قبلاً ثبت‌نام کرده‌اید؟ <a class="auth-link" href="{{ route('login') }}">وارد شوید</a>
        </div>
    </form>
</div>

<script>
function registrationForm(panels) {
    return {
        resellerType: '{{ old("reseller_type") }}',
        selectedPanelId: '{{ old("primary_panel_id") }}',
        selectedPanelType: '',
        availableNodes: [],
        availableServices: [],
        panels: panels,

        init() {
            if (this.selectedPanelId) {
                this.onPanelChange();
            }
        },

        onPanelChange() {
            const panel = this.panels.find(p => p.id == this.selectedPanelId);
            if (!panel) {
                this.selectedPanelType = '';
                this.availableNodes = [];
                this.availableServices = [];
                return;
            }

            this.selectedPanelType = panel.panel_type.toLowerCase();

            // Load nodes for Eylandoo
            if (this.selectedPanelType === 'eylandoo') {
                this.availableNodes = panel.registration_default_node_ids || [];
                this.availableServices = [];
            }
            // Load services for Marzneshin
            else if (this.selectedPanelType === 'marzneshin') {
                this.availableServices = panel.registration_default_service_ids || [];
                this.availableNodes = [];
            }
            else {
                this.availableNodes = [];
                this.availableServices = [];
            }
        }
    };
}
</script>

<style>
[x-cloak] { display: none !important; }

.input-label {
    display: block;
    margin-bottom: 8px;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.9rem;
}

.input-group {
    margin-bottom: 1rem;
}

.text-xs {
    font-size: 0.75rem;
}

.text-gray-400 {
    color: rgba(255, 255, 255, 0.5);
}

.mt-1 {
    margin-top: 0.25rem;
}
</style>
</body>
</html>
