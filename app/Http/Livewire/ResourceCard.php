<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Livewire\Component;
use Illuminate\View\View;

/**
 * High-performance metric visualization using PHP 8.5 features.
 */
class ResourceCard extends Component
{
    public string $icon = '';
    public string $stableId = 'resource-card-default-1';
    public string $label;
    public float $phase1Avg;
    public float $phase2Avg;
    public float $phase3Avg;
    public float $idleValue;
    public float $limit;
    public array $customData = [];
    public array $history;
    public float $tolerance = 10.0;
    public ?string $density = 'comfortable'; // 'comfortable' oder 'compact'
    public int $colSpan = 1;

    /**
     * synced with {@see VmStatus::$styles}
     *
     * @var array
     */
    public array $styles = [];

    /**
     * @return View
     */
    public function render(): View
    {
        return view('central-ui::livewire.resource-card');
    }

}
