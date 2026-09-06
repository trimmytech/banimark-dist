# Changelog

Notable changes to Banimark, newest first. Versions follow semver: while we are
on 0.x, a minor bump may change behaviour — the upgrade notes below say when.

## 0.16.1
- **Emoji work.** Sending one used to fail with "Could not send" (and a 500 in
  your log): Banimark's tables were created with whatever character set your
  database defaults to, and on MySQL that is usually still the three-byte
  `utf8`, which cannot store an emoji. Its tables are now `utf8mb4`, and
  upgrading converts the ones you already have - your application's own tables
  are not touched. Should a database still refuse a character, the message now
  arrives without it instead of failing outright.
- **A message that does not send can be sent again.** It stays in the chat,
  dimmed, with a Retry button, so nothing anyone typed is lost to a dropped
  connection or a moment's server trouble. In the widget and the shareable chat
  link; the Flutter app already had it.
- The chat endpoint always answers with JSON now, even when something breaks
  behind it - so the widget can say what happened and offer the retry, and your
  visitors never meet a stack trace.

**Upgrading:** `composer update banimark/banimark` then `php artisan migrate`
(standalone: open the admin panel once). The migration converts the tables.

## 0.16.0
- **The AI can explain itself when a lookup will not run.** A tool that needs a
  signed-in visitor now tells the assistant so (it can ask the visitor to sign
  in), and puts the real reason in the thread where only your team sees it -
  "the visitor was anonymous", or the database's own complaint - instead of a
  blank "not available right now".
- **Try it, in the Tool Builder.** Run a tool with sample values before saving:
  see the rows the AI would read, or exactly why it refuses.
- **Adding an AI provider no longer needs a URL you have to google.** Pick the
  service you have a key for (OpenAI, DeepSeek, Groq, Mistral, OpenRouter,
  Together, xAI, SiliconFlow, or a local Ollama / LM Studio) and the address is
  filled in, with a link to where the key comes from. Gemini and Claude need no
  address, so the field is gone for them.
- **Formatting in messages** - bold, italics, lists, links and code - rendered
  the same in the widget, the chat link, the Flutter app and the staff view.
  Nothing else is interpreted, so pasted HTML stays harmless text.
- **New page: AI settings.** Its name, tone and language; how much of the
  conversation it remembers; the longest reply; and a daily limit on AI
  answers as a safety net against a runaway bill (past it, visitors go to your
  team for the day and the thread says why).
- **New page: Data & protection.** Keep chats for N days then delete them
  automatically (files included); delete everything with one confirmed button;
  and flood limits so a script cannot fill your inbox or spend your AI budget.
  Any conversation can be deleted from its page, along with everything else
  from that visitor.
- **New page: Team.** Who is online, how many chats each person handled, how
  long visitors waited for them (median and average), and how fast handovers
  were picked up. Replies now show who sent them.
- **The typing indicator behaves like a person.** A short pause before the
  dots, and they stay up a moment even when the answer is instant - no more
  robotic instant dots. Widget, chat link and Flutter.
- Laravel: `php artisan banimark:prune` runs the retention policy on demand.

*Upgrade note:* `php artisan migrate` (Laravel) or open the panel once
(standalone). Existing staff replies show as "human agent" until new ones are
sent (the sender was not recorded before). Flutter apps: `flutter pub get`.

## 0.15.1
- **Your vendor now sets how often your licence is re-checked** (it used to be
  fixed at once a day). The Licence page tells you the rhythm you are on. This
  is also how quickly a change made by your vendor — a renewal, or a licence
  being withdrawn — reaches your panel. Your chat widget is never affected.
- Fixed: after the first daily re-check, the Licence page lost the plan, customer
  and dates it had shown since activation.

## 0.15.0
- **Emoji**, in the widget and in your replies. A built-in picker with search —
  nothing extra to load, and the same set on both sides of the conversation.
  The Flutter SDK has it too.
- **Send and receive files.** Visitors can attach a file to a message; you can
  attach one to a reply. Images preview inline, everything else arrives as a
  download. Works in the web widget, the shareable chat link and the Flutter app.
- **You choose where files live** (new *Files* page): this server, or any
  S3-compatible storage — AWS S3, Cloudflare R2, DigitalOcean Spaces,
  Backblaze B2, MinIO. Set the size limit and which types you accept.
  **Send a test file** proves the settings work before a customer ever tries.
  Scripts and programs are refused no matter what you allow, files are never
  written to a public folder, and each one is reachable only by its own
  unguessable link.
- **A redesigned inbox.** A list of conversations instead of a spreadsheet:
  who, what was last said and by whom, how long ago, whether they are in the
  chat right now, and a dot for anything new since you last looked. Filter tabs
  carry counts, and you can search people and messages.

*Upgrade note:* run `php artisan migrate` (Laravel) or open the admin panel once
(standalone). File sharing is on by default and stores on your server.

## 0.14.1
- **The widget now behaves like a live chat.** The conversation survives page
  reloads and, for signed-in users, even a cleared browser or a new device — the
  same person continues the same thread instead of opening a new one. Replies
  from your team arrive without a reload, with a soft chime, even while the chat
  bubble is closed.
- **When the AI cannot answer, a human takes over — instantly.** A provider
  problem (bad key, outage, quota) no longer shows the visitor an apology to
  retry: they are handed to your team on the spot, your inbox chimes, and the
  real error is recorded in the conversation as a staff-only note.
- **Typing indicators that mean it.** Staff see dots only while the visitor is
  actually typing; the visitor sees dots while a team member types.
- **Conversation view:** the visitor's messages sit on the left, yours on the
  right. Fixed the typing dots and an empty green box showing when nothing was
  happening.
- **Editing tools (Laravel):** the *Edit* button and prefilled builder were
  missing from the Laravel panel in 0.14.0 — they are here now.
- Flutter SDK: same continuity for signed-in users; shows when an agent is
  typing; `controller.typing()` reports the user's typing.

*Upgrade note:* run `php artisan migrate` (Laravel) or open the admin panel once
(standalone).

## 0.14.0
- **Free trial at first install.** A fresh install asks Banimark HQ for a trial
  licence automatically (or from *License → Start free trial*); your vendor sets
  the length. The licence page shows the days left. When the trial ends the
  admin panel locks until you enter a purchased key — the chat widget keeps
  working throughout. One trial per site.
- **Licence page shows your licence.** Once active: plan, site, modules, issued
  and expiry dates, last verification, your vendor's support contact, and a
  *Re-check with HQ now* button. A trial key can be replaced by a purchased one;
  a paid key stays locked to the site.
- **Staff invitations.** Adding a colleague now sends them an email with a link
  to choose their own password. The account is *pending* and cannot sign in
  until they do. Owners can resend a link; links work for 7 days. The link is
  also shown to the owner in case email is not set up.
- **Staff permissions.** Owners decide what each staff member can open and do:
  presets (*View only*, *Agent*, *Editor*) or a custom tick-list — inbox
  viewing, replying, closing, tools, rules, providers, widget, notifications.
  The sidebar shows only what a person may open. Existing staff keep the access
  they had.
- **Edit tools and providers.** Every tool and every AI provider now has an
  *Edit* button that reopens it in the form (a tool can also be renamed or
  switched off). Provider keys are never shown; leaving the key blank keeps it.
- **One AI provider at a time.** Exactly one provider answers the chat. Turning
  one on turns the others off; *Use this* switches in one click.
- **Widget light/dark mode.** Under Widget → Theme: follow the visitor's device,
  always light, or always dark. Applies to the website widget, the shareable
  chat page and the Flutter SDK (`followAdminAppearance`).
- **Chat as a link.** *Widget → Share as a link* gives a full-page chat URL for
  email signatures, QR codes or anywhere the widget cannot be embedded.

*Upgrade note:* run `php artisan migrate` (Laravel) or open the admin panel once
(standalone).

## 0.13.2
- **Works under your app's Content-Security-Policy.** If your app sets a CSP
  (many do), the panel's buttons and scripts were being blocked - the theme
  switch, *New folder*, the Tool Builder's table list ("Loading…"). The panel
  now serves its scripts and styles as normal files from your own domain and
  uses no inline handlers, so a `script-src 'self'` policy is all it needs
  (plus `style-src 'unsafe-inline'`, which almost every policy already has).
- **Licence checks when HQ is unreachable.** If Banimark HQ cannot be reached,
  the panel now says so at the top of every page - since when, and the date the
  grace window closes - instead of staying silent. Nothing changes to your
  licence when HQ does not answer: pressing *Save & check* during an outage no
  longer locks the panel (it used to), and the standalone runtime now re-checks
  daily on its own like the Laravel package does. The chat widget is never
  affected.
- **Tool Builder fix:** the table list was also stuck on "Loading…" because of
  a script error, so the visual builder could not be used even without a CSP.
  It now loads your tables straight away.
- **Rules page:** folders are collapsible — click a folder to open it, with the
  number of rules shown on each. *Expand all* / *Collapse all* at the top, and
  the panel remembers which folders you left open.
- **Widget page → Mobile apps:** a new card points to the Flutter SDK
  (`banimark_flutter`) with the current version and download link as published
  by your vendor, plus a ready-to-paste snippet aimed at your site.

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
