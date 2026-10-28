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
     $schedules = implode(', ', $item->schedules->pluck('command_code')->toArray());
     $vms = implode(', ', $item->vms->pluck('name')->toArray());
@endphp
<div>{{ $value }}</div>
<div>Schedules: {{ $schedules ?: '-' }}</div>
<div>VMs: {{ $vms ?: '-' }}</div>