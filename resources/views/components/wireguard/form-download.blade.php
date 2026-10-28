@php
    use Modules\CentralUi\app\Http\Livewire\Form\WireguardMember;

    /**
     * Form action: download the zip archive with the client configs of the opened WireGuard member.
     *
     * @var WireguardMember $this
     */
    if (!$this->formObjectId || !data_get($this->dataSource, 'has_configs')) {
        return;
    }
@endphp
@include('form::components.form.actions.defaults.default-button',[
    'buttonLabel' => __('Download client configs'),
    'buttonClick' => 'downloadConfig',
    'buttonCss' => 'form-action-download',
    'buttonColor' => 'secondary',
])
