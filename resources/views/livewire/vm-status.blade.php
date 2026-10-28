<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Modules\CentralUi\app\Http\Livewire\VmDesktop;
use Modules\CentralUi\app\Http\Livewire\VmStatus;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\CentralUi\app\Models\ProxtopObserveServerItem;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\SystemBase\app\Services\ThemeService;

/**
 * @var VmStatus $this
 * @var array    $styles
 */
if ($dataAvailable = ($this->observeItem && $this->observeItemData && ($this->observeItemData['name'] ?? ''))) {
    $systemBase = app('system_base');
    $uptimeInterval = CarbonInterval::seconds($this->observeItemData['uptime'] ?? null)
        ->cascade();
    $onlineSince = now()->diff(now()->add($uptimeInterval));

    // one state for the small layouts (icons, list)
    $d = $this->observeItemData;
    [$stateLabel, $stateBadge, $stateBorder] = match (true) {
        $d['status_is_pending'] => [__('Shutdown pending'), 'badge-warning', 'border-yellow-800'],
        $d['status_is_locked'] => [__('Busy ...'), 'badge-warning', 'border-yellow-800'],
        $d['status_is_running'] => [__('Online'), 'badge-success', 'border-green-800'],
        $d['status_has_stopped'] => [__('Offline'), 'badge-error', 'border-red-800'],
        default => [__('Unknown'), 'badge-muted', 'border-gray-800'],
    };
    $imageOpacity = $d['status_is_running'] || $d['status_is_pending'] ? '' : ($d['status_has_stopped'] ? 'opacity-40' : 'opacity-60');
}
$layout = in_array($layout ?? '', VmDesktop::LAYOUTS, true) ? $layout : VmDesktop::LAYOUT_COMPACT;
// "icons" is the compact card, just smaller (more cards per row)
$isIcons = ($layout === VmDesktop::LAYOUT_ICONS);
// user setting 'desktop_vm_details': '' hides the details in all layouts
$showDetails = (($density ?? '') !== '');
?>
{{-- Cards (compact, icons) fill the full height of their grid cell, so the switch can stick to the bottom --}}
<div @class(['flex flex-1 flex-col' => $layout !== VmDesktop::LAYOUT_LIST])>
    @if ($dataAvailable && $layout === VmDesktop::LAYOUT_LIST)
        {{-- ------------------------------------------------------------------ --}}
        {{-- Layout "list": one row, columns see VmStatus::listGridCss()         --}}
        {{-- ------------------------------------------------------------------ --}}
        <div class="{{ VmStatus::listGridCss($showDetails) }} hover:bg-surface-200/50 transition-colors">
            {{-- image --}}
            <div>
                @if($d['url'])
                    <a href="{{ $d['url'] }}" target="_blank"> @endif
                        <img class="h-10 w-10 rounded-lg object-cover border-[0.5px] {{ $stateBorder }} {{ $imageOpacity }} {{ $d['status_is_locked'] ? 'animate-pulse' : '' }}"
                             src="{{ $d['image_asset_path'] }}" alt="Server Icon"/>
                        @if($d['url']) </a>
                @endif
            </div>

            {{-- name --}}
            <div class="min-w-0">
                <div class="truncate font-semibold text-secondary-800" title="{{ $d['name'] }}">{{ $d['name'] }}</div>
                <div class="text-[10px] text-secondary-400">
                    ID {{ $this->observeItem->vm->extern_vm_id }}
                </div>
            </div>

            {{-- status --}}
            <div class="text-center">
                <span class="badge {{ $stateBadge }} rounded-full text-[10px] px-2 whitespace-nowrap">{{ $stateLabel }}</span>
            </div>

            @if ($showDetails)
                {{-- cpu, ram, disk --}}
                <div class="hidden md:block {{ $d['status_is_running'] ? '' : 'opacity-25' }}">
                    <x-central-ui::simple-number icon="cpu"
                                                 :value="$d['cpu_usage']"
                                                 stableId="popup-cpu-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('CPU Usage: :value', ['value' => $d['cpu_usage']]) }}"
                                                 popupComponentContent="No more data available."/>
                </div>
                <div class="hidden md:block {{ $d['status_is_running'] ? '' : 'opacity-25' }}">
                    <x-central-ui::simple-number icon="memory-stick"
                                                 :value="$d['mem_usage']"
                                                 stableId="popup-mem-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('RAM Usage: :value%', ['value' => $d['mem_usage']]) }}"
                                                 popupComponentView="components.popup.stats-details"
                                                 :popupCustomData="[
                                                'Mem Used' =>  $systemBase->bytesToHuman($d['mem_used'] ?? 0),
                                                'Max Mem' =>  $systemBase->bytesToHuman($d['mem_max'] ?? 0),
                                             ]"/>
                </div>
                <div class="hidden md:block {{ $d['status_is_running'] ? '' : 'opacity-25' }}">
                    <x-central-ui::simple-number icon="hard-drive-upload"
                                                 :value="$d['disk_usage']"
                                                 stableId="popup-disk-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('Disk Usage :value%', ['value' => $d['disk_usage']]) }}"
                                                 popupComponentView="components.popup.stats-details"
                                                 :popupCustomData="[]"/>
                </div>

                {{-- online since --}}
                <div class="hidden lg:block text-center text-xs text-secondary-600 tabular-nums">
                    @if ($d['status_is_running'])
                        {{ sprintf("%02d:%02d:%02d", $onlineSince->days, $onlineSince->h, $onlineSince->i) }}
                    @else
                        <span class="text-secondary-400">&ndash;</span>
                    @endif
                </div>
            @endif

            {{-- switch --}}
            <div class="[&_.image-switch]:mt-0 [&_.image-switch]:p-1">
                <x-central-ui::vm-switch
                        :state="$d['ui_state']"
                        :remaining="$d['grace_remaining']"
                        :canSwitch="$d['can_switch']"
                        :reasons="$d['switch_popup_content']"/>
            </div>
        </div>

    @elseif ($dataAvailable)
        {{-- ------------------------------------------------------------------ --}}
        {{-- Layout "compact" (default) and "icons" (same card, smaller)        --}}
        {{-- ------------------------------------------------------------------ --}}
        {{--        <div class="{{ $this->observeItemData['status_is_locked'] ? 'opacity-70 pointer-events-none cursor-not-allowed' : '' }}">--}}
        <div class="flex flex-1 flex-col">

            <div class="mt-0 py-0 px-1 mb-2 font-bold tracking-tight">
                <div class="grid grid-cols-[auto_1fr]">
                    <div class="flex {{ $this->observeItemData['status_has_stopped'] ? 'opacity-50' : '' }}">
                        <livewire:central-ui::update-bubble
                                :minimalistic="true"
                                label="API RRD Update"
                                channel="proxmox-rrd-updates"
                                event=".Modules\CentralUi\app\Events\ProxmoxApiRrdDataUpdated"
                                wire:key="update-bubble-1-{{$this->observeItemId}}-{{$this->lastRrdUpdate}}"/>
                        <livewire:central-ui::update-bubble
                                :minimalistic="true"
                                label="API Resource Update"
                                channel="proxmox-resource-updates"
                                event=".Modules\CentralUi\app\Events\ProxmoxApiResourceDataUpdated"
                                wire:key="update-bubble-2-{{$this->observeItemId}}-{{$this->lastServerUpdate}}"/>
                        <livewire:central-ui::update-bubble
                                :minimalistic="true"
                                label="Local Desktop Update"
                                channel="vm-updates"
                                event=".Modules\CentralUi\app\Events\LocalDesktopUpdated"
                                wire:key="update-bubble-3-{{$this->observeItemId}}-{{$this->lastDesktopUpdate}}"/>
                    </div>
                    <div class="mx-1 text-nowrap text-secondary-800 text-left {{ $isIcons ? 'text-sm truncate min-w-0' : 'text-xl' }}"
                         title="{{ $this->observeItemData['name'] }}">
                <span>
                    {{ $this->observeItemData['name'] }}
                </span>
                        <span class=" text-secondary-400 text-[10px]">
                    ({{ $this->observeItem->vm->extern_vm_id }})
                </span>
                    </div>
                </div>
                <x-website-base::popup title="Data Updates"
                                       stableId="popup-data-update-info-vm-{{ $this->observeItem->getKey() }}"
                                       componentView="components.popup.stats-details"
                                       :customData="[
                    __('Refresh Triggered:') => number_format($refreshEnterCount),
                    __('Refresh Processed:') => number_format($refreshProcessedCount),
                ]"/>
            </div>

            <div class="{{ $isIcons ? 'mx-1' : 'mx-8' }}">
                @if($this->observeItemData['url'])
                    <a href="{{ $this->observeItemData['url'] }}" target="_blank"> @endif
                        @if ($this->observeItemData['status_is_pending'] || $this->observeItemData['status_is_locked'])
                            {{--pending/locked status--}}
                            <div class="mx-auto w-fit rounded-2xl overflow-hidden border-[0.5px] border-yellow-800 container-with-spinner">
                                <img class="animate-pulse" src="{{ $this->observeItemData['image_asset_path'] }}" alt="Server Icon"/>
                                <div class="loading-spinner-wrapper">
                                    <div class="spinner"></div>
                                </div>
                            </div>
                        @elseif ($this->observeItemData['status_has_stopped'])
                            {{--stopped--}}
                            <div class="mx-auto w-fit opacity-40 rounded-2xl overflow-hidden border-[0.5px] border-red-800">
                                <img class="" src="{{ $this->observeItemData['image_asset_path'] }}" alt="Server Icon"/>
                            </div>
                        @elseif ($this->observeItemData['status_is_running'])
                            {{--running--}}
                            <div class="mx-auto w-fit rounded-2xl overflow-hidden border-[0.5px] border-green-800">
                                <img class="" src="{{ $this->observeItemData['image_asset_path'] }}" alt="Server Icon"/>
                            </div>
                        @else
                            {{--unknown/invalid status--}}
                            <div class="mx-auto w-fit opacity-10 rounded-2xl overflow-hidden border-[0.5px] border-gray-800">
                                <img class="" src="{{ $this->observeItemData['image_asset_path'] }}" alt="Server Icon"/>
                            </div>
                        @endif
                        @if($this->observeItemData['url']) </a>
                @endif
            </div>

            <div class="mt-2 collapse-transition {{ $this->observeItemData['status_is_running'] ?: 'opacity-25' }}">
                <div class="grid {{ $isIcons ? 'grid-cols-2 gap-1' : 'grid-cols-4' }} mb-2">
                    <x-central-ui::simple-status icon="{{ $this->observeItemData['status_is_idle'] ? 'circle-ellipsis' : 'circle-alert' }}" :value="$this->observeItemData['status_is_idle']"
                                                 :popupTitle="$this->observeItemData['status_is_idle'] ? 'In idle mode' : 'Busy ...'"
                                                 class="{{ (!$this->observeItemData['status_is_idle'] ? 'animate-pulse' : '') }}"
                                                 stableId="popup-idle-vm-{{ $this->observeItem->getKey() }}"
                                                 popupComponentView="components.popup.stats-details" :popupCustomData="[
                                                    'CPU' => $this->observeItemData['is_idle_cpu'] ?? false ? 'idle' : 'busy',
                                                    'MEM' => $this->observeItemData['is_idle_mem'] ?? false ? 'idle' : 'busy',
                                                    'NET' => $this->observeItemData['is_idle_net_traffic'] ?? false ? 'idle' : 'busy',
                                                    'Templates' =>  $this->observeItem->vm->propertyTemplates->implode('name', ','),
                                                    '',
                                                    'Can Online Start' => $this->observeItemData['schedule_can_online_start']['text'] ?? null,
                                                    'Can Online End' => $this->observeItemData['schedule_can_online_end']['text'] ?? null,
                                                    'Should Online Start' => $this->observeItemData['schedule_should_online_start']['text'] ?? null,
                                                    'Should Online End' => $this->observeItemData['schedule_should_online_end']['text'] ?? null,
                                                    'Can Offline Start' => $this->observeItemData['schedule_can_offline_start']['text'] ?? null,
                                                    'Can Offline End' => $this->observeItemData['schedule_can_offline_end']['text'] ?? null,
                                                    'Should Offline Start' => $this->observeItemData['schedule_should_offline_start']['text'] ?? null,
                                                    'Should Offline End' => $this->observeItemData['schedule_should_offline_end']['text'] ?? null,
                                                ]"/>
                    <x-central-ui::simple-number icon="cpu"
                                                 :value="$this->observeItemData['cpu_usage']"
                                                 stableId="popup-cpu-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('CPU Usage: :value', ['value' => $this->observeItemData['cpu_usage']]) }}"
                                                 popupComponentContent="No more data available."/>

                    <x-central-ui::simple-number icon="memory-stick"
                                                 :value="$this->observeItemData['mem_usage']"
                                                 stableId="popup-mem-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('RAM Usage: :value%', ['value' => $this->observeItemData['mem_usage']]) }}"
                                                 popupComponentView="components.popup.stats-details"
                                                 :popupCustomData="[
                                                    'Mem Usage' =>  $this->observeItemData['mem_usage'].'%',
                                                    'Mem Used' =>  $systemBase->bytesToHuman($this->observeItemData['mem_used'] ?? 0),
                                                    'Mem Free' =>  $systemBase->bytesToHuman($this->observeItemData['mem_free'] ?? 0),
                                                    'Max Mem' =>  $systemBase->bytesToHuman($this->observeItemData['mem_max'] ?? 0),
                                                ]"/>
                    <x-central-ui::simple-number icon="hard-drive-upload"
                                                 :value="$this->observeItemData['disk_usage']"
                                                 stableId="popup-disk-vm-{{ $this->observeItem->getKey() }}"
                                                 popupTitle="{{ __('Disk Usage :value%', ['value' => $this->observeItemData['disk_usage']]) }}"
                                                 popupComponentView="components.popup.stats-details"
                                                 :popupCustomData="[]"/>
                </div>

                <div class="grid transition-[grid-template-rows] duration-300 ease-in-out"
                     style="display: grid; grid-template-rows: {{ ($density !== '') ? '1fr' : '0fr' }};">
                    <div class="overflow-hidden">
                        @if($this->observeItemData['status_is_running'])
                            <div class="grid {{ $isIcons ? 'grid-cols-1' : 'grid-cols-2' }} gap-1">
                                @livewire('central-ui::resource-card', [
                                    'stableId' => "popup-res-card-cpu-vm-{$this->observeItem->getKey()}",
                                    'styles' => $styles,
                                    'colSpan' => $isIcons ? 1 : 2,
                                    'label' => sprintf("CPU (%s)", $this->observeItemData['cpu_max'] ?? 0),
                                    'icon' => 'cpu',
                                    'idleValue' => $this->observeItemData['cpu_idle'] ?? 0,
                                    'limit' => $this->observeItemData['cpu_limit'] ?? 0,
                                    'phase1Avg' => $this->observeItemData['cpu_avg_phase1_percent'] ?? 0,
                                    'phase2Avg' => $this->observeItemData['cpu_avg_phase2_percent'] ?? 0,
                                    'phase3Avg' => $this->observeItemData['cpu_avg_phase3_percent'] ?? 0,
                                    'density' => $density,
                                    'customData' => [
                                        [
                                          'label' => 'CPU Usage',
                                          'value' => $this->observeItemData['cpu_usage'].'%',
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase1_to_human'],
                                          'value' => $this->observeItemData['cpu_avg_phase1_percent'] ?? 0,
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase2_to_human'],
                                          'value' => $this->observeItemData['cpu_avg_phase2_percent'] ?? 0,
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase3_to_human'],
                                          'value' => $this->observeItemData['cpu_avg_phase3_percent'] ?? 0,
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Idle at',
                                          'value' => $this->observeItemData['cpu_idle'] ?? 0,
                                          'style' => $styles['idle']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Limit at',
                                          'value' => $this->observeItemData['cpu_limit'] ?? 0,
                                          'style' => $styles['limit']['text'] ?? null,
                                        ],
                                    ],
                                ], key($this->getStableKeyForServerUpdateRrd('res-card-cpu')))
                                @livewire('central-ui::resource-card', [
                                    'stableId' => "popup-res-card-mem-vm-{$this->observeItem->getKey()}",
                                    'styles' => $styles,
                                    'label' => 'MEM',
                                    'icon' => 'memory-stick',
                                    'phase1Avg' => $this->observeItemData['mem_avg_phase1_percent'] ?? 0,
                                    'phase2Avg' => $this->observeItemData['mem_avg_phase2_percent'] ?? 0,
                                    'phase3Avg' => $this->observeItemData['mem_avg_phase3_percent'] ?? 0,
                                    'idleValue' => 0,
                                    'limit' => 90,
                                    'density' => $density,
                                    'customData' => [
                                        [
                                          'label' => 'Mem Usage',
                                          'value' => $this->observeItemData['mem_usage'].'%',
                                        ],
                                        [
                                          'label' => 'Mem Used',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_used'] ?? 0),
                                        ],
                                        [
                                          'label' => 'Mem Free',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_free'] ?? 0),
                                        ],
                                        [
                                          'label' => 'Mem Max',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_max'] ?? 0),
                                        ],
                                        '',
                                        [
                                          'label' => $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase1_to_human'],
                                          'value' => ($this->observeItemData['mem_avg_phase1_percent'] ?? 0).'%',
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase2_to_human'],
                                          'value' => ($this->observeItemData['mem_avg_phase2_percent'] ?? 0).'%',
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['mem_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        [
                                          'label' => $this->observeItemData['str_phase3_to_human'],
                                          'value' => ($this->observeItemData['mem_avg_phase3_percent'] ?? 0).'%',
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                    ],
                                ], key($this->getStableKeyForServerUpdateRrd('res-card-mem')))
                                @livewire('central-ui::resource-card', [
                                    'stableId' => "popup-res-card-net-traffic-vm-{$this->observeItem->getKey()}",
                                    'styles' => $styles,
                                    'icon' => 'wifi',
                                    'label' => 'Net',
                                    'phase1Avg' => $this->observeItemData['net_traffic_avg_phase1_percent'] ?? 0,
                                    'phase2Avg' => $this->observeItemData['net_traffic_avg_phase2_percent'] ?? 0,
                                    'phase3Avg' => $this->observeItemData['net_traffic_avg_phase3_percent'] ?? 0,
                                    'idleValue' => $this->observeItemData['net_traffic_idle'] ?? 0,
                                    'limit' => $this->observeItemData['net_traffic_limit'] ?? 0,
                                    'density' => $density,
                                    'customData' => [
                                        [
                                          'label' => 'Traffic Total',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['net_traffic'] ?? 0),
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['net_traffic_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => ($this->observeItemData['net_traffic_avg_phase1_percent'] ?? 0).'%',
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['net_traffic_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => ($this->observeItemData['net_traffic_avg_phase2_percent'] ?? 0).'%',
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['net_traffic_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => ($this->observeItemData['net_traffic_avg_phase3_percent'] ?? 0).'%',
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Traffic Idle',
                                          'value' => $this->observeItemData['net_traffic_idle'] ?? 0,
                                          'style' => $styles['idle']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic Limit',
                                          'value' => $this->observeItemData['net_traffic_limit'] ?? 0,
                                          'style' => $styles['limit']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Max Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['net_traffic_max_phase3'] ?? 0),
                                          'style' => $styles['limit']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'In',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netin'] ?? 0),
                                        ],
                                        [
                                          'label' => 'In ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netin_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'In ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netin_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'In ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netin_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Out',
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netout'] ?? 0),
                                        ],
                                        [
                                          'label' => 'Out ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netout_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Out ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netout_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Out ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['netout_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                    ],
                                ], key($this->getStableKeyForServerUpdateRrd('res-card-net-traffic')))
                                @livewire('central-ui::resource-card', [
                                    'stableId' => "popup-res-card-disk-traffic-vm-{$this->observeItem->getKey()}",
                                    'styles' => $styles,
                                    'label' => 'DISK Traffic',
                                    'icon' => 'hard-drive-upload',
                                    'phase1Avg' => $this->observeItemData['disk_traffic_avg_phase1_percent'] ?? 0,
                                    'phase2Avg' => $this->observeItemData['disk_traffic_avg_phase2_percent'] ?? 0,
                                    'phase3Avg' => $this->observeItemData['disk_traffic_avg_phase3_percent'] ?? 0,
                                    'idleValue' => $this->observeItemData['disk_traffic_idle'] ?? 0,
                                    'limit' => $this->observeItemData['disk_traffic_limit'] ?? 0,
                                    'density' => $density,
                                    'customData' => [
                                        'Disk Usage' =>  ($this->observeItemData['disk_usage'] ?? 0).'%',
                                        'Disk Free' =>  $systemBase->bytesToHuman($this->observeItemData['disk_free'] ?? 0),
                                        'Disk Max' =>  $systemBase->bytesToHuman($this->observeItemData['maxdisk'] ?? 0),
                                        '',
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['disk_traffic_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => ($this->observeItemData['disk_traffic_avg_phase1_percent'] ?? 0).'%',
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Read ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskread_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Write ' . $this->observeItemData['str_phase1_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskwrite_avg_phase1'] ?? 0),
                                          'style' => $styles['phase1']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['disk_traffic_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => ($this->observeItemData['disk_traffic_avg_phase2_percent'] ?? 0).'%',
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Read ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskread_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Write ' . $this->observeItemData['str_phase2_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskwrite_avg_phase2'] ?? 0),
                                          'style' => $styles['phase2']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['disk_traffic_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => ($this->observeItemData['disk_traffic_avg_phase3_percent'] ?? 0).'%',
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Read ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskread_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Write ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['diskwrite_avg_phase3'] ?? 0),
                                          'style' => $styles['phase3']['text'] ?? null,
                                        ],
                                        '',
                                        [
                                          'label' => 'Traffic Idle',
                                          'value' => $this->observeItemData['disk_traffic_idle'] ?? 0,
                                          'style' => $styles['idle']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Traffic Limit',
                                          'value' => $this->observeItemData['disk_traffic_limit'] ?? 0,
                                          'style' => $styles['limit']['text'] ?? null,
                                        ],
                                        [
                                          'label' => 'Max Traffic ' . $this->observeItemData['str_phase3_to_human'],
                                          'value' => $systemBase->bytesToHuman($this->observeItemData['disk_traffic_max_phase3'] ?? 0),
                                          'style' => $styles['limit']['text'] ?? null,
                                        ],
                                    ],
                                ], key($this->getStableKeyForServerUpdateRrd('res-card-disk-traffic')))
                                @livewire('central-ui::resource-card', [
                                    'stableId' => "popup-res-card-disk-vm-{$this->observeItem->getKey()}",
                                    'styles' => $styles,
                                    'label' => 'DISK',
                                    'icon' => 'hard-drive',
                                    'phase1Avg' => $this->observeItemData['disk_avg_phase1_percent'] ?? 0,
                                    'phase2Avg' => $this->observeItemData['disk_avg_phase2_percent'] ?? 0,
                                    'phase3Avg' => $this->observeItemData['disk_avg_phase3_percent'] ?? 0,
                                    'idleValue' => 50,
                                    'limit' => 90,
                                    'density' => $density,
                                    'customData' => [
                                        'Disk Usage' =>  ($this->observeItemData['disk_usage'] ?? 0).'%',
                                        'Disk Free' =>  $systemBase->bytesToHuman($this->observeItemData['disk_free'] ?? 0),
                                        'Disk Max' =>  $systemBase->bytesToHuman($this->observeItemData['maxdisk'] ?? 0),
                                    ],
                                ], key($this->getStableKeyForServerUpdateRrd('res-card-disk')))
                            </div>
                        @else
                            <div class="py-4 flex items-center justify-center text-secondary-900">
                                <div class="">{{ __('Offline') }}</div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="text-[10px] mt-2 text-content-muted" wire:key="vm-last-seen-{{$this->observeItemId}}-{{$this->lastRrdUpdate}}">
                    <div class="inline-block text-[10px] badge badge-muted rounded-full">
                        @if ($this->observeItemData['status_is_running'])
                            <span>{{ __('Online') }} {{ sprintf("%02d:%02d:%02d", $onlineSince->days, $onlineSince->h, $onlineSince->i) }}</span>
                            <x-website-base::popup title="{{ __('Online') }} (DD:HH:MM)"
                                                   stableId="popup-started-vm-{{ $this->observeItem->getKey() }}"
                                                   componentView="components.popup.stats-details"
                                                   :customData="[
                    __('In Days:') => number_format($uptimeInterval->totalDays, 1),
                    __('Started Date:') => Carbon::createFromTimestamp(time() - $this->observeItemData['uptime'])->toFormattedDayDateString(),
                    __('Started Time:') => Carbon::createFromTimestamp(time() - $this->observeItemData['uptime'])->toTimeString(),
                ]"/>
                        @else
                            <span>{{ __('last seen: :time', ['time' => 0]) }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- The switch is a plain Blade component: wire:click goes straight to VmStatus --}}
            {{-- mt-auto: always at the bottom of the card, no matter how much content is above --}}
            <div class="mt-auto {{ $isIcons ? 'mx-1' : 'mx-8' }}">
                <x-central-ui::vm-switch
                        :state="$this->observeItemData['ui_state']"
                        :remaining="$this->observeItemData['grace_remaining']"
                        :canSwitch="$this->observeItemData['can_switch']"
                        :reasons="$this->observeItemData['switch_popup_content']"/>
            </div>

        </div>
    @endif
</div>