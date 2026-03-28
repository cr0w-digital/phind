<?php

namespace phind;

use Swoole\Coroutine;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Socket;

class Server
{
    public function __construct(
        private string $host,
        private int $port,
        private DnsHandler $handler
    ) {}

    public function start(): void
    {
        Coroutine\run(function (): void {
            Coroutine::create(fn() => $this->runUdp());
            Coroutine::create(fn() => $this->runTcp());
        });
    }

    private function runUdp(): void
    {
        $sock = new Socket(\AF_INET, \SOCK_DGRAM, 0);

        if (!$sock->bind($this->host, $this->port)) {
            throw new \RuntimeException("Failed to bind UDP {$this->host}:{$this->port}");
        }

        echo "[DNS:UDP] Listening on {$this->host}:{$this->port}\n";

        while (true) {
            $peer = null;
            $data = $sock->recvfrom($peer);

            if ($data === false || $data === '') {
                continue;
            }

            try {
                $response = $this->handler->handle($data);
                $sock->sendto($peer['address'], $peer['port'], $response);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function runTcp(): void
    {
        $server = new \Swoole\Coroutine\Server($this->host, $this->port, false, true);

        echo "[DNS:TCP] Listening on {$this->host}:{$this->port}\n";

        $server->handle(function (Connection $conn): void {
            try {
                $lengthBytes = $conn->recv(2);
                if ($lengthBytes === '' || $lengthBytes === false || strlen($lengthBytes) !== 2) {
                    $conn->close();
                    return;
                }

                $length = unpack('n', $lengthBytes)[1];
                if ($length < 1) {
                    $conn->close();
                    return;
                }

                $packet = $conn->recvAll($length);
                if ($packet === false || strlen($packet) !== $length) {
                    $conn->close();
                    return;
                }

                $response = $this->handler->handle($packet);
                $conn->sendAll(pack('n', strlen($response)) . $response);
            } catch (\Throwable) {
                // ignore malformed requests
            } finally {
                $conn->close();
            }
        });

        $server->start();
    }
}