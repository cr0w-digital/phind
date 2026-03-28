<?php

namespace phind;

class Parser
{
    public function parse(string $data): object
    {
        if (strlen($data) < 12) {
            throw new \InvalidArgumentException('DNS packet too short');
        }

        $id = unpack('n', substr($data, 0, 2))[1];
        $flags = unpack('n', substr($data, 2, 2))[1];
        $qdcount = unpack('n', substr($data, 4, 2))[1];

        if ($qdcount < 1) {
            throw new \InvalidArgumentException('DNS packet has no questions');
        }

        $offset = 12;
        $qnameStart = $offset;
        $labels = [];

        while (true) {
            if (!isset($data[$offset])) {
                throw new \InvalidArgumentException('Malformed QNAME');
            }

            $len = ord($data[$offset]);

            if ($len === 0) {
                $offset++;
                break;
            }

            if (($len & 0xC0) === 0xC0) {
                throw new \InvalidArgumentException('Compressed QNAME not supported in question');
            }

            $offset++;
            $label = substr($data, $offset, $len);

            if (strlen($label) !== $len) {
                throw new \InvalidArgumentException('Malformed DNS label');
            }

            $labels[] = $label;
            $offset += $len;
        }

        if (strlen($data) < $offset + 4) {
            throw new \InvalidArgumentException('DNS question truncated');
        }

        $hostname = strtolower(implode('.', $labels));
        $qtype = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        $qclass = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        $question = substr($data, $qnameStart, $offset - $qnameStart);

        return (object) [
            'id' => $id,
            'flags' => $flags,
            'hostname' => $hostname,
            'qtype' => $qtype,
            'qclass' => $qclass,
            'question' => $question,
            'raw' => $data,
        ];
    }
}