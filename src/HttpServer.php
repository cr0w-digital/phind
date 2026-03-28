<?php

namespace phind;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

class HttpServer
{
    public function __construct(
        private string $host,
        private int $port,
        private RecordStore $store,
        private Resolver $resolver,
        private Metrics $metrics
    ) {}

    public function start(): void
    {
        $server = new Server($this->host, $this->port);

        $server->on('start', function (): void {
            echo "[HTTP] Listening on http://{$this->host}:{$this->port}\n";
        });

        $server->on('request', function (Request $request, Response $response): void {
            $path = $request->server['request_uri'] ?? '/';
            $method = strtoupper($request->server['request_method'] ?? 'GET');

            try {
                if ($path === '/records' && $method === 'GET') {
                    $this->json($response, ['records' => $this->store->all()]);
                    return;
                }

                if ($path === '/records' && $method === 'POST') {
                    $payload = $this->jsonBody($request);

                    $hostname = (string) ($payload['hostname'] ?? '');
                    $entries = $payload['entries'] ?? null;

                    if ($hostname === '' || !is_array($entries)) {
                        $this->json($response, ['error' => 'hostname and entries are required'], 422);
                        return;
                    }

                    $this->store->set($hostname, $entries);
                    $this->resolver->clearCache();

                    $this->json($response, [
                        'ok' => true,
                        'hostname' => $hostname,
                        'records' => $this->store->all(),
                    ]);
                    return;
                }

                if (preg_match('#^/records/(.+)$#', $path, $m) && $method === 'DELETE') {
                    $hostname = urldecode($m[1]);
                    $this->store->delete($hostname);
                    $this->resolver->clearCache();

                    $this->json($response, [
                        'ok' => true,
                        'hostname' => $hostname,
                    ]);
                    return;
                }

                if ($path === '/metrics' && $method === 'GET') {
                    $this->json($response, $this->metrics->readSnapshot());
                    return;
                }

                if ($path === '/events' && $method === 'GET') {
                    $this->sse($response);
                    return;
                }

                if ($path === '/status' && $method === 'GET') {
                    $this->json($response, [
                        'running' => true,
                        'record_count' => count($this->store->all()),
                    ]);
                    return;
                }

                if ($path === '/cache/clear' && $method === 'POST') {
                    $this->resolver->clearCache();
                    $this->json($response, ['ok' => true]);
                    return;
                }

                if ($path === '/resolve' && $method === 'GET') {
                    $name = strtolower(trim((string) ($request->get['name'] ?? '')));
                    $type = strtoupper(trim((string) ($request->get['type'] ?? 'A')));

                    if ($name === '') {
                        $this->json($response, ['error' => 'name is required'], 422);
                        return;
                    }

                    $qtype = match ($type) {
                        'A' => 1,
                        'CNAME' => 5,
                        'AAAA' => 28,
                        default => null,
                    };

                    if ($qtype === null) {
                        $this->json($response, ['error' => 'unsupported type'], 422);
                        return;
                    }

                    $result = $this->resolver->resolve((object) [
                        'hostname' => $name,
                        'qtype' => $qtype,
                        'qclass' => 1,
                    ]);

                    $this->json($response, [
                        'name' => $name,
                        'type' => $type,
                        'result' => $result,
                    ]);
                    return;
                }

                $this->json($response, ['error' => 'Not found'], 404);
            } catch (\Throwable $e) {
                $this->json($response, ['error' => $e->getMessage()], 500);
            }
        });

        $server->start();
    }

    private function sse(Response $response): void
    {
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        $lastHash = '';

        \Swoole\Timer::tick(1000, function (int $timerId) use ($response, &$lastHash): void {
            if (method_exists($response, 'isWritable') && !$response->isWritable()) {
                \Swoole\Timer::clear($timerId);
                return;
            }

            $snapshot = $this->metrics->readSnapshot();

            $payload = [
                'queries_today' => $snapshot['queries_today'] ?? 0,
                'cache_hits' => $snapshot['cache_hits'] ?? 0,
                'cache_misses' => $snapshot['cache_misses'] ?? 0,
                'cache_hit_rate' => $snapshot['cache_hit_rate'] ?? 0.0,
                'resolution_failures' => $snapshot['resolution_failures'] ?? 0,
                'upstream_forwards' => $snapshot['upstream_forwards'] ?? 0,
                'upstream_truncated_responses' => $snapshot['upstream_truncated_responses'] ?? 0,
                'recent_queries' => $snapshot['recent_queries'] ?? [],
                'series' => $snapshot['series'] ?? [],
                'logs' => array_slice($snapshot['logs'] ?? [], -20),
            ];

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            $hash = md5($json);

            if ($hash === $lastHash) {
                $response->write(": ping\n\n");
                return;
            }

            $lastHash = $hash;
            $response->write("event: metrics\n");
            $response->write("data: {$json}\n\n");
        });
    }

    private function jsonBody(Request $request): array
    {
        $raw = $request->rawContent();
        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        return $decoded;
    }

    private function json(Response $response, array $data, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}