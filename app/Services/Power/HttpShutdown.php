<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Power;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Driver 'http': Proxtop does not shut down itself, it asks a power service of the host, which decides how
 * (e.g. pve-power: the same grace period with notifications as the power button).
 *
 * Contract (config 'central-ui.power.url', '.token'):
 *   POST <url>   Authorization: Bearer <token>   Content-Type: application/json
 *   {"action": "shutdown", "source": "proxtop", "requested_by": "<user name>", "force": false}
 *   2xx: {"ok": true, "shutdown_at": <UNIX time or null>, "message": "<optional>"}
 *   other status: refused (the body may contain "message")
 */
class HttpShutdown implements ServerPowerDriver
{
    /**
     * @return bool
     */
    public function isAvailable(): bool
    {
        return (string) config('central-ui.power.url', '') !== '';
    }

    /**
     * @param  bool  $backupRisk
     *
     * @return string
     */
    public function messageBox(bool $backupRisk): string
    {
        return $backupRisk ? 'central-ui-server.desktop.shutdown-http-backup' : 'central-ui-server.desktop.shutdown-http';
    }

    /**
     * @param  string  $requestedBy
     * @param  bool    $force
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function requestShutdown(string $requestedBy, bool $force = false): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'shutdown_at' => null, 'message' => __('The server power service is not configured.')];
        }

        $url = (string) config('central-ui.power.url');
        try {
            $response = Http::withToken((string) config('central-ui.power.token', ''))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('central-ui.power.timeout', 10))
                ->connectTimeout(3)
                ->withOptions(['verify' => (bool) config('central-ui.power.verify_tls', true)])
                ->post($url, [
                    'action'       => 'shutdown',
                    'source'       => 'proxtop',
                    'requested_by' => $requestedBy,
                    'force'        => $force,
                ]);
        } catch (Throwable $exception) {
            Log::error('Server power service not reachable: '.$exception->getMessage(), [__METHOD__]);

            return ['ok' => false, 'shutdown_at' => null, 'message' => __('The server power service is not reachable (:url).', ['url' => $url])];
        }

        $json = (array) ($response->json() ?? []);
        if ($response->failed() || (($json['ok'] ?? true) === false)) {
            Log::error(sprintf('Server power service refused the shutdown (%s)', $response->status()), ['body' => $response->body()]);

            return [
                'ok'          => false,
                'shutdown_at' => null,
                'message'     => (string) (($json['message'] ?? '') ?: __('The server power service refused the shutdown (:status).', ['status' => $response->status()])),
            ];
        }

        Log::warning(sprintf("Server shutdown requested by '%s' (http, force: %d)", $requestedBy, $force));

        return [
            'ok'          => true,
            'shutdown_at' => isset($json['shutdown_at']) ? (int) $json['shutdown_at'] : null,
            'message'     => (string) ($json['message'] ?? ''),
        ];
    }
}
