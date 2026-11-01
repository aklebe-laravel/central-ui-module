<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\CentralUi\app\Events\ProxmoxApiResourceDataUpdated;
use Modules\CentralUi\app\Events\ProxmoxApiRrdDataUpdated;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\CentralUi\app\Services\SafeBroadcast;

/**
 * Fetches data from the Proxmox API and rebuilds the payload cache.
 *
 * Why a job: these calls can take many seconds (one HTTP request per running VM,
 * timeouts up to 5s each). Inside the scheduler tick they blocked everything
 * behind them, including the processing of due shutdown/start actions.
 *
 * Two kinds of work, one class, so they can be serialized (see middleware()):
 *   - PartResources: cluster resources + payload rebuild (ProxmoxService::receiveResourcesAndBroadcast)
 *   - PartRrd:       RRD statistics per running VM for one timeframe (ProxmoxService::receiveRrdData),
 *                    one job per key of ProxmoxService::RrdTimeframes
 */
class RefreshProxmoxDataJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string PartResources = 'resources';
    public const string PartRrd = 'rrd';

    /**
     * Max runtime of one run (seconds).
     */
    public int $timeout = 120;

    /**
     * Do not queue a part again while the same part is still queued or running.
     * Prevents a pile-up when the Proxmox API is slow.
     */
    public int $uniqueFor = 120;

    /**
     * @param  string  $part       self::PartResources or self::PartRrd
     * @param  string  $timeframe  PartRrd only: key of ProxmoxService::RrdTimeframes
     */
    public function __construct(protected string $part, protected string $timeframe = 'hour')
    {
        // Optional: own queue/worker. Falls back to 'default' when the config key does not exist.
        $this->onQueue((string) config('central-ui.queues.proxmox_refresh', 'default'));
    }

    /**
     * One unique lock per part (resources, rrd-hour, rrd-day, ...).
     */
    public function uniqueId(): string
    {
        return ($this->part === self::PartRrd) ? $this->part.'-'.$this->timeframe : $this->part;
    }

    /**
     * Resources and RRD work never run at the same time.
     * Originally both wrote into one shared cache array. Since every VM has its own
     * cache keys (see ProxmoxService::CachePrefix) this is no longer required, but it
     * keeps the load on the Proxmox API low. The second job is released and retried.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('proxmox-data-refresh'))->releaseAfter(5)
                ->expireAfter(180),
        ];
    }

    /**
     * Released jobs count as attempts, so limit by time instead of by tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(3);
    }

    public function handle(): void
    {
        $proxmox = app(ProxmoxService::class);

        if ($this->part === self::PartResources) {
            $proxmox->receiveResourcesAndBroadcast();
            SafeBroadcast::send(new ProxmoxApiResourceDataUpdated());

            return;
        }

        if ($this->part === self::PartRrd) {
            $proxmox->receiveRrdData($this->timeframe);
            SafeBroadcast::send(new ProxmoxApiRrdDataUpdated());
        }
    }
}