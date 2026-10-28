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
     $value = implode(', ', $item->tag_names);

@endphp
<div>{{ $value }}</div>
