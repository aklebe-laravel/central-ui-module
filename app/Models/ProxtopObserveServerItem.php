<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Modules\WebsiteBase\app\Models\MediaItem;
use Modules\WebsiteBase\app\Models\User;

/**
 * @method static ProxtopObserveServerItemBuilder query()
 * @mixin IdeHelperProxtopObserveServerItem
 */
class ProxtopObserveServerItem extends Model
{
    //use TraitBaseModel, HasFactory;

    /**
     * Precision in Microseconds
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @param  array  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo
     */
    public function vm(): BelongsTo
    {
        return $this->belongsTo(ProxtopServerVm::class, 'vm_id');
    }

    /**
     * @return string
     */
    public function getUniqueIdentifier(): string
    {
        return $this->vm->getUniqueIdentifier();
    }

    /**
     * @param  Builder  $query
     *
     * @return ProxtopObserveServerItemBuilder<ProxtopObserveServerItem>
     */
    public function newEloquentBuilder($query): ProxtopObserveServerItemBuilder
    {
        return new ProxtopObserveServerItemBuilder($query);
    }

    /**
     * @return BelongsTo
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return string|null
     */
    public function getDisplayName(): ?string
    {
        return $this->name_intern ? sprintf("%s (%s)", $this->name_intern, $this?->vm->name) : $this->vm?->getDisplayName();
    }

}