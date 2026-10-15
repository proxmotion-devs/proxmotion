<?php

declare(strict_types=1);

namespace App\Proxmox;

use App\Proxmox\Exceptions\ProxmoxAuthenticationFailed;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\Exceptions\ProxmoxTlsVerificationFailed;
use App\Proxmox\Exceptions\ProxmoxUnreachable;
use App\Proxmox\Resources\ClusterResource;
use App\Proxmox\Resources\NodesResource;
use App\Proxmox\Resources\TasksResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Thin client for the Proxmox VE API. app/Proxmox is the only place in the
 * application that talks to Proxmox.
 */
class ProxmoxClient
{
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
     * @param  array<string, scalar>  $data
     */
    public function post(string $path, array $data = []): mixed
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * @param  array<string, scalar>  $data
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
     * @param  array<string, scalar>  $params
     */
    private function request(string $method, string $path, array $params): mixed
    {
        $lastError = 'no hosts configured';
        $tlsFailedHost = null;

        foreach ($this->connection->hosts as $host) {
            try {
                $response = $this->send($host, $method, $path, $params);
            } catch (ConnectionException $e) {
                $lastError = $this->connectionErrorSummary($e);
                $tlsFailedHost ??= $this->isTlsVerificationError($e) ? $host : null;

                continue;
            }

            return $this->unwrap($response);
        }

        if ($tlsFailedHost !== null) {
            throw ProxmoxTlsVerificationFailed::forHost($tlsFailedHost);
        }

        throw ProxmoxUnreachable::allHostsFailed($this->connection->hosts, $lastError);
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function send(string $host, string $method, string $path, array $params): Response
    {
        $request = $this->http
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
            'POST' => $request->asForm()->post($path, $params),
            'PUT' => $request->asForm()->put($path, $params),
            default => throw new InvalidArgumentException("Unsupported HTTP method {$method}."),
        };
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

    private function connectionErrorSummary(ConnectionException $e): string
    {
        // Guzzle messages contain the URL but never the Authorization header.
        return (string) preg_replace('/\s+/', ' ', mb_strimwidth($e->getMessage(), 0, 300, '…'));
    }
}
