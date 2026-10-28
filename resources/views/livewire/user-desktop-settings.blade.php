@php
    use Modules\CentralUi\app\Http\Livewire\UserDesktopSettings;

    /**
     * @var UserDesktopSettings $this
     * @var array $options value => ['label' => ..., 'icon' => ...]
     */
@endphp
{{-- Segmented control, used inside the user menu (see components/menu-control.blade.php) --}}
<div class="flex gap-0.5 rounded-lg bg-surface-200/70 p-0.5" role="radiogroup">
    @foreach($options as $optionValue => $option)
        @php $isActive = ((string) $optionValue === $value); @endphp
        <button type="button"
                role="radio"
                aria-checked="{{ $isActive ? 'true' : 'false' }}"
                title="{{ $option['label'] }}"
                wire:click="select(@js((string) $optionValue))"
                wire:loading.attr="disabled"
                @class([
                    'flex flex-1 items-center justify-center gap-1 rounded-md px-2 py-1 text-xs transition-colors focus:outline-none cursor-pointer disabled:cursor-wait',
                    'flex-col' => $mobile,
                    'bg-surface-50 text-secondary-600 font-semibold shadow-sm' => $isActive,
                    'text-surface-500 hover:text-secondary-500' => !$isActive,
                ])>
            <x-dynamic-component :component="'lucide-'.$option['icon']" class="h-4 w-4 shrink-0"/>
            <span class="truncate">{{ $option['label'] }}</span>
        </button>
    @endforeach
</div>
