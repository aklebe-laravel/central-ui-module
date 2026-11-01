<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

use Exception;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Models\ProxtopServerAction;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\CacheService;

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
     * @var string
     */
    protected string $cacheKey = 'proxtop_api_cache';

    /**
     *
     */
    const string CacheKeyProxmoxServerApi = 'proxmox_server_api';

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
     * Phase 1: 10 minutes
     *
     * @var int
     */
    protected int $avgPhase1InMin = 10;

    /**
     * Phase 2: 1 Hour
     *
     * @var int
     */
    protected int $avgPhase2InMin = 60;

    /**
     * Phase 3: 1 Day
     *
     * @var int
     */
    protected int $avgPhase3InMin;

    /**
     * @var CacheService|Application|mixed|object
     */
    protected CacheService $cacheService;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->cacheService = app(CacheService::class);
    }

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
     * Get cache by server id and extra path
     *
     * @param  int         $serverId   server id
     * @param  string      $arrayPath  path in cache like "vm.100.name"
     * @param  mixed|null  $default    default value if cache does not exists
     *
     * @return mixed
     */
    public function getServerApiCache(int $serverId, string $arrayPath, mixed $default = null): mixed
    {
        return $this->cacheService->getCacheByPath(self::CacheKeyProxmoxServerApi, $serverId.'.'.$arrayPath, $default);
    }

    /**
     * Set cache by server id and extra path
     *
     * @param  int     $serverId   server id
     * @param  string  $arrayPath  path in cache like "vm.100.name"
     * @param  mixed   $data       value(s) to set
     *
     * @return void
     */
    public function setServerApiCache(int $serverId, string $arrayPath, mixed $data): void
    {
        $this->cacheService->setCacheByPath(self::CacheKeyProxmoxServerApi, $serverId.'.'.$arrayPath, $data);
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
     * @param  array            $rrdDataPerDay
     *
     * @return array
     */
    public function prepareCpuStats(array $vmPayload, ProxtopServerVm $vm, array $rrdDataPerDay): array
    {
        $cpuPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'cpu', $this->avgPhase1InMin) * 100);
        $cpuPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'cpu', $this->avgPhase2InMin) * 100);
        $cpuPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'cpu', $this->avgPhase3InMin) * 100);

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
     * @param  array            $rrdDataPerDay
     *
     * @return array
     */
    public function prepareMemStats(array $vmPayload, ProxtopServerVm $vm, array $rrdDataPerDay): array
    {
        $memMax = $vmPayload['maxmem'] ?? 0;
        $memUsed = $vmPayload['mem'] ?? 0;
        $memUsage = ($memMax > 0) ? round((((float) $memUsed) / (float) $memMax) * 100.0) : 0;
        $memUsedPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'mem', $this->avgPhase1InMin, 0));
        $memUsedPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'mem', $this->avgPhase2InMin, 0));
        $memUsedPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'mem', $this->avgPhase3InMin, 0));

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
     * @param  array            $rrdDataPerDay
     *
     * @return array
     */
    public function prepareNetStats(array $vmPayload, ProxtopServerVm $vm, array $rrdDataPerDay): array
    {
        $netTrafficLimit = 20 * 1024 * 1204; // @todo: read max traffic of system
        $countVms = ProxtopServerVm::count();
        if ($countVms) {
            $netTrafficLimit /= $countVms;
        }
        // use vm template traffic if configured
        $netTrafficLimit = $vm->getTemplatesLowestNumber('net_traffic_limit', $netTrafficLimit);

        // idle traffic
        $netTrafficIdle = $vm->getTemplatesHighestNumber('net_traffic_idle', 1024);

        $netIn = $vmPayload['netin'] ?? 0.0;
        $netInAvgPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'netin', $this->avgPhase1InMin));
        $netInAvgPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'netin', $this->avgPhase2InMin));
        $netInAvgPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'netin', $this->avgPhase3InMin));
        $netInMaxPhase3 = round($this->getMaxRrdData($rrdDataPerDay, 'netin', $this->avgPhase3InMin));
        $netOut = $vmPayload['netout'] ?? 0.0;
        $netOutAvgPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'netout', $this->avgPhase1InMin));
        $netOutAvgPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'netout', $this->avgPhase2InMin));
        $netOutAvgPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'netout', $this->avgPhase3InMin));
        $netOutMaxPhase3 = round($this->getMaxRrdData($rrdDataPerDay, 'netout', $this->avgPhase3InMin));
        $netTraffic = $this->calcTraffic($netIn, $netOut);
        $netTrafficAvgPhase1 = $this->calcTraffic($netInAvgPhase1, $netOutAvgPhase1);
        $netTrafficAvgPhase2 = $this->calcTraffic($netInAvgPhase2, $netOutAvgPhase2);
        $netTrafficAvgPhase3 = $this->calcTraffic($netInAvgPhase3, $netOutAvgPhase3);
        $netTrafficMaxPhase3 = $this->calcTraffic($netInMaxPhase3, $netOutMaxPhase3);
        // use max traffic/day if limit is configured too high
        if ($netTrafficLimit > $netTrafficMaxPhase3) {
            $netTrafficLimit = $netTrafficMaxPhase3;
        }
        $netTrafficAvgPhase1Percent = $this->calcTrafficPercent($netTrafficAvgPhase1, $netTrafficLimit);
        $netTrafficAvgPhase2Percent = $this->calcTrafficPercent($netTrafficAvgPhase2, $netTrafficLimit);
        $netTrafficAvgPhase3Percent = $this->calcTrafficPercent($netTrafficAvgPhase3, $netTrafficLimit);

        $netTrafficIdlePercent = 10;
        $netTrafficLimitPercent = 90;

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
            'net_traffic_avg_phase1_percent' => $netTrafficAvgPhase1Percent,
            'net_traffic_avg_phase2_percent' => $netTrafficAvgPhase2Percent,
            'net_traffic_avg_phase3_percent' => $netTrafficAvgPhase3Percent,

            'net_traffic_idle'  => $netTrafficIdlePercent,
            'net_traffic_limit' => $netTrafficLimitPercent,

        ];
    }

    /**
     * @param  array            $vmPayload
     * @param  ProxtopServerVm  $vm
     * @param  array            $rrdDataPerDay
     *
     * @return array
     */
    public function prepareDiskStats(array $vmPayload, ProxtopServerVm $vm, array $rrdDataPerDay): array
    {
        $disk = (float) ($vmPayload['disk'] ?? 0.0);
        $maxDisk = (float) ($vmPayload['maxdisk'] ?? 0.0);
        $diskPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'disk', $this->avgPhase1InMin));
        $diskPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'disk', $this->avgPhase2InMin));
        $diskPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'disk', $this->avgPhase3InMin));
        $diskRead = $vmPayload['diskread'] ?? 0.0;
        $diskReadAvgPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'diskread', $this->avgPhase1InMin));
        $diskReadAvgPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'diskread', $this->avgPhase2InMin));
        $diskReadAvgPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'diskread', $this->avgPhase3InMin));
        $diskReadMaxPhase3 = round($this->getMaxRrdData($rrdDataPerDay, 'diskread', $this->avgPhase3InMin));
        $diskWrite = $vmPayload['diskwrite'] ?? 0.0;
        $diskWriteAvgPhase1 = round($this->getAvgRrdData($rrdDataPerDay, 'diskwrite', $this->avgPhase1InMin));
        $diskWriteAvgPhase2 = round($this->getAvgRrdData($rrdDataPerDay, 'diskwrite', $this->avgPhase2InMin));
        $diskWriteAvgPhase3 = round($this->getAvgRrdData($rrdDataPerDay, 'diskwrite', $this->avgPhase3InMin));
        $diskWriteMaxPhase3 = round($this->getMaxRrdData($rrdDataPerDay, 'diskwrite', $this->avgPhase3InMin));
        $diskTraffic = $this->calcTraffic($diskRead, $diskWrite);
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
        $diskTrafficAvgPhase1Percent = $this->calcTrafficPercent($diskTrafficAvgPhase1, $diskTrafficLimit);
        $diskTrafficAvgPhase2Percent = $this->calcTrafficPercent($diskTrafficAvgPhase1, $diskTrafficLimit);
        $diskTrafficAvgPhase3Percent = $this->calcTrafficPercent($diskTrafficAvgPhase1, $diskTrafficLimit);

        $diskTrafficIdlePercent = 10;
        $diskTrafficLimitPercent = 90;

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
            'disk_traffic_avg_phase1_percent' => $diskTrafficAvgPhase1Percent,
            'disk_traffic_avg_phase2_percent' => $diskTrafficAvgPhase2Percent,
            'disk_traffic_avg_phase3_percent' => $diskTrafficAvgPhase3Percent,

            'disk_traffic_idle'  => $diskTrafficIdlePercent,
            'disk_traffic_limit' => $diskTrafficLimitPercent,
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
        if (!$forceRefill && ($c = $this->getCachedVmProperty($vm, null, []))) {
            return $c;
        }

        if (!($server = $vm->server)) {
            Log::error('Invalid server relation!');

            return [];
        }

        // get old/legacy cache data for all servers ...
        $prevCachedResourcesTotalPayload = $this->getCache('resources', []);
        // ... to get the previous cached vm data
        $prevCachedVmPayload = $this->getVmPayloadData($vm, $prevCachedResourcesTotalPayload);

        /** @var Collection $serverResources */
        $serverResources = $serverResourcesCollections[$server->getKey()];
        /** @var array $vmPayload */
        $vmPayload = $serverResources->where('vmid', $vm->extern_vm_id)
                         ->where('type', $vm->vm_type)
                         ->first() ?? [];

        // just get day once, 1440 entries for all minutes are involved
        $rrdDataPerDay = $this->getServerApiCache($server->getKey(), "rrd_data.day.{$vm->getKey()}", []);
        $this->avgPhase3InMin = count($rrdDataPerDay); // last day

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
            'avg_phase1'   => $this->avgPhase1InMin,
            'avg_phase2'   => $this->avgPhase2InMin,
            'avg_phase3'   => $this->avgPhase3InMin,
            'schedules'    => $vm->getTemplatesNextScheduleWindows(),
        ];

        // if hash was same, avoid calculate extra data
        // i don't trust myself to check ($hasBaseDataChanged) xD
        // @todo: make it experimental in settings?
        if (true) {
            // preparing all kind of stats
            $vmNewPayload += $this->prepareCpuStats($vmPayload, $vm, $rrdDataPerDay);
            $vmNewPayload += $this->prepareMemStats($vmPayload, $vm, $rrdDataPerDay);
            $vmNewPayload += $this->prepareNetStats($vmPayload, $vm, $rrdDataPerDay);
            $vmNewPayload += $this->prepareDiskStats($vmPayload, $vm, $rrdDataPerDay);

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
        // receive api data and update cache
        $this->receiveResources();

        // rebuild the whole new payload
        $resourcesTotalPayload = [];
        // renew all prepared vm payloads ...
        //$this->forgetCache('prepared_vms');

        // prepare the collections outside the loop
        $serverResourcesCollections = [];
        $servers = ProxtopServer::with([])
            ->where('api_allowed', true)
            ->get();
        foreach ($servers as $server) {
            $serverResourcesCollections[$server->getKey()] = collect($this->getServerApiCache($server->getKey(), 'vms', []));
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

            $activeVmNames = [];
            /** @var ProxtopServerVm $templateVm */
            foreach ($propertyTemplate->vms as $templateVm) {
                $preparedVm = $this->getCachedVmProperty($templateVm, null, []);
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
     * 2) Save data in cache (key self::CacheKeyProxmoxServerApi, indexed by [serverId])
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
                $this->setServerApiCache($server->getKey(), 'vms', $objBig['data'] ?? []);

                if ($callbackDebug) {
                    $callbackDebug("Resources Results: ".print_r($this->getServerApiCache($server->getKey(), 'vms'), true));
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
     * Will only receive data if the cache by receiveResources() was already filled.
     * Also, vm must be running. If so, cache is updated with 'rrd_data' for related vm.
     * Otherwise, nothing happens or if vm is down rrd data wíll be removed.
     *
     * @param  string         $timeframe
     * @param  callable|null  $callbackDebug
     *
     * @return bool
     */
    public function receiveRrdData(string $timeframe = 'hour', ?callable $callbackDebug = null): bool
    {
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

                foreach ($this->getServerApiCache($server->getKey(), 'vms', []) as $index => $item) {

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

                        // assign them to the cache
                        if ($rrdDataJson = json_decode($rrdData, true)) {
                            $rrdDataJson = $rrdDataJson['data'] ?? [];

                            // update specific cache object
                            $this->setServerApiCache($server->getKey(), "rrd_data.{$timeframe}.{$vm->getKey()}", $rrdDataJson);

                            if ($callbackDebug) {
                                $callbackDebug(sprintf("Fetched '%s' RRD from VM '%s' (%s entries)", $timeframe, $vm->getKey(), count($rrdDataJson)));
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  array       $rrdData
     * @param  string      $property
     * @param  int         $lastItemsCount
     * @param  mixed|null  $default
     *
     * @return mixed
     */
    public function getAvgRrdData(array $rrdData, string $property, int $lastItemsCount = 15, mixed $default = null): mixed
    {
        $recentData = array_slice($rrdData, -$lastItemsCount);

        return (float) collect($recentData)->avg($property) ?? $default;
    }

    /**
     * @param  array       $rrdData
     * @param  string      $property
     * @param  int         $lastItemsCount
     * @param  mixed|null  $default
     *
     * @return mixed
     */
    public function getMinRrdData(array $rrdData, string $property, int $lastItemsCount = 15, mixed $default = null): mixed
    {
        $recentData = array_slice($rrdData, -$lastItemsCount);

        return (float) collect($recentData)->min($property) ?? $default;
    }

    /**
     * @param  array       $rrdData
     * @param  string      $property
     * @param  int         $lastItemsCount
     * @param  mixed|null  $default
     *
     * @return mixed
     */
    public function getMaxRrdData(array $rrdData, string $property, int $lastItemsCount = 15, mixed $default = null): mixed
    {
        $recentData = array_slice($rrdData, -$lastItemsCount);

        return (float) collect($recentData)->max($property) ?? $default;
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
     * @param  string  $path
     * @param  mixed   $default
     *
     * @return mixed
     */
    public function getCache(string $path, mixed $default = null): mixed
    {
        return $this->cacheService->getCacheByPath($this->cacheKey, $path, $default);
    }

    /**
     * @param  string  $path
     * @param  mixed   $value
     *
     * @return void
     */
    public function setCache(string $path, mixed $value): void
    {
        $this->cacheService->setCacheByPath($this->cacheKey, $path, $value);
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  string|null      $path
     *
     * @return string
     */
    private function getCacheKeyForVmProperty(ProxtopServerVm $vm, ?string $path = null): string
    {
        $key = 'prepared_vms.'.$vm->getKey();
        // if not path get whole object, otherwise specific ...
        if ($path) {
            $key .= '.'.$path;
        }

        return $key;
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  string|null      $path
     * @param  mixed            $default
     *
     * @return mixed
     */
    public function getCachedVmProperty(ProxtopServerVm $vm, ?string $path = null, mixed $default = null): mixed
    {
        return $this->getServerApiCache($vm->server_id, $this->getCacheKeyForVmProperty($vm, $path), $default);
    }

    /**
     * @param  ProxtopServerVm  $vm
     * @param  string|null      $path
     * @param  mixed            $value
     *
     * @return void
     */
    public function setCachedVmProperty(ProxtopServerVm $vm, ?string $path = null, mixed $value = null): void
    {
        $this->setServerApiCache($vm->server_id, $this->getCacheKeyForVmProperty($vm, $path), $value);
    }
}