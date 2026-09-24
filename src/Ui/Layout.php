<?php

namespace Banimark\Ui;

/**
 * The shared app shell. The stylesheet and behaviour script live in
 * resources/design/ and are INLINED rather than linked: the panel must render
 * inside any host app, on any path, offline, with no asset pipeline and no
 * extra HTTP round trip.
 */
class Layout
{
    private static ?string $css = null;
    private static ?string $js = null;

    public static function css(): string
    {
        return self::$css ??= Assets::content('panel.css');
    }

    public static function js(): string
    {
        return self::$js ??= Assets::content('panel.js');
    }

    /**
     * URL of a panel asset, when the runtime has told us where it serves them
     * (configure(['assets' => ...])). Null = not configured (the standalone
     * installer runs before any route exists) - callers fall back to inline.
     */
    public static function assetUrl(string $name): ?string
    {
        $base = (string) (self::$config['assets'] ?? '');
        return $base === '' ? null : rtrim($base, '/').'/'.$name.'?v='.Assets::version();
    }

    /**
     * The <head>: stylesheet + the theme pre-paint guard. External files when
     * an assets URL is configured (CSP-safe); inline only as the fallback.
     */
    public static function head(string $title): string
    {
        $out = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e($title).'</title>';
        if (($css = self::assetUrl('panel.css')) !== null) {
            return $out.'<link rel="stylesheet" href="'.self::e($css).'">'
                .'<script src="'.self::e((string) self::assetUrl('theme.js')).'"></script>';
        }
        return $out.'<script>'.Assets::content('theme.js').'</script><style>'.self::css().'</style>';
    }

    /** @var array<string, mixed> runtime facts the panel script needs (event feed url, current user) */
    private static array $config = [];

    /** Set once per request by the runtime that knows its URLs; emitted by scripts(). */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Runtime facts travel in a JSON data block - CSP never executes those, so
     * no nonce is needed - and the behaviour is an external file.
     */
    public static function scripts(): string
    {
        $config = '<script type="application/json" id="bm-config">'
            .json_encode(self::$config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS).'</script>';
        if (($js = self::assetUrl('panel.js')) !== null) {
            return $config.'<script src="'.self::e($js).'" defer></script>';
        }
        return $config.'<script>'.self::js().'</script>';
    }

    /** The "Try it" panel of the Tool Builder (same markup in the Blade view). */
    public static function tryItCard(string $tryUrl): string
    {
        return '<div data-tryit data-try-url="'.htmlspecialchars($tryUrl, ENT_QUOTES).'" style="margin-top:14px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">'
            .'<div class="row" style="gap:10px;align-items:baseline"><b>Try it</b><span class="muted">Run this tool now, exactly as the AI would, with values you choose. Read-only.</span></div>'
            .'<div class="grid2" style="margin-top:10px"><div><label>What the AI would send <span class="muted">(one box per parameter)</span></label><div data-try-args><span class="muted">No parameters yet.</span></div></div>'
            .'<div><label>Who the visitor is <span class="muted">(identity values your query needs)</span></label><div data-try-ctx><span class="muted">This query needs no identity values.</span></div></div></div>'
            .'<div class="row" style="gap:10px;margin-top:10px"><button type="button" class="btn2 btn-sm" data-try-run>'.Icons::get('play', 14).' Run it</button><span class="muted" data-try-status></span></div>'
            .'<div data-try-out hidden style="margin-top:10px"></div></div>';
    }

    /**
     * The provider form's Driver and Model menus - both runtimes render these,
     * so the offer (ProviderPresets) is decided in one place. The model is a
     * dropdown of tested models, never free text: a typo or a retired model
     * used to fail only when a visitor asked something.
     */
    public static function providerDriverSelect(string $current): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $current = $current !== '' ? $current : 'gemini';
        $opts = '';
        foreach (\Banimark\Ai\ProviderPresets::driverOptions($current) as $value => $label) {
            $opts .= '<option value="'.$e($value).'"'.($value === $current ? ' selected' : '').'>'.$e($label).'</option>';
        }
        return '<div><label>Driver</label><select name="driver">'.$opts.'</select></div>';
    }

    public static function providerModelSelect(string $driver, string $current): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $driver = $driver !== '' ? $driver : 'gemini';
        $selected = $current !== '' ? $current : (\Banimark\Ai\ProviderPresets::DEFAULT_MODEL[$driver] ?? '');
        $opts = '';
        foreach (\Banimark\Ai\ProviderPresets::modelOptions($driver, $current) as $value => $label) {
            // the clause is shown BEFORE the owner saves: it decides whether the
            // assistant can read what visitors attach (images, PDFs, plain text)
            $clause = \Banimark\Ai\ProviderPresets::capabilityClause($driver, (string) $value);
            $opts .= '<option value="'.$e($value).'"'.($value === $selected ? ' selected' : '').'>'.$e($label).' — '.$e($clause).'</option>';
        }
        return '<div><label>Model <span class="muted">(each one tested with Banimark)</span></label><select name="model" required>'.$opts.'</select>'
            .'<div class="hint">"Reads images &amp; PDFs" means the assistant can look at what a visitor attaches (images, PDF, plain text) and answer from it. "Text only" means it can see that a file was attached, but not what is inside.</div></div>';
    }

    /**
     * The provider form's "which service" block: a plain-language picker that
     * fills in the address for OpenAI-compatible services, hides the address
     * entirely for Gemini/Anthropic (they have none), and links to where the
     * key comes from. Behaviour lives in panel.js (data-provider-form); the
     * presets travel in a JSON block, never inline script (host CSPs).
     */
    public static function providerServiceBlock(string $driver, ?string $baseUrl, ?string $model): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $presets = \Banimark\Ai\ProviderPresets::all();
        $current = \Banimark\Ai\ProviderPresets::match($baseUrl);
        $opts = '<option value="">Choose the service you have a key for…</option>';
        foreach ($presets as $slug => $p) {
            $opts .= '<option value="'.$e($slug).'"'.($current === $slug ? ' selected' : '').'>'.$e($p['label']).'</option>';
        }
        return '<div data-provider-service'.(($driver ?: 'gemini') === 'openai-compat' ? '' : ' hidden').'>'
            .'<label>Which service?</label><select data-service>'.$opts.'</select>'
            .'<div class="hint" data-service-note>Pick one and the address below is filled in for you.</div></div>'
            .'<div data-provider-url'.(($driver ?: 'gemini') === 'openai-compat' ? '' : ' hidden').'><label>Address <span class="muted">(filled in from the list above; only change it if the service told you to)</span></label>'
            .'<input type="text" name="base_url" placeholder="https://api.example.com/v1" value="'.$e($baseUrl).'"></div>'
            .'<script type="application/json" data-provider-presets>'.json_encode(['presets' => $presets, 'driverKeys' => \Banimark\Ai\ProviderPresets::DRIVER_KEYS,
                'models' => \Banimark\Ai\ProviderPresets::MODELS, 'defaultModel' => \Banimark\Ai\ProviderPresets::DEFAULT_MODEL], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }

    /**
     * The "where does this look things up?" half of the tool form: the owner's
     * database, or one of their own HTTP endpoints. Shared by both runtimes so
     * the two panels cannot drift apart on something this fiddly.
     *
     * @param array $e the tool being edited, or [] for a new one
     */
    public static function toolSourceFields(array $e, string $schemaUrl): string
    {
        $q = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $v = fn (string $k, string $d = '') => $q($e[$k] ?? $d);
        $http = ($e['kind'] ?? 'sql') === 'http';
        $method = strtoupper((string) ($e['http_method'] ?? 'GET'));

        return '<h3 class="bm-step">Where should it look?</h3>'
            .'<div class="row" style="gap:10px;margin-bottom:12px">'
            .'<label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">'
            .'<input type="radio" name="kind" value="sql"'.($http ? '' : ' checked').' style="margin-top:2px" data-kind="sql">'
            .'<span><b>In my database</b><div class="muted">Point at a table and the builder writes the query.</div></span></label>'
            .'<label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">'
            .'<input type="radio" name="kind" value="http"'.($http ? ' checked' : '').' style="margin-top:2px" data-kind="http">'
            .'<span><b>From my own API</b><div class="muted">Call an address in your app - for data Banimark cannot reach directly.</div></span></label>'
            .'</div>'

            // ---- database ----
            .'<div data-kind-block="sql"'.($http ? ' hidden' : '').'>'
            .'<div data-toolbuilder data-schema-url="'.$q($schemaUrl).'" style="background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">'
            .'<div class="row" style="justify-content:space-between"><b>Visual builder</b><span class="muted" data-status>…</span></div>'
            .'<div class="grid2" style="margin-top:8px">'
            .'<div><label>Table</label><select data-table><option value="">Loading…</option></select></div>'
            .'<div><label>Who is chatting is identified by <span class="muted">(identity keys, comma-separated)</span></label>'
            .'<input type="text" name="context" value="'.$v('context_csv', 'user_id').'" placeholder="user_id"></div></div>'
            .'<label>Columns the AI may show the customer</label><div data-columns><span class="muted">Pick a table first.</span></div>'
            .'<label style="margin-top:12px">Only show rows where…</label><div data-conditions></div>'
            .'<button type="button" class="btn-ghost btn-sm" data-add-condition>'.Icons::get('plus', 14).' Add a condition</button>'
            .'<div class="muted" style="margin:10px 0 4px">Tip: add a condition on the customer\'s own id using the <i>identity</i> option so every customer only ever sees their own rows.</div>'
            .'<pre class="mono" data-preview style="white-space:pre-wrap;padding:10px 12px;border-radius:8px;margin:8px 0">-- pick a table and at least one column</pre>'
            .'<button type="button" class="btn2 btn-sm" data-apply disabled>'.Icons::get('check', 14).' Use this query</button></div>'
            .'<details style="margin-top:14px"'.(!$http && ($e['sql'] ?? '') !== '' ? ' open' : '').'>'
            .'<summary class="muted" style="cursor:pointer">Advanced: the query the AI will run (editable)</summary>'
            .'<label>SQL - SELECT only. <code>:param</code> for values the AI asks for, <code>:_key</code> for identity values</label>'
            .'<textarea name="sql" placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id">'.$q($e['sql'] ?? '').'</textarea>'
            .'<label>Columns the AI may see</label><input type="text" name="columns" placeholder="reference, status, total" value="'.$v('columns_csv').'">'
            .'</details></div>'

            // ---- the owner's own API ----
            .'<div data-kind-block="http"'.($http ? '' : ' hidden').' style="background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">'
            .'<div class="grid2">'
            .'<div><label>Request</label><select name="http_method">'
            .'<option value="GET"'.($method === 'GET' ? ' selected' : '').'>GET - fetch something</option>'
            .'<option value="POST"'.($method === 'POST' ? ' selected' : '').'>POST - fetch something, with a JSON body</option></select></div>'
            .'<div><label>Who is chatting is identified by <span class="muted">(identity keys)</span></label>'
            .'<input type="text" name="context_http" value="'.$v('context_csv', 'user_id').'" placeholder="user_id" data-mirror="context"></div>'
            .'</div>'
            .'<label>Address <span class="muted">Use <code>{name}</code> for what the AI asks the customer, <code>{_key}</code> for who they are</span></label>'
            .'<input type="text" name="http_url" placeholder="https://app.example.com/api/orders?user={_user_id}&amp;q={reference}" value="'.$v('http_url').'">'
            .self::apiAuthFields($e)
            .'<details style="margin-top:12px"'.(($e['http_headers'] ?? '') !== '' ? ' open' : '').'>'
            .'<summary class="muted" style="cursor:pointer">Advanced: extra headers</summary>'
            .'<label>One per line, e.g. <code>X-Tenant: acme</code> <span class="muted">(kept secret from the AI)</span></label>'
            .'<textarea name="http_headers" rows="2" placeholder="X-Tenant: acme">'.$q($e['http_headers'] ?? '').'</textarea></details>'
            .'<label>Body <span class="muted">(POST only, JSON, same <code>{placeholders}</code>)</span></label>'
            .'<textarea name="http_body" rows="2" placeholder=\'{"user": "{_user_id}", "query": "{reference}"}\'>'.$q($e['http_body'] ?? '').'</textarea>'
            .'<div class="grid2">'
            .'<div><label>Where the list is in the answer <span class="muted">(blank = the answer itself)</span></label>'
            .'<input type="text" name="http_path" placeholder="data.orders" value="'.$v('http_path').'"></div>'
            .'<div><label>Fields the AI may see <span class="muted">(comma-separated; use a.b for nested)</span></label>'
            .'<input type="text" name="http_fields" placeholder="reference, status, customer.email" value="'.$v('http_fields').'"></div>'
            .'</div>'
            .'<div class="hint">Your endpoint must answer with JSON. Only the fields you list ever reach the AI; everything else is dropped, and your headers are never shown to it or to the visitor.</div>'
            .'</div>';
    }

    /**
     * "Renew your licence" - how an owner whose admin is locked actually pays.
     *
     * There was no such path: an expired licence showed a red line and, at
     * best, "Need help? <email>". The address is HQ's support email (HQ ->
     * Settings), which reaches every install on each licence check. The button
     * opens an email already carrying what the vendor needs to renew - the
     * site, the key, the plan, the expiry - so the owner types nothing.
     *
     * @param array $ctx reason (expired|revoked|unknown|stale|missing|domain|trial), key, site, plan, expires_at,
     *                   support_email, support_url
     */
    public static function renewCard(array $ctx): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $email = trim((string) ($ctx['support_email'] ?? ''));
        $url = trim((string) ($ctx['support_url'] ?? ''));
        $reason = (string) ($ctx['reason'] ?? 'expired');
        $key = trim((string) ($ctx['key'] ?? ''));
        $site = trim((string) ($ctx['site'] ?? ''));
        $expires = trim((string) ($ctx['expires_at'] ?? ''));

        [$title, $lead, $subject] = match ($reason) {
            'trial' => ['Buy a licence', 'Your free trial has ended. Tell us you want to keep going and we will send you a licence key - everything you set up stays.', 'Buy a Banimark licence'],
            'missing' => ['Get a licence', 'This site has no licence key yet. Ask us for one - or start the free trial, if it is offered.', 'Banimark licence for'],
            'revoked' => ['Your licence was revoked', 'Contact us to find out why and to get it restored.', 'Revoked Banimark licence'],
            'domain' => ['This licence belongs to another site', 'Moving servers or domains? Ask us to release it to this one.', 'Move my Banimark licence to'],
            default => ['Renew your licence', 'Your licence has expired, so this admin panel is locked until it is renewed. Email us and we will extend it - the same key keeps working.', 'Renew my Banimark licence'],
        };

        $bodyLines = array_filter([
            'Hello,',
            '',
            $reason === 'trial' ? 'I would like to buy a Banimark licence.' : ($reason === 'domain' ? 'Please release my licence to this site.' : 'I would like to renew my Banimark licence.'),
            '',
            $site !== '' ? 'Site: '.$site : null,
            $key !== '' ? 'Licence key: '.$key : null,
            !empty($ctx['plan']) ? 'Plan: '.$ctx['plan'] : null,
            $expires !== '' ? 'Expired: '.$expires : null,
            '',
            'Thank you.',
        ], fn ($l) => $l !== null);
        $mailto = 'mailto:'.$email
            .'?subject='.rawurlencode($subject.($site !== '' ? ' - '.$site : ''))
            .'&body='.rawurlencode(implode("\n", $bodyLines));

        $buttons = '';
        if ($email !== '') {
            $buttons .= '<a class="btn" href="'.$e($mailto).'">'.Icons::get('send', 15).' Email '.$e($email).'</a> ';
        }
        if ($url !== '') {
            $buttons .= '<a class="btn2" href="'.$e($url).'" target="_blank" rel="noopener">'.Icons::get('key', 15).' '.($reason === 'trial' || $reason === 'missing' ? 'Buy online' : 'Renew online').'</a>';
        }
        if ($buttons === '') {
            // HQ has not told this install how to reach anyone yet
            $buttons = '<div class="muted">Contact whoever supplied Banimark to you. Once this site reaches our servers, their address appears here.</div>';
        }

        return '<div class="bm-card" style="border-color:color-mix(in srgb, var(--brand) 35%, transparent)">'
            .'<div class="row" style="gap:10px;align-items:flex-start"><span class="avatar">'.Icons::get('key', 16).'</span>'
            .'<div><h2 style="margin:0">'.$e($title).'</h2><div class="muted" style="margin-top:4px">'.$e($lead).'</div></div></div>'
            .($email !== '' ? '<p class="muted" style="margin:12px 0 0">The email opens ready to send, with your site and licence details filled in.</p>' : '')
            .'<div class="row" style="gap:10px;margin-top:14px;flex-wrap:wrap">'.$buttons.'</div>'
            .'<div class="divider"></div>'
            .'<div class="row" style="align-items:flex-start;gap:9px">'.Icons::get('widget', 16)
            .'<div class="muted">Your chat widget keeps answering visitors the whole time - only this admin panel is locked.</div></div>'
            .'</div>';
    }

    /**
     * "What your licence covers" - the same card in both runtimes.
     *
     * The panel used to let an owner walk into a wall: the S3 fields were
     * there, fully editable, and only the SAVE said "not in your plan". So
     * this card states the plan in words, and every control the plan does not
     * cover is greyed out next to it (see lockedNote) instead of failing at
     * the end of a form.
     *
     * @param array $p Licensing\Entitlements::summary()
     */
    public static function planCard(array $p, string $upgradeUrl = '', string $supportEmail = ''): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        $limits = '';
        foreach ($p['limits'] as $row) {
            $value = $row['unlimited']
                ? '<b>Unlimited</b>'
                : '<b>'.(int) $row['limit'].'</b>'.($row['used'] !== null ? ' <span class="muted">· '.(int) $row['used'].' in use</span>' : '');
            $bar = '';
            if (!$row['unlimited'] && $row['used'] !== null) {
                $pct = min(100, (int) round(100 * $row['used'] / max(1, (int) $row['limit'])));
                $bar = '<div class="plan-bar"><span'.($pct >= 100 ? ' class="full"' : '').' style="width:'.max(3, $pct).'%"></span></div>';
            }
            $limits .= '<div class="feat"><div class="row" style="justify-content:space-between;gap:10px">'
                .'<span>'.$e($row['label']).'</span>'.$value.'</div>'.$bar.'</div>';
        }

        $on = $off = '';
        foreach ($p['features'] as $f) {
            $line = '<div class="feat'.($f['on'] ? '' : ' off').'"><div class="row" style="gap:8px;align-items:flex-start">'
                .Icons::get($f['on'] ? 'check' : 'lock', 15)
                .'<span><b>'.$e($f['label']).'</b><div class="muted">'.$e($f['blurb']).'</div></span></div></div>';
            $f['on'] ? $on .= $line : $off .= $line;
        }

        $foot = '';
        if ($off !== '') {
            $foot = '<div class="divider"></div><div class="muted">Anything above that is not included stays visible in the panel but switched off, so you can see what it does before you decide.</div>';
            if ($upgradeUrl !== '') {
                $foot .= '<div style="margin-top:10px"><a class="btn2 btn-sm" href="'.$e($upgradeUrl).'" target="_blank" rel="noopener">'.Icons::get('key', 14).' See the other plans</a></div>';
            } elseif ($supportEmail !== '') {
                $foot .= '<div style="margin-top:10px"><a class="btn2 btn-sm" href="mailto:'.$e($supportEmail).'">'.Icons::get('key', 14).' Ask about upgrading</a></div>';
            }
        }

        // A licence issued before plans existed carries no limits and no
        // feature list. That is not "a plan with nothing in it" - it keeps
        // everything it always had, and saying so is the honest answer.
        $unknown = $p['known'] ? '' : '<div class="hint" style="margin-bottom:10px">This licence was issued before plans existed, so it is not limited: everything below is included. Your next check-in with HQ will fill in the details.</div>';

        return '<div class="bm-card"><div class="bm-sec-h"><div>'
            .'<h2>Your plan: '.$e($p['trial'] ? 'Free trial' : $p['name']).'</h2>'
            .'<div class="muted">What this licence covers on this site.</div></div></div>'
            .$unknown
            .'<div class="plan-grid">'.$limits.'</div>'
            .($on !== '' ? '<div style="margin-top:14px">'.$on.'</div>' : '')
            .($off !== '' ? '<div style="margin-top:10px">'.$off.'</div>' : '')
            .$foot.'</div>';
    }

    /**
     * "3 of 5 staff accounts used" - and, when they are all used, the sentence
     * that says so BEFORE the form instead of after it.
     *
     * @param array $a Licensing\Entitlements::allowance()
     */
    public static function allowance(array $a, string $upgradeUrl = ''): string
    {
        if ($a['limit'] === 0) {
            return '';   // unlimited: saying "0 of unlimited" helps nobody
        }
        if (!$a['full']) {
            return '<span class="pill">'.htmlspecialchars($a['note'], ENT_QUOTES).'</span>';
        }
        return self::lockedNote($a['note'].' Nothing has been removed - you simply cannot add another until your plan covers it.', $upgradeUrl);
    }

    /**
     * "Powered by Banimark", on or off    /**
     * "Powered by Banimark", on or off - the one control the whitelabel plan
     * feature buys. Shared, because the standalone panel and the Blade one
     * must offer exactly the same switch.
     *
     * When the plan does not cover it the box is SHOWN and disabled: an owner
     * who never sees it cannot ask for it.
     */
    public static function brandingToggle(bool $hidden, ?string $lock, string $upgradeUrl = ''): string
    {
        return '<div class="divider"></div>'
            .'<label class="'.($lock ? 'is-locked' : '').'" style="display:flex;align-items:center;gap:8px">'
            .'<input type="checkbox" name="hide_brand" value="1"'.($hidden ? ' checked' : '').($lock ? ' disabled' : '').'>'
            .' Remove "Powered by Banimark" from the widget and chat link</label>'
            .($lock ? self::lockedNote($lock, $upgradeUrl)
                : '<div class="hint">Your plan includes white-labelling. Visitors see only your own branding.</div>');
    }

    /**
     * The note that sits under a control the plan does not cover. The control
     * itself stays on the page - seeing what you could have is the point - but
     * it is disabled, so nobody fills in a form that was always going to be
     * refused.
     */
    public static function lockedNote(?string $reason, string $upgradeUrl = ''): string
    {
        if ($reason === null || $reason === '') {
            return '';
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        return '<div class="bm-lock">'.Icons::get('lock', 15).'<span>'.$e($reason)
            .($upgradeUrl !== '' ? ' <a href="'.$e($upgradeUrl).'" target="_blank" rel="noopener">See the plans</a>.' : '')
            .'</span></div>';
    }

    /**
     * "How is this endpoint secured?" - the part that makes item 3 usable by
     * someone who will not hand over a database.
     *
     * A secret is WRITE-ONLY here. It is stored, never rendered back, and the
     * box says so; leaving it blank keeps what is already saved. Rendering it
     * would put every API token of every tool in the page source of an admin
     * screen that people share and screenshot.
     */
    public static function apiAuthFields(array $e): string
    {
        $q = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $type = (string) ($e['http_auth'] ?? 'none');
        $has = !empty($e['http_auth_has_secret']);
        $kept = $has ? '•••••••• (stored - blank keeps it)' : '';
        $opt = function (string $value, string $label) use ($type) {
            return '<option value="'.$value.'"'.($type === $value ? ' selected' : '').'>'.$label.'</option>';
        };
        $box = fn (string $name, string $inner) => '<div data-auth-block="'.$name.'"'.($type === $name ? '' : ' hidden').' style="margin-top:10px">'.$inner.'</div>';

        return '<label style="margin-top:12px">How is this endpoint secured?</label>'
            .'<select name="http_auth" data-auth-select>'
            .$opt('none', 'It is open - anyone with the address can read it')
            .$opt('bearer', 'Bearer token - Authorization: Bearer ...')
            .$opt('header', 'API key in a header - X-Api-Key: ...')
            .$opt('basic', 'Username and password (HTTP basic)')
            .$opt('jwt', 'Signed JWT - Banimark signs each call (most secure)')
            .'</select>'

            .$box('none', '<div class="hint">Anyone who finds the address can read whatever it returns. Fine for a price list; not for anything about a customer.</div>')

            .$box('bearer', '<label>Token <span class="muted">'.($has ? '(stored - blank keeps it)' : '').'</span></label>'
                .'<input type="password" name="http_auth_token" value="" autocomplete="new-password" placeholder="'.$q($kept).'">'
                .'<div class="hint">Sent as <code>Authorization: Bearer &lt;token&gt;</code>. Your endpoint compares it and returns 401 if it does not match.</div>')

            .$box('header', '<div class="grid2"><div><label>Header name</label>'
                .'<input type="text" name="http_auth_header" value="'.$q($e['http_auth_header'] ?? '').'" placeholder="X-Api-Key"></div>'
                .'<div><label>Value <span class="muted">'.($has ? '(stored)' : '').'</span></label>'
                .'<input type="password" name="http_auth_value" value="" autocomplete="new-password" placeholder="'.$q($kept).'"></div></div>')

            .$box('basic', '<div class="grid2"><div><label>Username</label>'
                .'<input type="text" name="http_auth_user" value="'.$q($e['http_auth_user'] ?? '').'" autocomplete="off"></div>'
                .'<div><label>Password <span class="muted">'.($has ? '(stored)' : '').'</span></label>'
                .'<input type="password" name="http_auth_pass" value="" autocomplete="new-password" placeholder="'.$q($kept).'"></div></div>')

            .$box('jwt', '<label>Shared secret <span class="muted">'.($has ? '(stored - blank keeps it)' : '(at least 16 characters)').'</span></label>'
                .'<input type="password" name="http_auth_secret" value="" autocomplete="new-password" placeholder="'.$q($kept).'">'
                .'<div class="grid2" style="margin-top:8px">'
                .'<div><label>Issuer <span class="muted">(iss)</span></label><input type="text" name="http_auth_issuer" value="'.$q($e['http_auth_issuer'] ?? 'banimark').'"></div>'
                .'<div><label>Audience <span class="muted">(aud, optional)</span></label><input type="text" name="http_auth_audience" value="'.$q($e['http_auth_audience'] ?? '').'" placeholder="orders-api"></div>'
                .'</div>'
                .'<label style="margin-top:8px">Each token is valid for <span class="muted">(seconds)</span></label>'
                .'<input type="number" name="http_auth_ttl" min="10" max="900" value="'.(int) ($e['http_auth_ttl'] ?? \Banimark\Tools\HttpAuth::JWT_TTL).'" style="max-width:140px">'
                .'<div class="hint">Banimark signs a fresh <b>HS256</b> JWT for every call and sends it as a bearer token. Verify it with this same secret. '
                .'It carries <code>iss</code>, <code>iat</code>, <code>exp</code>, <code>jti</code>, and <code>sub</code> + <code>ctx</code> with the identity keys below - '
                .'taken from the visitor\'s signed token, never from anything the AI said. Scope your query by <code>ctx</code> and a leaked address is worth nothing on its own.</div>');
    }

    /**
     * "Where your data lives" on the Tools page. By default tools read the
     * database Banimark itself lives in; this is how an owner points them at a
     * read-only user, a replica, or another server entirely - which is what a
     * Node, Rails or Django shop needs when Banimark keeps its own tables
     * somewhere else.
     */
    public static function dataConnection(array $s, string $saveUrl, string $testUrl, string $csrf, string $ownDescription, string $result = '', bool $resultOk = false): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $separate = \Banimark\Storage\DataSource::isSeparate($s);
        $driver = \Banimark\Storage\DataSource::driver($s);
        $drivers = '';
        foreach (\Banimark\Storage\DataSource::DRIVERS as $key => $label) {
            $drivers .= '<option value="'.$e($key).'"'.($driver === $key ? ' selected' : '').'>'.$e($label).'</option>';
        }
        $hasPassword = trim((string) ($s['tools_db_password'] ?? '')) !== '';

        return '<div class="bm-card"><form method="post" action="'.$e($saveUrl).'">'.$csrf
            .'<div class="bm-sec-h"><div><h2>Where your data lives</h2>'
            .'<div class="muted">Tools only ever read, and only the columns you list. This is which database they read from.</div></div></div>'
            .'<div class="choices two" style="margin:12px 0">'
            .'<label class="choice">'
            .'<input type="radio" name="tools_db_enabled" value="0"'.($separate ? '' : ' checked').' data-toggle-hide="#bm-datadb">'
            .'<span><b>The same database as Banimark</b><div class="muted">'.$e($ownDescription).'</div></span></label>'
            .'<label class="choice">'
            .'<input type="radio" name="tools_db_enabled" value="1"'.($separate ? ' checked' : '').' data-toggle-show="#bm-datadb">'
            .'<span><b>A different database</b><div class="muted">Another server, another engine, or the same data through a read-only account.</div></span></label>'
            .'</div>'
            .'<div id="bm-datadb"'.($separate ? '' : ' hidden').'>'
            .'<div class="grid2">'
            .'<div><label>Engine</label><select name="tools_db_driver">'.$drivers.'</select></div>'
            .'<div><label>Host</label><input type="text" name="tools_db_host" value="'.$e($s['tools_db_host'] ?? '').'" placeholder="127.0.0.1"></div>'
            .'<div><label>Port <span class="muted">(blank = 3306 for MySQL, 5432 for PostgreSQL)</span></label><input type="number" name="tools_db_port" value="'.$e($s['tools_db_port'] ?? '').'"></div>'
            .'<div><label>Database name</label><input type="text" name="tools_db_database" value="'.$e($s['tools_db_database'] ?? '').'" placeholder="nodeapp_production"></div>'
            .'<div><label>Username</label><input type="text" name="tools_db_username" value="'.$e($s['tools_db_username'] ?? '').'" autocomplete="off" placeholder="banimark_readonly"></div>'
            .'<div><label>Password '.($hasPassword ? '<span class="muted">(stored - blank keeps it)</span>' : '').'</label>'
            .'<input type="password" name="tools_db_password" value="" autocomplete="new-password" placeholder="'.($hasPassword ? '•••••••• (unchanged)' : '').'"></div>'
            .'<div><label>PostgreSQL schema <span class="muted">(optional, e.g. public)</span></label><input type="text" name="tools_db_schema" value="'.$e($s['tools_db_schema'] ?? '').'" placeholder="public"></div>'
            .'<div><label>SQLite file <span class="muted">(only for SQLite)</span></label><input type="text" name="tools_db_sqlite_path" value="'.$e($s['tools_db_sqlite_path'] ?? '').'" placeholder="/var/www/app/data.sqlite"></div>'
            .'</div>'
            .'<div class="hint">Give this account <b>SELECT only</b>. Tools cannot write - the query is checked before it is saved and again before it runs - but a read-only account means a mistake anywhere cannot cost you data.</div>'
            .'</div>'
            .'<div class="row" style="gap:10px;margin-top:16px"><button type="submit">'.Icons::get('check', 15).' Save</button>'
            .'<button type="submit" class="btn2" formaction="'.$e($testUrl).'">'.Icons::get('bolt', 15).' Test the connection</button></div>'
            .($result !== '' ? '<div class="'.($resultOk ? 'flash-ok' : 'flash-err').'" style="margin-top:12px">'.Icons::get($resultOk ? 'check' : 'escalation', 16).'<span>'.$e($result).'</span></div>' : '')
            .'</form></div>';
    }

    /**
     * "Describe it and I'll build it" on the Tools page. The assistant is shown
     * the owner's TABLE AND COLUMN NAMES only - never a row of their data - and
     * it runs nothing: it fills in the form, the owner tries it, the owner saves
     * it. The disclaimer says exactly that, because an owner handing their
     * schema to an AI deserves to know what leaves the building.
     */
    public static function toolAssistant(string $assistUrl, bool $providerReady, string $providersUrl, string $providerName = ''): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        if (!$providerReady) {
            return '<div class="bm-card"><div class="row" style="gap:12px;align-items:flex-start">'
                .'<span class="avatar">'.Icons::get('providers', 16).'</span>'
                .'<div><h2 style="margin:0">Let the AI build this for you</h2>'
                .'<div class="muted" style="margin-top:4px">Describe what you want the assistant to be able to look up, in your own words, and it writes the tool for you - you just try it and save it.</div>'
                .'<div class="hint" style="margin-top:10px">This needs an AI provider, the same key your chat uses. You have not connected one yet.</div>'
                .'<div style="margin-top:12px"><a class="btn2 btn-sm" href="'.$e($providersUrl).'">'.Icons::get('key', 14).' Connect an AI provider</a></div>'
                .'</div></div></div>';
        }
        return '<div class="bm-card" data-tool-assistant data-assist-url="'.$e($assistUrl).'">'
            .'<div class="bm-sec-h"><div><h2>Describe it, and it builds it</h2>'
            .'<div class="muted">Tell the assistant what you want it to be able to look up for a customer. It fills in the form below; you try it and save it.</div></div>'
            .($providerName !== '' ? '<div class="spacer"></div><span class="pill ai">'.$e($providerName).'</span>' : '')
            .'</div>'
            .'<div class="bm-assist-log" data-assist-log>'
            .'<div class="bm-assist-msg bot">What should the assistant be able to look up? For example: <i>"let a customer check the status of their order from the order number"</i>.</div>'
            .'</div>'
            .'<form class="bm-assist-ask" data-assist-form>'
            .'<textarea data-assist-input rows="1" placeholder="Describe it in your own words…" autocomplete="off"></textarea>'
            .'<button type="submit" class="btn2" data-assist-send>'.Icons::get('send', 15).' Ask</button>'
            .'</form>'
            .'<div class="hint" style="margin-top:12px">'
            .'<b>What is sent:</b> your table and column <i>names</i> and what you type here go to your own AI provider. '
            .'<b>Your data never does</b> - the assistant is not shown any rows and cannot run anything. '
            .'It only fills in the form below; the query is checked (read-only, one statement) and nothing becomes a tool until you press <i>Try it</i> and save.'
            .' Treat every draft as a suggestion: <b>run it and read the rows</b> before you save it.'
            .'</div></div>';
    }

    /** Live staff conversation view, on that page only (with the emoji picker). */
    public static function chatScript(): string
    {
        return self::script('emoji.js').self::script('markdown.js').self::script('chat.js');
    }

    private static function script(string $name): string
    {
        if (($url = self::assetUrl($name)) !== null) {
            return '<script src="'.self::e($url).'" defer></script>';
        }
        return '<script>'.Assets::content($name).'</script>';
    }

    /** Header button: staff can mute/unmute the new-message chime (remembered per browser). */
    public static function soundButton(): string
    {
        return '<button type="button" class="btn-ghost btn-icon" data-sound-toggle title="New message sound">'.Icons::get('bell', 16).'</button>';
    }

    /** The visual Tool Builder, on the tools page only. */
    public static function toolBuilderScript(): string
    {
        return self::script('toolbuilder.js');
    }

    /** Product mark + wordmark used in the sidebar and on the auth screen. */
    public static function logo(string $name = 'Banimark', string $sub = ''): string
    {
        return '<div class="bm-logo"></div><div><b>'.self::e($name).'</b>'
            .($sub !== '' ? '<span>'.self::e($sub).'</span>' : '').'</div>';
    }

    /**
     * One sidebar link.
     * @param array{href: string, icon: string, label: string, on?: bool} $item
     */
    public static function navLink(array $item): string
    {
        return '<a href="'.self::e($item['href']).'"'.(!empty($item['on']) ? ' class="on"' : '').'>'
            .Icons::get($item['icon']).'<span>'.self::e($item['label']).'</span></a>';
    }

    public static function themeButton(): string
    {
        return '<button type="button" class="btn2 btn-icon theme-btn" data-theme-toggle title="Toggle theme" aria-label="Toggle theme">'
            .'<span class="sun">'.Icons::get('sun', 16).'</span><span class="moon">'.Icons::get('moon', 16).'</span></button>';
    }

    public static function burger(): string
    {
        return '<button type="button" class="btn2 btn-icon bm-burger" data-nav-toggle aria-label="Menu">'.Icons::get('menu', 16).'</button>';
    }

    /** A dashboard stat tile. $delta is a signed percentage, or null when there is no baseline. */
    /**
     * A KPI card: label with the sparkline beside it, the big number, and the
     * change as a coloured pill. $deltaUnit '%' (a change in amount) or 'pts'
     * (a change in a rate). $invert when a rise is the bad direction.
     */
    public static function stat(string $label, string $value, string $icon = '', ?int $delta = null, string $foot = '', string $spark = '', string $deltaUnit = '%', bool $invert = false): string
    {
        $d = '';
        if ($delta !== null) {
            $good = $invert ? $delta < 0 : $delta > 0;
            $cls = $delta === 0 ? 'flat' : ($good ? 'up' : 'down');
            $arrow = $delta > 0 ? '&uarr;' : ($delta < 0 ? '&darr;' : '&rarr;');
            $sign = $delta > 0 ? '+' : ($delta < 0 ? '&minus;' : '');
            $d = '<span class="delta '.$cls.'" title="Compared with the period before">'.$arrow.' '.$sign.abs($delta).($deltaUnit === 'pts' ? ' pts' : '%').'</span>';
        }
        return '<div class="bm-card stat">'
            .'<div class="stat-h"><span class="k">'.($icon !== '' ? Icons::get($icon, 14) : '').self::e($label).'</span>'.$spark.'</div>'
            .'<span class="v">'.self::e($value).'</span>'
            .'<span class="foot">'.$d.($foot !== '' ? '<span>'.self::e($foot).'</span>' : '').'</span>'
            .'</div>';
    }

    /**
     * The 7 / 30 / 90 day switch - plain links, so it needs no script.
     *
     * @param array<int,string> $periods days => label
     * @param callable(int):string $url the link for a period
     */
    public static function periodSwitch(array $periods, int $current, callable $url): string
    {
        $out = '<nav class="seg" aria-label="Period">';
        foreach ($periods as $days => $label) {
            $on = $days === $current;
            $out .= '<a href="'.self::e($url($days)).'"'.($on ? ' class="on" aria-current="true"' : '').'>'.self::e($label).'</a>';
        }
        return $out.'</nav>';
    }

    /**
     * "Needs your attention": each item says what, why, and links to the fix.
     *
     * @param array<int, array{title: string, sub?: string, href?: string, icon?: string, tone?: string}> $items tone: ''|warn|bad
     */
    public static function attention(array $items, string $allClear = 'Nothing needs you right now.'): string
    {
        if ($items === []) {
            return '<div class="todo-empty">'.Icons::get('check', 16).'<span>'.self::e($allClear).'</span></div>';
        }
        $out = '<div class="todo">';
        foreach ($items as $it) {
            $tone = in_array($it['tone'] ?? '', ['warn', 'bad'], true) ? ' class="'.$it['tone'].'"' : '';
            $inner = '<span class="ic">'.Icons::get($it['icon'] ?? 'bolt', 16).'</span>'
                .'<span><b>'.self::e($it['title']).'</b>'.(($it['sub'] ?? '') !== '' ? '<small>'.self::e($it['sub']).'</small>' : '').'</span>'
                .(($it['href'] ?? '') !== '' ? '<span class="go" aria-hidden="true">&rarr;</span>' : '<span></span>');
            $out .= ($it['href'] ?? '') !== ''
                ? '<a href="'.self::e($it['href']).'"'.$tone.'>'.$inner.'</a>'
                : '<div class="item'.(($it['tone'] ?? '') !== '' ? ' '.self::e($it['tone']) : '').'">'.$inner.'</div>';
        }
        return $out.'</div>';
    }

    /**
     * A settings section: what it is and why on the left, the controls in a
     * card on the right (stacked on narrow screens). $body is trusted markup.
     */
    public static function section(string $title, string $desc, string $body, string $id = '', string $extra = ''): string
    {
        return '<section class="set"'.($id !== '' ? ' id="'.self::e($id).'"' : '').'>'
            .'<div class="set-intro"><h2>'.self::e($title).'</h2>'.($desc !== '' ? '<p>'.self::e($desc).'</p>' : '').$extra.'</div>'
            .'<div class="bm-card set-body">'.$body.'</div></section>';
    }

    /** The save bar at the foot of a settings form; sticky (always in reach) on single-form pages. */
    public static function saveBar(string $label = 'Save changes', string $note = '', bool $sticky = false): string
    {
        // sticky only where the page has ONE settings form: two sticky bars on
        // one page stack on top of each other at the foot of the window
        return '<div class="savebar'.($sticky ? ' sticky' : '').'">'.($note !== '' ? '<span class="muted">'.self::e($note).'</span>' : '')
            .'<button type="submit">'.Icons::get('check', 15).' '.self::e($label).'</button></div>';
    }

    /**
     * One option in a set of radio "cards".
     */
    public static function choice(string $name, string $value, bool $checked, string $title, string $hint = ''): string
    {
        return '<label class="choice"><input type="radio" name="'.self::e($name).'" value="'.self::e($value).'"'.($checked ? ' checked' : '').'>'
            .'<span><b>'.self::e($title).'</b>'.($hint !== '' ? '<small>'.self::e($hint).'</small>' : '').'</span></label>';
    }

    /**
     * The brand side of every sign-in screen (login, 2FA, activate, locked,
     * installer). Decorative on a phone - hidden below 960px by CSS.
     */
    public static function authArt(string $name = 'Banimark', string $sub = 'Support desk', string $title = '', array $points = [], bool $chat = true): string
    {
        $title = $title !== '' ? $title : 'Your AI front desk, on your own server.';
        $points = $points !== [] ? $points : [
            'Answers from your own records, only for the customer asking',
            'Hands the hard ones to your team, with the whole history',
            'Your data never leaves your infrastructure',
        ];
        $li = '';
        foreach ($points as $pt) {
            $li .= '<li>'.Icons::get('check', 16).self::e($pt).'</li>';
        }
        return '<aside class="auth-art" aria-hidden="true">'
            .'<div class="bm-brand">'.self::logo($name, $sub).'</div>'
            .'<div class="auth-copy"><h2>'.self::e($title).'</h2><ul>'.$li.'</ul></div>'
            .(!$chat ? '' : '<div class="auth-chat"><div class="bub me">Where is my order #4417?</div><div class="chip-line">'.Icons::get('bolt', 12).' order_status · your database</div>'
            .'<div class="bub them">It left the warehouse on Tuesday and arrives tomorrow before 6 pm.</div></div>')
            .'</aside>';
    }

    /** A stable colour class for someone's avatar: the same name, the same colour, everywhere. */
    public static function tone(string $seed): string
    {
        return 'av-'.(crc32(mb_strtolower(trim($seed)) ?: 'a') % 6);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
