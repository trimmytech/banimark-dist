# Changelog

Notable changes to Banimark, newest first. Versions follow semver: while we are
on 0.x, a minor bump may change behaviour — the upgrade notes below say when.

## Unreleased
- **Visitors can delete their conversation.** A bin in the chat header - on
  the website, the shareable chat link and the app - clears the conversation
  after asking first, and the chat starts fresh. It disappears for the visitor
  straight away; your team still sees it in the inbox, marked "Deleted by
  visitor", and it is erased permanently, with its files, after 30 days. Open
  it and press **Keep this conversation** to stop that. The number of days is
  on the Data & protection page. Laravel: run `php artisan migrate` after
  updating (standalone installs update themselves on the next admin visit).
- **Unread replies show on the launcher - and stay counted.** When a visitor's
  chat is closed, a reply from your team shows as a count on the chat bubble
  (9+ past nine), on the website and in the app, and the count now survives a
  page reload or an app restart: only replies after the last one the visitor
  actually looked at are counted. Two new knobs on the Widget page: how often
  a closed chat checks for replies (10 s to 10 min, default 30 s), and how
  long a dismissed app bubble stays away (0 = until the next visit, default
  10 min). The chat bubble - on the website and in the app - can be dragged
  anywhere (it remembers the spot, and the chat opens on the side where there
  is room) and closed with a small ×; a reply from your team always brings it
  back.
- **Every button answers on the spot.** In the admin panel (Laravel and
  standalone) a button now posts in the background: the result is printed at
  the top of the page and as a small pop-up, an error keeps you on the page
  with what you typed, and a success takes you where it always did - with the
  message shown there. Links are unchanged. Browsers without JavaScript get the
  old full-page reload. The same applies to every button in Banimark HQ.
- **Banimark HQ (our vendor site):** fixed a 500 ("Unknown column
  'overrides'") when customising a licence on a production HQ - the new
  columns and tables (licence overrides, bound sites, review flags, install
  fingerprints) now ship as a migration; run `php artisan migrate` after
  deploying HQ.
- **Banimark HQ (our vendor site):** a free trial now mirrors the lowest plan
  instead of unlocking everything - the same sites, staff, tools and features
  as the cheapest tier (which plan it mirrors is a setting). A licence's type
  can now be changed after it is issued (upgrade or downgrade), from the
  Licences page or via the link on each install; the new limits reach the
  install at its next check-in.
- **Banimark HQ (our vendor site):** a licence can now be customised
  individually - its sites, staff, tools and features set for that one licence,
  above or below its plan. A trial, for example, can be granted a feature on
  its own. Anything left blank keeps the plan's value, and one button returns a
  licence to the plan's defaults. The install picks it up at its next check-in.
- **The assistant can now read what your customers attach.** With a model
  marked "reads images & PDFs" (every current Gemini model), an attached image,
  PDF or plain-text file is read and answered from - a receipt photo, a
  screenshot, a document - instead of "I can't open attachments". Follow-up
  questions about a file you sent earlier work too. Word, Excel, zips, audio
  and video are still not read, and the assistant says so rather than guessing.
- **You choose the model knowing what it can do.** The AI Providers page now
  labels every model "reads images & PDFs" or "text only" before you pick it.
  A text-only model behaves exactly as before.
- **A switch on the Files page: "Let the assistant read attachments".** It is
  on by default. File contents are sent to your AI provider so the assistant
  can read them; switch it off and the assistant only sees that a file was
  attached.

## 0.30.9
- **The admin panel now locks if the host app replaces Banimark's login
  service.** If the application Banimark is installed in overrides Banimark's
  own auth service, the licence check could previously be switched off without
  anyone noticing. Now the panel locks, keeps the Dashboard and Licence pages
  open, and says exactly what to fix. Nothing changes for a normal install.
- **Banimark HQ (our vendor site):** our own site hosts a Banimark install and
  was doing exactly that, so its licence gate was off. Fixed on our side, and
  the change above makes sure it can never be silent again.

## 0.30.8
- **A licence set in your server's environment now switches the chat on.** If
  your key lives in `BANIMARK_LICENSE_KEY` (or the config file) rather than on
  the Licence page, the admin already recognised it but the chat widget stayed
  off. The widget now honours it the same way.

## 0.30.7
- Maintenance re-release, identical in behaviour to 0.30.6.

## 0.30.6
- **A fresh install now asks you to activate before the chat goes live.** Until
  you start a free trial or enter a licence key, the chat widget, the shareable
  chat link and the mobile app stay off, and the admin panel shows only the
  dashboard - every other page sends you to the Licence screen to activate.
  Nothing changes for an install that has already activated: once you have ever
  started a trial or entered a key, your chat keeps working for good, even if the
  licence later expires or Banimark HQ can't be reached.

## 0.30.2
- **Licences can now cover more than one site.** A licence type's "Sites this
  licence may activate on" is finally enforced: a 3-site licence works on three
  sites, an unlimited one on any number, and a one-site licence stays locked to
  one. Going over the limit locks only the extra site's admin panel - every
  chat widget keeps working, always.
- **Your install now tells HQ a small anonymous id** so the vendor can tell one
  licence legitimately running on several of your own sites from a licence that
  was copied. It is a random value, holds nothing about you, and never affects
  your chat.
- **Much more control over the chat widget.** On the Widget page you can now:
  - add your logo or a team photo to the header. Any picture works, because
    the panel shrinks it to 128 pixels before upload.
  - write the line under the title, such as "Usually replies in 2 minutes".
  - choose rounded, soft or square corners, and comfortable or compact spacing.
  - pick the launcher icon and give it a label such as "Chat with us", which
    turns it into a wider button that more people notice.
  - set a different welcome message for when your working hours say nobody
    is in.
  - choose what happens before anyone clicks: the welcome bubble (as before),
    the chat opening by itself, or nothing. You also set how long it waits
    and on which pages. Opening by itself happens once per visit and never
    on phones.
  - show the widget only on some pages, or never on others.
  - turn the reply chime off.
- **A better preview.** Switch it between light and dark, desktop and phone,
  and team-in and out-of-hours. "Reset the look" fills in the defaults
  without saving. "Try it on a test page" opens your real widget on a sample
  page. The shareable chat link and the Flutter SDK follow the same settings,
  apart from the launcher, auto-open and page rules, which only apply to a
  website.
- **Widget page: the preview now shows your accent colour as you change it.**
  Picking a colour or typing a hex code recolours the preview's header,
  launcher and buttons straight away. Before, the preview stayed purple.
- **Files page: "Check it works" is no longer hidden behind the save bar.**
  The "Send a test file" button is visible and clickable again, and the same
  fix applies to the Data page.
- **A new typeface.** The admin panel now uses Inter. It is served from your
  own server, so no visitor or staff address goes to a font service.
## 0.30.1
- **Every admin screen redesigned.** Settings pages now explain each section on
  the left and hold the controls on the right. A conversation shows the visitor's
  details and your actions beside the chat. Staff, tools and AI providers are
  easy-to-scan lists, rules are numbered folders, and the Widget page has a live
  preview that changes as you type. Sign-in screens, error pages and the chat
  widget itself share the new look, and the shareable chat link is now a
  centred chat card on wide screens. Everything works on a phone.
- **A new look for your admin panel.** A deep-sea sidebar, teal and amber
  colours, clearer numbers, in light and dark. The dashboard now shows any
  7, 30 or 90 days, compares each figure with the period before, shows who
  handled your chats, and lists what needs your attention next - people
  waiting for a reply, a missing AI provider or lookup, ideas from your
  customer insights. It works on phones too.
- **Customer insights on your dashboard.** Press "Analyse conversations" and
  your AI reads what your customers wrote over the last 7, 30 or 90 days. It
  tells you what people talk about, what they ask you for, where they get
  stuck, how they feel, and concrete ideas to improve your business. Email
  addresses and phone numbers are removed before anything is sent, and only
  your customers' own messages are read. Owners run it; staff who can see the
  dashboard can read it.
- **Choose your AI model from a list.** The model is now a dropdown of models
  we have tested with Banimark, instead of a name you type. AI providers
  retire models without warning, and a typed name could stop working
  overnight. New models are added to the list once they pass our tests.
- **The assistant no longer guesses what is in a file a customer sends.** It
  can see that a file was attached, but not what is inside it. It now says so
  and asks the customer to type the details, or offers a colleague, instead of
  sometimes making up an answer.
- **Google Gemini is the provider offered for now.** A provider you already set
  up with another service keeps working exactly as before.
- **An error on a Banimark admin page now always shows Banimark's own error
  screen** - what broke, a check-for-update button and a one-click email to
  your Banimark supplier - even in apps that handle errors their own way. Some
  hosts turn an error into a redirect back, which on an admin page meant a
  redirect loop instead of any message. Visitors and the chat widget were
  never affected.
- **When an admin page breaks, it checks for a fix right there.** The error
  screen has a "Check for an update" button. If a newer version exists, it
  installs it on the spot with your licence key, step by step, with no
  sign-in needed. If none exists, it asks you to email support, with the
  error's details already filled in.
- **Banimark now requires PHP 8.2 or newer.** PHP 8.1 stopped receiving
  security fixes at the end of 2025. If your server is on 8.1, upgrade PHP
  before updating Banimark - composer will refuse the update until you do.
- Banimark's core now needs the free **ionCube Loader** on your server. Most
  hosts have it already; get it from https://www.ioncube.com/loaders.php.
  Without it, the rest of your site keeps working, the chat widget answers
  with a polite "temporarily unavailable", and Banimark's admin shows a page
  explaining exactly what to install and how - as does `php artisan banimark:doctor`.

## 0.25.2
- **Your licence page now says what you are actually on.** The plan by name,
  the staff seats and tools it covers with how many are in use, and every
  feature ticked or greyed - with a link to the other plans when something is
  not included.
- **Nothing in the panel fails at the last step any more.** Anything your plan
  does not cover is shown, switched off, with one line saying why: S3 storage,
  the Flutter SDK, white-labelling. You can see what it does before you decide
  to buy it, and you never fill in a form that was always going to be refused.
  Staff and tools say "2 of 5 used" up front, and the Add form greys out when
  they are all used. **Nothing you already have is ever removed or switched off.**
- **Remove "Powered by Banimark"** from the widget and the chat link, on plans
  that include white-labelling (Widget → the branding box).
- **Tools can now call a secured API of your own** - the answer for anyone who
  will not hand over a database. Pick how your endpoint is protected: a bearer
  token, an API-key header, a username and password, or - best - a **signed
  JWT** that Banimark mints fresh for every call, expires in a minute, and
  stamps with **who is asking**, so your API can return that customer's rows
  and nobody else's. Your secret is typed once, never shown again, never sent
  to the AI, and never sent over a plain http:// address to the internet.
- The **"describe it and it builds it"** assistant knows all of this: it will
  recommend the signed JWT for anything about a customer, fill the form in, and
  leave you to type the secret. It never asks for one and never stores one.

*Upgrade note:* nothing to run. Existing tools and licences are untouched -
a licence issued before plans existed still has no limits at all.

## 0.24.0
- **Fix: "You are up to date" when we had not managed to check.** If your server
  could not reach Banimark &mdash; no outbound connection, or an HQ address that
  was wrong &mdash; the Changelog page said you were on the latest version
  anyway. It now says it could not check, tells you which address it tried, and
  gives you a **Check for updates now** button so you are not waiting on a cache
  you cannot see.
- That button is on every state of the page, so after fixing a connection you
  get the real answer immediately instead of in six hours.
- **A new version now announces itself on every admin page**, not only on
  Changelog &mdash; owners only, since nobody else can act on it, and with a
  *Not now* to put it away. It says whether the release can be installed from
  the panel or needs a terminal, so you know before you click.
- **Updating now shows you where it is.** Instead of a button that appears to do
  nothing until the page reloads, you get a tick against each step as the server
  finishes it &mdash; downloading and checking, putting it in place, updating the
  database. If a step fails, it says which one, and the ones before it still
  happened. The database step runs on its own too, the same way.
- **Licence types.** Your vendor can now define what each plan allows &mdash; how
  many sites it activates on, how many staff accounts you can create, how many
  tools, and whether S3-compatible storage is included. The limits arrive inside
  your signed licence, so they are the same ones your plan was sold on.
- **Going over a limit never takes anything away.** If a plan changes, or you
  move to a smaller one, every staff account and every tool you already have
  keeps working &mdash; you just cannot add another until your plan covers it,
  and the panel says so in plain numbers. Your chat is never affected.
- **Checking for updates shows progress too**, and no longer reloads the page
  out from under you before it has an answer.
- **The database step now runs by itself** as part of an update, right after the
  files are in place. You only ever see a database button if that step could not
  finish &mdash; and if it could not, every page says so until it is sorted,
  because files without their tables is the one state that actually breaks
  things.
- **A friendlier new-message notification.** Proper icons instead of emoji, a
  visible difference between "someone wrote" and "someone needs a person", long
  messages that stay inside the card, a dismiss button, and it waits while your
  pointer is on it rather than vanishing mid-sentence. Several arriving at once
  now stack tidily instead of overlapping.

## 0.23.1
- **Fix: "One more step: update your database" would not go away.** Every
  Laravel install showed that warning even with a perfectly up-to-date database,
  and pressing the button then blamed the database user's permissions. Neither
  was true - the panel was reading its own settings through a list that left the
  version out, so it could not see the answer and assumed the worst. If you saw
  that message, nothing was ever wrong with your database.

## 0.23.0
- **Update Banimark from your admin panel.** When a new version is available,
  *Changelog* now has a button. Press it and Banimark downloads the release,
  checks it really came from us, keeps a copy of your current version and swaps
  the new one in. A second button applies any database changes. No terminal, no
  SSH, no developer.
- **A way back.** Your previous version stays on the server and the panel offers
  *Restore this one*. If anything goes wrong mid-update, the old version is put
  back automatically and nothing is lost.
- **It tells you when it cannot.** If your host will not let Banimark write to
  its own folder, or your licence has lapsed, or a release has to be installed by
  hand, the page says which — and names the directory or the step, so you or your
  host can fix it. The command-line instructions are always there too.

**Upgrading to this one** is still the old way: `composer update
banimark/banimark` then `php artisan migrate` (standalone: sign in to the panel
once). From 0.23.0 onward the button does it.

## 0.22.0
- **You decide what a first-time visitor meets** (Widget → *The first thing a
  visitor sees*): the welcome message, what a guest is asked for, and a few
  phrases they can tap instead of facing an empty box.
- **Choose the details you ask a guest for** - name, email, phone - each one
  *not asked*, *asked but skippable*, or *must be filled in*, with your own
  wording for each. Phone numbers are new, and staff see them on the
  conversation and in the inbox line. Visitors your own site has signed in are
  never asked for anything.
- **Tappable openers.** Add up to six ("Where is my order?", "I need a refund")
  and a visitor taps one to start. They disappear the moment someone types.
- All of it reaches your website widget, the shareable chat link **and your
  Flutter app** - the app reads your settings, so changing them needs no app
  release.
- Fixed: a guest who gave their name showed in the inbox as **"Anonymous"** once
  the conversation was handed to a person.

*Upgrade note:* run `php artisan migrate` (Laravel) or open the panel once
(standalone). Existing installs keep asking for name and email, optionally,
exactly as before - nothing changes until you change it.

## 0.21.0
- **Working hours** (Notifications → *Working hours*). Set the days and times
  your team is actually at their desks, in your own timezone, with two shifts a
  day if you need them and a list of days off. The assistant keeps answering
  around the clock - this is about what happens when it wants to fetch a person.
- **You choose what happens out of hours:**
  - *Take it anyway* - it reaches your inbox and waits; the visitor is told
    plainly that nobody will reply until you are back.
  - *Take it anyway, and email the team now* - the same, plus an email straight
    away, for anything that should not wait for the morning.
  - *Do not hand over* - the assistant keeps helping, explains your hours and
    asks the visitor to write again when you are open. Nothing reaches the inbox.
- Either way the assistant now says **when** someone will be back - "the team is
  back tomorrow at 9:00" - instead of implying a reply is moments away.
- **The widget says so before anyone types**: the header reads "Outside our
  hours - a person is back tomorrow at 9:00" with an amber dot.
- If your AI provider fails, it still hands over whatever the hours say -
  nobody is ever left without a reply.

*Upgrade note:* no database change, and nothing changes until you switch hours
on. Installs that do not use them stay open all the time, exactly as before.

## 0.20.0
- **Tools can read a different database from the one Banimark lives in**
  (Tools → *Where your data lives*). Point them at a **read-only account**, a
  replica, or another server entirely, and press *Test the connection* - it
  reports what it found and warns you if the account can write.
- **PostgreSQL.** Banimark can keep its own tables on PostgreSQL, and tools can
  read a PostgreSQL database, alongside MySQL/MariaDB and SQLite.
- **Tools can call your own API instead of your database.** Point one at an
  address in your app, give it the header it needs, and say which fields the AI
  may see. This is how a desk answers for data Banimark cannot query - a Node
  or Rails service, MongoDB, a microservice, anything that speaks JSON.
  The rules are the same as for a database tool: the AI fills in only the
  values you declared, who the customer is comes from their signed-in session
  and never from the AI, a lookup that cannot be scoped refuses to run, your
  headers are never shown to the AI or the visitor, and only the fields you
  list come back.
- **The AI builder knows all of it**: it writes for whichever database you
  connected, and if you tell it the data is behind an API it drafts that kind
  of tool instead - asking for the address and header rather than inventing them.

*Upgrade note:* run `php artisan migrate` (Laravel) or open the panel once
(standalone). Nothing changes until you change it: tools keep reading the same
database they always have.

> PostgreSQL is new and has not yet been run against a live PostgreSQL server -
> the SQL it generates is asserted in the test suite, but if you are the first
> to use it, test on a copy before pointing it at anything that matters.

## 0.19.0
- **Describe a tool and the AI builds it** (Tools → *Describe it, and it builds it*).
  Say "let a customer check the status of their order" in your own words; the
  assistant works out which of your tables to use, writes the query, fills in
  the whole form, and tells you to press **Try it**. No SQL, no jargon.
  It asks a question rather than guessing when it is unsure.
- It uses the same AI provider your chat uses. Without one, the card explains
  that and links you to the page to connect one.
- **What it can see is deliberately small:** your table and column *names*, and
  what you type. **Never a row of your data.** It cannot run anything - the
  query it writes is checked (read-only, one statement) and nothing becomes a
  tool until you try it and save it. Every draft is a suggestion: run it and
  read the rows before you keep it.

*Upgrade note:* no database change. Needs an AI provider with a key, which you
already have if your chat is answering.

## 0.18.1
- **You decide when the assistant brings in a person** (AI settings → *When to
  bring in a person*). It was handing conversations over the moment it could
  not finish a job itself, so a simple "can you refund my transaction?" went
  straight to your team instead of being answered.
  - *Only when the visitor asks for a person* - it does its best on everything else.
  - *Offer a person, hand over when they accept* (new default) - it says plainly
    what it cannot do, asks first, and only hands over once they say yes.
  - *As soon as it cannot finish the job itself* - the old behaviour, if you want it.
- Whatever you choose, it still never promises anything your rules forbid, and
  it still hands over on its own if the AI provider fails.

*Upgrade note:* existing installs move to *Offer a person* - the recommended
setting. If you had switched off "hand over when unsure", you keep the most
reserved option. No database change.

## 0.18.0
- **The AI now sees what your team said.** When a person takes over a chat and
  later hands it back, the assistant has their replies in front of it, in the
  right place, instead of a hole in the conversation. Visitors no longer have
  to repeat what they already told a colleague.
- **Fixed: staff notes disappeared.** The note explaining why a lookup or the
  provider failed was wiped by the visitor's next message. Notes now stay.
- **Fixed: the transcript was trimmed to the AI's memory.** Anything older than
  the "how much it remembers" setting was deleted from your database. The
  assistant still only reads its window, but you keep the whole conversation.
- **Fixed: an open conversation could repeat itself on screen** for staff, and a
  colleague's reply could appear out of order in the thread.

*Upgrade note:* no database change. Conversations already trimmed cannot be
recovered - what is there now is kept from here on.

## 0.17.2
- **Fixed: a long chat could stop working and hand every visitor to a human.**
  Once a conversation grew past the "how much it remembers" setting, the older
  messages were trimmed - and the trim could land in the middle of a lookup,
  leaving a half exchange at the start of the history. Providers reject that
  outright (Gemini: *"Please ensure that function call turn comes immediately
  after a user turn..."*), so every later message failed and was escalated.
  Trimming now keeps each exchange whole, and any conversation already stuck
  this way repairs itself the next time someone writes in it.

*Upgrade note:* no database change, nothing to re-configure. Affected
conversations start working again on their next message.

## 0.17.1
- **The inbox now shows what needs you.** Every conversation carries a coloured
  rail and a plain-English badge: **Waiting 12m** (red once someone has waited
  ten minutes, amber before that), **With a person**, **AI is answering** or
  **Closed**. Small marks beside the name say the visitor is signed in, how
  many files were shared, and whether something went wrong in that chat.
- **Filters you can combine.** Waiting for a reply, new to you, has files,
  signed in - each with a count where it helps - plus **Longest waiting** to
  put the person who has waited most at the top. Search keeps your filters.
- The page title says it plainly: "3 people are waiting for a reply", or
  "Nobody is waiting - everything is answered".

*Upgrade note:* no database change.

## 0.17.0
- **A roomier message box.** The text field now takes the full width of the
  widget, with emoji, attach and send on their own row underneath.
- **Long conversations open fast.** The widget, the chat link and the Flutter
  SDK draw the last 15 messages and fetch earlier ones when you scroll to the
  top (or tap "Load earlier messages"), keeping your place.
- **You will not miss a reply.** A chime when a member of the team writes back
  (it now sounds reliably - browsers only allow sound after you have clicked
  in the chat), an unread count on the launcher, and "(2) Acme Help" in the
  browser tab while you are on another tab. Cleared the moment you look.
  Flutter: `unread` and an `onStaffMessage` callback for your own sound/badge.
- **File uploads, hardened.** Every file's bytes are checked against what its
  name claims (a "photo.png" that is really a web page or a script is refused);
  SVG, HTML, XML and scripts are never accepted, even if added to the allowed
  list; files are stored under opaque `.bin` names so an exposed folder can
  only hand out downloads; download headers cannot be broken out of; at most
  10 unsent uploads per conversation, swept after a day with their bytes.

*Upgrade note:* no database change. Flutter apps: `flutter pub get`.

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
