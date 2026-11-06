<?php

namespace Banimark\Shopify;

/**
 * The desk's side of a Shopify store: one Admin GraphQL endpoint, read
 * scopes only, and one of two credentials the owner typed once in the panel
 * (never rendered back, never shown to the model):
 *
 *  - the Client ID and Client secret of an app made in Shopify's Dev
 *    Dashboard, the way Shopify now makes apps. Those never show a token:
 *    the desk mints one at the store's token endpoint (the client
 *    credentials grant), keeps it for the 24 hours it lives, and mints
 *    again when it expires or the store refuses it;
 *  - the Admin API access token (shpat_…) of an app made the older way in
 *    the Shopify admin, which is permanent.
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

    /** A minted token is renewed this long before Shopify would expire it. */
    public const RENEW_MARGIN = 600;

    /** @var callable(string, string, array, ?string, int): array{status:int, body:string, error:string} */
    private $transport;
    private string $clientId = '';
    private string $clientSecret = '';
    /** @var array<string, array{token: string, expires: int}> minted tokens, per store+app, for this process */
    private static array $minted = [];

    public function __construct(private string $store, private string $token, ?callable $transport = null, private int $timeout = self::TIMEOUT)
    {
        $this->transport = $transport ?? \Banimark\Tools\HttpTool::transport();
    }

    /** A client for a Dev Dashboard app: the token is minted from the Client ID and secret and renewed on its own. */
    public static function withCredentials(string $store, string $clientId, string $clientSecret, ?callable $transport = null, int $timeout = self::TIMEOUT): self
    {
        $c = new self($store, '', $transport, $timeout);
        $c->clientId = trim($clientId);
        $c->clientSecret = trim($clientSecret);
        return $c;
    }

    public function usesCredentials(): bool
    {
        return $this->token === '' && $this->clientId !== '';
    }

    /** The token endpoint of the client credentials grant. */
    public function tokenUrl(): string
    {
        return 'https://'.$this->store.'/admin/oauth/access_token';
    }

    /**
     * The access token to send: the permanent one the owner typed, or the
     * minted one - from memory, then from the small cache file on disk (so
     * every request of the day does not mint anew), else freshly minted.
     *
     * @throws \RuntimeException when Shopify will not mint one
     */
    public function token(bool $fresh = false): string
    {
        if (!$this->usesCredentials()) {
            return $this->token;
        }
        $key = md5($this->store.'|'.$this->clientId);
        $file = self::cacheDir().'/'.$key.'.json';
        if (!$fresh) {
            $have = self::$minted[$key] ?? (is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: null) : null);
            if (is_array($have) && (string) ($have['token'] ?? '') !== '' && (int) ($have['expires'] ?? 0) > time() + self::RENEW_MARGIN) {
                self::$minted[$key] = $have;
                return (string) $have['token'];
            }
        }
        $minted = $this->mint();
        self::$minted[$key] = $minted;
        if (is_dir(self::cacheDir()) || @mkdir(self::cacheDir(), 0700, true)) {
            @file_put_contents($file, json_encode($minted));
            @chmod($file, 0600);
        }
        return $minted['token'];
    }

    /** Forget a minted token - the credentials changed, or Shopify refused it. */
    public static function forget(string $store, string $clientId): void
    {
        $key = md5($store.'|'.$clientId);
        unset(self::$minted[$key]);
        $file = self::cacheDir().'/'.$key.'.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function cacheDir(): string
    {
        return sys_get_temp_dir().'/banimark-shopify';
    }

    /**
     * The client credentials grant: Client ID + secret -> a 24-hour token.
     * Shopify's refusals are turned into the thing to do next.
     *
     * @return array{token: string, expires: int}
     * @throws \RuntimeException
     */
    private function mint(): array
    {
        if ($this->clientSecret === '') {
            throw new \RuntimeException('The Client secret is missing. Copy it from the app\'s Settings page in the Dev Dashboard and paste it here.');
        }
        $res = ($this->transport)('POST', $this->tokenUrl(), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'User-Agent' => 'BanimarkDesk/1.0 (+https://banimark.com)',
        ], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]), $this->timeout);
        $status = (int) ($res['status'] ?? 0);
        if ($status === 0) {
            throw new \RuntimeException('Shopify could not be reached'.(($res['error'] ?? '') !== '' ? ' ('.$res['error'].')' : '').'. Check the store name: '.$this->store.'.');
        }
        if ($status === 404) {
            throw new \RuntimeException('There is no Shopify store at '.$this->store.'. The name is the one before .myshopify.com in your admin address.');
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($status >= 200 && $status < 300 && $token !== '') {
            return ['token' => $token, 'expires' => time() + max(60, (int) ($data['expires_in'] ?? 86400))];
        }
        $code = is_array($data) ? (string) ($data['error'] ?? '') : '';
        $why = is_array($data) ? (string) ($data['error_description'] ?? '') : '';
        if ($code === 'shop_not_permitted' || stripos($why, 'not permitted') !== false || stripos($why, 'organization') !== false) {
            throw new \RuntimeException('Shopify says this app may not use this store: the app and the store must belong to the same Dev Dashboard organisation. Sign in to dev.shopify.com with the account that owns the store, make the app there, and install it on the store from the app\'s Home page.');
        }
        if ($code === 'invalid_client' || $status === 401) {
            throw new \RuntimeException('Shopify refused the Client ID and Client secret. Copy both again from the app\'s Settings page in the Dev Dashboard (dev.shopify.com) - and paste them here, not an access token.');
        }
        if (stripos($why, 'install') !== false || $code === 'invalid_request' || $status === 400) {
            throw new \RuntimeException('Shopify would not issue a token'.($why !== '' ? ' ('.$why.')' : '').'. Is the app installed on this store? In the Dev Dashboard open the app, release a version with the read scopes, then press Install on its Home page and approve it on the store.');
        }
        throw new \RuntimeException('Shopify would not issue a token (HTTP '.$status.($code !== '' ? ', '.$code : '').').');
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
        $send = fn (string $token) => ($this->transport)('POST', $this->url(), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Shopify-Access-Token' => $token,
            'User-Agent' => 'BanimarkDesk/1.0 (+https://banimark.com)',
        ], $body, $this->timeout);
        $res = $send($this->token());
        $status = (int) ($res['status'] ?? 0);
        if ($status === 401 && $this->usesCredentials()) {
            // a minted token Shopify no longer honours (revoked, or the clock drifted): mint once more
            $res = $send($this->token(true));
            $status = (int) ($res['status'] ?? 0);
        }
        if ($status === 0) {
            throw new \RuntimeException('Shopify could not be reached'.(($res['error'] ?? '') !== '' ? ' ('.$res['error'].')' : '').'. Check the store name: '.$this->store.'.');
        }
        if ($status === 401) {
            throw new \RuntimeException($this->usesCredentials()
                ? 'Shopify refused the token it had just issued for this app. Check that the app is installed on this store in the Dev Dashboard, then try again.'
                : 'Shopify refused the access token. That box takes the Admin API access token (it starts with shpat_) of an app made in your Shopify admin - not a Client ID or Client secret. For an app made in the Dev Dashboard, paste its Client ID and Client secret in the boxes above instead.');
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
