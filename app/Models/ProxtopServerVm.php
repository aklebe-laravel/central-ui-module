<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Acl\app\Models\User as AclUser;
use Modules\WebsiteBase\app\Models\MediaItem;

/**
 * @mixin IdeHelperProxtopServerVm
 */
class ProxtopServerVm extends Model
{
    //use TraitBaseModel, HasFactory;

    /**
     * @var string
     */
    protected $table = 'proxtop_server_vms';

    /**
     *
     */
    const string StatusRunning = 'running';

    /**
     *
     */
    const string StatusStopped = 'stopped';

    /**
     * Busy but user can interact
     */
    const string StatusPending = 'pending';

    /**
     * Busy and everything is locked
     */
    const string StatusLocked = 'locked';

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
    public function server(): BelongsTo
    {
        return $this->belongsTo(ProxtopServer::class);
    }

    /**
     * @return BelongsTo
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * @return string
     */
    public function getUniqueIdentifier(): string
    {
        return $this->vm_type.'_'.$this->extern_vm_id;
    }

    /**
     * Relationship: Get all property templates assigned to this VM.
     */
    public function propertyTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ProxtopPropertyTemplate::class,
            'proxtop_vm_property_template', // pivot table name
            'vm_id',                        // foreign key on pivot for this model
            'template_id'                   // foreign key on pivot for related model
        );
    }

    /**
     * @return BelongsToMany
     */
    public function enabledPropertyTemplates(): BelongsToMany
    {
        return $this->propertyTemplates()
            ->where('is_enabled', true);
    }

    /**
     * Calculates the timing for all attached property schedules strictly in UTC.
     * Values:
     * 0: currently in time (can start/end now)
     * > 0: seconds to become true (can start/end in x seconds)
     *
     * @return array<string, array{starts_in: ?int, ends_in: ?int}>
     */
    public function getTemplatesNextScheduleWindows(?Carbon $now = null): array
    {
        if ($now === null) {
            $now = Carbon::now('UTC');
        }

        $results = [
            'can_online'     => ['starts_in' => null, 'ends_in' => null],
            'should_online'  => ['starts_in' => null, 'ends_in' => null],
            'can_offline'    => ['starts_in' => null, 'ends_in' => null],
            'should_offline' => ['starts_in' => null, 'ends_in' => null],
        ];

        $templates = $this->enabledPropertyTemplates()
            ->with(['schedules' => fn($q) => $q->where('is_enabled', true)])
            ->get();

        foreach ($templates as $template) {
            foreach ($template->schedules as $schedule) {
                $key = match ($schedule->command_code) {
                    $schedule::COMMAND_CODE_CAN_ONLINE => 'can_online',
                    $schedule::COMMAND_CODE_SHOULD_ONLINE => 'should_online',
                    $schedule::COMMAND_CODE_CAN_OFFLINE => 'can_offline',
                    $schedule::COMMAND_CODE_SHOULD_OFFLINE => 'should_offline',
                    default => null
                };

                if (!$key) {
                    continue;
                }

                $timing = $this->calculateTiming($schedule, $now);

                if ($timing) {
                    if ($results[$key]['starts_in'] === null || $timing['starts_in'] < $results[$key]['starts_in']) {
                        $results[$key]['starts_in'] = $timing['starts_in'];
                    }
                    if ($results[$key]['ends_in'] === null || $timing['ends_in'] > $results[$key]['ends_in']) {
                        $results[$key]['ends_in'] = $timing['ends_in'];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @return array
     */
    public function getTemplatesAclResources(): array
    {
        $result = [
            'acl_res_view'     => [],
            'acl_res_shutdown' => [],
            'acl_res_start'    => [],
            'acl_res_edit'     => [],
        ];
        foreach ($this->enabledPropertyTemplates as $template) {
            foreach (array_keys($result) as $key) {
                if ($codes = $template->$key) {
                    // array_merge instead of '+': lists have numeric keys, '+' would drop
                    // the codes of every further template at the same index.
                    $result[$key] = array_merge($result[$key], (array) $codes);
                }
            }
        }

        return array_map(fn(array $codes) => array_values(array_unique($codes)), $result);
    }

    /**
     * Returns the acl resource codes of a user (one query).
     * Use this once and pass the result to isVisibleForAclCodes() when checking many VMs.
     *
     * @param  AclUser|null  $user
     *
     * @return array<int, string>
     */
    public static function getUserAclCodes(?AclUser $user): array
    {
        return $user ? $user->aclResources->pluck('code')
            ->all() : [];
    }

    /**
     * Visibility rule (same logic as start/shutdown):
     * - no 'acl_res_view' in any enabled template: visible for everyone
     * - otherwise the user needs at least one of these codes
     * - admin can see everything
     *
     * @param  array<int, string>  $userAclCodes
     *
     * @return bool
     */
    public function isVisibleForAclCodes(array $userAclCodes): bool
    {
        $required = $this->getTemplatesAclResources()['acl_res_view'];
        if (!$required) {
            return true;
        }

        if (in_array(AclResource::RES_ADMIN, $userAclCodes, true)) {
            return true;
        }

        return (bool) array_intersect($required, $userAclCodes);
    }

    /**
     * @param  AclUser|null  $user
     *
     * @return bool
     */
    public function isVisibleForUser(?AclUser $user): bool
    {
        return $this->isVisibleForAclCodes(static::getUserAclCodes($user));
    }

    /**
     * Lists all VMs the user is allowed to see.
     *
     * The acl codes are stored as JSON in a string column, so the filter runs in PHP
     * instead of SQL. That is fine for the small number of VMs on a home server.
     *
     * @param  AclUser|null                 $user   null = current user (guests only see unrestricted VMs)
     * @param  ProxtopServerVmBuilder|null  $query  base query, default: enabled VMs (qemu/lxc) with active server
     *
     * @return Collection<int, ProxtopServerVm>
     */
    public static function getVisibleForUser(?AclUser $user = null, ?ProxtopServerVmBuilder $query = null): SupportCollection
    {
        $user ??= app('website_base_settings')->currentUser();
        $query ??= static::query()
            ->whereIsVm()
            ->whereIsEnabledAndHasActiveServer();

        // load the user's codes once instead of one query per VM (hasAclResource() has no caching)
        $userAclCodes = static::getUserAclCodes($user);

        return $query->with('enabledPropertyTemplates')
            ->get()
            ->filter(fn(self $vm) => $vm->isVisibleForAclCodes($userAclCodes))
            ->values();
    }

    /**
     * Core calculation logic strictly enforcing UTC.
     */
    private function calculateTiming(ProxtopPropertyTemplateSchedule $schedule, Carbon $now): ?array
    {
        // Ensure now is UTC (double safety)
        $now = $now->copy()
            ->utc();

        if ($schedule->starts_at_date
            && $now->isBefore($schedule->starts_at_date->startOfDay()
                ->utc())
        ) {
            return [
                'starts_in' => (int) $now->diffInSeconds($schedule->starts_at_date->startOfDay()
                    ->utc()),
                'ends_in'   => 0,
            ];
        }

        if ($schedule->ends_at_date
            && $now->isAfter($schedule->ends_at_date->endOfDay()
                ->utc())
        ) {
            return null;
        }

        if (empty($schedule->cron_expression)) {
            return ['starts_in' => 0, 'ends_in' => 31536000];
        }

        // CronExpression defaults to UTC if no timezone is passed, matching our 'now'
        $cron = new CronExpression($schedule->cron_expression);

        // Get dates and cast to UTC immediately
        $lastRun = Carbon::instance($cron->getPreviousRunDate($now, 0, true))
            ->utc();
        $nextRun = Carbon::instance($cron->getNextRunDate($now, 0, true))
            ->utc();

        $windowEndsAt = $lastRun->copy()
            ->addSeconds($schedule->duration);

        // Scenario A: Currently within the window
        if ($now->lessThanOrEqualTo($windowEndsAt)) {
            return [
                'starts_in' => 0,
                'ends_in'   => (int) $now->diffInSeconds($windowEndsAt),
            ];
        }

        // Scenario B: Waiting for next occurrence
        return [
            'starts_in' => (int) $now->diffInSeconds($nextRun),
            'ends_in'   => 0,
        ];
    }

    /**
     * Get the highest number of all related templates
     *
     * @param  string     $property
     * @param  int|float  $default
     *
     * @return int|float
     */
    public function getTemplatesHighestNumber(string $property, int|float $default = 0): int|float
    {
        if (!$this->relationLoaded('propertyTemplates') || !$this->propertyTemplates->count()) {
            return $default;
        }

        $value = PHP_FLOAT_MIN;
        foreach ($this->propertyTemplates as $template) {
            if (!isset($template->$property)) {
                return $default;
            }

            $propValue = (float) $template->$property;
            if ($propValue > $value) {
                $value = $propValue;
            }
        }

        return $value;
    }

    /**
     * Get the lowest number of all related templates
     *
     * @param  string     $property
     * @param  int|float  $default
     *
     * @return int|float
     */
    public function getTemplatesLowestNumber(string $property, int|float $default = 0): int|float
    {
        if (!$this->relationLoaded('propertyTemplates') || !$this->propertyTemplates->count()) {
            return $default;
        }

        $value = PHP_FLOAT_MAX;
        foreach ($this->propertyTemplates as $template) {
            if (!isset($template->$property)) {
                return $default;
            }

            $propValue = (float) $template->$property;
            if ($propValue < $value) {
                $value = $propValue;
            }
        }

        return $value;
    }

    /**
     * @param  Builder  $query
     *
     * @return ProxtopServerVmBuilder<ProxtopServerVmBuilder>
     */
    public function newEloquentBuilder($query): ProxtopServerVmBuilder
    {
        return new ProxtopServerVmBuilder($query);
    }

    /**
     * @return string|null
     */
    public function getDisplayName(): ?string
    {
        return $this->name_intern ? sprintf("%s (%s)", $this->name_intern, $this->name) : $this->name;
    }
}