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
        $value = $item->is_enabled && $item->vm->is_enabled;
    }
    elseif ($item instanceof ProxtopServerVm) {
        $value = $item->is_enabled;
    }
@endphp
@include('data-table::livewire.js-dt.tables.columns.bool-gray-green')