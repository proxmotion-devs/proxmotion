<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

use Proxmotion\Wisecp\Transport;
use RuntimeException;

/**
 * The Proxmotion API as the module sees it: answers per "METHOD /path"
 * (without /api/v1), used in order; the last answer of a route repeats.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, list<array{status: int, body: string}>> */
    private array $routes = [];

    /** @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: array<string, mixed>|null}> */
    public array $requests = [];

    /**
     * @param array<string, mixed>|null $json
     */
    public function on(string $route, int $status, ?array $json = null): self
    {
        $this->routes[$route][] = ['status' => $status, 'body' => $json === null ? '' : (string) json_encode($json)];

        return $this;
    }

    /**
     * The connection fails (no HTTP answer).
     */
    public function failOn(string $route): self
    {
        $this->routes[$route][] = ['status' => 0, 'body' => 'Connection refused'];

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $path = (string) preg_replace('#^https?://[^/]+/api/v1#', '', $url);
        $route = $method . ' ' . $path;

        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'headers' => $headers,
            'body' => $body === null ? null : json_decode($body, true),
        ];

        if (($this->routes[$route] ?? []) === []) {
            throw new RuntimeException("Unexpected request: {$route}");
        }

        return count($this->routes[$route]) > 1 ? array_shift($this->routes[$route]) : $this->routes[$route][0];
    }

    /**
     * @return list<string> "METHOD /path" of every request, in order
     */
    public function sent(): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'], $this->requests);
    }

    /**
     * @return array{method: string, url: string, path: string, headers: array<string, string>, body: array<string, mixed>|null}
     */
    public function last(string $route): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] . ' ' . $request['path'] === $route) {
                return $request;
            }
        }

        throw new RuntimeException("{$route} was not sent.");
    }

    /**
     * The JSON body of the last request to this route.
     *
     * @return array<string, mixed>
     */
    public function body(string $route): array
    {
        return $this->last($route)['body'] ?? throw new RuntimeException("{$route} had no body.");
    }
}
