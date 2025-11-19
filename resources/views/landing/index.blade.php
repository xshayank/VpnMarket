<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $homepage['seo_title'] ?? 'Falco Panel | فالکو پنل' }}</title>
    <meta name="description" content="{{ $homepage['seo_description'] ?? 'فالکو پنل | فالکو پنل - ثبت‌نام سریع نماینده با داشبورد فارسی و پایدار' }}">
    <meta property="og:title" content="{{ $homepage['seo_title'] ?? 'Falco Panel | فالکو پنل' }}">
    <meta property="og:description" content="{{ $homepage['seo_description'] ?? 'فالکو پنل | فالکو پنل - ثبت‌نام سریع نماینده با داشبورد فارسی و پایدار' }}">
    <meta property="og:image" content="{{ $homepage['og_image_url'] ?? asset('images/og-default.png') }}">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="website">
    <meta property="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1021;
            --card: rgba(255,255,255,0.04);
            --accent: #7bdcff;
            --accent-strong: #2de1a2;
            --text: #e8edf6;
            --muted: #c9d4e6;
            --border: rgba(255,255,255,0.08);
            --shadow: 0 20px 60px rgba(0,0,0,0.35);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Vazirmatn', 'Inter', system-ui;
            background: radial-gradient(circle at 20% 20%, rgba(79, 209, 197, 0.08), transparent 30%),
                        radial-gradient(circle at 80% 0%, rgba(125, 220, 255, 0.12), transparent 25%),
                        linear-gradient(135deg, #0a0e1b 0%, #0d1226 60%, #0b1021 100%);
            color: var(--text);
            line-height: 1.7;
        }
        a { color: inherit; }
        .container { width: min(1200px, 92vw); margin: 0 auto; }
        .hero { padding: 64px 0 40px; display: grid; gap: 32px; align-items: center; grid-template-columns: 1fr; }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 999px; background: rgba(255,255,255,0.06); color: var(--muted); font-size: 14px; }
        h1 { font-size: clamp(28px, 4vw, 40px); margin: 12px 0 10px; line-height: 1.3; }
        .subtitle { color: var(--muted); max-width: 640px; }
        .cta-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .btn { border: none; cursor: pointer; padding: 14px 18px; border-radius: 12px; font-weight: 700; text-decoration: none; transition: transform 0.15s ease, box-shadow 0.2s ease; }
        .btn-primary { background: linear-gradient(135deg, #22c1c3, #2de1a2); color: #0b1021; box-shadow: 0 15px 35px rgba(45,225,162,0.35); }
        .btn-secondary { background: rgba(255,255,255,0.08); color: var(--text); border: 1px solid var(--border); }
        .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
        .btn:hover { transform: translateY(-2px); }
        .glass { background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); }
        .grid { display: grid; gap: 16px; }
        .trust-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .steps-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .panels-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .plans-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .features-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .section { padding: 42px 0; }
        .section h2 { margin: 0 0 12px; font-size: 24px; }
        .section p.lead { margin: 0 0 24px; color: var(--muted); }
        .card { padding: 18px; border-radius: 14px; border: 1px solid var(--border); background: linear-gradient(180deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.04) 100%); }
        .muted { color: var(--muted); }
        .panel-tag { display: inline-flex; align-items: center; gap: 8px; padding: 8px 10px; background: rgba(255,255,255,0.06); border-radius: 10px; font-size: 13px; }
        .price { font-size: 22px; font-weight: 700; margin: 12px 0; }
        .faq-item { border-bottom: 1px solid var(--border); padding: 12px 0; }
        .faq-item:last-child { border-bottom: none; }
        .footer { border-top: 1px solid var(--border); padding: 24px 0 32px; margin-top: 32px; color: var(--muted); font-size: 14px; }
        .floating { position: relative; overflow: hidden; }
        .floating::after { content: ""; position: absolute; inset: -80px; background: radial-gradient(circle at 30% 30%, rgba(125,220,255,0.1), transparent 40%), radial-gradient(circle at 70% 0%, rgba(45,225,162,0.12), transparent 40%); z-index: 0; }
        .floating > * { position: relative; z-index: 1; }
        img.hero-media { max-width: 100%; width: 520px; border-radius: 18px; box-shadow: var(--shadow); border: 1px solid var(--border); object-fit: cover; }
        .badge { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: var(--text); }
        .inline-icon { font-size: 18px; }
        @media (min-width: 900px) {
            .hero { grid-template-columns: 1.1fr 0.9fr; }
        }
    </style>
</head>
<body>
@php
    $registerBase = url('/register');
    $defaultType = $homepage['default_reseller_type'] ?? 'wallet';
    $defaultPanel = $homepage['default_panel_id'] ?? null;
    $ctaQuery = ['reseller_type' => $defaultType];
    if ($defaultPanel) { $ctaQuery['primary_panel_id'] = $defaultPanel; }
    $primaryCtaLink = $registerBase . '?' . http_build_query($ctaQuery);
    $ratesAnchor = '#rates';
@endphp
<div class="container">
    <header class="hero">
        <div>
            <div class="pill">فالکو پنل • OpenVPN و V2Ray در یک پلتفرم • پشتیبانی ۲۴/۷</div>
            <h1>{{ $homepage['hero_title'] }}</h1>
            <p class="subtitle">{{ $homepage['hero_subtitle'] }}</p>
            <div class="cta-row">
                <a class="btn btn-primary" href="{{ $primaryCtaLink }}">{{ $homepage['primary_cta_text'] }}</a>
                <a class="btn btn-secondary" href="{{ $homepage['show_rates'] ? $ratesAnchor : $primaryCtaLink }}">{{ $homepage['secondary_cta_text'] }}</a>
            </div>
            <div class="cta-row" style="gap: 8px; margin-top: 18px;">
                <span class="badge"><span class="inline-icon">✔️</span>راه‌اندازی کمتر از ۵ دقیقه</span>
                <span class="badge"><span class="inline-icon">🔐</span>حفظ حریم خصوصی و لاگ صفر</span>
                <span class="badge"><span class="inline-icon">📊</span>داشبورد فارسی و ساده</span>
            </div>
        </div>
        @if(!empty($homepage['hero_media_url']))
            <div class="floating" aria-hidden="true">
                <img class="hero-media" src="{{ $homepage['hero_media_url'] }}" alt="نماینده VPN" loading="lazy">
            </div>
        @endif
    </header>

    <section class="section">
        <div class="glass card">
            <div class="grid trust-grid">
                @foreach($homepage['trust_badges'] as $badge)
                    <div class="badge" style="justify-content: center;">
                        <span class="inline-icon">{{ $badge['icon'] ?? '⭐' }}</span>
                        <div>
                            <div style="font-weight: 700;">{{ $badge['value'] ?? '' }}</div>
                            <div class="muted" style="font-size: 13px;">{{ $badge['label'] ?? '' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-header">
            <h2>چطور کار می‌کند؟</h2>
            <p class="lead">در سه مرحله ساده نماینده شوید و به هر دو پروتکل OpenVPN و V2Ray دسترسی داشته باشید.</p>
        </div>
        <div class="grid steps-grid">
            <div class="card"><div class="panel-tag">۱</div><h3>ثبت‌نام سریع</h3><p class="muted">فرم ثبت‌نام را پر کنید و نوع نماینده (کیف پول یا ترافیک) را انتخاب نمایید.</p></div>
            <div class="card"><div class="panel-tag">۲</div><h3>دسترسی به همه پنل‌ها</h3><p class="muted">به طور خودکار به تمام پنل‌های فعال (Eylandoo، Marzneshin و Marzban) دسترسی پیدا کنید.</p></div>
            <div class="card"><div class="panel-tag">۳</div><h3>شارژ اولیه</h3><p class="muted">کیف پول را شارژ کنید یا ترافیک اولیه را خریداری کنید تا حساب فعال شود.</p></div>
            <div class="card"><div class="panel-tag">۴</div><h3>ساخت کانفیگ</h3><p class="muted">از هر پنل که بخواهید کانفیگ بسازید، بفروشید و وضعیت مصرف را زنده مشاهده کنید.</p></div>
        </div>
    </section>

    @if($homepage['show_panels'] && $panels->isNotEmpty())
    <section class="section" id="panels">
        <div class="section-header">
            <h2>پنل‌های پشتیبانی‌شده</h2>
            <p class="lead">هر دو پروتکل OpenVPN و V2Ray در یک پلتفرم یکپارچه؛ میزبانی شده روی زیرساخت پایدار ما.</p>
        </div>
        <div class="grid panels-grid">
            @foreach($panels as $panel)
                @php
                    $panelLink = $registerBase . '?' . http_build_query([
                        'reseller_type' => $defaultType,
                        'primary_panel_id' => $panel->id,
                    ]);
                @endphp
                <div class="card glass">
                    <div class="panel-tag">{{ ucfirst($panel->panel_type) }}</div>
                    <h3 style="margin: 8px 0 6px;">{{ $panel->name }}</h3>
                    <p class="muted">اتصال امن و پایدار با مدیریت ساده. انتخاب این پنل را در هنگام ثبت‌نام پیش‌فرض می‌کنیم.</p>
                    <a class="btn btn-secondary" href="{{ $panelLink }}" aria-label="ثبت‌نام برای {{ $panel->name }}">انتخاب این پنل</a>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if($homepage['show_panels'] && $panels->isEmpty())
        <section class="section" id="panels-empty">
            <div class="glass card" style="text-align: center;">
                <h2>در انتظار اتصال پنل‌ها</h2>
                <p class="muted">فعلاً هیچ پنل فعالی در دسترس نیست. برای شروع سریع، به لیست انتظار بپیوندید تا اولین نفر باشید.</p>
                <a class="btn btn-primary" href="https://t.me/xShayank" rel="noopener" target="_blank">به لیست انتظار بپیوندید</a>
            </div>
        </section>
    @endif

    @if($homepage['show_rates'] ?? true)
        @include('landing.partials._rates', [
            'registerBase' => $registerBase,
            'trafficRate' => $trafficRate ?? config('billing.traffic_rate_per_gb', 750),
            'reseller' => $reseller ?? null,
        ])
    @endif

    <section class="section">
        <div class="section-header">
            <h2>چرا نماینده ما شوید؟</h2>
            <p class="lead">ویژگی‌هایی که خیال شما را بابت کیفیت، سرعت و پشتیبانی راحت می‌کند.</p>
        </div>
        <div class="grid features-grid">
            @foreach($homepage['features'] as $feature)
                <div class="card glass">
                    <div class="inline-icon">{{ $feature['icon'] ?? '✨' }}</div>
                    <h3 style="margin: 8px 0 6px;">{{ $feature['title'] ?? '' }}</h3>
                    <p class="muted">{{ $feature['description'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if($homepage['show_testimonials'] && !empty($homepage['testimonials']))
    <section class="section">
        <div class="section-header">
            <h2>بازخورد نماینده‌ها</h2>
            <p class="lead">نظرات چند نماینده فعال درباره تجربه همکاری.</p>
        </div>
        <div class="grid panels-grid">
            @foreach($homepage['testimonials'] as $testimonial)
                <div class="card glass">
                    <p class="muted">“{{ $testimonial['quote'] ?? '' }}”</p>
                    <div style="margin-top: 12px; font-weight: 700;">{{ $testimonial['name'] ?? '' }}</div>
                    <div class="muted" style="font-size: 13px;">{{ $testimonial['role'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if($homepage['show_faq'] && !empty($homepage['faqs']))
    <section class="section">
        <div class="section-header">
            <h2>پرسش‌های متداول</h2>
            <p class="lead">اگر سوالی دارید اینجا بررسی کنید یا با ما در ارتباط باشید.</p>
        </div>
        <div class="glass card">
            @foreach($homepage['faqs'] as $faq)
                <div class="faq-item">
                    <h3 style="margin: 0 0 6px;">{{ $faq['question'] ?? '' }}</h3>
                    <p class="muted" style="margin: 0;">{{ $faq['answer'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    <section class="section">
        <div class="glass card" style="text-align: center;">
            <h2>همین حالا شروع کنید</h2>
            <p class="muted">انتخاب نوع نماینده و پنل اصلی در ثبت‌نام قابل تغییر است.</p>
            <div class="cta-row" style="justify-content: center;">
                <a class="btn btn-primary" href="{{ $registerBase . '?reseller_type=wallet' }}">شروع نماینده کیف پول</a>
                <a class="btn btn-secondary" href="{{ $registerBase . '?reseller_type=traffic' }}">شروع نماینده ترافیک</a>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 12px;">
            <div><a href="{{ url('/register') }}">ثبت نام</a></div>
            <div><a href="{{ url('/login') }}">ورود</a></div>
            <div><a href="{{ url('/reseller') }}">پنل نماینده</a></div>
            <div><a href="https://t.me/xShayank" rel="noopener" target="_blank">پشتیبانی</a></div>
            <div><a href="{{ url('/privacy') }}">سیاست حریم خصوصی</a></div>
            <div><a href="{{ url('/terms') }}">شرایط استفاده</a></div>
        </div>
    </footer>
</div>
</body>
</html>
