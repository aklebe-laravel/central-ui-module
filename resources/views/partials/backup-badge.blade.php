{{--
    "Backup" badge of a VM card while Proxmox holds the config lock 'backup' (cluster/resources, field 'lock').
    Comes with the resource job (every 30s), no extra API call.
    @include('central-ui::partials.backup-badge', ['lock' => $d['lock'] ?? null])

    Always rendered (empty without lock): no @if around the tags of the card, see VmStatus::imageLinkAttributes().
--}}
@php($isBackup = (($lock ?? null) === 'backup'))
<span @class(['items-center gap-1 badge badge-warning rounded-full text-[10px] px-2 whitespace-nowrap', 'inline-flex' => $isBackup, 'hidden' => !$isBackup])
      title="{{ __('Proxmox is backing up this VM right now') }}">
    <x-lucide-archive class="h-3 w-3 animate-pulse"/> {{ __('Backup') }}
</span>
