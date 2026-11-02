<?php

use Modules\CentralUi\app\Http\Livewire\VmDesktop;

/**
 * @var VmDesktop $this
 */
?>
{{-- liveUpdateFallback (centralUi.js): polls the cards only while the websocket is disconnected --}}
<div x-data="liveUpdateFallback({{ VmDesktop::LIVE_UPDATE_FALLBACK_SECONDS }})">
    <div x-show="isBroken" x-cloak class="alert alert-warning mx-2 mb-2">
        {{ __('Live updates are disconnected. The VMs are refreshed every :seconds seconds.', ['seconds' => VmDesktop::LIVE_UPDATE_FALLBACK_SECONDS]) }}
    </div>

    {{-- form of the observe items, opened by the name in the list layout and "New Entry" (standard data table/form) --}}
    @livewire('central-ui::form.proxtop-observe-server-item', [
        'relatedLivewireDataTable' => 'central-ui::data-table.vm-desktop-items',
        'formObjectId' => null,
        'isFormOpen' => false,
        'objectInstanceDefaultValues' => ['user_id' => auth()->id()],
    ], key('vm-desktop-form'))

    {{-- the cards: data table with search, layouts as templates, one VmStatus component per VM --}}
    <livewire:central-ui::data-table.vm-desktop-items wire:key="vm-desktop-items"/>
</div>
