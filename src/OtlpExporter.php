<?php

namespace phind;

class OtlpExporter implements Exporter
{
    public function __construct(
        private string $endpoint,
        private float $timeout = 0.25,
        private array $headers = []
    ) {}

    public function export(string $event, array $data = []): void
    {
        if ($this->endpoint === '') {
            return;
        }

        $payload = json_encode([
            'event' => $event,
            'time' => date('c'),
            'service' => 'phind',
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ], $this->formatHeaders($this->headers));

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        try {
            @file_get_contents($this->endpoint, false, $context);
        } catch (\Throwable) {
            // best effort only
        }
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $formatted[] = (string) $value;
                continue;
            }

            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}