<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SystemBase\app\Models\ModelWithTags;

/**
 * @mixin IdeHelperProxtopServer
 */
class ProxtopPropertyTemplate extends ModelWithTags
{
    /**
     * @var string
     */
    protected $table = 'proxtop_property_templates';

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @var string[]
     */
    protected $casts = [
        'is_enabled'       => 'boolean',
        'acl_res_view'     => 'array',
        'acl_res_shutdown' => 'array',
        'acl_res_start'    => 'array',
        'acl_res_edit'     => 'array',
        'options'          => 'array', // AsArrayObject::class,
        //'created_at'       => 'datetime:Y-m-d H:i:s.u', // Handling microsecond precision
        //'updated_at'       => 'datetime:Y-m-d H:i:s.u',
    ];

    /**
     * @param  array  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * Relationship: Get all schedules (cron events) for this template.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ProxtopPropertyTemplateSchedule::class, 'template_id');
    }

    /**
     * Relationship: Get all VMs that use this specific template.
     */
    public function vms(): BelongsToMany
    {
        return $this->belongsToMany(ProxtopServerVm::class,
            'proxtop_vm_property_template',
            'template_id',
            'vm_id');
    }

}