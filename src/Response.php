<?php

namespace phind;

class Response
{
    public function build(object $query, array $answers, array $additionals = []): string
    {
        $id = pack('n', $query->id);
        $rd = $query->flags & 0x0100;
        $flags = pack('n', 0x8000 | $rd | 0x0080); // QR + copy RD + RA

        $packet = $id
            . $flags
            . pack('n', 1)
            . pack('n', count($answers))
            . pack('n', 0)
            . pack('n', count($additionals))
            . $query->question;

        foreach ($answers as $record) {
            $packet .= $this->buildRecord($record);
        }

        foreach ($additionals as $record) {
            $packet .= $this->buildRecord($record);
        }

        return $packet;
    }

    public function buildNx(object $query): string
    {
        $id = pack('n', $query->id);
        $rd = $query->flags & 0x0100;
        $flags = pack('n', 0x8000 | $rd | 0x0003);

        return $id
            . $flags
            . pack('n', 1)
            . pack('n', 0)
            . pack('n', 0)
            . pack('n', 0)
            . $query->question;
    }

    private function buildRecord(array $record): string
    {
        $name = $this->encodeName($record['name']);
        $type = $this->typeCode($record['type']);
        $class = 1;
        $ttl = (int) $record['ttl'];

        $rdata = match ($record['type']) {
            'A', 'AAAA' => inet_pton($record['value']),
            'CNAME' => $this->encodeName($record['value']),
            default => throw new \InvalidArgumentException("Unsupported record type {$record['type']}"),
        };

        return $name
            . pack('n', $type)
            . pack('n', $class)
            . pack('N', $ttl)
            . pack('n', strlen($rdata))
            . $rdata;
    }

    private function typeCode(string $type): int
    {
        return match ($type) {
            'A' => 1,
            'CNAME' => 5,
            'AAAA' => 28,
            default => throw new \InvalidArgumentException("Unsupported type {$type}"),
        };
    }

    private function encodeName(string $hostname): string
    {
        $hostname = rtrim($hostname, '.');
        $parts = explode('.', $hostname);
        $encoded = '';

        foreach ($parts as $part) {
            if (strlen($part) > 63) {
                throw new \InvalidArgumentException("DNS label too long: {$part}");
            }

            $encoded .= chr(strlen($part)) . $part;
        }

        return $encoded . "\0";
    }
}