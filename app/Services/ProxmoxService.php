<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Models\ProxtopServerAction;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\SystemBase\app\Services\Base\BaseService;

class ProxmoxService extends BaseService
{
    /**
     *
     */
    const string AuthHeaderName = 'Authorization';

    /**
     *
     */
    const string AuthHeaderApiTokenName = 'PVEAPIToken';

    /**
     * Prefix of all cache keys of this service.
     *
     * Every piece of data has its own key (see buildCacheKey()):
     *   central-ui.proxmox.server.<serverId>.vms           raw 'cluster/resources' of a server
     *   central-ui.proxmox.vm.<vmId>.rrd_stats.<timeframe> statistics of the RRD history of a VM (RrdStats)
     *   central-ui.proxmox.vm.<vmId>.prepared              prepared payload of a VM (read by the desktop cards)
     *   central-ui.proxmox.resources                       list of all prepared payloads (console commands)
     *
     * The raw RRD history (~450 KB per VM and day) is never cached, only its statistics (< 1 KB).
     * Overview for humans: README, section "Caching and statistics".
     */
    const string CachePrefix = 'central-ui.proxmox';

    /**
     * Old cache keys. Deleted by forgetLegacyCache().
     *   proxmox_server_api, proxtop_api_cache: one array for everything (several MB)
     *
     * @todo remove when every instance ran the resource job once with this version
     */
    const array LegacyCacheKeys = ['proxmox_server_api', 'proxtop_api_cache'];

    /**
     * RRD timeframes fetched from Proxmox ('rrddata?timeframe=...') and how often.
     *   config:   core config path (module central-ui) of the interval in seconds
     *   interval: default if the config is missing
     *
     * Large timeframes are expensive (day: ~450 KB per VM) and change slowly, so they are fetched rarely.
     * The scheduler dispatches one RefreshProxmoxDataJob per timeframe (see ScheduleServiceProvider).
     */
    const array RrdTimeframes = [
        'hour' => ['config' => 'scheduler.api.rrd.interval', 'interval' => 60],
        'day'  => ['config' => 'scheduler.api.rrd_day.interval', 'interval' => 1800],
    ];

    /**
     * The phases of the VM statistics (payload keys like 'cpu_avg_phase1').
     *   timeframe: which RRD timeframe the phase is calculated from (key of RrdTimeframes)
     *   minutes:   window length back from the newest entry, null = the whole timeframe
     *
     * receiveRrdData() calculates every phase of the fetched timeframe, getVmRrdPhases() merges them,
     * the prepare*Stats() methods read them.
     */
    const array RrdPhases = [
        'phase1' => ['timeframe' => 'hour', 'minutes' => 10],
        'phase2' => ['timeframe' => 'hour', 'minutes' => 60],
        'phase3' => ['timeframe' => 'day', 'minutes' => null],
    ];

    /**
     * Statistics are refreshed regularly while a VM is running. Without refresh (VM stopped,
     * scheduler down) they expire after this time instead of staying in the cache forever.
     */
    const int RrdCacheTtlSeconds = 86400;

    /**
     * @var string
     */
    public string $permaRequestUri = 'api2/json';

    /**
     * Tolerance Factor for comparisons
     *
     * @var float
     */
    public float $defaultCompareTolerance = 0.1;

    /**
     * True after forgetLegacyCache() ran in this process.
     *
     * @var bool
     */
    protected static bool $legacyCacheForgotten = false;

    /**
     * @param  ProxtopServer  $server
     * @param  string         $method
     * @param  string         $requestUrl
     * @param  array          $queryParams
     * @param  array          $formParams
     * @param  array          $headers
     *
     * @return string
     * @throws ConnectionException
     */
    public function makeRequest(ProxtopServer $server, string $method, string $requestUrl, array $queryParams = [], array $formParams = [], array $headers = []): string
    {
        $fullUrl = rtrim($server->host, '/').'/'.ltrim($this->permaRequestUri.'/'.$requestUrl, '/');

        // Manuelles Bauen des Bodys für Proxmox
        $pairs = [];
        //logger(print_r($formParams, true));
        foreach ($formParams as $key => $value) {

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (is_array($value)) {
                // Falls es ein Array ist (z.B. command => ['ls', '-la']),
                // wiederholen wir den Key ohne Indizes: command=ls&command=-la
                foreach ($value as $v) {
                    $pairs[] = urlencode((string) $key).'='.urlencode((string) $v);
                }
            } else {
                $pairs[] = urlencode((string) $key).'='.urlencode((string) $value);
            }

        }
        $formattedBody = implode('&', $pairs);

        // In deiner makeRequest Methode:
        $response = Http::withHeaders($headers)
            ->timeout(5)
            ->connectTimeout(2)
            ->withOptions(['verify' => false])
            ->withBody($formattedBody, 'application/x-www-form-urlencoded')
            ->send($method, $fullUrl, [
                'query' => $queryParams,
            ]);
        if ($response->failed()) {
            Log::error("Proxmox API Error: ".$response->status(), [
                'url'       => $fullUrl,
                'sent_body' => $formattedBody,
                'response'  => $response->body(),
            ]);

            return "";
        }


        return $response->body();
    }

    /**
     * @param  ProxtopServer  $server
     * @param  string         $additionalUri
     * @param  array          $headers
     * @param  string         $method
     * @param  array          $formParams
     * @param  array          $queryParams
     *
     * @return string
     */
    public function send(ProxtopServer $server, string $additionalUri, array $headers = [], string $method = 'GET', array $formParams = [], array $queryParams = []): string
    {
        try {
            if (!isset($headers[self::AuthHeaderName])) {
                $headers[self::AuthHeaderName] = $server->generateAuthorizationHeader();
            }

            return $this->makeRequest($server, $method, $additionalUri, $queryParams, $formParams, $headers);
        } catch (Exception $exception) {
            $this->error($exception->getMessage(), [__METHOD__]);

            return "";
        }
    }

    /**
     * @param  string|int  ...$parts
     *
     * @return string like 'central-ui.proxmox.vm.12.prepared'
     */
    public function buildCacheKey(string|int ...$parts): string
    {
        return implode('.', [self::CachePrefix, ...$parts]);
    }

    /**
     * @param  ProxtopServerVm|int  $vm  model or its id
     *
     * @return int
     */
    protected function vmKey(ProxtopServerVm|int $vm): int
    {
        return is_int($vm) ? $vm : (int) $vm->getKey();
    }

    /**
     * Raw 'cluster/resources' data of a server (received by receiveResources()).
     *
     * @param  int  $serverId
     *
     * @return array
     */
    public function getServerVms(int $serverId): array
    {
        return Cache::get($this->buildCacheKey('server', $serverId, 'vms')) ?? [];
    }

    /**
     * @param  int    $serverId
     * @param  array  $vms
     *
     * @return void
     */
    public function setServerVms(int $serverId, array $vms): void
    {
        Cache::forever($this->buildCacheKey('server', $serverId, 'vms'), $vms);
    }

    /**
     * @param  ProxtopServerVm|int  $vm
     * @param  string               $timeframe
     *
     * @return string
     */
    protected function getCacheKeyForRrdStats(ProxtopServerVm|int $vm, string $timeframe): string
    {
        return $this->buildCacheKey('vm', $this->vmKey($vm), 'rrd_stats', $timeframe);
    }

    /**
     * Statistics of one RRD timeframe of a VM (written by receiveRrdData()).
     *
     * @param  ProxtopServerVm|int  $vm
     * @param  string               $timeframe  key of RrdTimeframes
     *
     * @return array{updated_at?: int, phases?: array<string, array>}  [] if not cached
     */
    public function getVmRrdStats(ProxtopServerVm|int $vm, string $timeframe): array
    {
        return Cache::get($this->getCacheKeyForRrdStats($vm, $timeframe)) ?? [];
    }

    /**
     * @param  ProxtopServerVm|int  $vm
     * @param  string               $timeframe
     * @param  array                $stats  ['updated_at' => int, 'phases' => ['phase1' => RrdStats, ...]]
     *
     * @return void
     */
    public function setVmRrdStats(ProxtopServerVm|int $vm, string $timeframe, array $stats): void
    {
        Cache::put($this->getCacheKeyForRrdStats($vm, $timeframe), $stats, self::RrdCacheTtlSeconds);
    }

    /**
     * Calculates the statistics of every phase that uses $timeframe (see RrdPhases).
     *
     * @param  string  $timeframe
     * @param  array   $rrdData  raw entries of 'rrddata'
     *
     * @return array{updated_at: int, phases: array<string, array>}
     */
    public function calculateRrdStats(string $timeframe, array $rrdData): array
    {
        $phases = [];
        foreach (self::RrdPhases as $phase => $definition) {
            if ($definition['timeframe'] === $timeframe) {
                $phases[$phase] = RrdStats::calculate($rrdData, $definition['minutes']);
            }
        }

        return [
            'updated_at' => time(),
            'phases'     => $phases,
        ];
    }

    /**
     * All phases of a VM (one cache round trip for all timeframes).
     * Missing phases (not fetched yet) are empty: all values 0.
     *
     * @param  ProxtopServerVm|int  $vm
     *
     * @return array<string, array>  'phase1' => RrdStats::calculate() result, ...
     */
    public function getVmRrdPhases(ProxtopServerVm|int $vm): array
    {
        $keys = [];
        foreach (array_keys(self::RrdTimeframes) as $timeframe) {
            $keys[] = $this->getCacheKeyForRrdStats($vm, $timeframe);
        }

        $phases = [];
        foreach (Cache::many($keys) as $stats) {
            $phases += $stats['phases'] ?? [];
        }

        $result = [];
        foreach (self::RrdPhases as $phase => $definition) {
            $result[$phase] = $phases[$phase] ?? RrdStats::empty((int) $definition['minutes']);
        }

        return $result;
    }

    /**
     * One value of a phase.
     *
     * @param  array   $phases  result of getVmRrdPhases()
     * @param  string  $phase   'phase1', ...
     * @param  string  $metric  one of RrdStats::METRICS
     * @param  string  $kind    'avg' or 'max'
     *
     * @return float
     */
    protected function rrdValue(array $phases, string $phase, string $metric, string $kind = 'avg'): float
    {
        return (float) ($phases[$phase][$kind][$metric] ?? 0.0);
    }

    /**
     * Deletes the old cache keys (one big array for everything, several MB).
     * Runs once per process, deleting a missing key is cheap.
     *
     * @return void
     * @todo remove together with LegacyCacheKeys
     *
     */
    public function forgetLegacyCache(): void
    {
        if (static::$legacyCacheForgotten) {
            return;
        }

        foreach (self::LegacyCacheKeys as $key) {
            Cache::forget($key);
        }
        static::$legacyCacheForgotten = true;
    }

    /**
     * @param  float  $in
     * @param  float  $out
     *
     * @return float
     */
    protected function calcTraffic(float $in, float $out): float
    {
        return ($in + $out);// / 2.0;
    }

    /**
     * @param  float  $currentValue
     * @param  float  $trafficLimit
     *
     * @return float
     */
    protected function calcTrafficPercent(float $currentValue, float $trafficLimit): float
    {
        return round($trafficLimit ? (($currentValue / $trafficLimit) * 100.0) : 0, 2);
    }

    /**
     * True if cpu_usage and cpu_avg_phase1 are lower than cpu_idle in their templates.
     *
     * @param  array            $vmStatusPayload
     * @param  ProxtopServerVm  $vm
     *
     * @return bool
     */
    protected function isCpuIdle(array $vmStatusPayload, ProxtopServerVm $vm): bool
    {
        $low = $vm->getTemplatesHighestNumber('cpu_idle', 10);
        if ($vmStatusPayload['cpu_usage'] <= $low || $vmStatusPayload['cpu_avg_phase1'] <= $low) {
            return true;
        }

        return $this->cmpWithTolerance($vmStatusPayload['cpu_avg_phase1'], $vmStatusPayload['cpu_avg_phase2']) < 1; // 0 or -1
    }

    /**
     * @param  array  $vmStatusPayload
     *
     * @return bool
     */
    protected function isMemIdle(array $vmStatusPayload): bool
    {
        return true; // im not sure if it's useful
    }

    /**
     * True if net traffic phase1 is same or lower than phase2 with tolerance
     *
     * @param  array            $vmStatusPayload
     * @param  ProxtopServerVm  $vm
     *
     * @return bool
     */
    protected function isNetTrafficIdle(array $vmStatusPayload, ProxtopServerVm $vm): bool
    {
        if ($vmStatusPayload['net_traffic_avg_phase1_percent'] <= $vmStatusPayload['net_traffic_idle']) {
            return true;
        }

        return $this->cmpWithTolerance($vmStatusPayload['net_traffic_avg_phase1_percent'], $vmStatusPayload['net_traffic_avg_phase2_percent'], 0.1) < 1 // 0 or -1
               && $this->cmpWithTolerance($vmStatusPayload['net_traffic_avg_phase1_percent'], $vmStatusPayload['net_traffic_avg_phase3_percent'], 0.5) < 1; // 0 or -1
    }

    /**
     * Compares a value 'current' is like a value 'base' with tolerance (from base).
     * Use $tolerance as 1 for 100%, 0.1 for 10%
     *
     * @param  float       $current
     * @param  float       $base
     * @param  float|null  $tolerance  like 0.1 is equal 10% (null = use defaultCompareTolerance)
     *
     * @return int  0: $current is in ($base +- $tolerance), otherwise -1 (lower) or 1 (greather)
     */
    public function cmpWithTolerance(float $current, float $base, ?float $tolerance = null): int
    {
        if ($tolerance === null) {
            $tolerance = $this->defaultCompareTolerance;
        }
        $vMax = $base + $base * $tolerance;
        $vMin = $base - $base * $tolerance;

        return ($current < $vMin) ? -1 : (($current > $vMax) ? 1 : 0);
    }

    /**
     * @param  array            $vmPayload
     * @param  ProxtopServerVm  $vm
     * @param  array            $phases  result of getVmRrdPhases()
     *
     * @return array
     */
    public function prepareCpuStats(array $vmPayload, ProxtopServerVm $vm, array $phases): array
    {
        $cpuPhase1 = round($this->rrdValue($phases, 'phase1', 'cpu') * 100);
        $cpuPhase2 = round($this->rrdValue($phases, 'phase2', 'cpu') * 100);
        $cpuPhase3 = round($this->rrdValue($phases, 'phase3', 'cpu') * 100);

        return [
            'cpu_idle'               => $vm->getTemplatesHighestNumber('cpu_idle', 10),
            'cpu_limit'              => $vm->getTemplatesLowestNumber('cpu_limit', 95),
            'cpu_avg_phase1'         => $cpuPhase1,
            'cpu_avg_phase2'         => $cpuPhase2,
            'cpu_avg_phase3'         => $cpuPhase3,
            'cpu_avg_phase1_percent' => $cpuPhase1,
            'cpu_avg_phase2_percent' => $cpuPhase2,
            'cpu_avg_phase3_percent' => $cpuPhase3,
        ];
    }

    /**
     * @param  array            $vmPayload
     * @param  ProxtopServerVm  $vm
     * @param  array            $phases  result of getVmRrdPhases()
     *
     * @return array
     */
    public function prepareMemStats(array $vmPayload, ProxtopServerVm $vm, array $phases): array
    {
        $memMax = $vmPayload['maxmem'] ?? 0;
        $memUsed = $vmPayload['mem'] ?? 0;
        $memUsage = ($memMax > 0) ? round((((float) $memUsed) / (float) $memMax) * 100.0) : 0;
        $memUsedPhase1 = round($this->rrdValue($phases, 'phase1', 'mem'));
        $memUsedPhase2 = round($this->rrdValue($phases, 'phase2', 'mem'));
        $memUsedPhase3 = round($this->rrdValue($phases, 'phase3', 'mem'));

        return [
            'mem_free'               => $memMax - $memUsed,
            'mem_usage'              => $memUsage,
            'mem_avg_phase1'         => $memUsedPhase1,
            'mem_avg_phase2'         => $memUsedPhase2,
            'mem_avg_phase3'         => $memUsedPhase3,
            'mem_avg_phase1_percent' => $memMax ? round(($memUsedPhase1 / $memMax) * 100.0) : 100,
            'mem_avg_phase2_percent' => $memMax ? round(($memUsedPhase2 / $memMax) * 100.0) : 100,
            'mem_avg_phase3_percent' => $memMax ? round(($memUsedPhase3 / $memMax) * 100.0) : 100,
        ];
    }

    /**
     * @param  array            $vmPayload
     * @param  ProxtopServerVm  $vm
     * @param  array            $phases  result of getVmRrdPhases()
     *
     * @return array
     */
    public function prepareNetStats(array $vmPayload, ProxtopServerVm $vm, array $phases): array
    {
        $netTrafficLimit = 20 * 1024 * 1204; // @todo: read max traffic of system
        $countVms = ProxtopServerVm::count();
        if ($countVms) {
            $netTrafficLimit /= $countVms;
        }
        // use vm template traffic if configured
        $netTrafficLimit = $vm->getTemplatesLowestNumber('net_traffic_limit', $netTrafficLimit);

        $netIn = $vmPayload['netin'] ?? 0.0;
        $netInAvgPhase1 = round($this->rrdValue($phases, 'phase1', 'netin'));
        $netInAvgPhase2 = round($this->rrdValue($phases, 'phase2', 'netin'));
        $netInAvgPhase3 = round($this->rrdValue($phases, 'phase3', 'netin'));
        $netInMaxPhase3 = round($this->rrdValue($phases, 'phase3', 'netin', 'max'));
        $netOut = $vmPayload['netout'] ?? 0.0;
        $netOutAvgPhase1 = round($this->rrdValue($phases, 'phase1', 'netout'));
        $netOutAvgPhase2 = round($this->rrdValue($phases, 'phase2', 'netout'));
        $netOutAvgPhase3 = round($this->rrdValue($phases, 'phase3', 'netout'));
        $netOutMaxPhase3 = round($this->rrdValue($phases, 'phase3', 'netout', 'max'));
        $netTraffic = $this->calcTraffic($netIn, $netOut);
        $netTrafficAvgPhase1 = $this->calcTraffic($netInAvgPhase1, $netOutAvgPhase1);
        $netTrafficAvgPhase2 = $this->calcTraffic($netInAvgPhase2, $netOutAvgPhase2);
        $netTrafficAvgPhase3 = $this->calcTraffic($netInAvgPhase3, $netOutAvgPhase3);
        $netTrafficMaxPhase3 = $this->calcTraffic($netInMaxPhase3, $netOutMaxPhase3);
        // use max traffic/day if limit is configured too high
        if ($netTrafficLimit > $netTrafficMaxPhase3) {
            $netTrafficLimit = $netTrafficMaxPhase3;
        }

        return [
            'netin'                          => $netIn,
            'netout'                         => $netOut,
            'net_traffic'                    => $netTraffic,
            'netin_avg_phase1'               => $netInAvgPhase1,
            'netin_avg_phase2'               => $netInAvgPhase2,
            'netin_avg_phase3'               => $netInAvgPhase3,
            'netout_avg_phase1'              => $netOutAvgPhase1,
            'netout_avg_phase2'              => $netOutAvgPhase2,
            'netout_avg_phase3'              => $netOutAvgPhase3,
            'net_traffic_avg_phase1'         => $netTrafficAvgPhase1,
            'net_traffic_avg_phase2'         => $netTrafficAvgPhase2,
            'net_traffic_avg_phase3'         => $netTrafficAvgPhase3,
            'net_traffic_max_phase3'         => $netTrafficMaxPhase3,
            'net_traffic_avg_phase1_percent' => $this->calcTrafficPercent($netTrafficAvgPhase1, $netTrafficLimit),
            'net_traffic_avg_phase2_percent' => $this->calcTrafficPercent($netTrafficAvgPhase2, $netTrafficLimit),
            'net_traffic_avg_phase3_percent' => $this->calcTrafficPercent($netTrafficAvgPhase3, $netTrafficLimit),

            'net_traffic_idle'  => 10, // percent
            'net_traffic_limit' => 90, // percent
        ];
    }

    /**
     * @param  array            $vmPayload
     * @param  ProxtopServerVm  $vm
     * @param  array            $phases  result of getVmRrdPhases()
     *
     * @return array
     */
    public function prepareDiskStats(array $vmPayload, ProxtopServerVm $vm, array $phases): array
    {
        $disk = (float) ($vmPayload['disk'] ?? 0.0);
        $maxDisk = (float) ($vmPayload['maxdisk'] ?? 0.0);
        $diskPhase1 = round($this->rrdValue($phases, 'phase1', 'disk'));
        $diskPhase2 = round($this->rrdValue($phases, 'phase2', 'disk'));
        $diskPhase3 = round($this->rrdValue($phases, 'phase3', 'disk'));
        $diskRead = $vmPayload['diskread'] ?? 0.0;
        $diskReadAvgPhase1 = round($this->rrdValue($phases, 'phase1', 'diskread'));
        $diskReadAvgPhase2 = round($this->rrdValue($phases, 'phase2', 'diskread'));
        $diskReadAvgPhase3 = round($this->rrdValue($phases, 'phase3', 'diskread'));
        $diskReadMaxPhase3 = round($this->rrdValue($phases, 'phase3', 'diskread', 'max'));
        $diskWrite = $vmPayload['diskwrite'] ?? 0.0;
        $diskWriteAvgPhase1 = round($this->rrdValue($phases, 'phase1', 'diskwrite'));
        $diskWriteAvgPhase2 = round($this->rrdValue($phases, 'phase2', 'diskwrite'));
        $diskWriteAvgPhase3 = round($this->rrdValue($phases, 'phase3', 'diskwrite'));
        $diskWriteMaxPhase3 = round($this->rrdValue($phases, 'phase3', 'diskwrite', 'max'));
        $diskTrafficLimit = 300 * 1024 * 1204; // @todo: read max traffic disks
        $diskTrafficLimit = $vm->getTemplatesLowestNumber('disk_traffic_limit', $diskTrafficLimit);
        $diskTrafficMaxPhase3 = $this->calcTraffic($diskReadMaxPhase3, $diskWriteMaxPhase3);
        // use max traffic/day if limit is configured too high
        if ($diskTrafficLimit > $diskTrafficMaxPhase3) {
            $diskTrafficLimit = $diskTrafficMaxPhase3;
        }
        $diskTrafficAvgPhase1 = $this->calcTraffic($diskReadAvgPhase1, $diskWriteAvgPhase1);
        $diskTrafficAvgPhase2 = $this->calcTraffic($diskReadAvgPhase2, $diskWriteAvgPhase2);
        $diskTrafficAvgPhase3 = $this->calcTraffic($diskReadAvgPhase3, $diskWriteAvgPhase3);

        return [
            'disk_usage'                      => $maxDisk ? round(($disk / $maxDisk) * 100.0) : 0.0,
            'disk'                            => $disk,
            'disk_free'                       => $maxDisk - $disk,
            'maxdisk'                         => $maxDisk,
            'disk_avg_phase1'                 => $diskPhase1,
            'disk_avg_phase2'                 => $diskPhase2,
            'disk_avg_phase3'                 => $diskPhase3,
            'disk_avg_phase1_percent'         => $maxDisk ? round(($diskPhase1 / $maxDisk) * 100.0) : 100,
            'disk_avg_phase2_percent'         => $maxDisk ? round(($diskPhase2 / $maxDisk) * 100.0) : 100,
            'disk_avg_phase3_percent'         => $maxDisk ? round(($diskPhase3 / $maxDisk) * 100.0) : 100,
            'diskread_avg_phase1'             => $diskReadAvgPhase1,
            'diskread_avg_phase2'             => $diskReadAvgPhase2,
            'diskread_avg_phase3'             => $diskReadAvgPhase3,
            'diskwrite_avg_phase1'            => $diskWriteAvgPhase1,
            'diskwrite_avg_phase2'            => $diskWriteAvgPhase2,
            'diskwrite_avg_phase3'            => $diskWriteAvgPhase3,
            'disk_traffic_avg_phase1'         => $diskTrafficAvgPhase1,
            'disk_traffic_avg_phase2'         => $diskTrafficAvgPhase2,
            'disk_traffic_avg_phase3'         => $diskTrafficAvgPhase3,
            'disk_traffic_max_phase3'         => $diskTrafficMaxPhase3,
            // phase 2 and 3 used the phase 1 value before (copy & paste error)
            'disk_traffic_avg_phase1_percent' => $this->calcTrafficPercent($diskTrafficAvgPhase1, $diskTrafficLimit),
            'disk_traffic_avg_phase2_percent' => $this->calcTrafficPercent($diskTrafficAvgPhase2, $diskTrafficLimit),
            'disk_traffic_avg_phase3_percent' => $this->calcTrafficPercent($diskTrafficAvgPhase3, $diskTrafficLimit),

            'disk_traffic_idle'  => 10, // percent
            'disk_traffic_limit' => 90, // percent
        ];
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  array            $serverResourcesCollections
     * @param  bool             $forceRefill  force recalculation and overwrite cache
     *
     * @return array
     */
    public function getCachedPreparedVmData(ProxtopServerVm $vm, array $serverResourcesCollections, bool $forceRefill = false): array
    {
        // previous payload of this vm: returned as it is, or compared with the new one (hash, status)
        $prevCachedVmPayload = $this->getCachedVmProperty($vm, null, []);
        if (!$forceRefill && $prevCachedVmPayload) {
            return $prevCachedVmPayload;
        }

        if (!($server = $vm->server)) {
            Log::error('Invalid server relation!');

            return [];
        }

        /** @var Collection $serverResources */
        $serverResources = $serverResourcesCollections[$server->getKey()];
        /** @var array $vmPayload */
        $vmPayload = $serverResources->where('vmid', $vm->extern_vm_id)
                         ->where('type', $vm->vm_type)
                         ->first() ?? [];

        // statistics of all phases (calculated by the RRD jobs, small)
        $rrdPhases = $this->getVmRrdPhases($vm);

        // at first load important values to comparing by hash ...
        $vmNewPayload = [
            'vm_type'        => $vm->vm_type,
            'status_message' => '',
            'id'             => $vm->getKey(),
            'vm_id'          => $vm->extern_vm_id,
            'node'           => $vm->node,
            'name'           => $vm->name_intern ?? $vm->name ?? ($vmPayload['name'] ?? null),
            'image'          => $vm->mediaItem->final_url ?? '',
            'status'         => $vmPayload['status'] ?? null,
            'cpu_usage'      => round((float) ($vmPayload['cpu'] ?? 0) * 100.0),
            'cpu_max'        => $vmPayload['maxcpu'] ?? 1,
            'mem_max'        => $vmPayload['maxmem'] ?? 0,
            'mem_used'       => $vmPayload['mem'] ?? 0,
        ];

        $newHash = hash('xxh64', json_encode($vmNewPayload));
        $oldHash = $prevCachedVmPayload['hash'] ?? null;
        $hasBaseDataChanged = $oldHash !== $newHash;

        //
        $vmNewPayload += [
            'hash'         => $newHash,
            'hash_changed' => $hasBaseDataChanged,
            'uptime'       => $vmPayload['uptime'] ?? 0,
            // length of the phases in minutes (labels like "10m", "1h", "1d")
            'avg_phase1'   => $rrdPhases['phase1']['minutes'],
            'avg_phase2'   => $rrdPhases['phase2']['minutes'],
            'avg_phase3'   => $rrdPhases['phase3']['minutes'],
            'schedules'    => $vm->getTemplatesNextScheduleWindows(),
        ];

        // if hash was same, avoid calculate extra data
        // i don't trust myself to check ($hasBaseDataChanged) xD
        // @todo: make it experimental in settings?
        if (true) {
            // preparing all kind of stats
            $vmNewPayload += $this->prepareCpuStats($vmPayload, $vm, $rrdPhases);
            $vmNewPayload += $this->prepareMemStats($vmPayload, $vm, $rrdPhases);
            $vmNewPayload += $this->prepareNetStats($vmPayload, $vm, $rrdPhases);
            $vmNewPayload += $this->prepareDiskStats($vmPayload, $vm, $rrdPhases);

            // idle states
            $vmNewPayload['is_idle_cpu'] = $this->isCpuIdle($vmNewPayload, $vm);
            $vmNewPayload['is_idle_mem'] = $this->isMemIdle($vmNewPayload);
            $vmNewPayload['is_idle_net_traffic'] = $this->isNetTrafficIdle($vmNewPayload, $vm);
            $vmNewPayload['is_idle'] = $vmNewPayload['is_idle_cpu']
                                       && $vmNewPayload['is_idle_mem']
                                       && $vmNewPayload['is_idle_net_traffic'];

        }

        // determine status switch depending on previous status ...
        $status = $vmNewPayload['status'] ?? null;
        $statusPrev = $prevCachedVmPayload['status'] ?? null;
        if ($status != $statusPrev) {
            Log::info(sprintf("VM '%s' status (flag) changed: '%s' > '%s'", $vm->extern_vm_id, $statusPrev, $status));
            if ($status == ProxtopServerVm::StatusRunning) {
                if (ProxtopServerAction::getScheduledAction($vm->getKey(), ProxtopServerAction::ActionPendingToStart)) {
                    ProxtopServerAction::removeAction($vm->getKey(), ProxtopServerAction::ActionPendingToStart);
                }
                data_set($vmNewPayload, 'status_message', __('Started.'));
            }
            if ($status == ProxtopServerVm::StatusStopped) {
                if (ProxtopServerAction::getScheduledAction($vm->getKey(), ProxtopServerAction::ActionPendingToShutdown)) {
                    ProxtopServerAction::removeAction($vm->getKey(), ProxtopServerAction::ActionPendingToShutdown);
                }
                data_set($vmNewPayload, 'status_message', __('Stopped'));
            }
        }

        //
        $this->setCachedVmProperty($vm, null, $vmNewPayload);

        return $vmNewPayload;
    }

    /**
     * Called every 10 seconds to receive resources by api.
     * 1) Get resources by api
     * 2) Each ObservableItem get their vm payload (cached after first calc)
     *
     *
     * @return bool
     */
    public function receiveResourcesAndBroadcast(): bool
    {
        $this->forgetLegacyCache();

        // receive api data and update cache
        $this->receiveResources();

        // rebuild the whole new payload
        $resourcesTotalPayload = [];

        // prepare the collections outside the loop
        $serverResourcesCollections = [];
        $servers = ProxtopServer::with([])
            ->where('api_allowed', true)
            ->get();
        foreach ($servers as $server) {
            $serverResourcesCollections[$server->getKey()] = collect($this->getServerVms($server->getKey()));
        }

        /** @var Collection<ProxtopServerVm> $vmList */
        $vmList = ProxtopServerVm::query()
            ->whereIsEnabledAndHasActiveServer()
            ->withStandardRelations()
            ->whereIsVm()
            ->get();

        // Fill VM cache
        foreach ($vmList as $vm) {
            if ($vmNewPayload = $this->getCachedPreparedVmData($vm, $serverResourcesCollections, true)) {
                // add vm payload to the big (desktop) payload
                $resourcesTotalPayload[] = $vmNewPayload;
            }
        }

        // 'options.allow_max_launched_vms' is checked live when rendering and starting, see getMaxLaunchedReasons()

        // set resources cache for all servers
        $this->setCache('resources', $resourcesTotalPayload);

        return true;
    }

    /**
     * Reasons why a VM must not be started because of 'options.allow_max_launched_vms' of its enabled templates.
     *
     * A VM of the template counts as soon as its start was requested (actions 'start' and 'pending_to_start'),
     * not only when Proxmox reports it running. So the other VMs of the group are blocked right after the click.
     * Read-only, checked on every render of a stopped VM and again when starting (see VmStatus::requestStart()).
     *
     * @param  ProxtopServerVm  $vm
     *
     * @return array<int, string>  empty = start allowed
     */
    public function getMaxLaunchedReasons(ProxtopServerVm $vm): array
    {
        $reasons = [];
        foreach (
            $vm->enabledPropertyTemplates()
                ->with('vms')
                ->get() as $propertyTemplate
        ) {
            $maxLaunch = (int) data_get($propertyTemplate, 'options.allow_max_launched_vms', -1);
            if ($maxLaunch < 0) {
                continue;
            }

            // VMs of this template being started right now (start requested or start lock until "running")
            $startingVmIds = ProxtopServerAction::query()
                ->whereIn('vm_id', $propertyTemplate->vms->modelKeys())
                ->whereIn('action', [ProxtopServerAction::ActionStart, ProxtopServerAction::ActionPendingToStart])
                ->pluck('vm_id')
                ->all();

            // one cache round trip for all VMs of the template
            $preparedVms = $this->getCachedVmProperties($propertyTemplate->vms);

            $activeVmNames = [];
            /** @var ProxtopServerVm $templateVm */
            foreach ($propertyTemplate->vms as $templateVm) {
                $preparedVm = $preparedVms[$templateVm->getKey()] ?? [];
                if ((($preparedVm['status'] ?? null) === ProxtopServerVm::StatusRunning)
                    || in_array($templateVm->getKey(), $startingVmIds)
                ) {
                    $activeVmNames[] = $preparedVm['name'] ?? $templateVm->getDisplayName();
                }
            }

            if (count($activeVmNames) >= $maxLaunch) {
                $reasons[] = __('Unable to start more than :max VMs of ":template". Running or starting: :vms.', [
                    'max'      => $maxLaunch,
                    'template' => $propertyTemplate->name,
                    'vms'      => implode(', ', $activeVmNames),
                ]);
            }
        }

        return $reasons;
    }

    /**
     * Find payload of a vm in the whole payload.
     *
     * @param  ProxtopServerVm  $vm
     * @param  array            $payloadTotal
     *
     * @return mixed
     */
    public function getVmPayloadData(ProxtopServerVm $vm, array $payloadTotal): mixed
    {
        foreach ($payloadTotal as $value) {
            if (($value['id'] ?? null) == $vm->getKey()) {
                return $value;
            }
        }

        return [];
    }

    /**
     * 1) Receive data by api for each server
     * 2) Save data in cache (one key per server, see getServerVms())
     *
     * @param  callable|null  $callbackDebug
     *
     * @return bool
     */
    public function receiveResources(?callable $callbackDebug = null): bool
    {
        // for every server ...
        $servers = ProxtopServer::with([])
            ->where('api_allowed', true);
        /** @var ProxtopServer $server */
        foreach ($servers->get() as $server) {

            $serverVmsFound = [];

            // get the resources ...
            if ($vmData = $this->send($server, 'cluster/resources')) {
                $objBig = json_decode($vmData, true);

                try {
                    // save/update VMs into db ...
                    foreach ($objBig['data'] ?? [] as $index => $item) {
                        $vm = ProxtopServerVm::updateOrCreate([
                            'server_id'    => $server->getKey(),
                            'extern_vm_id' => $item['vmid'] ?? null,
                            'node'         => $item['node'] ?? null,
                        ], [
                            'vm_type'      => $item['type'] ?? null,
                            'is_enabled'   => true, // properly disabled because vm was deleted
                            'name'         => $item['name'] ?? null,
                            'last_seen_at' => now(), // @todo: move to schedule?
                        ]);

                        $serverVmsFound[] = $vm->getKey();
                    }
                } catch (Exception $e) {
                    Log::error($e->getMessage(), [__METHOD__]);
                }

                // fill/update cache
                $this->setServerVms($server->getKey(), $objBig['data'] ?? []);

                if ($callbackDebug) {
                    $callbackDebug("Resources Results: ".print_r($this->getServerVms($server->getKey()), true));
                }

                // Disable all vms not found (delete later) ...
                ProxtopServerVm::where('server_id', $server->getKey())
                    ->whereNotIn('id', $serverVmsFound)
                    ->update(['is_enabled' => false]);
            }
        }

        return true;
    }

    /**
     * Fetches the RRD history of every running VM for one timeframe and caches its statistics
     * (see calculateRrdStats()). The raw history is not cached.
     *
     * Only works if receiveResources() already filled the cache (list of running VMs).
     * Stopped VMs are skipped, their statistics expire after RrdCacheTtlSeconds.
     *
     * @param  string         $timeframe  key of RrdTimeframes
     * @param  callable|null  $callbackDebug
     *
     * @return bool  false for an unknown timeframe
     */
    public function receiveRrdData(string $timeframe = 'hour', ?callable $callbackDebug = null): bool
    {
        if (!isset(self::RrdTimeframes[$timeframe])) {
            Log::error(sprintf("Unknown RRD timeframe '%s', see %s::RrdTimeframes", $timeframe, self::class));

            return false;
        }

        // for every server ...
        $servers = ProxtopServer::with([])
            ->where('api_allowed', true);
        /** @var ProxtopServer $server */
        foreach ($servers->get() as $server) {

            if ($server = ProxtopServer::with([])
                ->whereId($server->getKey())
                ->where('api_allowed', true)
                ->first()
            ) {

                foreach ($this->getServerVms($server->getKey()) as $index => $item) {

                    // if vm is not running, do not update stats form this vm ...
                    if (($item['status'] ?? null) !== ProxtopServerVm::StatusRunning) {
                        continue;
                    }

                    if ($vm = ProxtopServerVm::where([
                        'vm_type'      => $item['type'] ?? null,
                        'server_id'    => $server->getKey(),
                        'extern_vm_id' => $item['vmid'] ?? null,
                        'node'         => $item['node'] ?? null,
                    ])
                        ->first()
                    ) {
                        // fetch new rrd data ...
                        $rrdData = $this->send($server, "nodes/{$vm->node}/{$vm->vm_type}/{$vm->extern_vm_id}/rrddata", queryParams: ['timeframe' => $timeframe]);

                        // cache only the statistics, not the raw history
                        if ($rrdDataJson = json_decode($rrdData, true)) {
                            $rrdDataJson = $rrdDataJson['data'] ?? [];

                            $this->setVmRrdStats($vm, $timeframe, $this->calculateRrdStats($timeframe, $rrdDataJson));

                            if ($callbackDebug) {
                                $callbackDebug(sprintf("Fetched '%s' RRD from VM '%s' (%s entries, %s bytes)", $timeframe, $vm->getKey(), count($rrdDataJson), strlen($rrdData)));
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  ProxtopServerVm  $vm
     *
     * @return void
     */
    public function sendStartVm(ProxtopServerVm $vm): void
    {
        Log::info(sprintf("Starting VM '%s' on '%s' ...", $vm->extern_vm_id, $vm->server->host));
        $this->send($vm->server, "nodes/{$vm->node}/{$vm->vm_type}/{$vm->extern_vm_id}/status/start", method: 'POST');
    }

    /**
     * @param  ProxtopServerVm  $vm
     *
     * @return void
     */
    public function sendShutdownVm(ProxtopServerVm $vm): void
    {
        Log::info(sprintf("Shutdown VM '%s' on '%s' ...", $vm->extern_vm_id, $vm->server->host));
        $this->send($vm->server, "nodes/{$vm->node}/{$vm->vm_type}/{$vm->extern_vm_id}/status/shutdown", method: 'POST', formParams: [
            'forceStop' => true,
            'timeout'   => 20,
        ]);
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  string           $command
     * @param  array            $args
     *
     * @return string
     */
    protected function sendVmExec(ProxtopServerVm $vm, string $command, array $args = []): string
    {
        $commandList = array_merge([$command], $args);

        return $this->send($vm->server,
            "nodes/{$vm->node}/{$vm->vm_type}/{$vm->extern_vm_id}/agent/exec",
            [],
            'POST',
            ['command' => $commandList] // Das wird zu command=ls&command=-la
        );
    }

    protected function sendVmExecSafe(ProxtopServerVm $vm, string $command): string
    {
        // Wir nehmen den kompletten Befehl (z.B. 'echo "Hällo Wörld"')
        $base64Command = base64_encode($command);

        // Wir senden NUR einen Befehl an den Agenten:
        // sh -c "echo <BASE64> | base64 -d | sh"
        // Das ist sicher, weil im HTTP-Request nur noch A-Z, 0-9 vorkommen.
        $wrapper = "echo '{$base64Command}' | base64 -d | sh";

        return $this->sendVmExec($vm, 'sh', ['-c', $wrapper]);
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  int              $pid
     *
     * @return array
     */
    protected function getVmExecStatus(ProxtopServerVm $vm, int $pid): array
    {
        $responseString = $this->send($vm->server,
            "nodes/{$vm->node}/{$vm->vm_type}/{$vm->extern_vm_id}/agent/exec-status",
            [],
            'GET',
            [], ['pid' => $pid] // Als Query-Parameter
        );

        $data = json_decode($responseString, true)['data'] ?? [];

        return [
            'exited'   => (int) $data['exited'] ?? 0,
            'exitcode' => (int) $data['exitcode'] ?? -1,
            'stdout'   => $data['out-data'] ?? '',
            'stderr'   => $data['err-data'] ?? '',
        ];
    }

    /**
     * $args will always surround with ''!
     * Avoid use $args if you able add it to the $command!
     * Only use it for dynamic user params.
     *
     * @param  ProxtopServerVm  $vm
     * @param  string           $command
     * @param  array            $args
     * @param  int              $timeout
     *
     * @return string
     */
    public function runCommandInVmAndWait(ProxtopServerVm $vm, string $command, array $args = [], int $timeout = 5): string
    {
        if ($args) {
            $safeArgs = array_map('escapeshellarg', $args);
            $command .= ' '.implode(' ', $safeArgs);
        }
        Log::info(sprintf("Sending command to vm %s ...", $vm->extern_vm_id), ['command' => $command]);
        $execRaw = $this->sendVmExecSafe($vm, $command);


        $execData = json_decode($execRaw, true);
        $pid = $execData['data']['pid'] ?? null;

        if (!$pid) {
            return "Fehler: PID nicht erhalten. API Response: ".$execRaw;
        }

        for ($i = 0; $i < $timeout; $i++) {
            $status = $this->getVmExecStatus($vm, $pid);

            if (empty($status)) {
                return "Error: No vm status available.";
            }

            if ($status['exited'] == 1) {
                return $status['stdout'].$status['stderr'];
            }

            usleep(500000); // wait 0,5 sec
        }

        return "Timeout: Command not ready after {$timeout}s.";
    }

    /**
     * Small values of this service with an own cache key each, like 'resources'.
     * The name is part of the key, it is not a path into an array.
     *
     * @param  string  $name
     * @param  mixed   $default
     *
     * @return mixed
     */
    public function getCache(string $name, mixed $default = null): mixed
    {
        return Cache::get($this->buildCacheKey($name)) ?? $default;
    }

    /**
     * @param  string  $name
     * @param  mixed   $value
     *
     * @return void
     */
    public function setCache(string $name, mixed $value): void
    {
        Cache::forever($this->buildCacheKey($name), $value);
    }

    /**
     * @param  ProxtopServerVm|int  $vm
     *
     * @return string
     */
    protected function getCacheKeyForPreparedVm(ProxtopServerVm|int $vm): string
    {
        return $this->buildCacheKey('vm', $this->vmKey($vm), 'prepared');
    }

    /**
     * Prepared payload of a VM (see getCachedPreparedVmData()).
     *
     * @param  ProxtopServerVm|int  $vm
     * @param  string|null          $path  null = whole payload, otherwise a dot path inside it
     * @param  mixed                $default
     *
     * @return mixed
     */
    public function getCachedVmProperty(ProxtopServerVm|int $vm, ?string $path = null, mixed $default = null): mixed
    {
        $payload = Cache::get($this->getCacheKeyForPreparedVm($vm));

        if ($path === null) {
            return $payload ?? $default;
        }

        return data_get($payload ?? [], $path) ?? $default;
    }

    /**
     * Prepared payloads of many VMs with one cache round trip.
     *
     * @param  iterable<ProxtopServerVm|int>  $vms
     *
     * @return array<int, array>  vm id => payload ([] if not cached)
     */
    public function getCachedVmProperties(iterable $vms): array
    {
        $keys = [];
        foreach ($vms as $vm) {
            $keys[$this->vmKey($vm)] = $this->getCacheKeyForPreparedVm($vm);
        }
        if (!$keys) {
            return [];
        }

        $values = Cache::many(array_values($keys));

        $result = [];
        foreach ($keys as $vmId => $key) {
            $result[$vmId] = $values[$key] ?? [];
        }

        return $result;
    }

    /**
     * @param  ProxtopServerVm|int  $vm
     * @param  string|null          $path  null = replace the whole payload, otherwise a dot path inside it
     * @param  mixed                $value
     *
     * @return void
     */
    public function setCachedVmProperty(ProxtopServerVm|int $vm, ?string $path = null, mixed $value = null): void
    {
        $key = $this->getCacheKeyForPreparedVm($vm);

        if ($path !== null) {
            $payload = Cache::get($key) ?? [];
            data_set($payload, $path, $value);
            $value = $payload;
        }

        Cache::forever($key, $value);
    }
}
