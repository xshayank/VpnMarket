@props([
    'used' => 0,
    'limit' => 1,
    'settled' => 0,
    'showText' => true,
    'showTotal' => true,
    'size' => 'md',
    'unit' => 'GB',
    'precision' => 2,
])

@php
    $usedValue = is_numeric($used) ? $used : 0;
    $limitValue = is_numeric($limit) && $limit > 0 ? $limit : 1;
    $settledValue = is_numeric($settled) ? $settled : 0;
    
    // Calculate percentage based on current usage only
    $percent = min(100, round(($usedValue / $limitValue) * 100));
    
    // Total usage (current + settled)
    $totalUsed = $usedValue + $settledValue;
    
    // Color coding based on percentage
    $colorClass = match(true) {
        $percent >= 90 => 'bg-red-500',
        $percent >= 70 => 'bg-amber-500',
        default => 'bg-blue-500',
    };
    
    // Size variants
    $heightClass = match($size) {
        'sm' => 'h-1.5',
        'lg' => 'h-3',
        default => 'h-2',
    };
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    {{-- Progress Bar Track --}}
    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full {{ $heightClass }} mb-1.5">
        <div class="{{ $heightClass }} rounded-full transition-all duration-300 {{ $colorClass }}" 
             style="width: {{ $percent }}%"></div>
    </div>
    
    @if ($showText)
        {{-- Usage Text --}}
        <div class="text-xs text-gray-600 dark:text-gray-400">
            <span class="font-medium">{{ round($usedValue, $precision) }}</span> / {{ round($limitValue, $precision) }} {{ $unit }}
            <span class="text-gray-400 dark:text-gray-500">({{ $percent }}%)</span>
        </div>
    @endif
    
    @if ($showTotal && $settledValue > 0)
        {{-- Total Usage (including settled) --}}
        <div class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">
            کل: {{ round($totalUsed, $precision) }} {{ $unit }}
        </div>
    @endif
</div>
