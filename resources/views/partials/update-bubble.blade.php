{{--
    Small pulse that shows when a broadcast of a channel arrived (title: label + local time).
    @include('central-ui::partials.update-bubble', ['channel' => 'proxmox-resource-updates', 'label' => '...', 'key' => '...'])

    Pure Alpine (updateBubble in centralUi.js), no Livewire component and no server request:
    one Echo subscription per channel for the whole page forwards the events to all bubbles.
    wire:ignore keeps it out of the re-render of the VM card, so the animation is not interrupted.
--}}
<div wire:ignore
     wire:key="{{ $key }}"
     x-data="updateBubble(@js($channel))"
     :title="@js($label) + ': ' + lastSeen"
     class="flex items-center rounded-lg border border-surface-200/10">
    <div class="relative flex items-center justify-center w-4 h-4">
        <div x-ref="bubble" class="w-3 h-3 rounded-full bg-blue-950 animate-pulse-blue"></div>
        <div class="absolute inset-0 border-2 border-blue-500/10 rounded-full"></div>
    </div>
</div>
