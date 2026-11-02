<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;

/**
 * The desktop: live update fallback, the form of the observe items and the cards, which are the
 * data table VmDesktopItems (one VmStatus component per VM). VmDesktopItems reads the user settings
 * (layout, details) itself, the constants and options of these settings stay here.
 */
class VmDesktop extends BaseComponent
{
    /**
     * Values of the user setting 'desktop_layout'
     */
    public const string LAYOUT_COMPACT = 'compact';
    public const string LAYOUT_ICONS = 'icons';
    public const string LAYOUT_LIST = 'list';

    /**
     * While the websocket (Reverb) is disconnected, the desktop asks all cards to refresh this often.
     * Connected: no polling at all, the cards get pushed events.
     */
    public const int LIVE_UPDATE_FALLBACK_SECONDS = 20;

    public const array LAYOUTS = [
        self::LAYOUT_COMPACT,
        self::LAYOUT_ICONS,
        self::LAYOUT_LIST,
    ];

    /**
     * Values of the user setting 'desktop_vm_details'.
     * The stored values stay as they are ('compact' is still the value for "brief"),
     * only the labels changed to avoid confusion with the layout "compact".
     */
    public const string DETAILS_HIDE = '';
    public const string DETAILS_BRIEF = 'compact';
    public const string DETAILS_FULL = 'comfortable';

    /**
     * value => label (not translated)
     *
     * @return array<string, string>
     */
    public static function layoutOptions(): array
    {
        return [
            self::LAYOUT_COMPACT => 'Compact',
            self::LAYOUT_ICONS   => 'Icons',
            self::LAYOUT_LIST    => 'List',
        ];
    }

    /**
     * value => label (not translated)
     *
     * @return array<string, string>
     */
    public static function detailOptions(): array
    {
        return [
            self::DETAILS_HIDE  => 'Hide Details',
            self::DETAILS_BRIEF => 'Brief',
            self::DETAILS_FULL  => 'Comfortable',
        ];
    }

    /**
     * Unknown values fall back to the compact layout.
     *
     * @param  mixed  $layout
     *
     * @return string
     */
    public static function sanitizeLayout(mixed $layout): string
    {
        return in_array($layout, self::LAYOUTS, true) ? $layout : self::LAYOUT_COMPACT;
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        return view('central-ui::livewire.vm-desktop');
    }
}
