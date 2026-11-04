<?php

use Modules\CentralUi\app\Http\Livewire\ServerPower;

/**
 * @var ServerPower $this
 * @var bool        $canUse        admin with usable driver: may start a shutdown
 * @var bool        $canCancel     admin: may stop a planned shutdown
 * @var array       $risk          see ProxmoxBackupService::shutdownRisk()
 * @var bool        $backupRisk    a backup runs or starts soon
 * @var string      $messageBox    path of the message box of the driver (normal or with backup warning)
 * @var int|null    $shutdownAt    planned shutdown (UNIX time), null = none (countdown hidden)
 * @var string      $shutdownTime  $shutdownAt as local time of the user ('13:05')
 * @var int[]       $extendMinutes extensions offered to everyone
 */
// callback params of messageBox.js: goAction('shutdown-server') dispatches them to this component
$messageBoxParams = [
    'shutdown-server' => [
        'name'       => $this->getName(),
        'livewireId' => $this->getId(),
        // confirmed with the backup warning
        'force'      => $backupRisk,
    ],
];
$extendLabel = fn(int $minutes): string => ($minutes >= 60 && $minutes % 60 === 0) ? __('+:hours h', ['hours' => $minutes / 60]) : __('+:minutes min', ['minutes' => $minutes]);
?>
{{-- root element always exists (Livewire), it is empty for non admins without planned shutdown --}}
<div class="relative">
    @if ($shutdownAt)
        {{-- countdown for everyone: the key contains the time, Alpine reads it only once (init) --}}
        <div wire:key="server-power-countdown-{{ $shutdownAt }}"
             x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false"
             class="relative">
            <button type="button" @click="open = !open"
                    title="{{ __('The server shuts down at :time.', ['time' => $shutdownTime]) }}"
                    class="flex items-center gap-1 h-9 px-3 rounded-lg border border-error-600 bg-error-600/15 text-error-600 font-semibold tabular-nums animate-pulse cursor-pointer">
                <x-lucide-power class="h-4 w-4"/>
                <span x-data="powerCountdown({{ $shutdownAt }})" x-text="text"></span>
            </button>

            <div x-show="open" x-cloak x-transition.origin.top.right
                 class="absolute right-0 top-full mt-2 z-40 w-72 max-w-[calc(100vw-1rem)] rounded-lg border border-surface-300/60 bg-surface-100 shadow-lg p-3 space-y-3 text-sm">
                <div class="font-semibold text-error-600">{{ __('The server shuts down at :time.', ['time' => $shutdownTime]) }}</div>
                <div class="text-xs text-content-muted">{{ __('All VMs and Proxtop itself go off. Extend it if you still need the server.') }}</div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($extendMinutes as $minutes)
                        <button type="button" wire:click="extendShutdown({{ (int) $minutes }})" @click="open = false"
                                class="h-8 px-3 rounded-lg border border-surface-300/60 hover:bg-surface-200/40 cursor-pointer">
                            {{ $extendLabel((int) $minutes) }}
                        </button>
                    @endforeach
                    @if ($canCancel)
                        <button type="button" wire:click="cancelShutdown" @click="open = false"
                                class="h-8 px-3 rounded-lg border border-error-600/60 text-error-600 hover:bg-error-600/10 cursor-pointer">
                            {{ __('Stop shutdown') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($canUse)
        <button type="button"
                x-on:click="messageBox.show(@js($messageBox), @js($messageBoxParams))"
                title="{{ __('Shut down the server') }}"
                class="flex items-center gap-1 h-9 px-3 rounded-lg border border-error-600/60 text-error-600 hover:bg-error-600/10 transition-colors cursor-pointer">
            <x-lucide-power class="h-4 w-4"/>
            <span class="hidden sm:inline text-sm">{{ __('Server') }}</span>
            @if ($backupRisk)
                {{-- a backup runs or starts soon: the confirmation warns --}}
                <x-lucide-archive class="h-3 w-3 text-yellow-500 animate-pulse"/>
            @endif
        </button>
    @endif
    {{-- results appear in the messages of the desktop (VmDesktopItems::EventMessage) --}}
</div>
