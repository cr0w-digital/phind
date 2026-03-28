<?php

namespace phind;

class Resolver
{
    private array $cache = [];

    public function __construct(
        private RecordStore $store,
        private Metrics $metrics,
        private ?Upstream $upstream = null
    ) {}

    public function resolve(object $query): object
    {
        $start = microtime(true);

        $hostname = strtolower(rtrim($query->hostname, '.'));
        $qtype = $this->qtypeName($query->qtype) ?? 'UNKNOWN';

        if ($qtype === 'UNKNOWN' || $query->qclass !== 1) {
            $this->metrics->recordResolutionFailure();
            $this->metrics->recordRequest(
                $hostname,
                $qtype,
                microtime(true) - $start,
                'local',
                'nxdomain'
            );

            return (object) ['mode' => 'nxdomain'];
        }

        $cacheKey = "{$hostname}|{$qtype}";
        if (isset($this->cache[$cacheKey])) {
            $this->metrics->recordCacheHit();
            $this->metrics->recordRequest(
                $hostname,
                $qtype,
                microtime(true) - $start,
                'local',
                'ok'
            );

            return $this->cache[$cacheKey];
        }

        $result = $this->resolveLocal($hostname, $qtype, []);
        if ($result !== null) {
            $this->metrics->recordCacheMiss();
            $this->cache[$cacheKey] = $result;
            $this->metrics->recordRequest(
                $hostname,
                $qtype,
                microtime(true) - $start,
                'local',
                'ok'
            );

            return $result;
        }

        if ($this->upstream) {
            $packet = $this->upstream->forward($query);
            if ($packet !== null) {
                $this->metrics->recordUpstreamForward();
                $this->metrics->recordRequest(
                    $hostname,
                    $qtype,
                    microtime(true) - $start,
                    'upstream',
                    'ok'
                );

                return (object) [
                    'mode' => 'forwarded',
                    'packet' => $packet,
                ];
            }
        }

        $this->metrics->recordResolutionFailure();
        $this->metrics->recordRequest(
            $hostname,
            $qtype,
            microtime(true) - $start,
            'local',
            'nxdomain'
        );

        return (object) ['mode' => 'nxdomain'];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function clearCacheFor(string $hostname): void
    {
        $hostname = strtolower(rtrim($hostname, '.'));

        foreach (['A', 'AAAA', 'CNAME'] as $type) {
            unset($this->cache["{$hostname}|{$type}"]);
        }
    }

    private function resolveLocal(string $hostname, string $qtype, array $visited): ?object
    {
        if (isset($visited[$hostname])) {
            return null;
        }

        $visited[$hostname] = true;

        [$matchedName, $records] = $this->findRecords($hostname);
        if ($records === null) {
            return null;
        }

        $answers = [];

        foreach ($records as $record) {
            if ($record['type'] === $qtype) {
                $answers[] = [
                    'name' => $hostname,
                    'type' => $record['type'],
                    'ttl' => $record['ttl'],
                    'value' => $record['value'],
                ];
            }
        }

        if ($answers) {
            return (object) [
                'mode' => 'local',
                'source' => 'local',
                'matched' => $matchedName,
                'answers' => $answers,
                'additionals' => [],
            ];
        }

        foreach ($records as $record) {
            if ($record['type'] !== 'CNAME') {
                continue;
            }

            $target = strtolower(rtrim($record['value'], '.'));

            $answers[] = [
                'name' => $hostname,
                'type' => 'CNAME',
                'ttl' => $record['ttl'],
                'value' => $target,
            ];

            $targetResult = $this->resolveLocal($target, $qtype, $visited);

            if ($targetResult !== null && isset($targetResult->answers)) {
                foreach ($targetResult->answers as $answer) {
                    $answers[] = $answer;
                }

                return (object) [
                    'mode' => 'local',
                    'source' => 'local',
                    'matched' => $matchedName,
                    'answers' => $answers,
                    'additionals' => [],
                ];
            }
        }

        return null;
    }

    private function findRecords(string $hostname): array
    {
        $records = $this->store->all();

        if (isset($records[$hostname])) {
            return [$hostname, $records[$hostname]];
        }

        $labels = explode('.', $hostname);

        while (count($labels) > 1) {
            array_shift($labels);
            $wildcard = '*.' . implode('.', $labels);

            if (isset($records[$wildcard])) {
                return [$wildcard, $records[$wildcard]];
            }
        }

        return [null, null];
    }

    private function qtypeName(int $qtype): ?string
    {
        return match ($qtype) {
            1 => 'A',
            5 => 'CNAME',
            28 => 'AAAA',
            default => null,
        };
    }
}