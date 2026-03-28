<?php

namespace phind;

class RecordStore
{
    private array $records = [];
    private string $path;

    public function __construct(string $path, array $initial = [])
    {
        $this->path = $path;

        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $this->records = $this->normalize($data);
                return;
            }
        }

        $this->records = $this->normalize($initial);
        $this->persist();
    }

    public function all(): array
    {
        return $this->records;
    }

    public function get(string $hostname): ?array
    {
        $hostname = $this->normalizeHostname($hostname);
        return $this->records[$hostname] ?? null;
    }

    public function set(string $hostname, array $entries): void
    {
        $hostname = $this->normalizeHostname($hostname);
        $this->records[$hostname] = $this->normalizeEntries($entries);
        $this->persist();
    }

    public function delete(string $hostname): void
    {
        $hostname = $this->normalizeHostname($hostname);
        unset($this->records[$hostname]);
        $this->persist();
    }

    public function replaceAll(array $records): void
    {
        $this->records = $this->normalize($records);
        $this->persist();
    }

    private function persist(): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function normalize(array $records): array
    {
        $normalized = [];

        foreach ($records as $hostname => $entries) {
            $normalized[$this->normalizeHostname($hostname)] = $this->normalizeEntries($entries);
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $normalized[] = [
                'type' => strtoupper((string) ($entry['type'] ?? 'A')),
                'value' => (string) ($entry['value'] ?? ''),
                'ttl' => (int) ($entry['ttl'] ?? 300),
            ];
        }

        return $normalized;
    }

    private function normalizeHostname(string $hostname): string
    {
        return strtolower(rtrim($hostname, '.'));
    }
}