<?php
declare(strict_types=1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CentralUi\app\Services\ProxmoxService;

/**
 * @mixin IdeHelperProxtopServer
 */
class ProxtopServer extends Model
{
    //use TraitBaseModel, HasFactory;

    /**
     * Precision in Microseconds
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
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::saved(static function (self $server): void {
            // Trigger if the model is brand new OR if the host column was updated
            if ($server->wasRecentlyCreated || $server->wasChanged('host')) {
                $server->handleHostChange();
            }
        });
    }

    /**
     * Execute business logic when the host property changes.
     */
    public function handleHostChange(): void
    {
        if ($this->host) {
            logger()->info(sprintf("%s Host changed. Retriving data using api: %s", self::class, $this->host));
            $proxmox = app(ProxmoxService::class);
            $proxmox->receiveResources();
        }
    }

    /**
     * @return HasMany
     */
    public function vms(): HasMany
    {
        return $this->hasMany(ProxtopServerVm::class, 'server_id');
    }

    /**
     * @return string
     */
    public function generateAuthorizationHeader(): string
    {
        return ProxmoxService::AuthHeaderApiTokenName."={$this->user}!{$this->token_name}={$this->token_secret}";
    }


}