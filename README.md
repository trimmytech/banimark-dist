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

**Free trial.** A fresh install asks HQ for a trial automatically (or from
*License → Start free trial*). Your vendor sets the length; one trial per site.
When it ends the admin panel locks until a purchased key is entered — the chat
widget keeps working. Once active, the License page shows plan, site, modules,
dates and a *Re-check with HQ now* button.

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

**Tools** are read-only lookups the assistant can run against *your* database —
"find this customer's orders", "check a ticket status". Three steps, no SQL:

1. **Name it** and describe it in plain words (the AI reads this to know when to use it).
2. **What should the AI ask the customer for?** — add as many items as you need
   (order number, date, product…). Tick *required* where it must have the value.
3. **Where is the data?** — pick a table, tick the columns the AI may show, add
   conditions ("reference equals *the order number the customer gave*"). Add a
   condition on the customer's own id using the *identity* option so every
   customer only ever sees their own rows.

Click **Use this query**, then **Validate & save**. The query stays visible and
editable under *Advanced* for anyone who prefers SQL. Every tool goes through the
same checks: SELECT only, values always bound, results limited to the columns you
ticked, identity values from the signed visitor token — never from the AI.

## Rules — shaping how the assistant behaves

Rules live in **folders** (Personality, Response behaviour, Business protection,
Service rules, Custom instructions, plus any you create). Each folder is applied
in order; switch a folder or a single rule off without deleting it. No prompt
writing needed — plain sentences work: *"Never promise a refund date."*

## Staff, 2FA and the live inbox

- **Staff** (owners only): invite colleagues by email. They choose their own
  password from the link (valid 7 days) and the account stays *pending* until
  they do. Give each person a preset (*View only*, *Agent*, *Editor*) or tick
  exactly what they may open and do; the sidebar follows. Banimark has its own
  login, independent of your app's users.
- **Security**: every staff member can turn on two-factor authentication with any
  authenticator app. Owners can *require* it for everyone and reset a colleague
  who lost their phone.
- **Inbox**: the conversation page updates live — new visitor messages appear as
  they arrive, replies go out without a reload, and one-tap *quick replies*
  (edit them under Notifications) speed things up. A chime and a badge announce
  new messages and handovers on every page; the bell in the header mutes it.

## The widget as a link

*Widget → Share as a link* gives a full-page version of the chat for email
signatures, QR codes, SMS — anywhere a script cannot be embedded. Theme
(light / dark / follow the device) is set under *Widget → Theme* and applies to
the widget, the page and the Flutter SDK.

## Mobile apps (Flutter)

`banimark_flutter` gives your app the same chat, native: themeable bubbles,
human handover with live agent replies, resume on reopen, guest mode.

```dart
BanimarkChat(config: BanimarkConfig.laravel('https://yourapp.com', token: userToken))
```

Ask support for the package; its README covers theming and the signed visitor token.

## AI settings, data & protection, team

- **AI settings** - the assistant's name, tone and language; how much of the
  conversation it remembers; the longest reply; a daily limit on AI answers
  (past it, visitors go to your team and the thread says why).
- **Data & protection** - keep chats for N days then delete them (files too);
  delete everything with one confirmed button; flood limits per visitor and per
  connection so a script cannot fill the inbox or spend your AI budget.
  `php artisan banimark:prune` runs the retention policy from a scheduler.
- **Team** - who is online, chats handled per person, how long visitors waited,
  how fast handovers were picked up. Replies show who sent them.

Messages may carry light formatting (bold, italics, lists, links, code); it is
rendered the same in the widget, the chat link, the Flutter app and the panel,
and nothing else is interpreted.

## Files in a chat

Visitors and staff can attach files. Choose where they are stored on the
**Files** page — this server, or S3-compatible storage (AWS S3, Cloudflare R2,
DigitalOcean Spaces, Backblaze B2, MinIO) — set the size limit and the accepted
types, then press **Send a test file** to confirm it works.

Files are never written to a public folder: each one is reachable only through
its own unguessable link, images render inline and everything else downloads.
Scripts and programs are refused whatever the settings say. Storage adapters
live in `src/Files/` and ship readable, so you can point them at your own
storage or add an adapter.

## Content-Security-Policy

The panel needs only `script-src 'self'` (its scripts are served from your own
domain, nothing inline) and `style-src 'unsafe-inline'`. No nonce, no hash, no
allow-list changes. The visitor widget is a same-origin script too.

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
