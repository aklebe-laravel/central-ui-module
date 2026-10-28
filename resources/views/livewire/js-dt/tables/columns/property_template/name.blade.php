@php
    use Illuminate\Database\Eloquent\Model;
    use Modules\CentralUi\app\Models\ProxtopPropertyTemplate;
    use Modules\DataTable\app\Http\Livewire\DataTable\Base\BaseDataTable;

    /**
     * @var BaseDataTable $this
     * @var ProxtopPropertyTemplate $item
     * @var string $name
     * @var mixed $value
     **/
    if ($value) {
        $value = sprintf("%s (%s schedules, %s vms)", $value, $item->schedules->count(), $item->vms->count());
    } else {
        $value = '???';
    }
@endphp
@include('data-table::livewire.js-dt.tables.columns.default')