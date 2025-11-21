<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} - ثبت نام</title>

    <link rel="stylesheet" href="{{ asset('themes/auth/modern/style.css') }}">
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="modern-auth-body">

@php
    $prefill = $prefill ?? [];
    $initialResellerType = old('reseller_type') ?? ($prefill['reseller_type'] ?? '');
@endphp

<div class="auth-card">

    <div class="auth-logo">{{ $settings->get('auth_brand_name', 'ARV') }}</div>
    <h2 class="auth-title">ایجاد حساب کاربری جدید</h2>

    <form method="POST" action="{{ route('register') }}" x-data="registrationForm(@js($initialResellerType))" x-init="init()">
        @csrf

        @if(request()->has('ref'))
            <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
        @endif

        <div class="input-group">
            <input id="name" class="input-field" type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="نام کامل">
            <x-input-error :messages="$errors->get('name')" class="input-error-message" />
        </div>

        <div class="input-group">
            <input id="email" class="input-field" type="email" name="email" value="{{ old('email') }}" required placeholder="ایمیل">
            <x-input-error :messages="$errors->get('email')" class="input-error-message" />
        </div>

        <div class="input-group">
            <input id="password" class="input-field" type="password" name="password" required placeholder="رمز عبور">
            <x-input-error :messages="$errors->get('password')" class="input-error-message" />
        </div>

        <div class="input-group">
            <input id="password_confirmation" class="input-field" type="password" name="password_confirmation" required placeholder="تکرار رمز عبور">
            <x-input-error :messages="$errors->get('password_confirmation')" class="input-error-message" />
        </div>

        {{-- Reseller Type Selection --}}
        <div class="input-group">
            <label class="input-label">نوع حساب نماینده:</label>
            <select name="reseller_type" class="input-field" x-model="resellerType" required>
                <option value="">انتخاب کنید...</option>
                <option value="wallet">کیف پول (۱۵۰,۰۰۰ تومان حداقل شارژ اول)</option>
                <option value="traffic">ترافیک (۱۰۰۰ گیگابایت حداقل خرید اول)</option>
            </select>
            <x-input-error :messages="$errors->get('reseller_type')" class="input-error-message" />
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
function registrationForm(defaultType) {
    return {
        resellerType: defaultType || '',

        init() {
            // Simple form with no panel selection
        }
    };
}
</script>
</body>
</html>
