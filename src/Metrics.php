<?php

namespace phind;

class Metrics
{
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $resolutionFailures = 0;
    private int $upstreamForwards = 0;
    private int $upstreamTruncatedResponses = 0;

    private array $recentLogs = [];
    private array $recentQueries = [];
    private array $minuteBuckets = [];

    private bool $dirty = false;

    public function __construct(
        private string $path,
        private ?Exporter $exporter = null,
        private int $recentQueryLimit = 20,
        private int $logLimit = 500,
        private int $minuteBucketLimit = 1440,
    ) {
        $this->exporter ??= new NullExporter();

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->loadPersistedState();
    }

    public function startAutoFlush(int $intervalMs = 1000): void
    {
        if (!class_exists(\Swoole\Timer::class)) {
            return;
        }

        \Swoole\Timer::tick($intervalMs, function (): void {
            $this->flush();
        });
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $state = $this->rawState();
        $this->writePersistedState($state);
        $this->dirty = false;
    }

    public function snapshot(): array
    {
        return $this->formatSnapshot($this->rawState());
    }

    public function readSnapshot(): array
    {
        $state = $this->readPersistedState();

        if ($state === null) {
            return $this->snapshot();
        }

        return $this->formatSnapshot($state);
    }

    public function recordCacheHit(): void
    {
        $this->cacheHits++;
        $this->markDirty();
        $this->log('Cache hit');
        $this->export('cache_hit');
    }

    public function recordCacheMiss(): void
    {
        $this->cacheMisses++;
        $this->markDirty();
        $this->log('Cache miss');
        $this->export('cache_miss');
    }

    public function recordResolutionFailure(): void
    {
        $this->resolutionFailures++;
        $this->markDirty();
        $this->log('Resolution failure');
        $this->export('resolution_failure');
    }

    public function recordUpstreamForward(): void
    {
        $this->upstreamForwards++;
        $this->markDirty();
        $this->log('Upstream forward');
        $this->export('upstream_forward');
    }

    public function recordUpstreamTruncatedResponse(): void
    {
        $this->upstreamTruncatedResponses++;
        $this->markDirty();
        $this->log('Upstream truncated response');
        $this->export('upstream_truncated_response');
    }

    public function recordRequest(
        string $hostname,
        string $qtype,
        float $latencySeconds,
        string $source = 'local',
        string $outcome = 'ok'
    ): void {
        $latencyMs = round($latencySeconds * 1000, 3);
        $minute = date('Y-m-d H:i:00');

        $this->ensureMinuteBucket($minute);

        $this->minuteBuckets[$minute]['queries']++;
        $this->minuteBuckets[$minute]['latency_sum_ms'] += $latencyMs;

        if ($source === 'upstream') {
            $this->minuteBuckets[$minute]['upstream']++;
        } else {
            $this->minuteBuckets[$minute]['local']++;
        }

        if ($outcome !== 'ok') {
            $this->minuteBuckets[$minute]['failures']++;
        }

        $this->recentQueries[] = [
            'time' => date('c'),
            'hostname' => $hostname,
            'qtype' => $qtype,
            'latency_ms' => $latencyMs,
            'source' => $source,
            'outcome' => $outcome,
        ];

        while (count($this->recentQueries) > $this->recentQueryLimit) {
            array_shift($this->recentQueries);
        }

        $this->trimMinuteBuckets();
        $this->markDirty();

        $this->log(
            "Request: {$hostname}, qtype: {$qtype}, latency: {$latencyMs}ms, source: {$source}, outcome: {$outcome}"
        );

        $this->export('dns_request', [
            'hostname' => $hostname,
            'qtype' => $qtype,
            'latency_ms' => $latencyMs,
            'source' => $source,
            'outcome' => $outcome,
        ]);
    }

    public function incrementFailureBucket(): void
    {
        $minute = date('Y-m-d H:i:00');
        $this->ensureMinuteBucket($minute);
        $this->minuteBuckets[$minute]['failures']++;
        $this->markDirty();
    }

    public function incrementTruncatedBucket(): void
    {
        $minute = date('Y-m-d H:i:00');
        $this->ensureMinuteBucket($minute);
        $this->minuteBuckets[$minute]['truncated']++;
        $this->markDirty();
    }

    public function reset(): void
    {
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->resolutionFailures = 0;
        $this->upstreamForwards = 0;
        $this->upstreamTruncatedResponses = 0;
        $this->recentLogs = [];
        $this->recentQueries = [];
        $this->minuteBuckets = [];
        $this->dirty = true;

        $this->flush();
    }

    private function rawState(): array
    {
        return [
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'resolution_failures' => $this->resolutionFailures,
            'upstream_forwards' => $this->upstreamForwards,
            'upstream_truncated_responses' => $this->upstreamTruncatedResponses,
            'recent_queries' => array_values($this->recentQueries),
            'minute_buckets' => $this->minuteBuckets,
            'logs' => array_values($this->recentLogs),
        ];
    }

    private function formatSnapshot(array $state): array
    {
        $totalCacheLookups = ($state['cache_hits'] ?? 0) + ($state['cache_misses'] ?? 0);
        $hitRate = $totalCacheLookups > 0
            ? round((($state['cache_hits'] ?? 0) / $totalCacheLookups) * 100, 2)
            : 0.0;

        $series = [];
        foreach (($state['minute_buckets'] ?? []) as $minute => $bucket) {
            $queries = (int) ($bucket['queries'] ?? 0);
            $avgLatency = $queries > 0
                ? round(((float) ($bucket['latency_sum_ms'] ?? 0.0)) / $queries, 3)
                : 0.0;

            $series[] = [
                'minute' => $minute,
                'queries' => $queries,
                'avg_latency_ms' => $avgLatency,
                'local' => (int) ($bucket['local'] ?? 0),
                'upstream' => (int) ($bucket['upstream'] ?? 0),
                'failures' => (int) ($bucket['failures'] ?? 0),
                'truncated' => (int) ($bucket['truncated'] ?? 0),
            ];
        }

        return [
            'queries_today' => array_sum(array_column($series, 'queries')),
            'cache_hits' => (int) ($state['cache_hits'] ?? 0),
            'cache_misses' => (int) ($state['cache_misses'] ?? 0),
            'cache_hit_rate' => $hitRate,
            'resolution_failures' => (int) ($state['resolution_failures'] ?? 0),
            'upstream_forwards' => (int) ($state['upstream_forwards'] ?? 0),
            'upstream_truncated_responses' => (int) ($state['upstream_truncated_responses'] ?? 0),
            'recent_queries' => array_values($state['recent_queries'] ?? []),
            'series' => $series,
            'logs' => array_values($state['logs'] ?? []),
        ];
    }

    private function ensureMinuteBucket(string $minute): void
    {
        if (!isset($this->minuteBuckets[$minute])) {
            $this->minuteBuckets[$minute] = [
                'queries' => 0,
                'latency_sum_ms' => 0.0,
                'local' => 0,
                'upstream' => 0,
                'failures' => 0,
                'truncated' => 0,
            ];
        }
    }

    private function trimMinuteBuckets(): void
    {
        if (count($this->minuteBuckets) <= $this->minuteBucketLimit) {
            return;
        }

        $this->minuteBuckets = array_slice(
            $this->minuteBuckets,
            -$this->minuteBucketLimit,
            null,
            true
        );
    }

    private function markDirty(): void
    {
        $this->dirty = true;
    }

    private function log(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message;
        $this->recentLogs[] = $line;

        while (count($this->recentLogs) > $this->logLimit) {
            array_shift($this->recentLogs);
        }

        $this->markDirty();
        echo "[Metrics] {$message}\n";
    }

    private function export(string $event, array $data = []): void
    {
        try {
            $this->exporter?->export($event, $data);
        } catch (\Throwable) {
            // best effort only
        }
    }

    private function loadPersistedState(): void
    {
        $state = $this->readPersistedState();
        if ($state === null) {
            return;
        }

        $this->cacheHits = (int) ($state['cache_hits'] ?? 0);
        $this->cacheMisses = (int) ($state['cache_misses'] ?? 0);
        $this->resolutionFailures = (int) ($state['resolution_failures'] ?? 0);
        $this->upstreamForwards = (int) ($state['upstream_forwards'] ?? 0);
        $this->upstreamTruncatedResponses = (int) ($state['upstream_truncated_responses'] ?? 0);
        $this->recentLogs = array_values($state['logs'] ?? []);
        $this->recentQueries = array_values($state['recent_queries'] ?? []);
        $this->minuteBuckets = is_array($state['minute_buckets'] ?? null)
            ? $state['minute_buckets']
            : [];

        $this->dirty = false;
    }

    private function readPersistedState(): ?array
    {
        $fp = fopen($this->path, 'c+');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function writePersistedState(array $state): void
    {
        $fp = fopen($this->path, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open metrics file: {$this->path}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock metrics file: {$this->path}");
            }

            rewind($fp);
            ftruncate($fp, 0);

            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('Unable to encode metrics state');
            }

            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }
}