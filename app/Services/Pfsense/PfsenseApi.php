<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Pfsense;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\SystemBase\app\Services\Base\BaseService;
use RuntimeException;

/**
 * Thin client for the pfSense REST API v2 (package pfSense-pkg-RESTAPI).
 *
 * The "id" of a peer is its list index in the pfSense config and shifts after every delete.
 * Never cache IDs: always read them fresh via peers() right before a change.
 *
 * Reinstalling the package: see the module README (pkg-static add ... and check the ABI in /usr/local/etc/pkg.conf).
 */
final class PfsenseApi extends BaseService
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $apiKey,
        private readonly bool $verifyTls = true,
    ) {
        parent::__construct();
    }

    public static function fromConfig(): self
    {
        return new self(config('central-ui.pfsense.url'), config('central-ui.pfsense.api_key'), (bool) config('central-ui.pfsense.verify_tls', true),);
    }

    /** @return list<array<string, mixed>> */
    public function peers(): array
    {
        return $this->call('GET', 'vpn/wireguard/peers') ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function tunnels(): array
    {
        return $this->call('GET', 'vpn/wireguard/tunnels') ?? [];
    }

    public function tunnelPublicKey(string $tunnel): string
    {
        foreach ($this->tunnels() as $candidate) {
            if (($candidate['name'] ?? null) === $tunnel) {
                return $candidate['publickey'];
            }
        }

        throw new RuntimeException("Tunnel {$tunnel} not found on the pfSense.");
    }

    public function createPeer(string $tunnel, string $description, string $publicKey, string $ip): void
    {
        $this->call('POST', 'vpn/wireguard/peer', [
            'enabled'             => true,
            'tun'                 => $tunnel,
            'descr'               => $description,
            'publickey'           => $publicKey,
            'persistentkeepalive' => 25,
            'allowedips'          => [['address' => $ip, 'mask' => 32, 'descr' => $description]],
        ]);
    }

    public function updatePeerKey(int $id, string $publicKey): void
    {
        $this->call('PATCH', 'vpn/wireguard/peer', ['id' => $id, 'publickey' => $publicKey]);
    }

    public function deletePeer(int $id): void
    {
        // Tested with REST API v2.10.2: the id must be sent in the body.
        // As query parameter (?id=) the API answers with HTTP 500.
        $this->call('DELETE', 'vpn/wireguard/peer', ['id' => $id]);
    }

    public function applyChanges(): void
    {
        $this->call('POST', 'vpn/wireguard/apply');
    }

    /** Version of the installed REST API package, or null if the API does not report it. */
    public function restApiVersion(): ?string
    {
        $data = $this->call('GET', 'system/restapi/version');

        return is_array($data) ? ($data['current_version'] ?? null) : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->baseUrl, '/').'/api/v2/')
            ->withHeaders(['X-API-Key' => (string) $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->withOptions(['verify' => $this->verifyTls]);
    }

    private function call(string $method, string $path, array $data = []): mixed
    {
        if (!$this->baseUrl || !$this->apiKey) {
            throw new RuntimeException('PFSENSE_URL and PFSENSE_API_KEY must be set in the .env file.');
        }

        $client = $this->client();

        $response = match ($method) {
            'GET' => $client->get($path),
            'POST' => $client->post($path, $data),
            'PATCH' => $client->patch($path, $data),
            'DELETE' => $client->delete($path, $data),
        };

        // Never log request bodies or the API key.
        $this->debug('pfSense API request', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->body();

            $this->error('pfSense API request failed', [
                'method'  => $method,
                'path'    => $path,
                'status'  => $response->status(),
                'message' => $message,
            ]);

            throw new RuntimeException(sprintf('pfSense API error on %s %s (HTTP %d): %s', $method, $path, $response->status(), $message));
        }

        return $response->json('data');
    }
}
