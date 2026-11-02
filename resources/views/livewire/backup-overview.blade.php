<?php

use Illuminate\Support\Carbon;
use Modules\CentralUi\app\Http\Livewire\BackupOverview;

/**
 * @var BackupOverview $this
 * @var array          $overview see BackupOverview::buildOverview()
 */
$running = $overview['running'];
$upcoming = $overview['upcoming'];
$failed = $overview['failed'];
$errors = $overview['errors'];
$hasContent = $running || $upcoming || $failed || $errors;
// time zone of the current user (or the installation), internally everything is UTC
$timezone = app('website_base_settings')->displayTimezone();
// short local time: today only the time, otherwise weekday + time (more than 6 days: date)
$formatTime = function (int $timestamp) use ($timezone): string {
    $time = Carbon::createFromTimestamp($timestamp, $timezone);
    $now = Carbon::now($timezone);

    return match (true) {
        $time->isSameDay($now) => __('today').' '.$time->format('H:i'),
        $time->isSameDay($now->copy()
            ->addDay()) => __('tomorrow').' '.$time->format('H:i'),
        $time->diffInDays($now, true) < 6 => $time->isoFormat('dd HH:mm'),
        default => $time->isoFormat('DD.MM. HH:mm'),
    };
};
?>
{{-- root element always exists (Livewire), it is empty when there is nothing to show --}}
<div>
    @if ($hasContent)
        {{-- chip in the desktop toolbar, the details open as popover above the cards (no layout shift) --}}
        <div x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false"
             class="relative text-sm">

            {{-- chip: signal, short state, failures --}}
            <button type="button" @click="open = !open"
                    :aria-expanded="open"
                    class="flex items-center gap-2 h-9 px-3 rounded-lg border border-surface-300/60 bg-surface-100/50 hover:bg-surface-200/40 transition-colors cursor-pointer">
                @include('central-ui::partials.backup-signal', ['signal' => $overview['signal']])

                {{-- text hidden on small screens: the signal tells enough there --}}
                <span class="hidden sm:inline max-w-56 truncate">
                    @if ($running)
                        @php($first = $running[0])
                        {{ __('Backup running') }}@if ($first['current_name'])
                            <span class="text-content-muted"> · {{ $first['current_name'] }}{{ $first['percent'] !== null ? ' '.$first['percent'].' %' : '' }}</span>
                        @endif
                    @elseif ($upcoming)
                        {{-- the key contains the time: Alpine reads the timestamp only once (init) --}}
                        {{ __('Backup') }} <span wire:key="backup-next-rel-{{ $upcoming[0]['next_run'] }}" x-data="relativeTime({{ $upcoming[0]['next_run'] }})" x-text="text"></span>
                    @else
                        {{ __('Backups') }}
                    @endif
                </span>

                @if ($failed)
                    <span class="badge badge-error rounded-full text-[10px] px-2"
                          title="{{ trans_choice(':count backup failed|:count backups failed', count($failed), ['count' => count($failed)]) }}">{{ count($failed) }}</span>
                @endif
                @if ($errors)
                    <span class="badge badge-error rounded-full text-[10px] px-2" title="{{ __('Backup data not readable') }}">!</span>
                @endif
                <x-lucide-chevron-down class="h-4 w-4 text-content-muted transition-transform" ::class="open ? 'rotate-180' : ''"/>
            </button>

            {{-- popover: right aligned under the chip, above the cards --}}
            <div x-show="open" x-cloak x-transition.origin.top.right
                 class="absolute right-0 top-full mt-2 z-40 w-[26rem] max-w-[calc(100vw-1rem)] rounded-lg border border-surface-300/60 bg-surface-100 shadow-lg p-3 space-y-3">

                {{-- API errors (admins only): mostly a token without Sys.Audit on "/" --}}
                @foreach ($errors as $host)
                    <div class="alert alert-warning text-xs py-1" wire:key="backup-error-{{ md5($host) }}">
                        {{ __('Backup data of :host not readable. The API token needs the privilege Sys.Audit on "/".', ['host' => $host]) }}
                    </div>
                @endforeach

                {{-- guests whose last backup failed (until a later backup succeeds) --}}
                @if ($failed)
                    <div>
                        <div class="text-xs font-semibold text-error-600 mb-1">{{ __('Last backup failed') }}</div>
                        <ul class="space-y-1">
                            @foreach ($failed as $failure)
                                <li wire:key="backup-failed-{{ $failure['key'] }}" class="flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <span class="w-24 shrink-0 tabular-nums text-content-muted">{{ $failure['at'] ? $formatTime($failure['at']) : '' }}</span>
                                    <span class="font-semibold">{{ $failure['name'] }}</span>
                                    @if ($failure['reason'])
                                        <span class="min-w-0 truncate text-content-muted" title="{{ $failure['reason'] }}">{{ $failure['reason'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                                    <span wire:key="backup-job-rel-{{ $job['key'] }}-{{ $job['next_run'] }}" class="text-[10px] text-content-muted w-20 shrink-0"
                                          x-data="relativeTime({{ $job['next_run'] }})" x-text="text"></span>
                                    @if ($job['label'])
                                        <span class="font-semibold">{{ $job['label'] }}</span>
                                    @endif
                                    <span class="min-w-0 truncate text-xs" title="{{ implode(', ', $job['names']) }}">
                                        {{ implode(', ', $job['names']) }}{{ $job['more'] ? ' +'.$job['more'] : '' }}
                                    </span>
                                    @if ($job['storage'])
                                        <span class="text-[10px] text-content-muted">→ {{ $job['storage'] }}{{ $job['mode'] ? ' ('.$job['mode'].')' : '' }}</span>
                                    @endif

                                    {{-- last result: latest backup of each guest of the job --}}
                                    @php($last = $job['last'])
                                    @if ($last['failed'])
                                        <span class="badge badge-error rounded-full text-[10px] px-2 whitespace-nowrap"
                                              title="{{ implode("\n", $last['failed']) }}">
                                            {{ __('Last: :failed of :total failed', ['failed' => count($last['failed']), 'total' => $job['count']]) }}
                                        </span>
                                    @elseif ($last['ok'])
                                        <span class="badge badge-success rounded-full text-[10px] px-2 whitespace-nowrap"
                                              title="{{ $last['at'] ? __('Last backup: :time', ['time' => $formatTime($last['at'])]) : '' }}">
                                            {{ __('Last: OK') }}{{ $last['unknown'] ? ' ('.$last['ok'].'/'.$job['count'].')' : '' }}
                                        </span>
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
