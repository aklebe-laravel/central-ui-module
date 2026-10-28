<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\WebsiteBase\app\Models\User;

/**
 * @mixin IdeHelperProxtopServerAction
 */
class ProxtopServerAction extends Model
{
    /**
     * Starting vm launch timer
     */
    public const string ActionStart = 'start';

    /**
     * Starting vm shutdown timer
     */
    public const string ActionShutDown = 'shutdown';

    /**
     * fixed amount of lock for the vm
     */
    public const string ActionPendingLock = 'pending_lock';

    /**
     * locked until start for the vm
     */
    public const string ActionPendingToStart = 'pending_to_start';

    /**
     * locked until lockdown for the vm
     */
    public const string ActionPendingToShutdown = 'pending_to_shutdown';

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

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime', // automatically use the $dateFormat
        ];
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
     * @param  int          $vmId
     * @param  string|null  $action
     *
     * @return Builder
     */
    public static function getScheduledActions(int $vmId, ?string $action = null): Builder
    {
        $result = self::with([])->where('vm_id', $vmId);
        if ($action) {
            $result->where('action', $action);
        }

        return $result;
    }

    /**
     * @param  int    $vmId
     * @param  array  $actions
     *
     * @return Builder
     */
    public static function getAnyScheduledAction(int $vmId, array $actions): Builder
    {
        return self::with([])->where('vm_id', $vmId)->whereIn('action', $actions);
    }

    /**
     * @param  int          $vmId
     * @param  string|null  $action
     *
     * @return Model|ProxtopServerAction|null
     */
    public static function getScheduledAction(int $vmId, ?string $action = null): Model|self|null
    {
        $result = self::getScheduledActions($vmId, $action);

        return $result->get()->first();
    }

    /**
     * @param  int     $vmId
     * @param  string  $actionCode
     *
     * @return void
     */
    public static function removeAction(int $vmId, string $actionCode): void
    {
        if ($action = self::getScheduledAction($vmId, $actionCode)) {
            if ($action->delete()) {
                //Log::warning(sprintf("Action '%s' deleted!", $actionCode));
            }
        } else {
            Log::warning(sprintf("Unable to delete! Action '%s' not found!", $actionCode));
        }
    }

    /**
     * @param  int     $vmId
     * @param  string  $actionCode
     * @param  int     $userId
     * @param  Carbon  $schedule
     *
     * @return Model|ProxtopServerAction|null
     */
    public static function createAction(int $vmId, string $actionCode, int $userId, CarbonInterface $schedule): Model|self|null
    {
        //Log::info(sprintf("Creating Action '%s' for VM '%s'.", $actionCode, $vmId));

        return self::create([
            'action'       => $actionCode,
            'vm_id'        => $vmId,
            'user_id'      => $userId,
            'scheduled_at' => $schedule,
        ]);
    }

    /**
     * @param  int     $vmId
     * @param  string  $actionCode
     * @param  int     $userId
     * @param  Carbon  $schedule
     *
     * @return Model|ProxtopServerAction|null
     */
    public static function createActionIfNotExists(int $vmId, string $actionCode, int $userId, CarbonInterface $schedule): Model|self|null
    {
        if ($found = self::with([])->where([
            'action' => $actionCode,
            'vm_id'  => $vmId,
        ])->first()
        ) {
            return $found;
        }

        return self::createAction($vmId, $actionCode, $userId, $schedule);
    }

}