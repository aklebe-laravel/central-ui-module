<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Power;

use Illuminate\Support\Facades\Log;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\CentralUi\app\Services\ProxmoxService;

/**
 * Driver 'proxmox' (default): shuts down the Proxmox nodes by the API of the servers, like "Shutdown" of a node in
 * the Proxmox web interface (POST nodes/{node}/status command=shutdown). Proxmox stops the guests first (their
 * shutdown order), there is no grace period.
 *
 * Nodes: all online nodes of the servers with API access (cluster/resources, cached by the resource job),
 * or only those of config 'central-ui.power.nodes'. The API token needs 'Sys.PowerMgmt' on /nodes/<node>.
 */
class ProxmoxNodeShutdown implements ServerPowerDriver
{
    /**
     * @param  ProxmoxService  $proxmox
     */
    public function __construct(protected ProxmoxService $proxmox)
    {
    }

    /**
     * @return bool
     */
    public function isAvailable(): bool
    {
        return (bool) $this->nodes();
    }

    /**
     * @param  bool  $backupRisk
     *
     * @return string
     */
    public function messageBox(bool $backupRisk): string
    {
        return $backupRisk ? 'central-ui-server.desktop.shutdown-proxmox-backup' : 'central-ui-server.desktop.shutdown-proxmox';
    }

    /**
     * @param  string  $requestedBy
     * @param  bool    $force
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function requestShutdown(string $requestedBy, bool $force = false): array
    {
        $nodes = $this->nodes();
        if (!$nodes) {
            return ['ok' => false, 'shutdown_at' => null, 'message' => __('No Proxmox node found to shut down.')];
        }

        $done = [];
        $failed = [];
        foreach ($nodes as ['server' => $server, 'node' => $node]) {
            // answers with the task id at once, the node shuts down afterwards (so all nodes get the request)
            $answer = $this->proxmox->send($server, 'nodes/'.rawurlencode($node).'/status', method: 'POST', formParams: ['command' => 'shutdown']);
            if ($answer === '') {
                $failed[] = $node;
            } else {
                $done[] = $node;
            }
        }

        Log::warning(sprintf("Server shutdown requested by '%s' (proxmox, force: %d): %s%s",
            $requestedBy,
            $force,
            implode(', ', $done) ?: '-',
            $failed ? ', failed: '.implode(', ', $failed) : ''));

        if (!$done) {
            return ['ok' => false, 'shutdown_at' => null, 'message' => __('Proxmox refused the shutdown of :nodes (the API token needs Sys.PowerMgmt).', ['nodes' => implode(', ', $failed)])];
        }

        return [
            'ok'          => true,
            'shutdown_at' => null,
            'message'     => __('Shutdown of :nodes started.', ['nodes' => implode(', ', $done)]).($failed ? ' '.__('Not shut down: :nodes.', ['nodes' => implode(', ', $failed)]) : ''),
        ];
    }

    /**
     * Online nodes of the servers with API access.
     *
     * @return list<array{server: ProxtopServer, node: string}>
     */
    protected function nodes(): array
    {
        $only = (array) config('central-ui.power.nodes', []);
        $result = [];
        foreach (
            ProxtopServer::query()
                ->where('api_allowed', true)
                ->get() as $server
        ) {
            foreach (self::onlineNodes($this->proxmox->getServerVms((int) $server->getKey()), $only) as $node) {
                $result[] = ['server' => $server, 'node' => $node];
            }
        }

        return $result;
    }

    /**
     * Names of the online nodes in cluster/resources, optionally only those of $only.
     *
     * @param  array         $resources  cluster/resources of a server
     * @param  list<string>  $only       empty = all
     *
     * @return list<string>
     */
    public static function onlineNodes(array $resources, array $only = []): array
    {
        $nodes = [];
        foreach ($resources as $resource) {
            if (($resource['type'] ?? null) !== 'node' || ($resource['status'] ?? null) !== 'online' || empty($resource['node'])) {
                continue;
            }
            if ($only && !in_array($resource['node'], $only, true)) {
                continue;
            }
            $nodes[] = (string) $resource['node'];
        }

        return array_values(array_unique($nodes));
    }
}
