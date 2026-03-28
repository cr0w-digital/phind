# ↯ phind

Local-first DNS.

Records, wildcards, runtime management, upstream fallback.

## Install

Requires the [Swoole](https://swoole.co.uk) PHP extension:

```bash
pecl install swoole
```

Install globally:

```bash
composer global require cr0w/phind
```

Make sure Composer's global bin directory is on your PATH:

```bash
export PATH="$PATH:$(composer global config bin-dir --absolute)"
```

Or install locally in a project:

```bash
composer require cr0w/phind
```

## Usage

```bash
bin/phind
# [DNS:UDP] Listening on 0.0.0.0:8053
# [DNS:TCP] Listening on 0.0.0.0:8053

bin/phind-http
# [HTTP] Listening on http://127.0.0.1:8080
```

## Records

Records live at `~/.phind/records.json` by default:

```json
{
  "*.phind": [
    { "type": "A", "value": "127.0.0.1", "ttl": 300 }
  ]
}
```

Override the path:

```bash
export PHIND_STORAGE_PATH=/your/path
```

Wildcards, multiple domains, and cross-host targets all work, all part of the same naming layer:

```json
{
  "*.dev":    [{ "type": "A", "value": "127.0.0.1" }],
  "api.local":[{ "type": "A", "value": "127.0.0.1" }],
  "db.test":  [{ "type": "A", "value": "192.168.1.50" }]
}
```

phind requires a restart to pick up changes to the records file directly. To add, update, or remove records without restarting, use the HTTP API.

## Resolution

phind resolves `A`, `AAAA`, and `CNAME` records locally, including chains:

```
api.phind → auth.phind → 127.0.0.1
```

Local records resolve first. Unknown names fall through to upstream DNS.

Results are cached in memory and cleared automatically when records are modified via the HTTP API.

Both UDP and TCP are supported on port 8053. TCP is used automatically by clients when a UDP response is truncated. This matters if you're routing real network traffic through phind, where large responses (DNSSEC, SPF, MX) can exceed the UDP limit.

Test resolution directly:

```bash
dig @127.0.0.1 -p 8053 app.dev
```

## System-wide Resolution

phind listens on port 8053 by default and won't affect normal DNS until you point your system or network at it, making your naming layer available everywhere.

**Router DNS** is the simplest option. Point your router's DNS server at the machine running phind and every device on the network gets wildcard resolution, with everything else falling through upstream as usual. Note that if the machine running phind restarts, your network loses DNS until it comes back up.

**dnsmasq forwarding** is more surgical. Forward only your domains to phind and leave everything else alone:

```
# /etc/dnsmasq.conf
server=/.phind/127.0.0.1#8053
server=/.dev/127.0.0.1#8053
```

**Explicit queries** require no resolver config and are useful for testing:

```bash
dig @127.0.0.1 -p 8053 app.phind
```

Without one of the first two options, phind will only answer explicit queries.

## HTTP API

| Method | Endpoint                          | Description                     |
|--------|-----------------------------------|---------------------------------|
| GET    | /records                          | List all records                |
| POST   | /records                          | Add or update a record          |
| DELETE | /records/{hostname}               | Remove a record                 |
| GET    | /resolve?name=auth.phind&type=A   | Resolve a name                  |
| GET    | /metrics                          | Current metrics snapshot        |
| GET    | /events                           | SSE stream of metrics updates   |
| GET    | /status                           | Server status and record count  |
| POST   | /cache/clear                      | Flush cache                     |

Records are posted as `{ "hostname": "*.dev", "entries": [...] }`. Adding or removing a record clears the cache automatically.

## Observability

phind tracks cache hits/misses, request latency, local vs. upstream resolution, failures, upstream forwards, and truncated upstream responses.

Metrics are kept in memory and flushed to a snapshot on an interval. The HTTP API reads from that snapshot, metrics writes are never in the DNS lookup path.

Access a snapshot:

```bash
GET /metrics
```

Stream live updates via SSE:

```bash
GET /events
```

The event stream emits a `metrics` event every second when anything has changed, including recent queries and the last 20 log entries. If nothing has changed, a keepalive ping is sent instead. Connect a dashboard or tail it with curl:

```bash
curl -N http://127.0.0.1:8080/events
```

Or export to an OTLP endpoint:

```bash
export PHIND_OTLP_ENDPOINT=http://localhost:4318/phind/events
```

## Limitations

- Requires a restart to pick up changes to the records file (use the HTTP API to manage records at runtime without restarting)

## License

MIT