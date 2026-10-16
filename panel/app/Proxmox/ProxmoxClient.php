<?php

declare(strict_types=1);

namespace App\Proxmox;

use App\Proxmox\Exceptions\ProxmoxAuthenticationFailed;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\Exceptions\ProxmoxTlsVerificationFailed;
use App\Proxmox\Exceptions\ProxmoxUnreachable;
use App\Proxmox\Resources\AccessResource;
use App\Proxmox\Resources\ClusterResource;
use App\Proxmox\Resources\GuestAgentResource;
use App\Proxmox\Resources\NodesResource;
use App\Proxmox\Resources\QemuResource;
use App\Proxmox\Resources\TasksResource;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Thin client for the Proxmox VE API. app/Proxmox is the only place in the
 * application that talks to Proxmox.
 */
class ProxmoxClient
{
    /**
     * One curl handler for the whole process, so connections to Proxmox are
     * kept open and reused (keep-alive) instead of a new TCP and TLS
     * handshake per request. Polling loops send a request every few seconds;
     * opening that many new connections can trip connection-rate limits in
     * front of a node (seen on a hosted test node, 2026-10-16).
     */
    private static ?CurlHandler $handler = null;

    /** Connection attempts per host before the next host is tried. */
    private const CONNECT_ATTEMPTS = 2;

    public function __construct(
        private readonly ProxmoxConnection $connection,
        private readonly HttpFactory $http,
    ) {}

    public function cluster(): ClusterResource
    {
        return new ClusterResource($this);
    }

    public function nodes(): NodesResource
    {
        return new NodesResource($this);
    }

    public function tasks(): TasksResource
    {
        return new TasksResource($this);
    }

    public function qemu(string $node): QemuResource
    {
        return new QemuResource($this, $node);
    }

    public function guestAgent(string $node): GuestAgentResource
    {
        return new GuestAgentResource($this, $node);
    }

    public function access(): AccessResource
    {
        return new AccessResource($this);
    }

    public function taskWaiter(): TaskWaiter
    {
        return new TaskWaiter($this);
    }

    /**
     * The Proxmox VE version, e.g. "8.4.1".
     */
    public function version(): string
    {
        $data = $this->get('/version');

        return is_array($data) && is_scalar($data['version'] ?? null) ? (string) $data['version'] : '';
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * A list value is sent as repeated keys (command=a&command=b), which is
     * how Proxmox reads array parameters such as the guest agent's command.
     *
     * @param  array<string, scalar|list<scalar>>  $data
     */
    public function post(string $path, array $data = []): mixed
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $data
     */
    public function put(string $path, array $data = []): mixed
    {
        return $this->request('PUT', $path, $data);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public function delete(string $path, array $query = []): mixed
    {
        return $this->request('DELETE', $path, $query);
    }

    /**
     * Send a request, trying each configured host in turn if it can't be reached.
     *
     * @param  array<string, scalar|list<scalar>>  $params
     */
    private function request(string $method, string $path, array $params): mixed
    {
        $lastError = 'no hosts configured';
        $tlsFailedHost = null;

        foreach ($this->connection->hosts as $host) {
            $attempt = 1;

            while (true) {
                try {
                    return $this->unwrap($this->send($host, $method, $path, $params));
                } catch (ConnectionException $e) {
                    $lastError = $this->connectionErrorSummary($e);
                    $tlsFailedHost ??= $this->isTlsVerificationError($e) ? $host : null;

                    // Not connected means not sent: trying again is safe for any method.
                    if ($attempt >= self::CONNECT_ATTEMPTS || ! $this->isConnectFailure($e)) {
                        continue 2;
                    }

                    $attempt++;
                    Sleep::for(1)->second();
                }
            }
        }

        if ($tlsFailedHost !== null) {
            throw ProxmoxTlsVerificationFailed::forHost($tlsFailedHost);
        }

        throw ProxmoxUnreachable::allHostsFailed($this->connection->hosts, $lastError);
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $params
     */
    private function send(string $host, string $method, string $path, array $params): Response
    {
        $request = $this->http
            ->setHandler(self::handler())
            ->baseUrl($this->connection->baseUrl($host))
            ->withHeaders(['Authorization' => $this->connection->authorizationHeader()])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($this->connection->timeoutSeconds)
            ->withOptions(['verify' => $this->connection->verifyTls]);

        $path = '/'.ltrim($path, '/');

        return match ($method) {
            'GET' => $request->get($path, $params),
            'DELETE' => $request->delete($params === [] ? $path : $path.'?'.http_build_query($params)),
            'POST' => $request->withBody(self::formEncode($params), 'application/x-www-form-urlencoded')->post($path),
            'PUT' => $request->withBody(self::formEncode($params), 'application/x-www-form-urlencoded')->put($path),
            default => throw new InvalidArgumentException("Unsupported HTTP method {$method}."),
        };
    }

    private static function handler(): CurlHandler
    {
        return self::$handler ??= new CurlHandler;
    }

    /**
     * Form-encode parameters, sending a list as repeated keys.
     *
     * @param  array<string, scalar|list<scalar>>  $params
     */
    private static function formEncode(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = is_bool($item) ? (int) $item : $item;
                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    private function unwrap(Response $response): mixed
    {
        if ($response->status() === 401) {
            throw ProxmoxAuthenticationFailed::invalidToken();
        }

        if ($response->failed()) {
            throw new ProxmoxRequestFailed($this->errorMessage($response), $response->status());
        }

        return $response->json('data');
    }

    /**
     * Proxmox puts the error summary in the HTTP reason phrase and
     * per-parameter details in an "errors" object.
     */
    private function errorMessage(Response $response): string
    {
        $message = trim($response->reason());
        $message = $message !== '' ? $message : "HTTP {$response->status()}";
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $details = [];

            foreach ($errors as $field => $error) {
                $text = is_scalar($error) ? trim((string) $error) : (string) json_encode($error);
                $details[] = is_string($field) ? "{$field}: {$text}" : $text;
            }

            $message .= ' ('.implode('; ', $details).')';
        }

        return $message;
    }

    /**
     * cURL error 60: peer certificate cannot be authenticated with known CA certificates.
     */
    private function isTlsVerificationError(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 60')
            || str_contains($e->getMessage(), 'SSL certificate problem');
    }

    /**
     * The TCP connection was never established (curl 7, or 28 in the connect
     * phase), so the request did not reach Proxmox. A dropped first packet on
     * the way to a node shows up like this.
     */
    private function isConnectFailure(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 7:')
            || (str_contains($e->getMessage(), 'cURL error 28:') && str_contains($e->getMessage(), 'Connection timed out'));
    }

    private function connectionErrorSummary(ConnectionException $e): string
    {
        // Guzzle messages contain the URL but never the Authorization header.
        return (string) preg_replace('/\s+/', ' ', mb_strimwidth($e->getMessage(), 0, 300, '…'));
    }
}
