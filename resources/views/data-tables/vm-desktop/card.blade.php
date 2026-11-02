{{-- Desktop card (compact and icons layout) of VmDesktopItems, the content is the VmStatus component (lazy, see VmStatus::placeholder()) --}}
@php
    use Modules\CentralUi\app\Http\Livewire\DataTable\VmDesktopItems;
    use Modules\CentralUi\app\Http\Livewire\VmDesktop;
    use Modules\CentralUi\app\Models\ProxtopObserveServerItem;

    /** @var VmDesktopItems $this */
    /** @var ProxtopObserveServerItem $item */
    $_isIcons = ($this->layout === VmDesktop::LAYOUT_ICONS);
    $_cardKey = $this->getCardKey($item->id);
@endphp
<div wire:key="wrap-{{ $_cardKey }}"
        @class([
            'flex flex-col bg-surface-100 text-center',
            'm-1 px-1 py-2 rounded-xl min-w-0' => $_isIcons,
            'm-2 px-1 py-4 rounded-2xl' => !$_isIcons,
        ])>
    <livewire:central-ui::vm-status
            :observeItemId="(int) $item->id"
            :density="$this->density"
            :layout="$this->layout"
            lazy
            wire:key="{{ $_cardKey }}"/>
</div>
