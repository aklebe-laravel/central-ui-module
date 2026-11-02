<?php

use Illuminate\Support\Carbon;
use Modules\CentralUi\app\Http\Livewire\BackupOverview;

/**
 * @var BackupOverview $this
 * @var array          $overview  see BackupOverview::buildOverview()
 */
$running = $overview['running'];
$upcoming = $overview['upcoming'];
$errors = $overview['errors'];
$hasContent = $running || $upcoming || $errors;
$timezone = config('app.timezone');
// short local time: today only the time, otherwise weekday + time (more than 6 days: date)
$formatTime = function (int $timestamp) use ($timezone): string {
    $time = Carbon::createFromTimestamp($timestamp, $timezone);
    $now = Carbon::now($timezone);

    return match (true) {
        $time->isSameDay($now) => __('today').' '.$time->format('H:i'),
        $time->isSameDay($now->copy()->addDay()) => __('tomorrow').' '.$time->format('H:i'),
        $time->diffInDays($now, true) < 6 => $time->isoFormat('dd HH:mm'),
        default => $time->isoFormat('DD.MM. HH:mm'),
    };
};
?>
{{-- root element always exists (Livewire), it is empty when there is nothing to show --}}
<div>
    @if ($hasContent)
        {{-- backupBar (centralUi.js): open/closed is remembered per browser, open by default while a backup runs --}}
        <div x-data="backupBar(@js((bool) $running))"
             class="mx-2 mb-3 rounded-lg border border-surface-200/10 bg-surface-100/50 text-sm">

            {{-- summary line, always visible --}}
            <button type="button" @click="toggle()"
                    class="w-full flex items-center gap-2 px-3 py-2 text-left">
                @include('central-ui::partials.update-bubble', ['channel' => 'proxmox-backup-updates', 'label' => 'API Backup Update', 'key' => 'update-bubble-backups'])
                <x-lucide-archive class="h-4 w-4 shrink-0 {{ $running ? 'text-info-500 animate-pulse' : 'text-content-muted' }}"/>

                <span class="min-w-0 flex-1 truncate">
                    @if ($running)
                        @php($first = $running[0])
                        <span class="font-semibold">{{ __('Backup running') }}</span>
                        @if ($first['current_name'])
                            <span class="text-content-muted">· {{ $first['current_name'] }}{{ $first['percent'] !== null ? ' '.$first['percent'].' %' : '' }}</span>
                        @endif
                        <span class="text-content-muted">· {{ __(':done of :total', ['done' => $first['done'], 'total' => $first['total']]) }}</span>
                        @if (count($running) > 1)
                            <span class="text-content-muted">(+{{ count($running) - 1 }})</span>
                        @endif
                    @elseif ($upcoming)
                        <span class="font-semibold">{{ __('Next backup') }}</span>
                        <span class="text-content-muted">· {{ $formatTime($upcoming[0]['next_run']) }}</span>
                        {{-- the key contains the time: Alpine reads the timestamp only once (init) --}}
                        <span wire:key="backup-next-rel-{{ $upcoming[0]['next_run'] }}" class="text-content-muted" x-data="relativeTime({{ $upcoming[0]['next_run'] }})" x-text="'(' + text + ')'"></span>
                    @else
                        <span class="font-semibold">{{ __('Backups') }}</span>
                    @endif
                </span>

                @if ($errors)
                    <span class="badge badge-error rounded-full text-[10px] px-2" title="{{ __('Backup data not readable') }}">!</span>
                @endif
                <span class="shrink-0 transition-transform" :class="open ? 'rotate-180' : ''">
                    <x-lucide-chevron-down class="h-4 w-4 text-content-muted"/>
                </span>
            </button>

            <div x-show="open" x-cloak x-collapse class="px-3 pb-3 space-y-3">

                {{-- API errors (admins only): mostly a token without Sys.Audit on "/" --}}
                @foreach ($errors as $host)
                    <div class="alert alert-warning text-xs py-1" wire:key="backup-error-{{ md5($host) }}">
                        {{ __('Backup data of :host not readable. The API token needs the privilege Sys.Audit on "/".', ['host' => $host]) }}
                    </div>
                @endforeach

                {{-- running backups --}}
                @foreach ($running as $task)
                    <div wire:key="backup-running-{{ $task['key'] }}">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-semibold">{{ __('Running since :time', ['time' => Carbon::createFromTimestamp($task['started_at'], $timezone)->format('H:i')]) }}</span>
                            @if ($task['node'])
                                <span class="text-[10px] text-content-muted">{{ $task['node'] }}</span>
                            @endif
                            <span class="text-xs text-content-muted">{{ __(':done of :total', ['done' => $task['done'], 'total' => $task['total']]) }}</span>
                        </div>

                        {{-- progress of all visible guests --}}
                        <div class="mt-1 h-1.5 w-full rounded-full bg-surface-200/20 overflow-hidden">
                            <div class="h-full rounded-full bg-info-500 transition-[width] duration-500" style="width: {{ $task['progress'] }}%"></div>
                        </div>

                        <div class="mt-1 flex flex-wrap gap-1 text-[10px]">
                            @if ($task['current_name'])
                                <span class="badge badge-warning rounded-full px-2">
                                    {{ $task['current_name'] }}{{ $task['percent'] !== null ? ' · '.$task['percent'].' %' : '' }}
                                </span>
                            @endif
                            @foreach ($task['failed_names'] as $failedName)
                                <span class="badge badge-error rounded-full px-2" title="{{ __('Backup failed') }}">{{ $failedName }}</span>
                            @endforeach
                            @foreach (array_slice($task['waiting'], 0, BackupOverview::MaxNames) as $waitingName)
                                <span class="badge badge-muted rounded-full px-2" title="{{ __('Waiting') }}">{{ $waitingName }}</span>
                            @endforeach
                            @if (count($task['waiting']) > BackupOverview::MaxNames)
                                <span class="text-content-muted">+{{ count($task['waiting']) - BackupOverview::MaxNames }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- upcoming jobs --}}
                @if ($upcoming)
                    <div>
                        <div class="text-xs font-semibold text-content-muted mb-1">{{ __('Scheduled backups') }}</div>
                        <ul class="space-y-1">
                            @foreach ($upcoming as $job)
                                <li wire:key="backup-job-{{ $job['key'] }}" class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="w-24 shrink-0 tabular-nums {{ $job['soon'] ? 'text-yellow-500 font-semibold' : '' }}">{{ $formatTime($job['next_run']) }}</span>
                                    <span wire:key="backup-job-rel-{{ $job['key'] }}-{{ $job['next_run'] }}" class="text-[10px] text-content-muted w-20 shrink-0" x-data="relativeTime({{ $job['next_run'] }})" x-text="text"></span>
                                    @if ($job['label'])
                                        <span class="font-semibold">{{ $job['label'] }}</span>
                                    @endif
                                    <span class="min-w-0 truncate text-xs" title="{{ implode(', ', $job['names']) }}">
                                        {{ implode(', ', $job['names']) }}{{ $job['more'] ? ' +'.$job['more'] : '' }}
                                    </span>
                                    @if ($job['storage'])
                                        <span class="text-[10px] text-content-muted">→ {{ $job['storage'] }}{{ $job['mode'] ? ' ('.$job['mode'].')' : '' }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
