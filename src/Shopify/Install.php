<?php

namespace Banimark\Shopify;

/**
 * Shopify sends the owner back to the desk's Tools page once the app is
 * installed on the store: ?shop=name.myshopify.com&hmac=…&timestamp=…
 * (the App URL the guide told them to give). The hmac is HMAC-SHA256 over
 * the other parameters with the app's Client secret, so when the secret is
 * already saved here the arrival proves which store the app now sits on:
 * the store name is saved, the mode is switched to Shopify, and the
 * connection is tested on the spot - the owner pastes nothing. Without the
 * secret, the store name is at least put in the box for them.
 */
final class Install
{
    /**
     * @param array    $query     the request's query string
     * @param array    $settings  the desk's settings
     * @param callable $set       fn(string $key, string $value): void - persists a setting
     * @param \PDO     $own       the desk's own database (DataSource::check needs one)
     * @return array{flash: string, settings: array} the message to show (HTML, '' when this is not an arrival) and the settings the data card should draw
     */
    public static function arrival(array $query, array $settings, callable $set, \PDO $own, ?callable $transport = null): array
    {
        $shop = Client::store((string) ($query['shop'] ?? ''));
        if ($shop === '' || !isset($query['hmac'])) {
            return ['flash' => '', 'settings' => $settings];
        }
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        $secret = trim((string) ($settings['shopify_client_secret'] ?? ''));
        $shown = ['tools_db_enabled' => \Banimark\Storage\DataSource::MODE_SHOPIFY, 'shopify_store' => $shop] + $settings;
        if ($secret === '') {
            return ['settings' => $shown, 'flash' => '<div class="flash-ok" data-flash data-shopify-arrival>Shopify installed the app on <b>'.$e($shop).'</b>. '
                .'Now paste the app\'s Client ID and Client secret below (its Settings page in the Dev Dashboard), press Save, then Test the connection.</div>'];
        }
        if (!self::verify($query, $secret)) {
            return ['settings' => $shown, 'flash' => '<div class="flash-err" data-flash data-shopify-arrival>Shopify sent you back from <b>'.$e($shop).'</b>, but its signature does not match the Client secret saved here. '
                .'Copy the Client secret again from the app\'s Settings page, paste it below, Save, and press Install in the Dev Dashboard once more.</div>'];
        }
        $set('tools_db_enabled', \Banimark\Storage\DataSource::MODE_SHOPIFY);
        $set('shopify_store', $shop);
        Client::forget($shop, trim((string) ($settings['shopify_client_id'] ?? '')));
        $now = ['tools_db_enabled' => \Banimark\Storage\DataSource::MODE_SHOPIFY, 'shopify_store' => $shop] + $settings;
        \Banimark\Storage\DataSource::forget();
        $r = \Banimark\Storage\DataSource::check($now, $own, $transport);
        return ['settings' => $now, 'flash' => '<div class="'.($r['ok'] ? 'flash-ok' : 'flash-err').'" data-flash data-shopify-arrival>Shopify installed the app on <b>'.$e($shop).'</b>'
            .($r['ok'] ? ' and the desk is connected to it. ' : ', but the connection does not work yet. ').$e($r['message']).'</div>'];
    }

    /**
     * Shopify's HMAC rule: every parameter but hmac (and signature), keys
     * sorted, key=value joined by &, with & % and = escaped inside, signed
     * with the Client secret as hex.
     */
    public static function verify(array $query, string $secret): bool
    {
        $given = (string) ($query['hmac'] ?? '');
        if ($given === '' || $secret === '') {
            return false;
        }
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $parts = [];
        foreach ($query as $k => $v) {
            $k = str_replace(['%', '&', '='], ['%25', '%26', '%3D'], (string) $k);
            $v = str_replace(['%', '&'], ['%25', '%26'], is_array($v) ? implode(',', $v) : (string) $v);
            $parts[] = $k.'='.$v;
        }
        return hash_equals(hash_hmac('sha256', implode('&', $parts), $secret), strtolower($given));
    }
}
