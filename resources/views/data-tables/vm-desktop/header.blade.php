{{-- Header of VmDesktopItems in the cards layouts: the search only (without the settings menu) --}}
@php
    use Modules\CentralUi\app\Http\Livewire\DataTable\VmDesktopItems;

    /** @var VmDesktopItems $this */
    /** @var string $collectionName */
    $_config = $this->getOrderedFilterElementConfig()['search'] ?? null;
@endphp
@if ($_config)
    <div class="header mx-2 mb-2 max-w-md">
        @include('data-table::livewire.js-dt.filters.default-elements.text')
    </div>
@endif
