<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperProxtopServer
 */
class ProxtopPropertyTemplateSchedule extends Model
{
    /**
     * VM can be started
     */
    const string COMMAND_CODE_CAN_ONLINE = 'CAN_ONLINE';

    /**
     * VM can shut down
     */
    const string COMMAND_CODE_CAN_OFFLINE = 'CAN_OFFLINE';

    /**
     * VM must be started
     */
    const string COMMAND_CODE_SHOULD_ONLINE = 'SHOULD_ONLINE';

    /**
     * VM have to shut down
     */
    const string COMMAND_CODE_SHOULD_OFFLINE = 'SHOULD_OFFLINE';

    /**
     * VM should reduce CPU
     */
    const string COMMAND_CODE_REDUCE_CPU = 'REDUCE_CPU';

    /**
     * VM should reduce RAM
     */
    const string COMMAND_CODE_REDUCE_RAM = 'REDUCE_RAM';

    /**
     * @var string
     */
    protected $table = 'proxtop_property_template_schedules';

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @var string[]
     */
    protected $casts = [
        'is_active'      => 'boolean',
        'starts_at_date' => 'date',
        'ends_at_date'   => 'date',
        'duration'       => 'integer',
    ];

    /**
     * Relationship: Get the parent template for this schedule.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProxtopPropertyTemplate::class, 'template_id');
    }
}