@php
    use Modules\CentralUi\app\Http\Livewire\DataTable\WireguardMember;

    /**
     * Row command: download the zip archive with the client configs of a WireGuard member.
     *
     * @var WireguardMember $this
     * @var array $item
     **/
    if (!data_get($item, 'has_configs')) {
        return;
    }
@endphp
<button
        class="btn btn-sm btn-outline-primary text-primary-500 dt-interaction {{ data_get($this->mobileCssClasses, 'button', '') }}"
        wire:click="downloadConfig('{{ data_get($item, $this->columnNameId) }}')"
        title="{{ __('Download client configs') }}"
>
    <x-lucide-download class="h-5 w-5"/>
</button>
