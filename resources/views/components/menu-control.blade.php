{{--
    Menu entry with an inline control instead of a link.
    Selected by the navigation tag (column "tags" of the navigation, see module-deploy-env navigations.php):
      - CONTROL_DARK_MODE                 : light/dark toggle (TallStackUI, stored in the browser)
      - CONTROL_CHANGE_DESKTOP_VM_DETAILS : user setting 'desktop_vm_details'
      - CONTROL_CHANGE_DESKTOP_LAYOUT     : user setting 'desktop_layout'

    Usage (theme menu partials):
        <x-central-ui::menu-control :item="$item" :tag="$controlTag" :mobile="false"/>
--}}
@use('Modules\CentralUi\app\Http\Livewire\UserDesktopSettings')
@props([
    'item',            // navigation item (object)
    'tag',             // one of the CONTROL_* tags
    'mobile' => false, // mobile slide-over navigation
])
@php
    $icon = $item->attributes['icon_class'] ?? 'settings';
    $livewireControl = match ($tag) {
        'CONTROL_CHANGE_DESKTOP_VM_DETAILS' => UserDesktopSettings::CONTROL_DETAILS,
        'CONTROL_CHANGE_DESKTOP_LAYOUT' => UserDesktopSettings::CONTROL_LAYOUT,
        default => null,
    };
    $textClasses = $mobile ? 'text-base font-semibold text-surface-700' : 'text-sm text-surface-700';
@endphp

@if($tag === 'CONTROL_DARK_MODE')
    {{-- darkTheme comes from x-data="tallstackui_darkTheme()" on <html> --}}
    <button type="button"
            @click="darkTheme = !darkTheme"
            class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg {{ $mobile ? 'py-3' : 'px-4 py-2 hover:bg-surface-100' }} {{ $textClasses }} transition-colors focus:outline-none">
        <span class="flex items-center gap-3">
            <x-lucide-moon class="h-4 w-4 text-surface-400" x-show="!darkTheme"/>
            <x-lucide-sun class="h-4 w-4 text-surface-400" x-show="darkTheme" x-cloak/>
            <span>{{ $item->title }}</span>
        </span>
        {{-- small switch showing the current state --}}
        <span class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
              x-bind:class="darkTheme ? 'bg-secondary-500' : 'bg-surface-300'">
            <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
                  x-bind:class="darkTheme ? 'translate-x-4.5' : 'translate-x-0.5'"></span>
        </span>
    </button>

@elseif($livewireControl)
    <div class="{{ $mobile ? 'py-3' : 'px-4 py-2' }} {{ $textClasses }}">
        <div class="mb-1.5 flex items-center gap-3">
            <x-dynamic-component :component="'lucide-'.$icon" class="h-4 w-4 text-surface-400"/>
            <span>{{ $item->title }}</span>
        </div>
        @livewire('central-ui::user-desktop-settings', [
            'control' => $livewireControl,
            'mobile' => (bool) $mobile,
        ], key('menu-control-'.$livewireControl.'-'.($mobile ? 'mobile' : 'desktop')))
    </div>

@else
    {{-- unknown control: just show the label --}}
    <span class="block {{ $mobile ? 'py-3' : 'px-4 py-2' }} {{ $textClasses }}">{{ $item->title }}</span>
@endif
