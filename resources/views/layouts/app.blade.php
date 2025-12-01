<!DOCTYPE html>
<!-- Powered by VPNMarket CMS | v1.0 -->

<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Livewire Styles -->
        @livewireStyles

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
    <!-- Powered by VPNMarket CMS | v1.0 -->

    <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @if(auth()->check() && auth()->user()->reseller)
                @include('partials.reseller-nav')
            @else
                @include('layouts.navigation')
            @endif

            <!-- Page Heading -->
            @isset($header)
                @if(!(auth()->check() && auth()->user()->reseller))
                    <header class="bg-white dark:bg-gray-800 shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif
            @endisset


            <main>
                {{ $slot }}
            </main>
        </div>
        
        @stack('scripts')
        
        <!-- Livewire Scripts -->
        @livewireScripts
    </body>
</html>
