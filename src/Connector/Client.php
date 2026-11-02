<?php

namespace Banimark\Connector;

/**
 * The desk's side of the connector contract (Protocol): sign a call, send it,
 * read the answer. SqlTool runs through runner(), the Tool Builder's table
 * picker through schema(), "Test the connection" through health(). This
 * ships readable on purpose: an owner's developer may want to see exactly
 * what reaches their server.
 */
final class Client
{
    /** @var callable(string, string, array, ?string, int): array{status:int, body:string, error:string} */
    private $transport;
    private ?array $health = null;

    public function __construct(private string $url, private string $secret, ?callable $transport = null, private int $timeout = 15)
    {
        $this->transport = $transport ?? \Banimark\Tools\HttpTool::transport();
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @throws \RuntimeException when the connector cannot be reached or refuses */
    public function health(): array
    {
        return $this->health ??= $this->call('health', []);
    }

    /** 'mysql' | 'pgsql' | 'sqlite' - what the connector's database speaks, for the query builder. */
    public function dialect(): string
    {
        $d = (string) ($this->health()['dialect'] ?? 'mysql');
        return in_array($d, ['mysql', 'pgsql', 'sqlite'], true) ? $d : 'mysql';
    }

    /**
     * The owner's tables and columns, the shape SchemaInspector::all() has, so
     * the Tool Builder cannot tell the difference.
     *
     * @return array<string, array<int, array{name: string, type: string}>>
     */
    public function schema(): array
    {
        $out = [];
        foreach ((array) ($this->call('schema', [])['tables'] ?? []) as $table => $columns) {
            $out[(string) $table] = array_values(array_map(fn ($c) => ['name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? '')], (array) $columns));
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function query(string $sql, array $bindings, int $maxRows = Protocol::MAX_ROWS): array
    {
        $out = $this->call('query', ['sql' => $sql, 'bindings' => (object) $bindings, 'max_rows' => max(1, min(Protocol::MAX_ROWS, $maxRows))]);
        return array_values(array_map(fn ($r) => (array) $r, (array) ($out['rows'] ?? [])));
    }

    /** The callable a SqlTool runs through - identical in shape to DataSource::runner(). */
    public function runner(): callable
    {
        return fn (string $sql, array $bindings) => $this->query($sql, $bindings);
    }

    /** @throws \RuntimeException */
    private function call(string $action, array $payload): array
    {
        $body = json_encode(['action' => $action] + $payload, JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.Protocol::sign($this->secret, $action, $body),
            Protocol::HEADER => (string) Protocol::VERSION,
        ];
        $res = ($this->transport)('POST', $this->url, $headers, $body, $this->timeout);
        $status = (int) ($res['status'] ?? 0);
        if ($status === 0) {
            throw new \RuntimeException('The connector could not be reached'.(($res['error'] ?? '') !== '' ? ' ('.$res['error'].')' : '').'. Is it installed at '.$this->url.'?');
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($data)) {
            throw new \RuntimeException('The address answered with something other than the connector (HTTP '.$status.'). Check that it is the connector\'s own address.');
        }
        if (empty($data['ok'])) {
            $why = (string) ($data['error'] ?? 'HTTP '.$status);
            if ($status === 401 || $status === 403) {
                $why .= ' - the secret in the connector does not match this desk. Download the connector again and replace the installed one.';
            }
            throw new \RuntimeException('The connector refused: '.$why);
        }
        return $data;
    }
}
