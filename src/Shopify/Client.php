<?php

namespace Banimark\Shopify;

/**
 * The desk's side of a Shopify store: one Admin GraphQL endpoint, one
 * access token the owner made for a custom app in their Shopify admin, read
 * scopes only. The token is a setting like the connector secret - written
 * once in the panel, never rendered back, never shown to the model.
 *
 * Everything the desk asks is a single query per lookup (Catalog::query),
 * bounded by `first`, so the store's rate limit is never in play.
 */
final class Client
{
    /** The Admin API version every call names. Shopify supports a version for a year; bump this each release cycle. */
    public const VERSION = '2026-01';
    public const TIMEOUT = 15;
    /** The most rows one lookup fetches - the Tool Builder's max_rows caps lower. */
    public const MAX_ROWS = 50;

    /** @var callable(string, string, array, ?string, int): array{status:int, body:string, error:string} */
    private $transport;

    public function __construct(private string $store, private string $token, ?callable $transport = null, private int $timeout = self::TIMEOUT)
    {
        $this->transport = $transport ?? \Banimark\Tools\HttpTool::transport();
    }

    /**
     * The store's myshopify.com name from whatever the owner pasted: the bare
     * name, the admin URL, the storefront's myshopify address. '' when it is
     * not a myshopify.com name at all - a custom domain (shop.acme.com) is not
     * what the Admin API answers on, and nothing else may ever be called.
     */
    public static function store(string $input): string
    {
        $s = strtolower(trim($input));
        if ($s === '') {
            return '';
        }
        $s = preg_replace('#^https?://#', '', $s) ?? $s;
        $s = preg_replace('#^admin\.shopify\.com/store/([a-z0-9-]+).*$#', '$1.myshopify.com', $s) ?? $s;
        $s = (string) preg_replace('#[/?\#].*$#', '', $s);
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $s)) {
            $s .= '.myshopify.com';
        }
        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $s) ? $s : '';
    }

    public function storeName(): string
    {
        return $this->store;
    }

    public function url(): string
    {
        return 'https://'.$this->store.'/admin/api/'.self::VERSION.'/graphql.json';
    }

    /**
     * "Test the connection": who the store is and which read scopes the token carries.
     *
     * @return array{name: string, domain: string, currency: string, scopes: string[]}
     */
    public function shop(): array
    {
        $data = $this->graphql('query BanimarkShop { shop { name myshopifyDomain currencyCode } currentAppInstallation { accessScopes { handle } } }', []);
        return [
            'name' => (string) (($data['shop'] ?? [])['name'] ?? ''),
            'domain' => (string) (($data['shop'] ?? [])['myshopifyDomain'] ?? $this->store),
            'currency' => (string) (($data['shop'] ?? [])['currencyCode'] ?? ''),
            'scopes' => array_values(array_map(fn ($s) => (string) ($s['handle'] ?? ''), (array) ((($data['currentAppInstallation'] ?? [])['accessScopes']) ?? []))),
        ];
    }

    /**
     * The rows of one table matching a Shopify search string, flattened to
     * the Catalog's columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $table, string $search, int $limit, ?string $sortKey = null, bool $reverse = false): array
    {
        $vars = ['q' => $search === '' ? null : $search, 'n' => max(1, min(self::MAX_ROWS, $limit)), 'sortKey' => $sortKey, 'reverse' => $reverse];
        $data = $this->graphql(Catalog::query($table), $vars);
        return array_values(array_map(fn ($node) => Catalog::row($table, (array) $node), (array) (($data[$table] ?? [])['nodes'] ?? [])));
    }

    /**
     * One GraphQL call. Every failure becomes a sentence the owner can act on;
     * Shopify's own words are kept where they help (a missing scope names itself).
     *
     * @throws \RuntimeException
     */
    public function graphql(string $query, array $variables): array
    {
        $body = json_encode(['query' => $query, 'variables' => (object) $variables], JSON_UNESCAPED_SLASHES);
        $res = ($this->transport)('POST', $this->url(), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Shopify-Access-Token' => $this->token,
            'User-Agent' => 'BanimarkDesk/1.0 (+https://banimark.com)',
        ], $body, $this->timeout);
        $status = (int) ($res['status'] ?? 0);
        if ($status === 0) {
            throw new \RuntimeException('Shopify could not be reached'.(($res['error'] ?? '') !== '' ? ' ('.$res['error'].')' : '').'. Check the store name: '.$this->store.'.');
        }
        if ($status === 401) {
            throw new \RuntimeException('Shopify refused the access token. Open the app in your Shopify admin (Settings → Apps and sales channels → Develop apps), copy the Admin API access token again and paste it here.');
        }
        if ($status === 402) {
            throw new \RuntimeException('Shopify says this store is frozen or has a billing problem, so its API is paused.');
        }
        if ($status === 403) {
            throw new \RuntimeException('Shopify refused the request: the app is not allowed to read this. Give it the read_orders, read_customers and read_products scopes and install it again.');
        }
        if ($status === 404) {
            throw new \RuntimeException('There is no Shopify store at '.$this->store.'. The name is the one before .myshopify.com in your admin address.');
        }
        if ($status === 423) {
            throw new \RuntimeException('Shopify says this store is locked.');
        }
        if ($status === 429) {
            throw new \RuntimeException('Shopify is rate-limiting this store right now. Try again in a moment.');
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Shopify answered with something other than JSON (HTTP '.$status.').');
        }
        if (!empty($data['errors'])) {
            $errors = (array) $data['errors'];
            $first = (array) ($errors[0] ?? []);
            $code = (string) ((($first['extensions'] ?? [])['code']) ?? '');
            $message = (string) ($first['message'] ?? 'unknown error');
            if ($code === 'ACCESS_DENIED' || stripos($message, 'access denied') !== false || stripos($message, 'scope') !== false) {
                throw new \RuntimeException('Shopify refused: '.$message.' Give the app the read_orders, read_customers and read_products scopes in your Shopify admin, install it again, and paste the new token if it made one.');
            }
            if ($code === 'THROTTLED') {
                throw new \RuntimeException('Shopify is rate-limiting this store right now. Try again in a moment.');
            }
            throw new \RuntimeException('Shopify refused the query: '.$message);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Shopify answered HTTP '.$status.'.');
        }
        return (array) ($data['data'] ?? []);
    }
}
