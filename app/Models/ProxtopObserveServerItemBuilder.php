<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin IdeHelperProxtopServerVm
 */
class ProxtopObserveServerItemBuilder extends Builder
{
    /**
     * Filter items that have an active server connection.
     */
    public function whereIsEnabledAndHasActiveServer(): self
    {
        // Include vm relation check
        return $this->whereHas('vm', function (ProxtopServerVmBuilder $q1) {
            $q1->whereIsEnabledAndHasActiveServer();

        })->where('is_enabled', true);
    }

    /**
     * Default eager loading for this module.
     */
    public function withStandardRelations(): self
    {
        return $this->with(['user', 'mediaItem', 'vm.enabledPropertyTemplates', 'vm.server', 'vm.mediaItem']);
    }
}