<div class="flex items-center rounded-lg border border-surface-200/10"
     x-data="{
        lastSeen: '{{ $lastTimestamp }}',
        trigger() {
            const el = this.$refs.bubble;
            if (!el) return;
            el.style.animation = 'none';
            el.offsetHeight;
            el.style.animation = null;
        }
     }"
     x-init="$watch('lastSeen', value => trigger())"
     x-on:pulse-reset="trigger()"
     x-effect="lastSeen = '{{ $lastTimestamp }}'">

    <div class="relative flex items-center justify-center w-4 h-4">
        <!-- wire:ignore sorgt dafür, dass Livewire die Animation nicht abbricht -->
        <div wire:ignore class="relative flex items-center justify-center w-4 h-4">
            <div x-ref="bubble"
                 class="w-3 h-3 rounded-full bg-blue-950 animate-pulse-blue">
            </div>
            <div class="absolute inset-0 border-2 border-blue-500/10 rounded-full"></div>
        </div>
    </div>

    @unless($minimalistic)
    <div class="flex flex-col text-left">
        <span class="text-[10px] font-bold uppercase tracking-tighter text-surface-500">
            {{ $label }}
        </span>
        <span class="text-xs font-mono text-blue-500/80">
            {{ $lastTimestamp }}
        </span>
    </div>
    @endunless
</div>
