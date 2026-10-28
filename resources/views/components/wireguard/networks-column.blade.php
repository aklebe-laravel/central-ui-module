@php
    use Modules\DataTable\app\Http\Livewire\DataTable\Base\BaseDataTable;

    /**
     * Data table column: networks of a WireGuard member as badges "tunnel ip".
     *
     * @var BaseDataTable $this
     * @var array $item
     **/
    $networks = data_get($item, 'networks', []);
@endphp
<div class="flex flex-wrap gap-1">
    @forelse($networks as $network)
        <span class="inline-flex items-center gap-1 rounded-md border border-surface-300/60 bg-surface-200/60 px-2 py-0.5 text-xs whitespace-nowrap">
            <span class="font-semibold">{{ $network['tunnel'] }}</span>
            <span class="text-secondary-500">{{ $network['ip'] }}</span>
        </span>
    @empty
        <span class="decent">-</span>
    @endforelse
</div>
