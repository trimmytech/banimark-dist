# The master file

## Two files, one artifact

```
src/Core.php       GIT-IGNORED. The plaintext bundle you edit and encode.
src/Core.enc.php   COMMITTED.   The encoded release customers receive.
```

`src/Core.php` holds 25 classes: the canonical DTOs and contracts, the whole
tool layer (SqlTool and its save-time validator), the drivers, VisitorToken,
AgentAuth, the admin choke point, the standalone front controller, and
`Licensing\Master` - the conversation loop welded to the licence check.

Its **sources** stay ordinary, organised, git-ignored files you edit as normal
(`src/Engine/Engine.php`, `src/Drivers/GeminiDriver.php`, …).
`build/bundle.php` concatenates them into `Core.php`.

This replaced 17 separate `enc/` twins spread over six directories: 17 encoder
invocations per release, 17 chances to ship a stale one.

## How loading resolves

`src/core_loader.php` registers **after** Composer's PSR-4 loader, so it fires
only when PSR-4 has already missed:

| Where | What is present | What loads |
|---|---|---|
| Your dev machine | the individual sources | PSR-4 serves them — edit freely, no rebundle needed to test |
| A source-less checkout | `Core.php` | the plaintext bundle |
| A customer | `Core.enc.php` only | the encoded bundle |
| Neither | nothing | `Class "Banimark\…" not found` — the core is not optional |

Nothing in that chain is worth patching out. Substituting your own `Core.php`
means supplying **every** class in it — the conversation loop, the drivers, the
DTOs and the tool layer. Under the old per-file scheme a stub only had to define
one class and the rest still loaded; that hole is closed by construction.

## The loop

```bash
# edit sources normally, then:
composer test                 # rebundles Core.php, runs phpunit
php build/publish.php         # encode Core.php -> Core.enc.php   (before every push)
php build/publish.php --check # fail if Core.enc.php is behind    (CI / pre-push)
git commit src/Core.enc.php && git push
```

`Core.php` is git-ignored, so **whatever is in `Core.enc.php` at push time is the
release**. Forget to publish and customers get the previous build while your
tests pass against the new one — which is why `--check` exists.

## Releasing a dist

```bash
IONCUBE_ENCODER=ioncube_encoder8 bash build/release.sh dist
```

The build copies `Core.enc.php` in as `src/Core.php`, deletes every readable
source in the manifest, deletes `core_loader.php`, and points the dist's
`composer.json` classmap at the bundle. So a customer install has **one file and
no fallback** — there is no plaintext-preferring path left to exploit. It then
smoke-tests the dist: if the bundle does not load, or a forged licence unlocks,
the build fails rather than shipping.

## Why the licence lives in here## Why the licence lives in here

A licence check that is only a decision can be faked - anyone can write a class
whose `lock()` returns null. So the check shares a file with `runLoop()`, the
conversation loop itself, and `Engine::reply()` is a thin facade that delegates
to it. Stub this bundle to unlock the admin and you delete the tool loop, the
drivers and the DTOs with it: no chat, no product. And the status it reads is an
Ed25519 token signed by HQ, so it cannot be forged from the customer's own
database.

## What is deliberately NOT in the bundle

`BanimarkServiceProvider` extends a Laravel base class. Declaring it inside an
always-loaded bundle would fatal on a standalone install with no Illuminate
present, so it stays a readable file. The same goes for the storage layer, the
HTTP endpoints, the mailers, the panels and the UI - integration glue a customer
may reasonably need to read, and useless without the core it calls into.

## The policy (do not regress)

- The lock gates the **ADMIN panel only**. The widget and chat are never gated
  by licence state, at any status.
- Activation needs one successful signed handshake; after that the token's
  14-day grace covers HQ outages, then the admin locks (chat still never stops).

## Backup warning

The bundle's sources exist ONLY on this machine - git has the artifact, not the
originals. Keep a private mirror or an encrypted backup, or a disk failure loses
the readable source of your product.
