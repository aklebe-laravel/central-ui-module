<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A WireGuard network (= one tunnel on the pfSense) managed by Proxtop.
 *
 * Read by DatabaseNetworkRepository. Networks with the same host_group share their host numbers.
 *
 * @property int         $id
 * @property float       $position
 * @property string      $tunnel      pfSense tunnel name, e.g. "tun_wg1"
 * @property string|null $host_group
 * @property string      $prefix      e.g. "10.0.99."
 * @property int         $first_host
 * @property int         $mask
 * @property string      $endpoint
 * @property string      $allowed_ips
 * @property string      $dns
 * @property int         $mtu
 * @property string|null $description
 */
class ProxtopWireguardNetwork extends Model
{
    /**
     * Precision in Microseconds
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var string
     */
    protected $table = 'proxtop_wireguard_networks';

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @var string[]
     */
    protected $casts = [
        'position'   => 'float',
        'first_host' => 'integer',
        'mask'       => 'integer',
        'mtu'        => 'integer',
    ];

    /**
     * Order used everywhere the networks are listed (forms, overview, config files).
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')
            ->orderBy('tunnel');
    }

    /**
     * Normalize the free text fields, so the repository gets clean values.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $network): void {
            foreach (['tunnel', 'host_group', 'prefix', 'endpoint', 'allowed_ips', 'dns'] as $field) {
                if (is_string($network->$field)) {
                    $network->$field = trim($network->$field);
                }
            }

            // An empty group means "no group".
            if ($network->host_group === '') {
                $network->host_group = null;
            }
        });
    }
}
