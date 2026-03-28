<?php

namespace phind;

class DnsHandler
{
    public function __construct(
        private Parser $parser,
        private Resolver $resolver,
        private Response $response
    ) {}

    public function handle(string $data): string
    {
        $query = $this->parser->parse($data);
        $result = $this->resolver->resolve($query);

        return match ($result->mode) {
            'local' => $this->response->build(
                $query,
                $result->answers,
                $result->additionals ?? []
            ),
            'forwarded' => $result->packet,
            default => $this->response->buildNx($query),
        };
    }
}