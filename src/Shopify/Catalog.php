<?php

namespace Banimark\Shopify;

/**
 * A Shopify store, seen as a small database. Shopify is a hosted platform:
 * the owner cannot install the connector there, and there is no SQL to run.
 * What it has is the Admin GraphQL API, so the desk shows the store as three
 * tables - orders, customers, products - with plain column names, and a
 * tool is the same single SELECT it would be against a real database. The
 * Tool Builder's picker, the draftsman and the save-time validator cannot
 * tell the difference; Query translates the SELECT and Client sends it.
 *
 * Each column is: its type for the picker, the GraphQL search key it can be
 * looked up by (null = shown but not searchable), and how its value is read
 * out of the GraphQL answer (a dotted path, or a closure for the ones that
 * flatten a list). Only read scopes are ever asked for.
 */
final class Catalog
{
    public const TABLES = ['orders', 'customers', 'products'];

    /** The Admin API scopes a tool on each table needs. */
    public const SCOPES = ['orders' => 'read_orders', 'customers' => 'read_customers', 'products' => 'read_products'];

    /**
     * The scopes without which Shopify quietly hides part of the answer: an
     * app sees only the last 60 days of orders without read_all_orders, and
     * no stock levels without read_inventory. Asked for in the guide, reported
     * by "Test the connection", never fatal.
     */
    public const EXTRA_SCOPES = ['read_all_orders' => 'orders older than 60 days', 'read_inventory' => 'stock levels on products'];

    /** @return array<string, array<int, array{name: string, type: string}>> SchemaInspector::all()'s shape */
    public static function schema(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = array_values(array_map(fn ($c, $name) => ['name' => $name, 'type' => $c[0].($c[1] !== null ? ' (searchable)' : '')], self::columns($table), array_keys(self::columns($table))));
        }
        return $out;
    }

    /** The columns a table may be searched by, for error messages and the draftsman. @return string[] */
    public static function searchable(string $table): array
    {
        return array_keys(array_filter(self::columns($table), fn ($c) => $c[1] !== null));
    }

    /** The GraphQL search key for a column, or null when the column cannot be searched. */
    public static function searchKey(string $table, string $column): ?string
    {
        return self::columns($table)[$column][1] ?? null;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return isset(self::columns($table)[$column]);
    }

    /**
     * name => [type, search key|null, reader]. A reader is a dotted path into
     * the node, or a closure given the node.
     *
     * @return array<string, array{0: string, 1: ?string, 2: string|\Closure}>
     */
    public static function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $money = fn (string $path) => fn (array $n) => self::at($n, $path.'.amount');
        $status = fn (string $path) => fn (array $n) => strtolower(str_replace('_', ' ', (string) self::at($n, $path)));
        $lines = fn (string $path) => fn (array $n) => implode(', ', array_filter(array_map('strval', (array) self::at($n, $path))));
        $tags = fn (array $n) => implode(', ', array_map('strval', (array) ($n['tags'] ?? [])));
        $cache[$table] = match ($table) {
            'orders' => [
                'order_number' => ['string', 'name', 'name'],
                'email' => ['string', 'email', 'email'],
                'phone' => ['string', null, 'phone'],
                'customer_name' => ['string', null, 'customer.displayName'],
                'created_at' => ['datetime', null, 'createdAt'],
                'updated_at' => ['datetime', null, 'updatedAt'],
                'financial_status' => ['string', 'financial_status', $status('displayFinancialStatus')],
                'fulfillment_status' => ['string', 'fulfillment_status', $status('displayFulfillmentStatus')],
                'total' => ['number', null, $money('totalPriceSet.shopMoney')],
                'currency' => ['string', null, 'totalPriceSet.shopMoney.currencyCode'],
                'items' => ['string', null, fn (array $n) => implode('; ', array_map(
                    fn ($i) => ((int) ($i['quantity'] ?? 1)).' × '.(string) ($i['title'] ?? '').(($i['sku'] ?? '') !== '' && $i['sku'] !== null ? ' ['.$i['sku'].']' : ''),
                    (array) self::at($n, 'lineItems.nodes')))],
                'shipping_address' => ['string', null, $lines('shippingAddress.formatted')],
                'tracking_company' => ['string', null, fn (array $n) => self::tracking($n, 'company')],
                'tracking_number' => ['string', null, fn (array $n) => self::tracking($n, 'number')],
                'tracking_url' => ['string', null, fn (array $n) => self::tracking($n, 'url')],
                'shipment_status' => ['string', null, fn (array $n) => implode(', ', array_unique(array_filter(array_map(
                    fn ($f) => strtolower(str_replace('_', ' ', (string) ($f['displayStatus'] ?? ''))), (array) ($n['fulfillments'] ?? [])))))],
                'estimated_delivery' => ['datetime', null, fn (array $n) => implode(', ', array_unique(array_filter(array_map(fn ($f) => (string) ($f['estimatedDeliveryAt'] ?? ''), (array) ($n['fulfillments'] ?? [])))))],
                'shipping_method' => ['string', null, 'shippingLine.title'],
                'return_status' => ['string', null, $status('returnStatus')],
                'refunded' => ['number', null, $money('totalRefundedSet.shopMoney')],
                'discount_code' => ['string', null, fn (array $n) => implode(', ', array_map('strval', (array) ($n['discountCodes'] ?? [])))],
                'cancelled_at' => ['datetime', null, 'cancelledAt'],
                'note' => ['string', null, 'note'],
                'tags' => ['string', 'tag', $tags],
            ],
            'customers' => [
                'email' => ['string', 'email', 'email'],
                'first_name' => ['string', 'first_name', 'firstName'],
                'last_name' => ['string', 'last_name', 'lastName'],
                'phone' => ['string', 'phone', 'phone'],
                'orders_count' => ['number', null, fn (array $n) => (int) ($n['numberOfOrders'] ?? 0)],
                'total_spent' => ['number', null, $money('amountSpent')],
                'currency' => ['string', null, 'amountSpent.currencyCode'],
                'created_at' => ['datetime', null, 'createdAt'],
                'updated_at' => ['datetime', null, 'updatedAt'],
                'last_order' => ['string', null, 'lastOrder.name'],
                'last_order_date' => ['datetime', null, 'lastOrder.createdAt'],
                'address' => ['string', null, $lines('defaultAddress.formatted')],
                'tags' => ['string', 'tag', $tags],
            ],
            'products' => [
                'title' => ['string', 'title', 'title'],
                'handle' => ['string', 'handle', 'handle'],
                'status' => ['string', 'status', $status('status')],
                'vendor' => ['string', 'vendor', 'vendor'],
                'product_type' => ['string', 'product_type', 'productType'],
                'sku' => ['string', 'sku', fn (array $n) => implode(', ', array_filter(array_map(fn ($v) => (string) ($v['sku'] ?? ''), (array) self::at($n, 'variants.nodes'))))],
                'price' => ['number', null, $money('priceRangeV2.minVariantPrice')],
                'currency' => ['string', null, 'priceRangeV2.minVariantPrice.currencyCode'],
                'available' => ['number', null, fn (array $n) => (int) ($n['totalInventory'] ?? 0)],
                'in_stock' => ['string', null, fn (array $n) => (int) ($n['totalInventory'] ?? 0) > 0
                    || array_filter((array) self::at($n, 'variants.nodes'), fn ($v) => !empty($v['availableForSale'])) !== [] ? 'yes' : 'no'],
                'description' => ['string', null, 'description'],
                'url' => ['string', null, 'onlineStoreUrl'],
                'image_url' => ['string', null, 'featuredMedia.preview.image.url'],
                'created_at' => ['datetime', null, 'createdAt'],
                'updated_at' => ['datetime', null, 'updatedAt'],
                'tags' => ['string', 'tag', $tags],
                'variants' => ['string', null, fn (array $n) => implode('; ', array_map(
                    fn ($v) => (string) ($v['title'] ?? '').' - '.(string) ($v['price'] ?? '').(isset($v['inventoryQuantity']) ? ' ('.(int) $v['inventoryQuantity'].' in stock)' : (array_key_exists('availableForSale', (array) $v) ? ($v['availableForSale'] ? ' (in stock)' : ' (sold out)') : '')),
                    (array) self::at($n, 'variants.nodes')))],
            ],
            default => [],
        };
        return $cache[$table];
    }

    /** The GraphQL document for one table: the connection and the fields every column reads from. */
    public static function query(string $table): string
    {
        $sort = ['orders' => 'OrderSortKeys', 'customers' => 'CustomerSortKeys', 'products' => 'ProductSortKeys'][$table] ?? 'OrderSortKeys';
        $fields = match ($table) {
            'orders' => 'name email phone createdAt updatedAt cancelledAt note tags discountCodes returnStatus displayFinancialStatus displayFulfillmentStatus
                totalPriceSet { shopMoney { amount currencyCode } } totalRefundedSet { shopMoney { amount } } customer { displayName } shippingAddress { formatted(withName: false) }
                shippingLine { title } lineItems(first: 25) { nodes { title quantity sku } }
                fulfillments(first: 5) { displayStatus estimatedDeliveryAt trackingInfo(first: 5) { company number url } }',
            'customers' => 'email firstName lastName phone numberOfOrders createdAt updatedAt tags amountSpent { amount currencyCode } defaultAddress { formatted(withName: false) } lastOrder { name createdAt }',
            'products' => 'title handle status vendor productType description(truncateAt: 400) onlineStoreUrl totalInventory tags createdAt updatedAt
                featuredMedia { preview { image { url } } } priceRangeV2 { minVariantPrice { amount currencyCode } } variants(first: 25) { nodes { title sku price inventoryQuantity availableForSale } }',
            default => throw new \InvalidArgumentException('No such table'),
        };
        return 'query BanimarkLookup($q: String, $n: Int!, $sortKey: '.$sort.', $reverse: Boolean) { '
            .$table.'(first: $n, query: $q, sortKey: $sortKey, reverse: $reverse) { nodes { '.$fields.' } } }';
    }

    /** The GraphQL sort key for an ORDER BY column, or null when the table cannot be sorted by it. */
    public static function sortKey(string $table, string $column): ?string
    {
        return match ($column) {
            'created_at' => 'CREATED_AT',
            'updated_at' => 'UPDATED_AT',
            'order_number' => $table === 'orders' ? 'ORDER_NUMBER' : null,
            'title' => $table === 'products' ? 'TITLE' : null,
            'last_name' => $table === 'customers' ? 'NAME' : null,
            default => null,
        };
    }

    /** One GraphQL node, flattened to the table's columns. @return array<string, mixed> */
    public static function row(string $table, array $node): array
    {
        $out = [];
        foreach (self::columns($table) as $name => [, , $reader]) {
            $out[$name] = $reader instanceof \Closure ? $reader($node) : self::at($node, (string) $reader);
        }
        return $out;
    }

    private static function tracking(array $node, string $key): string
    {
        $out = [];
        foreach ((array) ($node['fulfillments'] ?? []) as $f) {
            foreach ((array) ($f['trackingInfo'] ?? []) as $t) {
                if (($t[$key] ?? '') !== '' && $t[$key] !== null) {
                    $out[] = (string) $t[$key];
                }
            }
        }
        return implode(', ', array_unique($out));
    }

    /** @return mixed */
    private static function at(array $node, string $path)
    {
        $cur = $node;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
