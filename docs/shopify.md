# Shopify as a data connection

A Shopify store is hosted by Shopify: nothing can be installed on it, so the
connector does not apply. What a store has is the Admin GraphQL API, and that
is what the desk uses. The owner makes a custom app in their Shopify admin
with three read scopes (`read_orders`, `read_customers`, `read_products`),
pastes the store name and the app's Admin API access token on the Tools page,
and the store becomes the desk's database.

## The store as three tables

`Banimark\Shopify\Catalog` shows the store the way `SchemaInspector` shows a
database, so the Tool Builder, "Describe it, and it builds it" and the
save-time validator cannot tell the difference:

| table | searchable by | also shown |
|---|---|---|
| `orders` | order_number, email, financial_status, fulfillment_status, tags | phone, customer_name, created_at, updated_at, total, currency, items, shipping_address, tracking_company, tracking_number, tracking_url, cancelled_at, note |
| `customers` | email, first_name, last_name, phone, tags | orders_count, total_spent, currency, created_at, updated_at, address |
| `products` | title, handle, status, vendor, product_type, sku, tags | price, currency, available, description, url, created_at, updated_at, variants |

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
three read scopes the token is missing.

## Scoping

A storefront visitor is usually anonymous, so the draftsman and the builder's
hint both say what Shopify's own order page does: ask for the order number
**and** the email on the order, both in the WHERE. A desk whose widget signs
an `email` claim may scope with `:_email` instead, exactly like any identity
placeholder.

## Settings

`tools_db_enabled = shopify`, `shopify_store` (normalised to
`name.myshopify.com`), `shopify_token` (write-only in the panel; a blank box
keeps the stored one). `DataSource::isShopify()` is true when both are set;
`DataSource::ownerData()` is true for a connector or a store, and is what lets
a hosted desk build database tools.
