<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin IdeHelperProxtopServerVm
 */
class ProxtopServerVmBuilder extends Builder
{
    /**
     * Filter items that have an active server connection.
     */
    public function whereIsEnabledAndHasActiveServer(): self
    {
        // Use whereRelation for the nested check
        return $this->where('is_enabled', true)->whereRelation('server', 'api_allowed', true);
    }

    /**
     * Default eager loading for this module.
     */
    public function withStandardRelations(): self
    {
        return $this->with(['propertyTemplates', 'server', 'mediaItem']);
    }

    /**
     * @return self
     */
    public function whereIsVm(): self
    {
        return $this->whereIn('vm_type', ['lxc', 'qemu']);
    }
}