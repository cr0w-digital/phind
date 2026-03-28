<?php

namespace phind;

interface Exporter
{
    public function export(string $event, array $data = []): void;
}