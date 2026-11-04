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
 * Contract (config 'central-ui.power.url' ending with "/shutdown", '.token'), always Authorization: Bearer <token>:
 *   POST .../shutdown  {"action": "shutdown", "source": "proxtop", "requested_by": "<user name>", "force": false}
 *   GET  .../status    -> {"ok": true, "scheduled": bool, "shutdown_at": <UNIX time or null>}
 *   POST .../extend    {"minutes": 30, "requested_by": "<user name>"}
 *   POST .../cancel    {"requested_by": "<user name>"}
 *   2xx: {"ok": true, "shutdown_at": <UNIX time or null>, "message": "<optional>"}
 *   other status or "ok": false: refused (the body may contain "message")
 */
class HttpShutdown implements ServerPowerDriver, ServerPowerSchedule
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

        $result = $this->call('POST', 'shutdown', [
            'action'       => 'shutdown',
            'source'       => 'proxtop',
            'requested_by' => $requestedBy,
            'force'        => $force,
        ]);
        if ($result['ok']) {
            Log::warning(sprintf("Server shutdown requested by '%s' (http, force: %d)", $requestedBy, $force));
        }

        return $result;
    }

    /**
     * GET <base>/status
     *
     * @return array{ok: bool, shutdown_at: ?int}
     */
    public function scheduledShutdown(): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'shutdown_at' => null];
        }
        $result = $this->call('GET', 'status', [], false);

        return ['ok' => $result['ok'], 'shutdown_at' => $result['shutdown_at']];
    }

    /**
     * POST <base>/extend
     *
     * @param  int     $minutes
     * @param  string  $requestedBy
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function extendShutdown(int $minutes, string $requestedBy): array
    {
        return $this->call('POST', 'extend', ['minutes' => $minutes, 'requested_by' => $requestedBy, 'source' => 'proxtop']);
    }

    /**
     * POST <base>/cancel
     *
     * @param  string  $requestedBy
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    public function cancelShutdown(string $requestedBy): array
    {
        return $this->call('POST', 'cancel', ['requested_by' => $requestedBy, 'source' => 'proxtop']);
    }

    /**
     * URL of an action: the configured url ends with "/shutdown", the other actions are its siblings
     * ("https://power.example/shutdown" -> "https://power.example/status"). Without "/shutdown" at the end
     * the action is appended.
     *
     * @param  string  $shutdownUrl
     * @param  string  $action  shutdown, status, extend, cancel
     *
     * @return string
     */
    public static function endpoint(string $shutdownUrl, string $action): string
    {
        $base = (string) preg_replace('#/shutdown/?$#', '', rtrim($shutdownUrl));

        return rtrim($base, '/').'/'.$action;
    }

    /**
     * @param  string  $method
     * @param  string  $action
     * @param  array   $payload
     * @param  bool    $logErrors  false for the status (asked every few seconds)
     *
     * @return array{ok: bool, shutdown_at: ?int, message: string}
     */
    protected function call(string $method, string $action, array $payload = [], bool $logErrors = true): array
    {
        $url = self::endpoint((string) config('central-ui.power.url'), $action);
        try {
            $request = Http::withToken((string) config('central-ui.power.token', ''))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('central-ui.power.timeout', 10))
                ->connectTimeout(3)
                ->withOptions(['verify' => (bool) config('central-ui.power.verify_tls', true)]);
            $response = ($method === 'GET') ? $request->get($url) : $request->post($url, $payload);
        } catch (Throwable $exception) {
            if ($logErrors) {
                Log::error('Server power service not reachable: '.$exception->getMessage(), [__METHOD__]);
            }

            return ['ok' => false, 'shutdown_at' => null, 'message' => __('The server power service is not reachable (:url).', ['url' => $url])];
        }

        $json = (array) ($response->json() ?? []);
        if ($response->failed() || (($json['ok'] ?? true) === false)) {
            if ($logErrors) {
                Log::error(sprintf('Server power service refused %s %s (%s)', $method, $action, $response->status()), ['body' => $response->body()]);
            }

            return [
                'ok'          => false,
                'shutdown_at' => null,
                'message'     => (string) (($json['message'] ?? '') ?: __('The server power service refused the request (:status).', ['status' => $response->status()])),
            ];
        }

        return [
            'ok'          => true,
            'shutdown_at' => isset($json['shutdown_at']) ? (int) $json['shutdown_at'] : null,
            'message'     => (string) ($json['message'] ?? ''),
        ];
    }
}
