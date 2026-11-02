# The Banimark connector

A connector is a small program the owner installs on **their own** website so
the assistant can look things up in their database without Banimark ever
holding a database password. Banimark ships one for plain PHP, WordPress,
Laravel and Node; any language can implement the contract below, and
`php build/check-connector.php <url> <secret>` proves an implementation.

The owner never writes a query. They build tools in Banimark as on a
self-hosted desk (pick a table, tick columns, add conditions); the desk sends
the finished SELECT to the connector, signed, and the connector runs it with
the values bound. The connector is read-only, caps rows and time, and strips
columns that look like secrets even if a query names them.

## One endpoint

```
POST <connector url>
Authorization: Bearer <token>
Content-Type: application/json
X-Banimark-Connector: 1

{"action": "health" | "schema" | "query", ...}
```

A `GET` of the same address shows a small page confirming the connector is
installed, with its address to paste into Banimark. It shows no data.

### The token

A JWT, `HS256`, signed with the shared secret (48 hex characters, minted by
the desk and baked into the download). Claims:

| claim | value |
|---|---|
| `iss` | `banimark` |
| `aud` | `banimark-connector` |
| `act` | the action in the body: `health`, `schema` or `query` |
| `iat`, `exp` | issued at, and 60 seconds later |
| `jti` | a random nonce |
| `bh` | hex SHA-256 of the exact request body |

A connector must refuse (`{"ok": false, "error": "..."}`) when the signature
is wrong, the token is expired or `bh` differs from the body it received
(HTTP 401), and when `act` differs from the body's `action` (HTTP 400: the
token is valid, the body is not). Allow 30 seconds of clock skew on `iat`.

### Actions

**health** → `{"ok": true, "connector": "banimark", "version": 1, "platform": "php", "dialect": "mysql", "tables": 14}`

`dialect` is `mysql`, `pgsql` or `sqlite`. `platform` is free text
(`php`, `wordpress`, `laravel`, `node`, ...).

**schema** → `{"ok": true, "tables": {"orders": [{"name": "id", "type": "int"}, ...], ...}}`

Every table the owner's app keeps, minus plumbing (migrations, sessions,
caches, queues, Banimark's own `banimark_*` and `hq_*` tables) and minus
sensitive columns (below). Column types are the engine's own words,
lowercased.

**query** `{"action": "query", "sql": "SELECT ... WHERE id = :id", "bindings": {"id": 5}, "max_rows": 10}`
→ `{"ok": true, "rows": [{"id": 5, "status": "shipped"}], "count": 1}`

Named placeholders `:name` (a `:name` inside a string literal, or a `::type`
cast, is not one); the connector binds them (never interpolates), ignores
bindings the statement does not use, and binds a missing one as NULL. Rows
are capped at `min(max_rows, 100)`, `max_rows` defaulting to 10; sensitive
columns are removed from every row.

### What a connector must refuse

* A statement that is not a single `SELECT`: must start with `SELECT`, no `;`,
  no comments (`--`, `#`, `/*`), none of these words outside string literals:
  `insert update delete drop alter truncate replace create grant revoke rename
  call handler do into outfile dumpfile load_file load benchmark sleep get_lock
  release_lock union information_schema performance_schema mysql sys set
  prepare execute deallocate lock unlock "for update" procedure pg_sleep
  pg_catalog`. Answer HTTP 422.
* More than 5 seconds of query time where the engine can be told
  (`MAX_EXECUTION_TIME` on MySQL, `statement_timeout` on PostgreSQL).
* Anything on `GET`/`HEAD` other than the status page (405 for any other
  method); any action not listed (400).

### Sensitive columns

Never listed, never returned. The rule is this regular expression, matched
case-insensitively anywhere in the column name (so `user_pass`,
`remember_token`, `stripe_secret_key` and `card_no` are all caught):

```
/pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i
```

Copy it verbatim; `Banimark\Connector\Protocol::SENSITIVE` is the canonical
copy and the checker tests a row with `password` and `api_key` columns.

### Errors

Always JSON: `{"ok": false, "error": "a short reason"}`. The database's own
error text is fine in `error` for `query` (only the desk's staff ever see it),
but never credentials.

| status | when |
|---|---|
| 400 | not JSON, no action |
| 401 | missing, bad or expired token |
| 422 | the statement was refused |
| 500 | the database failed |

## Installing

Each download is generated with the desk's secret already inside; the owner
installs it and pastes back the address the status page shows.

* **Plain PHP**: upload `banimark-connector.php` next to the site's
  `index.php`. It finds a WordPress `wp-config.php` by itself; otherwise the
  four database lines at the top of the file are filled in once.
* **WordPress**: Plugins → Add new → Upload plugin → the zip → Activate.
  Settings → Banimark connector shows the address.
* **Laravel**: copy the controller into `app/Http/Controllers/`, add one line
  to `routes/api.php`. The address is `/api/banimark-connector`.
* **Node**: one file, no package. `node banimark-connector.js` serves it; or
  `app.post('/banimark-connector', require('./banimark-connector').express())`
  inside an Express app. Needs the database driver the app already has
  (`mysql2`, `pg`) or Node's built-in `node:sqlite`.

## Checking an implementation

```
php build/check-connector.php https://shop.example/banimark-connector.php <secret>
```

Runs health, schema, a query with a binding, a refused statement, a bad
signature, an expired token and a tampered body, and prints a line per check.
Exit code 1 if any fails.
