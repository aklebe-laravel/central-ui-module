<?php

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends \Modules\WebsiteBase\app\Models\User
{
    /**
     * @return HasMany
     */
    public function observeItems(): HasMany
    {
        return $this->hasMany(ProxtopObserveServerItem::class);
    }


}