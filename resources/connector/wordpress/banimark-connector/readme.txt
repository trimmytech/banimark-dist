=== Banimark Connector ===
Contributors: banimark
Tags: banimark, assistant, connector, database
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT

Lets the Banimark assistant for your desk look things up in this site's database, read-only, without Banimark ever holding a database password.

== Description ==

The desk you downloaded this plugin from signs every request with a secret that is already inside the plugin. The connector:

* answers only requests signed by your desk; anything else gets a 401;
* runs only a single SELECT, with values bound, capped at 100 rows and 5 seconds; never writes;
* never lists or returns columns that look like secrets (password, token, card number ...), whatever the query asks for;
* leaves WordPress' own plumbing tables (options, meta, transients ...) out.

The address is /wp-json/banimark/v1/connector. Settings -> Banimark connector shows the exact address to paste into Banimark -> Tools -> "Where your data lives". That page, and a GET of the address, show no data.

== Installation ==

1. Plugins -> Add New -> Upload Plugin -> choose the zip -> Install Now -> Activate.
2. Settings -> Banimark connector: copy the address.
3. In Banimark -> Tools -> "Where your data lives", paste the address and test the connection.

If the test says "no token", your host is dropping the Authorization header before it reaches PHP; ask them to pass it through.

== Changelog ==

= 1.0.0 =
* First release: health, schema and query actions; status page; Settings page.
