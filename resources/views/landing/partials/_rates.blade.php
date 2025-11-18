<section class="section" id="rates">
    <div class="section-header">
        <h2>تعرفه‌ها</h2>
        <p class="lead">مدل‌های حساب ریسلر را شفاف ببینید و مناسب خود را انتخاب کنید.</p>
    </div>
    <div class="grid plans-grid">
        <div class="card glass">
            <div class="panel-tag">نماینده Wallet</div>
            <h3 style="margin: 10px 0 4px;">پرداخت به تومان — شارژ کیف پول</h3>
            <p class="muted">حساب ریسلر با تومان شارژ می‌شود. شارژ اولیه برای فعال‌سازی: ۱۵۰,۰۰۰ تومان. محدودیت کانفیگ: ۱۰۰۰ (ریسلرهای Wallet). کاربران نهایی: نامحدود.</p>
            <div class="price">شارژ اولیه پیشنهادی: ۱۵۰,۰۰۰ تومان</div>
            <p class="muted">هر زمان نیاز داشتید کیف پول را دوباره شارژ کنید و بدون محدودیت کاربر بسازید.</p>
            <a class="btn btn-primary" href="{{ $registerBase . '?reseller_type=wallet' }}">شروع نماینده کیف پول</a>
        </div>
        <div class="card glass">
            <div class="panel-tag">نماینده Traffic</div>
            <h3 style="margin: 10px 0 4px;">پرداخت بر اساس ترافیک</h3>
            @php
                $effectiveRate = $reseller?->getTrafficPricePerGb() ?? $trafficRate;
            @endphp
            <div class="price">هر گیگابایت = {{ number_format($effectiveRate) }} تومان</div>
            @if($reseller && $reseller->getTrafficPricePerGb() !== $trafficRate)
                <p class="muted">نرخ اختصاصی شما (نرخ پیش‌فرض {{ number_format($trafficRate) }} تومان برای هر گیگابایت است).</p>
            @else
                <p class="muted">نرخ پیش‌فرض: {{ number_format($trafficRate) }} تومان برای هر گیگابایت.</p>
            @endif
            <p class="muted">شارژ اولیه: ۲۵۰ گیگابایت. هر زمان نیاز بود ترافیک اضافه خریداری کنید.</p>
            <a class="btn btn-secondary" href="{{ $registerBase . '?reseller_type=traffic' }}">شروع نماینده ترافیک</a>
        </div>
    </div>
</section>
