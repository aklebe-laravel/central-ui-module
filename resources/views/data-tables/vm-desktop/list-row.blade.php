{{-- Column "Status" of VmDesktopItems in the list layout: the VmStatus component (lazy, live) --}}
@php
    use Modules\CentralUi\app\Http\Livewire\DataTable\VmDesktopItems;
    use Modules\CentralUi\app\Models\ProxtopObserveServerItem;

    /** @var VmDesktopItems $this */
    /** @var ProxtopObserveServerItem $item */
@endphp
<livewire:central-ui::vm-status
        :observeItemId="(int) $item->id"
        :density="$this->density"
        :layout="$this->layout"
        lazy
        wire:key="{{ $this->getCardKey($item->id) }}"/>
