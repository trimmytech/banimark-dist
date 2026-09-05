# Banimark

**A self-hosted AI support desk for any PHP app.** It runs on your server, uses
your own AI key, and your conversations and customer data never leave your
infrastructure.

- Answers from **your** database — you define read-only lookups in the panel; the
  AI can never widen their scope
- Hands off to a human when it should, and emails your team
- Works as a **Laravel package** or **standalone** on any PHP stack
- MySQL/MariaDB or SQLite; no queue, no Redis, no build step

Requires PHP 8.1+ with `ext-curl`, `ext-json` and PDO.

---

## Install — Laravel

```bash
composer require banimark/banimark
php artisan banimark:install
```

`banimark:install` publishes `config/banimark.php`, creates the `banimark_*`
tables, generates an identity secret in your `.env`, and asks for your first
admin account. It is safe to re-run at any time.

Then open **`/banimark/admin`**, enter your licence key, and add an AI provider.

Embed the widget in your layout, before `</body>`:

```blade
<script src="{{ url('banimark/widget.js') }}" defer></script>
```

For signed-in users, mint a token server-side so the AI can look up *their*
records — and only theirs:

```php
$token = \Banimark\Identity\VisitorToken::mint(
    ['user_id' => auth()->id()],
    config('banimark.identity_secret')
);
```

```blade
<script src="{{ url('banimark/widget.js') }}" data-token="{{ $token }}" defer></script>
```

Check an install at any time with:

```bash
php artisan banimark:doctor
```

---

## Install — standalone (no framework)

For plain PHP, CodeIgniter, WordPress, or any other stack.

```bash
composer require banimark/banimark
```

Create one file in your web root:

```php
<?php // banimark.php
require __DIR__.'/vendor/autoload.php';
\Banimark\Standalone\App::run(__DIR__.'/banimark.config.php');
```

Open **`banimark.php/install`** in a browser. The installer checks your server,
sets up the database (MySQL or SQLite), creates your admin account and an
optional first AI provider, then locks itself.

It serves everything from that one file:

| Path | What it is |
|---|---|
| `banimark.php/admin` | the admin panel |
| `banimark.php/widget.js` | the chat widget |
| `banimark.php/chat` | the chat endpoint |

Embed it with:

```html
<script src="/banimark.php/widget.js" defer></script>
```

---

## Your licence

Enter your key under **License** in the admin panel, or set it in `.env`:

```
BANIMARK_LICENSE_KEY=BM-XXXX-XXXX-XXXX-XXXX
```

Activation needs one check with our servers; after that your licence is valid
offline for 14 days at a time, so a network problem never interrupts you.

**Your chat widget always keeps working.** If a licence lapses, only the admin
panel locks — visitors carry on being served.

---

## Building a tool

Tools are how the AI answers from your own data. In **Tools**, give it a name, a
description the AI reads to decide when to call it, the parameters it may pass,
and a `SELECT` statement:

```sql
SELECT reference, status, total, delivered_at
FROM orders
WHERE reference = :reference AND user_id = :_user_id
```

- `:reference` is supplied by the AI, always as a **bound** parameter
- `:_user_id` comes from the **signed visitor token**, never from the AI — so a
  visitor can only ever see their own rows
- Only the columns you whitelist are returned, and the row cap is yours

Tools are validated when you save: `SELECT` only, no semicolons, comments, or
write statements, and every placeholder must be declared. If the identity
context a tool needs is missing, it refuses to run rather than running unscoped.

---

## Settings worth knowing

| Where | What |
|---|---|
| **AI Providers** | Gemini, OpenAI, Anthropic, DeepSeek, SiliconFlow, or any OpenAI-compatible gateway via a base URL |
| **Rules** | extra instructions added to every conversation |
| **Notifications** | SMTP for escalation alerts and visitor follow-ups |
| **Widget** | colour, position, greeting, guest details, reply polling |
| **Staff** | Banimark's own logins, separate from your app's users |

---

## Upgrading

```bash
composer update banimark/banimark
php artisan banimark:install     # or reload the standalone installer once
```

Both are idempotent and apply any new database columns. See `CHANGELOG.md` for
version notes.

---

## Support

Run `php artisan banimark:doctor` and include its output with any request.

Banimark is commercial software. Your licence covers updates and support for its
term.
