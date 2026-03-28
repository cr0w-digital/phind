<?php

namespace phind;

use Swoole\Coroutine\Socket;

class Upstream
{
    public function __construct(
        private Metrics $metrics,
        private string $host = '1.1.1.1',
        private int $port = 53,
        private float $timeout = 2.0
    ) {}

    public function forward(object $query): ?string
    {
        $udpResponse = $this->forwardUdp($query->raw);

        if ($udpResponse === null) {
            return null;
        }

        if (!$this->isTruncated($udpResponse)) {
            return $udpResponse;
        }

        $this->metrics->recordUpstreamTruncatedResponse();
        $this->metrics->incrementTruncatedBucket();

        $tcpResponse = $this->forwardTcp($query->raw);

        return $tcpResponse ?? $udpResponse;
    }

    private function forwardUdp(string $packet): ?string
    {
        $sock = new Socket(\AF_INET, \SOCK_DGRAM, 0);

        if (!$sock->connect($this->host, $this->port)) {
            return null;
        }

        $sent = $sock->send($packet);
        if ($sent === false) {
            $sock->close();
            return null;
        }

        $response = $sock->recv(65535, $this->timeout);
        $sock->close();

        if ($response === false || $response === '') {
            return null;
        }

        return $response;
    }

    private function forwardTcp(string $packet): ?string
    {
        $sock = new Socket(\AF_INET, \SOCK_STREAM, 0);

        if (!$sock->connect($this->host, $this->port, $this->timeout)) {
            return null;
        }

        $framed = pack('n', strlen($packet)) . $packet;

        if ($sock->sendAll($framed) === false) {
            $sock->close();
            return null;
        }

        $lengthBytes = $sock->recvAll(2, $this->timeout);
        if ($lengthBytes === false || strlen($lengthBytes) !== 2) {
            $sock->close();
            return null;
        }

        $length = unpack('n', $lengthBytes)[1];
        if ($length < 1) {
            $sock->close();
            return null;
        }

        $response = $sock->recvAll($length, $this->timeout);
        $sock->close();

        if ($response === false || strlen($response) !== $length) {
            return null;
        }

        return $response;
    }

    private function isTruncated(string $packet): bool
    {
        if (strlen($packet) < 4) {
            return false;
        }

        $flags = unpack('n', substr($packet, 2, 2))[1];

        return (bool) ($flags & 0x0200);
    }
}