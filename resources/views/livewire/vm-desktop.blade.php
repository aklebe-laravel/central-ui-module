<?php

use Modules\CentralUi\app\Http\Livewire\VmDesktop;
use Modules\CentralUi\app\Http\Livewire\VmStatus;

/**
 * @var VmDesktop $this
 */

$layout = $this->sanitizeLayout($globalDesktopLayout ?? VmDesktop::LAYOUT_COMPACT);
// user setting 'desktop_vm_details': '' hides the details in all layouts
$showDetails = (($globalDesktopVmDetails ?? '') !== '');
?>
<div>
    @if ($observeItemIds ?? null)

        @if ($layout === VmDesktop::LAYOUT_LIST)
            {{-- List: one row per VM. The column template is shared with vm-status.blade.php (VmStatus::listGridCss()). --}}
            <div class="mx-2 overflow-hidden rounded-2xl border border-surface-300/60 bg-surface-100 shadow-sm">
                <div class="{{ VmStatus::listGridCss($showDetails) }} bg-surface-200/80 border-b border-surface-300 text-xs font-semibold uppercase tracking-wide text-secondary-600">
                    <div></div>
                    <div>{{ __('Name') }}</div>
                    <div class="text-center">{{ __('Status') }}</div>
                    @if ($showDetails)
                        <div class="hidden md:block text-center">CPU</div>
                        <div class="hidden md:block text-center">RAM</div>
                        <div class="hidden md:block text-center">Disk</div>
                        <div class="hidden lg:block text-center">{{ __('Online') }}</div>
                    @endif
                    <div class="text-center">{{ __('Switch') }}</div>
                </div>
                <div class="divide-y divide-surface-300/50">
                    @foreach ($observeItemIds as $observeItemId)
                        <livewire:central-ui::vm-status
                                :observeItemId="$observeItemId"
                                :density="$globalDesktopVmDetails"
                                :layout="$layout"
                                wire:key="{{ $this->getCardKey($observeItemId) }}"/>
                    @endforeach
                </div>
            </div>

        @elseif ($layout === VmDesktop::LAYOUT_ICONS)
            {{-- Icons: the compact card, smaller and denser (2 on mobile up to 10 on the largest screens) --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10 gap-2">
                @foreach ($observeItemIds as $observeItemId)
                    <div class="flex flex-col bg-surface-100 m-1 px-1 py-2 text-center rounded-xl min-w-0">
                        <livewire:central-ui::vm-status
                                :observeItemId="$observeItemId"
                                :density="$globalDesktopVmDetails"
                                :layout="$layout"
                                wire:key="{{ $this->getCardKey($observeItemId) }}"/>
                    </div>
                @endforeach
            </div>

        @else
            {{-- Compact (default): 1 card on mobile up to 6 on the largest screens --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6 gap-4">
                @foreach ($observeItemIds as $observeItemId)
                    <div class="flex flex-col bg-surface-100 m-2 px-1 py-4 text-center rounded-2xl">
                        <livewire:central-ui::vm-status
                                :observeItemId="$observeItemId"
                                :density="$globalDesktopVmDetails"
                                :layout="$layout"
                                wire:key="{{ $this->getCardKey($observeItemId) }}"/>
                    </div>
                @endforeach
            </div>
        @endif

    @endif
</div>
