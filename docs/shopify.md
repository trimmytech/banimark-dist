# Shopify as a data connection

A Shopify store is hosted by Shopify: nothing can be installed on it, so the
connector does not apply. What a store has is the Admin GraphQL API, and that
is what the desk uses. The owner makes an app in Shopify's Dev Dashboard
(dev.shopify.com, signed in as the store owner), with five read scopes (`read_orders`, `read_all_orders`, `read_customers`,
`read_products`, `read_inventory` - the last two of the five because Shopify
otherwise shows only the last 60 days of orders and no stock levels), pastes
the Client ID and secret first, then installs. Shopify sends the owner back to
the desk's Tools page with `?shop=…&hmac=…`; `Shopify\Install::arrival()`
verifies the hmac with the saved secret, saves the store, switches the mode and
runs the connection test, so the install ends connected.
releases a version, installs it on the store from the app's Home page (if
the dashboard asks for an App URL or a redirect URL, the desk's own Tools
page address does - Shopify only opens it after the install; "Use legacy
install flow" and "Embed app" stay off), and
pastes the store name plus the app's **Client ID and Client secret** (its
Settings page) on the Tools page. A Dev Dashboard app never shows an access
token: `Shopify\Client::withCredentials()` mints one with the client
credentials grant (`POST https://<store>/admin/oauth/access_token`,
form-encoded `grant_type=client_credentials`), keeps it for the 24 hours it
lives (in memory and in a 0600 file under the temp dir), renews it ten
minutes before expiry, and mints once more on a 401. An app made the older
way in the Shopify admin (Settings → Apps → Develop apps) has a permanent
`shpat_` token instead; the card takes that under "made the app in your
Shopify admin instead". The store then becomes the desk's database.

## The store as three tables

`Banimark\Shopify\Catalog` shows the store the way `SchemaInspector` shows a
database, so the Tool Builder, "Describe it, and it builds it" and the
save-time validator cannot tell the difference:

| table | searchable by | also shown |
|---|---|---|
| `orders` | order_number, email, financial_status, fulfillment_status, tags | phone, customer_name, created_at, updated_at, total, currency, items, shipping_address, tracking_company, tracking_number, tracking_url, shipment_status, estimated_delivery, shipping_method, return_status, refunded, discount_code, cancelled_at, note |
| `customers` | email, first_name, last_name, phone, tags | orders_count, total_spent, currency, created_at, updated_at, last_order, last_order_date, address |
| `products` | title, handle, status, vendor, product_type, sku, tags | price, currency, available, in_stock, description, url, image_url, created_at, updated_at, variants |

A column marked *searchable* maps to a key of Shopify's search syntax
(`name:`, `email:`, `tag:` ...). The others are read from the GraphQL answer
and flattened: `items` is `2 × Blue shirt [SH-B]; 1 × Hat`, `tracking_url`
is the first fulfillment's tracking link, `total` is the shop-money amount.

## The SELECT a tool may be

A Shopify tool is the same `sql` kind of tool, through the same
`SqlToolValidator` (one SELECT, bound placeholders, every placeholder
declared), and then through `Banimark\Shopify\Query`, which accepts:

```sql
SELECT order_number, fulfillment_status, tracking_url
FROM orders
WHERE order_number = :order_number AND email = :email
  AND (:status IS NULL OR fulfillment_status = :status)
ORDER BY created_at DESC LIMIT 10
```

One table. Conditions joined by AND, each `column = :value` (or a quoted
literal) or `column LIKE :value` ("contains", including the builder's
`LIKE CONCAT('%', :v, '%')` and PostgreSQL's `'%' || :v || '%'`), only on a
searchable column; the optional idiom `(:x IS NULL OR column = :x)`;
`ORDER BY created_at | updated_at` (plus `order_number` on orders and `title`
on products); `LIMIT` up to 50. A JOIN, a subquery, `OR`, `<`, a function or
an unsearchable column is refused with a sentence saying what Shopify can do
instead - at save time, in "Try it", and in the draftsman's retry.

## How it runs

The conditions become one search string (`name:"1001" email:"ada@example.com"`)
sent with `Catalog::query()` for the table (`first: n`, newest orders first
unless the SELECT says otherwise). **Every condition is then checked again on
the rows that came back**, case-insensitively, a leading `#` ignored on order
numbers: Shopify's search is how few rows are fetched, never what decides who
sees what. A required value the tool did not get fetches nothing at all.

The token travels only as `X-Shopify-Access-Token` to
`https://<store>.myshopify.com/admin/api/<version>/graphql.json`, where the
store name must be a myshopify.com name (`Client::store()` refuses anything
else, so no other host is ever called). Shopify's refusals become sentences:
a 401 says to copy the token again, an `ACCESS_DENIED` names the missing
scope, a throttle says to try again shortly. "Test the connection" reads
`shop` and `currentAppInstallation.accessScopes` and reports which of the
three read scopes the token is missing, and warns when `read_all_orders` or
`read_inventory` is.

## Scoping

A storefront visitor is usually anonymous, so the draftsman and the builder's
hint both say what Shopify's own order page does: ask for the order number
**and** the email on the order, both in the WHERE. A desk whose widget signs
an `email` claim may scope with `:_email` instead, exactly like any identity
placeholder.

## Settings

`tools_db_enabled = shopify`, `shopify_store` (normalised to
`name.myshopify.com`), `shopify_client_id` + `shopify_client_secret` (the
secret write-only in the panel; a blank box keeps the stored one; saving
forgets the minted token), or `shopify_token` for an admin app (also
write-only). Credentials win when both are set. `DataSource::isShopify()` is true when both are set;
`DataSource::ownerData()` is true for a connector or a store, and is what lets
a hosted desk build database tools.
