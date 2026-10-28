<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Modules\SystemBase\app\Http\Livewire\BaseComponent;

/**
 * Quick switch for a desktop user setting, shown in the user menu.
 * Saves the value as extra attribute of the current user (same as the user profile)
 * and tells the desktop (VmDesktop) to update without page reload.
 */
class UserDesktopSettings extends BaseComponent
{
    public const string CONTROL_DETAILS = 'details';
    public const string CONTROL_LAYOUT = 'layout';

    /**
     * extra attribute code per control
     */
    protected const array CONTROLS = [
        self::CONTROL_DETAILS => [
            'attribute' => 'desktop_vm_details',
        ],
        self::CONTROL_LAYOUT  => [
            'attribute' => 'desktop_layout',
        ],
    ];

    /**
     * One of the CONTROL_* constants
     *
     * @var string
     */
    public string $control = self::CONTROL_LAYOUT;

    /**
     * Mobile navigation (shows labels below the icons)
     *
     * @var bool
     */
    public bool $mobile = false;

    /**
     * Current value
     *
     * @var string
     */
    public string $value = '';

    /**
     * @return void
     */
    protected function initMount(): void
    {
        parent::initMount();

        $this->value = $this->loadValue();
    }

    /**
     * value => ['label' => ..., 'icon' => ...]
     *
     * @return array
     */
    public function getOptions(): array
    {
        if ($this->control === self::CONTROL_DETAILS) {
            $icons = [
                VmDesktop::DETAILS_HIDE  => 'eye-off',
                VmDesktop::DETAILS_BRIEF => 'rows-2',
                VmDesktop::DETAILS_FULL  => 'rows-4',
            ];
            $options = VmDesktop::detailOptions();
        } else {
            $icons = [
                VmDesktop::LAYOUT_COMPACT => 'layout-grid',
                VmDesktop::LAYOUT_ICONS   => 'grid-3x3',
                VmDesktop::LAYOUT_LIST    => 'list',
            ];
            $options = VmDesktop::layoutOptions();
        }

        $result = [];
        foreach ($options as $value => $label) {
            $result[$value] = [
                'label' => __($label),
                'icon'  => $icons[$value] ?? 'circle',
            ];
        }

        return $result;
    }

    /**
     * Called by the buttons (wire:click)
     *
     * @param  string  $value
     *
     * @return void
     */
    public function select(string $value): void
    {
        $config = self::CONTROLS[$this->control] ?? null;
        $user = app('website_base_settings')->currentUser();

        // only known controls, known values and logged-in users
        if (!$config || !$user || !array_key_exists($value, $this->getOptions())) {
            return;
        }

        if ($user->saveModelAttributeTypeValue($config['attribute'], $value)) {
            $user->clearCacheParametersExtraAttributeEntity();
            $this->value = $value;

            // Rebuild the desktop (if present on this page). VmDesktop reads the saved
            // settings again and mounts all cards fresh (see VmDesktop::getCardKey()).
            $this->dispatch('vm-desktop:refresh');
        }
    }

    /**
     * @return string
     */
    protected function loadValue(): string
    {
        $config = self::CONTROLS[$this->control] ?? null;
        $user = app('website_base_settings')->currentUser();
        if (!$config || !$user) {
            return '';
        }

        $default = ($this->control === self::CONTROL_LAYOUT) ? VmDesktop::LAYOUT_COMPACT : VmDesktop::DETAILS_HIDE;

        return (string) $user->getExtraAttribute($config['attribute'], $default);
    }

    /**
     * @return Application|Factory|View
     */
    public function render(): Factory|View|Application
    {
        return view('central-ui::livewire.user-desktop-settings', [
            'options' => $this->getOptions(),
        ]);
    }
}
