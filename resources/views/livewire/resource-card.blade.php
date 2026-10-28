@php
    $lucideIcon = "lucide-{$icon}";
@endphp
<div class="col-span-{{ $colSpan }} p-2 bg-surface-50 border border-surface-200 rounded-lg shadow-sm hover:border-primary-500/50 transition-all duration-300">

    <x-website-base::popup title="{{ $label }} Details" componentView="components.popup.stats-details" :customData="$customData" :stableId="$stableId"/>

    @if($density == 'compact')
        <div class="flex justify-between items-center mb-1">
            <div class="flex items-center gap-2">
                <x-dynamic-component :component="$lucideIcon" class="h-5 w-5"/>
                <span class="text-[10px] font-black text-surface-500 uppercase tracking-tighter">{{ $label }}</span>
                <span @class(['text-xs font-bold leading-none', $styles['phase1']['text'] ?? ''])>
                {{ number_format($phase1Avg, 0) }}%
            </span>
            </div>
            <span class="text-[9px] text-surface-400 font-medium">24h: {{ number_format($phase3Avg, 0) }}%</span>
        </div>

        {{-- The "Minimal Layer": Sparkline and Bars in one container --}}
        <div class="relative h-6 w-full bg-surface-200/50 rounded-sm overflow-hidden border border-surface-300/20">
            {{-- Background Sparkline (As an area fill) --}}
            <div class="absolute inset-0 opacity-20">
                <svg viewBox="0 0 100 20" class="w-full h-full" preserveAspectRatio="none">
                    <path
                            fill="currentColor"
                            class="text-primary-500"
                            d="M 0 20 @foreach($history as $index => $value) L {{ ($index / (max(count($history) - 1, 1))) * 100 }} {{ 20 - ($value / 100 * 20) }} @endforeach L 100 20 Z"
                    />
                </svg>
            </div>

            {{-- Threshold Marker --}}
            <div @class(["absolute top-0 bottom-0 w-px z-30", $styles['limit']['bg'] ?? '']) style="left: {{ $limit }}%"></div>

            {{-- Phase3 Average Indicator (Simple Line instead of Bar) --}}
            <div @class(["absolute top-0 bottom-0 w-0.5 z-10", $styles['phase3']['bg'] ?? '']) style="left: {{ $phase3Avg }}%"></div>

            {{-- Idle Indicator (Simple Line instead of Bar) --}}
            <div @class(["absolute top-0 bottom-0 w-0.5 z-10", $styles['idle']['bg'] ?? '']) style="left: {{ $idleValue }}%"></div>

            {{-- Phase1 Average (Slim glowing bar at bottom) --}}
            <div @class([
                'absolute bottom-2 left-0 h-1 z-20 rounded-r-full transition-all duration-500',
                $styles['phase1']['bg'] ?? ''
            ]) style="width: {{ $phase1Avg }}%"></div>
            {{-- Phase1 Average (Slim glowing bar at bottom) --}}
            <div @class([
                'absolute bottom-0 left-0 h-1 z-20 rounded-r-full transition-all duration-500',
                $styles['phase2']['bg'] ?? ''
            ]) style="width: {{ $phase2Avg }}%"></div>
        </div>
    @else
        <div class="flex justify-between items-end mb-2">
            <span class="text-[10px] font-bold text-surface-500 uppercase tracking-tighter">{{ $label }}</span>
            <div class="flex items-baseline gap-1">
                {{-- Dynamisch gefärbte Prozentanzeige via Component-Properties --}}
                <span @class([
                'text-lg font-black leading-none transition-colors duration-500',
                $styles['phase2']['text'] ?? ''
            ])>
                {{ number_format($phase2Avg, 0) }}%
            </span>
                <span class="text-[10px] text-surface-400 font-medium uppercase">avg/h</span>
            </div>
        </div>

        {{-- Sparkline: Dezenter Hintergrund-Trend --}}
        <div class="h-8 w-full opacity-30">
            <svg viewBox="0 0 100 20" class="w-full h-full" preserveAspectRatio="none">
                <polyline
                        fill="none"
                        stroke="currentColor"
                        class="text-primary-500"
                        stroke-width="1.2"
                        points="@foreach($history as $index => $value){{ ($index / (max(count($history) - 1, 1))) * 100 }},{{ 20 - ($value / 100 * 20) }} @endforeach"
                />
            </svg>
        </div>

        {{-- Dual-Bar System --}}
        <div class="relative mt-2 h-3 w-full bg-surface-200 rounded-sm overflow-hidden border border-surface-300/30">
            {{-- Threshold Marker (Gefahrenschwelle) --}}
            <div @class(["absolute top-0 bottom-0 w-0.5 shadow-[0_0_8px_rgba(220,38,38,0.6)] z-30", $styles['limit']['bg'] ?? ''])
                 style="left: {{ $limit }}%"></div>

            {{-- Daily Average (Hintergrundbalken) --}}
            <div class="absolute top-0 left-0 h-full bg-surface-400/20 z-10 transition-all duration-1000 ease-in-out"
                 style="width: {{ $phase3Avg }}%"></div>

            {{-- Hourly Average (Hauptbalken mit Status-Farbe) --}}
            <div @class([
            'absolute top-[30%] left-0 h-[40%] z-20 rounded-r-sm transition-all duration-500 ease-out',
            $styles['phase2']['bg'] ?? ''
        ]) style="width: {{ $phase2Avg }}%"></div>
        </div>

        {{-- Footer Metriken --}}
        <div class="flex justify-between mt-1.5 text-[9px] font-bold uppercase tracking-tight">
            <div class="flex gap-2">
                <span class="text-surface-500">24h: <span class="text-surface-900">{{ number_format($phase3Avg, 1) }}%</span></span>
            </div>
            <span @class([
            'transition-colors duration-500',
            $phase2Avg > $limit ? (($styles['limit']['text'] ?? '') . ' animate-pulse') : 'text-surface-400'
        ])>
            Limit: {{ number_format($limit, 0) }}%
        </span>
        </div>

    @endif
</div>