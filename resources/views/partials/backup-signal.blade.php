{{--
    Signal bubble of the backup bar: the color shows the backup state (backupSignal in centralUi.js).
    @include('central-ui::partials.backup-signal', ['signal' => $overview['signal']])

    The key contains the facts: Alpine reads them only once (init), new facts mount a new bubble.
    The css classes stay complete strings in this file, Tailwind scans them as they are.
--}}
@php
    use Modules\CentralUi\app\Http\Livewire\BackupOverview;

    $signalCss = [
        'running'  => 'bg-orange-500 shadow-[0_0_8px_var(--color-orange-500)] animate-pulse',
        'imminent' => 'bg-yellow-400 shadow-[0_0_8px_var(--color-yellow-400)] animate-pulse',
        'soon'     => 'bg-yellow-300/70 shadow-[0_0_5px_var(--color-yellow-300)]',
        'failed'   => 'bg-red-600 shadow-[0_0_8px_var(--color-red-600)]',
        'ok'       => 'bg-green-600 shadow-[0_0_6px_var(--color-green-600)]',
        'idle'     => 'bg-blue-950',
    ];
    $signalLabels = [
        'running'  => __('Backup running'),
        'imminent' => __('Backup starts within 15 minutes'),
        'soon'     => __('Backup starts within 2 hours'),
        'failed'   => __('Last backup failed'),
        'ok'       => __('Last backup successful'),
        'idle'     => __('No backup soon'),
    ];
@endphp
<span wire:key="backup-signal-{{ md5(json_encode($signal)) }}"
      x-data="backupSignal(@js($signal), @js(BackupOverview::SignalWindows))"
      :title="@js($signalLabels)[state]"
      class="relative flex items-center justify-center w-4 h-4 shrink-0">
    <span class="w-3 h-3 rounded-full transition-colors duration-500"
          :class="@js($signalCss)[state]"></span>
    <span class="absolute inset-0 border-2 border-surface-200/10 rounded-full"></span>
</span>
