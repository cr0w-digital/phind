<?php

namespace phind;

class NullExporter implements Exporter
{
    public function export(string $event, array $data = []): void
    {
        // no-op
    }
}