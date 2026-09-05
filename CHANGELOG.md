# Changelog

Notable changes to Banimark, newest first. Versions follow semver: while we are
on 0.x, a minor bump may change behaviour — the upgrade notes below say when.

## 0.13.1
- **Fixes upgrading from an earlier version.** On an existing install
  `composer update` alone left the new tables and columns missing, so the Rules
  page and the live inbox could not work. Upgrading now completes properly:
  run `php artisan migrate` (Laravel), or just open the admin panel once
  (standalone). Fresh installs were never affected.
- The Tool Builder's table list leaves out framework plumbing (queues, caches,
  sessions, migrations) so you see your own data.

## 0.13.0
- **Rules are now folders.** Instead of one long list, your assistant's rules
  live in folders — Personality, Response behaviour, Business protection,
  Service rules, Custom instructions, and any you add (Refund policy, Opening
  hours…). Reorder folders and rules, switch any of them off without deleting.
  Existing rules are kept and appear under *Custom instructions*.
- **Tool Builder without SQL.** Building a lookup is now three plain steps:
  name it, list what the AI should ask the customer for (add as many as you
  like), then pick a table, tick the columns and add conditions — the query is
  written for you and stays editable under *Advanced*. Every tool still passes
  the same safety checks before it is saved.
- **Two-factor authentication.** Every staff member can protect their login
  with an authenticator app (Security page). Owners can require it for all
  staff and reset anyone who loses their phone.
- **Live inbox.** The conversation page updates as the visitor types no reload
  needed — replies land instantly, the visitor's presence is shown, and one-tap
  *quick replies* (edit them under Notifications) speed up answers. A soft chime
  and a badge tell staff about new visitor messages and handovers on every page;
  mute it with the bell in the header.
- **Flutter SDK.** `banimark_flutter` gives mobile apps the same chat — fully
  themeable, human handover, resume, guest mode. Ask support for access.
- Licence page: shows your vendor's support email whenever you are locked out
  or need help; an active key is read-only until it expires or is revoked.

## 0.12.2
- Fixed: the Changelog page returned a 500 (`syntax error, unexpected end of
  file`). Please update.

## 0.12.1
- **Changelog** is now its own page in the sidebar, for owners. It shows one
  clear notice when an update is available, with the command to run, followed by
  the release notes. Reachable whether or not your licence is active — you
  should never be the last to know there is a new release.

## 0.11.0
- **Licences are now per module.** Your key lists what it covers — the Support
  Desk today, further modules as they arrive — and the panel shows which are
  active on your licence.
- **A licence activates on one site.** The first site you activate binds the key
  to that domain; the same key on a second site is refused. Moving servers or
  changing domain is fine — contact support and we release it. Switching to
  HTTPS, adding or dropping `www`, or running on a port is *not* a different
  site.
- The admin sidebar is grouped by module, so it stays clear as more are added.

## 0.10.2
- Runs on MySQL/MariaDB or SQLite, on Laravel or standalone.
- Licence activation no longer asks for a server address — enter your key and
  Banimark does the rest.

## 0.9.0 — email, presence and chat continuation
- **SMTP settings in the panel.** Banimark now sends its own email rather than
  depending on the host application's mail configuration. Set your server under
  **Notifications**, and use *Send test* to confirm it before relying on it.
- **Escalation alerts by email**, in addition to the staff inbox.
- **Visitor follow-up.** If a visitor closes the tab and an agent then replies,
  Banimark can email them the reply. One email per absence; requires an address.
- **Chat continuation.** A returning visitor's conversation is replayed instead
  of starting over.
- **Guest mode.** The widget can ask a visitor for their name and email — off,
  optional or required. Pages can also supply them at load:
  `window.__BANIMARK_CFG = { user: { name: '…', email: '…' } }`, or
  `data-name` / `data-email` on the script tag.
- **Configurable reply polling** (3–600 seconds) and an optional offline note.
- **Upgrade note:** run `php artisan banimark:install` (or reload the standalone
  installer) once after updating — it adds the new conversation columns to
  existing installs. Safe to re-run.

## 0.8.0 — redesigned panel and widget
- New admin panel: dashboard with conversation, escalation and tool-usage
  figures; redesigned inbox, tools, rules, providers, staff and widget pages.
- Light and dark themes, remembered per browser.
- Redesigned chat widget: new launcher, typing indicator, greeting bubble,
  auto-growing composer, keyboard and reduced-motion support.
- No configuration changes; `composer update` is enough.

## 0.7.0 — licensing
- Banimark is now licensed. Enter your key under **License** in the admin panel,
  or set `BANIMARK_LICENSE_KEY` in `.env`.
- **Without a valid licence the ADMIN PANEL locks.** Your chat widget is never
  affected — visitors keep being served whatever your licence says.
- Activation needs one successful check with our servers. After that the licence
  is valid offline for 14 days at a time, so a network problem on either side
  does not interrupt you.

## 0.6.0 — staff accounts
- Banimark has its **own staff login**, independent of your application's users.
  Owners can add and remove agents who handle escalated conversations.
- **Escalation modes:** staff inbox (default) or email notification.
- `php artisan banimark:agent` to add staff from the command line.
- **Upgrade note:** the admin panel is now always mounted on `web` middleware
  plus an optional `banimark.admin.extra_middleware`. The old
  `banimark.admin.middleware` key is no longer read. **Existing installs need
  only `composer update`** — a previously published config is harmless. To
  restrict who can reach the Banimark login, set `admin.extra_middleware`.

## 0.5.0 — standalone runtime
- Banimark runs on any PHP stack, not just Laravel, with a browser installer.

## Earlier
- AI drivers (Gemini, OpenAI-compatible, Anthropic), the conversation engine,
  the Tool Builder, the chat widget and the admin panel.
