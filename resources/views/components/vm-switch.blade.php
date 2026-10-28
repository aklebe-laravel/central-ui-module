{{--
    VM switch. A plain (anonymous) Blade component, NOT a Livewire component.

    It has no state of its own. Because it is rendered inside the view of VmStatus,
    wire:click talks directly to VmStatus: no events, no props sync, no wire:key tricks.

    Usage (inside vm-status.blade.php):
        <x-central-ui::vm-switch
            :state="$this->observeItemData['ui_state']"
            :remaining="$this->observeItemData['grace_remaining']"
            :canSwitch="$this->observeItemData['can_switch']"
            :reasons="$this->observeItemData['switch_popup_content']"/>
--}}
@use('Modules\CentralUi\app\Enums\VmUiState')
@props([
    'state',                 // VmUiState
    'remaining' => 0,        // seconds left in the grace period (only used in state "grace")
    'canSwitch' => true,     // same value the server checks in the actions
    'reasons'   => [],       // why switching is not possible (shown in the popup)
])
@php
    // state -> Livewire method of VmStatus. null = button is disabled.
    $action = !$canSwitch ? null : match ($state) {
        VmUiState::Stopped => 'requestStart',
        VmUiState::Running => 'requestShutdown',
        VmUiState::Grace   => 'cancelShutdown',   // NOT disabled: a click means "cancel"
        default            => null,
    };

    $label = !$canSwitch ? __('Not switchable') : match ($state) {
        VmUiState::Stopped => __('Launch VM'),
        VmUiState::Running => __('Shutdown VM'),
        VmUiState::Grace   => __('Cancel shutdown'),
        default            => __('Busy ...'),
    };

    // "on" while the VM is running (including the grace period)
    $isOn = in_array($state, [VmUiState::Running, VmUiState::Grace], true);
@endphp

<button type="button"
        aria-label="{{ $label }}"
        class="relative overflow-hidden image-switch {{ $isOn ? 'on' : 'off' }} disabled:opacity-50 {{ $state === VmUiState::Busy ? 'disabled:cursor-wait' : 'disabled:cursor-not-allowed' }}"
        @if($action)
            wire:click="{{ $action }}"
        wire:target="{{ $action }}"
        wire:loading.attr="disabled"
        @else
            disabled
        @endif
>
    {{-- Grace period overlay: pure display, the server decides everything --}}
    @if($state === VmUiState::Grace)
        <div x-data="graceCountdown(@js($remaining))"
             class="absolute inset-0 z-10 flex flex-col justify-end bg-surface/30 backdrop-blur-[1px] pointer-events-none">
            <div class="flex-grow flex items-center justify-center text-2xl font-bold text-info-700" x-text="Math.ceil(left)"></div>
            <div class="w-full h-1.5 bg-info-200/50">
                <div class="h-full bg-info-500 transition-all duration-100 ease-linear" :style="'width: ' + (left / total * 100) + '%'"></div>
            </div>
        </div>
    @endif

    <x-website-base::popup :title="$label">
        <ul class="mx-2 text-left list-disc text-secondary-500 text-sm">
            @foreach($reasons as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </x-website-base::popup>
</button>