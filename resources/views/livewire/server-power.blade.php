<?php

use Modules\CentralUi\app\Http\Livewire\ServerPower;

/**
 * @var ServerPower $this
 * @var bool        $canUse
 * @var array       $risk        see ProxmoxBackupService::shutdownRisk()
 * @var bool        $backupRisk  a backup runs or starts soon
 * @var string      $messageBox  path of the message box of the driver (normal or with backup warning)
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
?>
{{-- root element always exists (Livewire), it is empty for non admins --}}
<div class="relative">
    @if ($canUse)
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

        {{-- the result appears in the messages of the desktop (VmDesktopItems::EventMessage) --}}
    @endif
</div>
