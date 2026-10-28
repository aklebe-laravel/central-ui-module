@php
    use Illuminate\Database\Eloquent\Model;
    use Modules\CentralUi\app\Models\ProxtopObserveServerItem;
    use Modules\CentralUi\app\Models\ProxtopServerVm;
    use Modules\DataTable\app\Http\Livewire\DataTable\Base\BaseDataTable;

    /**
     * @var BaseDataTable $this
     * @var Model $item
     * @var string $name
     * @var mixed $value
     **/
        if ($item instanceof ProxtopObserveServerItem) {
            $value = $item->getDisplayName();
        }
        elseif ($item instanceof ProxtopServerVm) {
            $value = $item->getDisplayName();
        }
@endphp
@include('data-table::livewire.js-dt.tables.columns.default')