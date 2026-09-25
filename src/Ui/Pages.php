<?php

namespace Banimark\Ui;

use Banimark\Ai\Behaviour;
use Banimark\Http\Flood;
use Banimark\Storage\Retention;
use Banimark\Storage\TeamStats;

/**
 * Page bodies shared by the Laravel panel and the standalone panel, so a
 * settings form exists exactly once. Each takes the URLs and the CSRF field
 * from the runtime and returns markup; behaviour is data-* only (host CSPs).
 */
final class Pages
{
    /** Release notes as a timeline, newest first. ONE body for both runtimes. */
    public static function releaseNotes(array $releases, string $installed): string
    {
        $e = [self::class, 'e'];
        $items = '';
        foreach ($releases as $r) {
            $v = (string) ($r['version'] ?? '');
            $on = $v === $installed;
            $items .= '<li class="'.($on ? 'on' : '').'"><div class="rel-h"><b>'.$e($v).'</b>'
                .($on ? '<span class="pill active">INSTALLED</span>' : '')
                .(!empty($r['test']) ? '<span class="pill expired">TEST</span>' : '')
                .'<span class="muted">'.$e((string) ($r['released_at'] ?? '')).'</span></div>'
                .'<div class="rel-notes">'.Markdown::toHtml((string) ($r['notes'] ?? '')).'</div></li>';
        }
        return Layout::section('Release notes', 'What changed in each version. Your installed version is highlighted.',
            $items !== '' ? '<ol class="releases">'.$items.'</ol>' : Chart::empty('No release notes right now', 'They appear here once Banimark HQ can be reached.'));
    }

    /**
     * Tools: what the assistant can look up, where the data lives, the
     * describe-it assistant and the builder. ONE body for both runtimes; the
     * builder script (toolbuilder.js) finds its hooks unchanged.
     *
     * @param array<int, array{name: string, description: string, params: string[], sql: string, max_rows: int, enabled: bool}> $rows
     * @param array $f form values: name, max_rows, description, enabled, params, kind, sql, columns_csv, context_csv, http_*
     * @param array{editing: bool, csrf: string, allowance: array, upgrade_url: string, assist_ready: bool, data_card: string,
     *   urls: array{save: string, delete: string, page: string, schema: string, try: string, assist: string, providers: string}} $o
     */
    public static function tools(array $rows, array $f, array $o): string
    {
        $e = [self::class, 'e'];
        $u = $o['urls'];
        $csrf = $o['csrf'];
        $editing = (bool) $o['editing'];
        $full = !$editing && $o['allowance']['full'];
        $edit = fn (string $name) => $u['page'].(str_contains($u['page'], '?') ? '&' : '?').'edit='.rawurlencode($name).'#build';

        $list = '';
        foreach ($rows as $r) {
            $needs = \Banimark\Tools\ToolTester::identityKeys((string) $r['sql']);
            $chips = '';
            foreach ($r['params'] as $pn) {
                $chips .= '<span class="chip-sm">'.$e($pn).'</span>';
            }
            $list .= '<div class="toolrow'.($r['enabled'] ? '' : ' off').'">'
                .'<span class="prov-ic">'.Icons::get('tools', 17).'</span>'
                .'<div class="toolrow-t"><b class="mono-name">'.$e($r['name']).'</b> <span class="pill '.($r['enabled'] ? 'good' : 'closed').'">'.($r['enabled'] ? 'ON' : 'OFF').'</span>'
                .'<p>'.$e(mb_strimwidth((string) $r['description'], 0, 160, '…')).'</p>'
                .'<div class="toolrow-m">'.($chips !== '' ? '<span class="muted">Asks for</span> '.$chips : '<span class="muted">Asks the customer for nothing</span>')
                .' <span class="muted">· up to '.(int) $r['max_rows'].' rows</span>'
                .($needs !== [] ? ' <span class="needs" title="The widget must be embedded with a signed token carrying these values">'.Icons::get('lock', 12).' signed-in visitors only ('.$e(implode(', ', $needs)).')</span>' : '').'</div></div>'
                .'<div class="toolrow-a"><a class="btn2 btn-sm" href="'.$e($edit($r['name'])).'">Edit</a>'
                .'<form method="post" action="'.$e($u['delete']).'">'.$csrf.'<input type="hidden" name="name" value="'.$e($r['name']).'">'
                .'<button class="btn-ghost btn-icon danger" data-confirm="Delete this tool?" title="Delete" aria-label="Delete '.$e($r['name']).'">'.Icons::get('trash', 15).'</button></form></div></div>';
        }
        if ($list === '') {
            $list = Chart::empty('No tools yet', 'The assistant can chat, but it cannot look anything up until you build one. Describe one below and it drafts it for you.');
        }

        $v = fn (string $k, $d = '') => $f[$k] ?? $d;
        $builder = '<div class="bm-card" id="build"><div class="bm-sec-h"><div>'
            .'<h2>'.($editing ? 'Edit tool: '.$e($v('name')) : 'Build a tool').'</h2>'
            .'<div class="muted">'.($editing ? 'Change anything below and save - the assistant uses the new version straight away.'
                : 'Three steps: name it, say what the assistant needs to ask the customer for, then point at your data. No SQL knowledge needed - the builder writes it for you.').'</div></div>'
            .'<div class="spacer"></div>'
            .($editing ? '<a class="btn-ghost btn-sm" href="'.$e($u['page']).'#build">Cancel - build a new one</a>' : (!$full ? Layout::allowance($o['allowance']) : ''))
            .'</div>'
            .($full ? Layout::allowance($o['allowance'], $o['upgrade_url']) : '')
            .'<form method="post" action="'.$e($u['save']).'">'.$csrf
            // only a NEW tool is blocked; an existing one stays editable whatever the plan says
            .'<fieldset class="bare '.($full ? 'is-locked' : '').'"'.($full ? ' disabled' : '').'>'
            .($editing ? '<input type="hidden" name="original_name" value="'.$e($v('name')).'">' : '')
            .'<h3 class="bm-step">What is this tool?</h3>'
            .'<div class="grid2"><div><label>Name <span class="muted">(letters, numbers, underscores)</span></label><input type="text" name="name" required placeholder="find_orders" value="'.$e($v('name')).'"></div>'
            .'<div><label>Most rows to return</label><input type="number" name="max_rows" value="'.(int) $v('max_rows', 10).'" min="1" max="50"></div></div>'
            .'<label>Describe it in plain words - the assistant reads this to know when to use it</label>'
            .'<textarea name="description" required placeholder="Look up a customer\'s orders by order number or by what they bought.">'.$e($v('description')).'</textarea>'
            .'<label class="check"><input type="checkbox" name="enabled" value="1"'.($v('enabled', true) ? ' checked' : '').'> Tool is on (the assistant may use it)</label>'
            .'<h3 class="bm-step">What should the assistant ask the customer for?</h3>'
            .'<div class="muted" style="margin-bottom:8px">Each item becomes a question the assistant can ask - an order number, a date, a product name.</div>'
            .'<div data-params data-prefill="'.$e(json_encode($v('params', []))).'"></div>'
            .'<button type="button" class="btn-ghost btn-sm" data-add-param>'.Icons::get('plus', 14).' Add another</button>'
            .Layout::toolSourceFields([
                'kind' => $v('kind', 'sql'), 'sql' => $v('sql'), 'columns_csv' => $v('columns_csv'), 'context_csv' => $v('context_csv', 'user_id'),
                'http_method' => $v('http_method', 'GET'), 'http_url' => $v('http_url'), 'http_headers' => $v('http_headers'), 'http_body' => $v('http_body'),
                'http_path' => $v('http_path'), 'http_fields' => $v('http_fields'), 'http_auth' => $v('http_auth', 'none'), 'http_auth_header' => $v('http_auth_header'),
                'http_auth_user' => $v('http_auth_user'), 'http_auth_issuer' => $v('http_auth_issuer', 'banimark'), 'http_auth_audience' => $v('http_auth_audience'),
                'http_auth_ttl' => $v('http_auth_ttl', \Banimark\Tools\HttpAuth::JWT_TTL), 'http_auth_has_secret' => $v('http_auth_has_secret', false),
            ], $u['schema'])
            .Layout::tryItCard($u['try'])
            .'<div class="row" style="margin-top:18px;justify-content:flex-end"><button type="submit">'.Icons::get('check', 15).' '.($editing ? 'Check and save changes' : 'Check and save tool').'</button></div></fieldset></form></div>';

        return '<div class="bm-card pad0"><div class="bm-sec-h" style="padding:18px 20px 12px;margin:0;border-bottom:1px solid var(--border)"><div><h2>Your tools</h2>'
                .'<div class="muted">Each tool is one question the assistant can answer from your data - "find this customer\'s orders". It can only read, and only what you allow.</div></div>'
                .'<div class="spacer"></div><a class="btn btn-sm" href="#build">'.Icons::get('plus', 14).' New tool</a></div>'
                .'<div class="toollist">'.$list.'</div></div>'
            .(isset($o['library']) ? self::toolTemplates($o['library'], $csrf) : '')
            .$o['data_card']
            .Layout::toolAssistant($u['assist'], (bool) $o['assist_ready'], $u['providers'])
            .$builder
            .Layout::toolBuilderScript();
    }

    /**
     * Ready-made tools on free public APIs - to learn how a tool is built, and
     * to show the assistant working on day one. Shown and disabled when the
     * plan has no room (never hidden): templates may use at most half of the
     * plan's tools (Entitlements::templateAllowance).
     *
     * @param array{installed: array<string,string>, allowance: array, url: string, edit: callable} $lib
     */
    public static function toolTemplates(array $lib, string $csrf): string
    {
        $e = [self::class, 'e'];
        $a = $lib['allowance'];
        $room = $a['left'] === null || $a['left'] > 0;
        $line = $a['left'] === null
            ? 'Your plan has no limit on tools, so you can add as many templates as you like.'
            : 'Templates can use up to <b>'.(int) $a['cap'].'</b> of your plan\'s '.(int) $a['limit'].' tools - half, so the rest stay free for your own. '
                .(int) $a['used'].' in use, '.(int) $a['left'].' more you can add now.';
        $groups = [];
        foreach (\Banimark\Library\ToolTemplates::all() as $slug => $t) {
            $groups[$t['group']][$slug] = $t;
        }
        $cards = '';
        foreach ($groups as $group => $items) {
            $cards .= '<div class="tpl-group"><h3>'.$e($group).'</h3><div class="tpl-grid">';
            foreach ($items as $slug => $t) {
                $have = $lib['installed'][$slug] ?? null;
                $action = $have !== null
                    ? '<span class="pill good">Added</span> <a class="btn2 btn-sm" href="'.$e(($lib['edit'])($have)).'">Open it</a>'
                    : '<form method="post" action="'.$e($lib['url']).'">'.$csrf.'<input type="hidden" name="template" value="'.$e($slug).'">'
                        .'<button type="submit" class="btn2 btn-sm"'.($room ? '' : ' disabled title="Your plan has no room for another template"').'>'.Icons::get('plus', 13).' Add this tool</button></form>';
                $cards .= '<div class="tpl-card" data-template="'.$e($slug).'">'
                    .'<b>'.$e($t['title']).'</b><p>'.$e($t['about']).'</p>'
                    .'<div class="tpl-meta"><span class="mono-name">'.$e($t['tool']['name']).'</span> · '.$e($t['api']).'</div>'
                    .'<div class="tpl-terms">'.$e($t['terms']).'</div>'
                    .'<div class="tpl-act">'.$action.'</div></div>';
            }
            $cards .= '</div></div>';
        }
        return '<div class="bm-card" id="templates"><div class="bm-sec-h"><div><h2>Templates: tools you can add in one click</h2>'
            .'<div class="muted">Each calls a free public service - no key, no database - so it works straight away. Add one, open it to see how it is built, press <b>Try it</b>, then build your own on your data the same way.</div></div></div>'
            .'<p class="tpl-allow'.($room ? '' : ' full').'">'.$line.'</p>'
            .(!$room && $a['left'] !== null ? Layout::lockedNote('Remove a template you no longer need to add another, or build your own tool - the rest of your allowance is for those.') : '')
            .$cards.'</div>';
    }

    /**
     * The rule library: a common pack and one per industry. Rules are text, so
     * every plan gets every pack. Rules already added show as added.
     *
     * @param array{installed: array<string,true>, url: string} $lib
     */
    public static function ruleLibrary(array $lib, string $csrf): string
    {
        $e = [self::class, 'e'];
        $packs = '';
        foreach (\Banimark\Library\RulePacks::all() as $slug => $p) {
            $n = count($p['rules']);
            $done = 0;
            $rows = '';
            foreach ($p['rules'] as $key => $r) {
                $have = isset($lib['installed'][$slug.'/'.$key]);
                $done += $have ? 1 : 0;
                $rows .= '<label class="lib-rule'.($have ? ' have' : '').'"><input type="checkbox" name="rules[]" value="'.$e($key).'"'.($have ? ' checked disabled' : ' checked').'>'
                    .'<span><b>'.$e($r[0]).'</b>'.($have ? ' <span class="pill good">added</span>' : '').'<br><span class="muted">'.$e($r[1]).'</span></span></label>';
            }
            $all = $done === $n;
            $packs .= '<details class="lib-pack" data-pack="'.$e($slug).'"><summary><b>'.$e($p['title']).'</b> <span class="muted">'.$e($p['blurb']).'</span>'
                .'<span class="spacer"></span><span class="pill '.($all ? 'good' : 'closed').'">'.$done.' of '.$n.' added</span></summary>'
                .'<form method="post" action="'.$e($lib['url']).'">'.$csrf.'<input type="hidden" name="pack" value="'.$e($slug).'">'
                .'<p class="muted" style="margin:6px 0 10px">'.($p['folder'] !== null ? 'Goes into its own folder, "'.$e($p['folder']).'", so you can switch the whole pack off in one click.' : 'Goes into your standard folders (Personality, Response behaviour, Business protection, Service rules).').' Untick any you do not want. Every rule can be edited after.</p>'
                .$rows
                .'<div class="row" style="justify-content:flex-end;margin-top:10px"><button type="submit" class="btn2"'.($all ? ' disabled' : '').'>'.Icons::get('plus', 14).' Add the ticked rules</button></div>'
                .'</form></details>';
        }
        return '<div class="bm-card" id="library"><div class="bm-sec-h"><div><h2>Rule library</h2>'
            .'<div class="muted">Ready-made rules for every business, and packs for common industries - VTU &amp; data, online shops, fintech, logistics, and more. Add a pack, then make it yours.</div></div></div>'
            .'<div class="lib-packs">'.$packs.'</div></div>';
    }

    /**
     * Rules: folders of plain-sentence rules, applied top to bottom. ONE body
     * for both runtimes; panel.js drives the folds ([data-collapse]).
     *
     * @param array{csrf: string, urls: array{folder: string, folder_move: string, folder_delete: string, save: string, move: string, delete: string}} $o
     */
    public static function rules(array $folders, array $o): string
    {
        $e = [self::class, 'e'];
        $u = $o['urls'];
        $csrf = $o['csrf'];
        $post = fn (string $url, array $hidden, string $button) => '<form method="post" action="'.$e($url).'">'.$csrf
            .implode('', array_map(fn ($k, $v) => '<input type="hidden" name="'.$e($k).'" value="'.$e($v).'">', array_keys($hidden), $hidden)).$button.'</form>';
        $nF = count($folders);
        $total = array_sum(array_map(fn ($f) => count($f['rules']), $folders));

        $library = isset($o['library']) ? self::ruleLibrary($o['library'], $csrf) : '';
        $html = '<div class="page-head"><div><h2>Your rules</h2><p>'.$nF.' '.($nF === 1 ? 'folder' : 'folders').', '.$total.' '.($total === 1 ? 'rule' : 'rules')
            .'. Folders apply top to bottom; the assistant follows them before anything else.</p></div>'
            .'<div class="row" style="gap:6px;flex-wrap:wrap">'
            .'<button type="button" class="btn-ghost btn-sm" data-collapse-all="open">Expand all</button>'
            .'<button type="button" class="btn-ghost btn-sm" data-collapse-all="close">Collapse all</button>'
            .'<button type="button" class="btn-sm" data-reveal="#new-folder">'.Icons::get('plus', 15).' New folder</button></div></div>'
            .'<div class="bm-card" id="new-folder" hidden><h2>New folder</h2>'
            .'<div class="muted">A folder groups related rules - Personality, Refund policy, Opening hours.</div>'
            .'<form method="post" action="'.$e($u['folder']).'">'.$csrf
            .'<div class="grid2"><div><label>Folder name</label><input type="text" name="title" required placeholder="Refund policy"></div>'
            .'<div><label>What goes in here <span class="muted">(optional)</span></label><input type="text" name="description" placeholder="What the assistant may and may not promise about refunds"></div></div>'
            .'<div class="row" style="margin-top:14px;justify-content:flex-end"><button type="button" class="btn-ghost" data-dismiss=".bm-card">Cancel</button><button type="submit">Create folder</button></div></form></div>';

        if ($folders === []) {
            $html .= '<div class="bm-card">'.Chart::empty('No folders yet', 'Create a folder, then add rules to it.').'</div>';
        }
        foreach ($folders as $fi => $f) {
            $fid = (int) $f['id'];
            $nR = count($f['rules']);
            // the header is the toggle; its buttons still work without toggling
            $html .= '<div class="bm-card pad0 folder'.($f['enabled'] ? '' : ' off').'" data-collapsible>'
                .'<div class="folder-h bm-fold" data-collapse="folder-'.$fid.'" title="Click to open or close this folder">'
                .'<span class="folder-n">'.($fi + 1).'</span>'
                .'<div class="folder-t"><h2>'.$e($f['title']).($f['enabled'] ? '' : ' <span class="pill closed">OFF</span>').'</h2>'
                .'<small>'.$nR.' '.($nR === 1 ? 'rule' : 'rules').($f['description'] !== '' ? ' · '.$e($f['description']) : '').'</small></div>'
                .'<div class="folder-a">'
                .$post($u['folder_move'], ['id' => $fid, 'direction' => -1], '<button class="btn-ghost btn-icon" title="Move up" aria-label="Move folder up"'.($fi === 0 ? ' disabled' : '').'>&uarr;</button>')
                .$post($u['folder_move'], ['id' => $fid, 'direction' => 1], '<button class="btn-ghost btn-icon" title="Move down" aria-label="Move folder down"'.($fi === $nF - 1 ? ' disabled' : '').'>&darr;</button>')
                .'<button type="button" class="btn-ghost btn-sm" data-toggle="#edit-folder-'.$fid.'">Edit</button>'
                .$post($u['folder_delete'], ['id' => $fid], '<button class="btn-ghost btn-icon danger" title="Delete folder and its rules" aria-label="Delete folder" data-confirm="Delete this folder and every rule in it?">'.Icons::get('trash', 15).'</button>')
                .'</div><span class="bm-chevron" aria-hidden="true"></span></div>'
                .'<form method="post" action="'.$e($u['folder']).'" id="edit-folder-'.$fid.'" hidden class="folder-edit">'.$csrf
                .'<input type="hidden" name="id" value="'.$fid.'"><div class="grid2"><div><label>Folder name</label><input type="text" name="title" value="'.$e($f['title']).'" required></div>'
                .'<div><label>Description</label><input type="text" name="description" value="'.$e($f['description']).'"></div></div>'
                .'<div class="row" style="margin-top:12px;gap:14px;justify-content:space-between"><label class="check" style="margin:0"><input type="checkbox" name="enabled" value="1"'.($f['enabled'] ? ' checked' : '').'> Folder is active</label>'
                .'<button type="submit" class="btn-sm">Save folder</button></div></form>'
                .'<div data-collapse-body hidden class="folder-b">';
            if ($nR === 0) {
                $html .= '<div class="muted" style="padding:14px 0">No rules in this folder yet - add the first one below.</div>';
            }
            foreach ($f['rules'] as $ri => $r) {
                $rid = (int) $r['id'];
                $html .= '<div class="rule'.($r['enabled'] ? '' : ' off').'">'
                    .'<div class="rule-t">'.($r['title'] !== '' ? '<b>'.$e($r['title']).'</b>' : '').($r['enabled'] ? '' : ' <span class="pill closed">OFF</span>')
                    .'<p>'.$e($r['content']).'</p>'
                    .'<form method="post" action="'.$e($u['save']).'" id="edit-rule-'.$rid.'" hidden class="rule-edit">'.$csrf
                    .'<input type="hidden" name="id" value="'.$rid.'"><input type="text" name="title" value="'.$e($r['title']).'" placeholder="Short title (optional)">'
                    .'<textarea name="content" required style="margin-top:8px">'.$e($r['content']).'</textarea>'
                    .'<div class="row" style="margin-top:10px;gap:14px;justify-content:space-between"><label class="check" style="margin:0"><input type="checkbox" name="enabled" value="1"'.($r['enabled'] ? ' checked' : '').'> Active</label>'
                    .'<button type="submit" class="btn-sm">Save rule</button></div></form></div>'
                    .'<div class="rule-a">'
                    .$post($u['move'], ['id' => $rid, 'direction' => -1], '<button class="btn-ghost btn-icon" title="Up" aria-label="Move rule up"'.($ri === 0 ? ' disabled' : '').'>&uarr;</button>')
                    .$post($u['move'], ['id' => $rid, 'direction' => 1], '<button class="btn-ghost btn-icon" title="Down" aria-label="Move rule down"'.($ri === $nR - 1 ? ' disabled' : '').'>&darr;</button>')
                    .'<button type="button" class="btn-ghost btn-sm" data-toggle="#edit-rule-'.$rid.'">Edit</button>'
                    .$post($u['delete'], ['id' => $rid], '<button class="btn-ghost btn-icon danger" title="Delete" aria-label="Delete rule" data-confirm="Delete this rule?">'.Icons::get('trash', 15).'</button>')
                    .'</div></div>';
            }
            $html .= '<form method="post" action="'.$e($u['save']).'" class="rule-add">'.$csrf
                .'<input type="hidden" name="folder_id" value="'.$fid.'">'
                .'<input type="text" name="title" placeholder="Short title (optional)" aria-label="Rule title">'
                .'<input type="text" name="content" required placeholder="Add a rule to '.$e($f['title']).'…" aria-label="Rule">'
                .'<button type="submit" class="btn2">'.Icons::get('plus', 14).' Add</button></form></div></div>';
        }
        return $html.$library;
    }

    /**
     * Widget: how the chat looks and greets, with a live preview beside it
     * (panel.js [data-widget-preview]), and how to put it anywhere - website,
     * a shareable link, a Flutter app. ONE body for both runtimes.
     *
     * @param array{csrf: string, save_url: string, widget_js: string, chat_page_url: string, token_snippet: string,
     *   flutter: ?array, flutter_lock: ?string, whitelabel_lock: ?string, upgrade_url: string, support_email: string, flutter_config: string} $o
     */
    public static function widget(array $cfg, array $o): string
    {
        $e = [self::class, 'e'];
        $g = fn (string $k, string $d = '') => (string) ($cfg[$k] ?? $d);
        $sel = fn (string $k, string $v, string $d) => $g($k, $d) === $v ? ' selected' : '';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $g('color')) ? $g('color') : '#6F04D9';
        $starters = array_slice(array_values(array_filter(array_map('trim', explode("\n", $g('starters'))))), 0, 6);

        $W = \Banimark\Http\WidgetConfig::class;
        $options = function (string $key, array $choices) use ($g, $e): string {
            $cur = $g($key, (string) \Banimark\Http\WidgetConfig::DEFAULTS[$key]);
            $out = '';
            foreach ($choices as $v => $label) {
                $out .= '<option value="'.$e((string) $v).'"'.((string) $v === $cur ? ' selected' : '').'>'.$e($label).'</option>';
            }
            return $out;
        };
        $logo = \Banimark\Http\WidgetLogo::fromSettings($cfg);
        $logoSrc = $logo === null ? '' : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']);

        $appearance = '<div class="grid2">'
            .'<div><label>Accent colour</label><div class="colorfield"><input type="color" value="'.$e($color).'" data-color-pick aria-label="Pick a colour">'
            .'<input type="text" name="color" value="'.$e($color).'" placeholder="#6F04D9" data-color-text></div></div>'
            .'<div><label>Position</label><select name="position"><option value="right"'.$sel('position', 'right', 'right').'>Bottom right</option><option value="left"'.$sel('position', 'left', 'right').'>Bottom left</option></select></div>'
            .'<div><label>Theme</label><select name="theme"><option value="auto"'.$sel('theme', 'auto', 'auto').'>Auto - follow the visitor\'s device</option><option value="light"'.$sel('theme', 'light', 'auto').'>Always light</option><option value="dark"'.$sel('theme', 'dark', 'auto').'>Always dark</option></select></div>'
            .'<div><label>Header title</label><input type="text" name="title" value="'.$e($g('title', 'Support')).'" maxlength="60"></div>'
            .'<div><label>Line under the title <span class="muted">(optional)</span></label><input type="text" name="status_line" value="'.$e($g('status_line')).'" maxlength="80" placeholder="We typically reply in a moment"></div>'
            .'<div><label>Corners</label><select name="corner">'.$options('corner', $W::CORNERS).'</select></div>'
            .'<div><label>Spacing</label><select name="density">'.$options('density', $W::DENSITIES).'</select></div>'
            .'</div>'
            .'<label>Logo or team photo in the header <span class="muted">(optional)</span></label>'
            .'<div class="logofield"><span class="logo-now" data-logo-now>'.($logoSrc !== '' ? '<img src="'.$e($logoSrc).'" alt="Current logo">' : Icons::get('image', 18)).'</span>'
            .'<div><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif" data-logo-file>'
            .'<input type="hidden" name="logo_data" value="" data-logo-data>'
            .($logoSrc !== '' ? '<label class="check" style="margin:6px 0 0"><input type="checkbox" name="logo_remove" value="1" data-logo-remove> Remove the logo</label>' : '')
            .'<div class="hint">PNG, JPEG, GIF or WebP. It is shrunk to 128 pixels before upload. Without one, the header shows a chat icon.</div></div></div>'
            .'<div class="hint">Applies to the website widget, the shareable chat link and the Flutter SDK (when it follows your settings).</div>'
            .Layout::brandingToggle($g('hide_brand', '0') === '1', $o['whitelabel_lock'], $o['upgrade_url']);

        $launcher = '<div class="grid2">'
            .'<div><label>Icon</label><select name="launcher_icon">'.$options('launcher_icon', $W::LAUNCHER_ICONS).'</select></div>'
            .'<div><label>Label beside the icon <span class="muted">(optional)</span></label><input type="text" name="launcher_label" value="'.$e($g('launcher_label')).'" maxlength="30" placeholder="Chat with us"></div>'
            .'</div><div class="hint">With a label the button becomes a wider pill, which more people notice. Leave it empty for a round button.</div>';

        $appears = '<div class="grid2">'
            .'<div><label>Before anyone clicks</label><select name="auto_open">'.$options('auto_open', $W::AUTO_OPEN).'</select></div>'
            .'<div><label>When</label><select name="auto_open_after">'.$options('auto_open_after', $W::AUTO_OPEN_AFTER).'</select></div>'
            .'</div>'
            .'<div class="hint">"Open the chat by itself" happens once per visit, and on phones it shows the bubble instead - a chat covering the whole screen chases people away.</div>'
            .'<label>Only on these pages <span class="muted">(one path per line; empty = every page)</span></label>'
            .'<textarea name="auto_open_pages" rows="2" placeholder="/pricing&#10;/checkout*">'.$e($g('auto_open_pages')).'</textarea>'
            .'<div class="divider"></div>'
            .'<div class="grid2">'
            .'<div><label>Show the widget only on <span class="muted">(empty = everywhere)</span></label>'
            .'<textarea name="show_on" rows="3" placeholder="/shop*&#10;/help/*">'.$e($g('show_on')).'</textarea></div>'
            .'<div><label>Never show it on</label>'
            .'<textarea name="hide_on" rows="3" placeholder="/admin*&#10;/checkout/pay">'.$e($g('hide_on')).'</textarea></div>'
            .'</div>'
            .'<div class="hint">Paths start with <code>/</code>. A <code>*</code> matches anything, so <code>/blog/*</code> covers every post. "Never" wins over "only". A visitor who already has a conversation going still gets replies on the pages where the widget shows. The shareable chat link ignores these rules.</div>';

        $behaviour = '<div class="grid2">'
            .'<div><label>Check for replies every</label><div class="row"><input type="number" name="poll_seconds" min="3" max="600" value="'.$e($g('poll_seconds', '10')).'" style="max-width:120px"><span class="muted">seconds</span></div>'
            .'<div class="hint">Only while the chat is open. This is also the visitor\'s heartbeat.</div></div>'
            .'<div><label>While the chat is closed, check every</label><div class="row"><input type="number" name="poll_idle_seconds" min="10" max="600" value="'.$e($g('poll_idle_seconds', '30')).'" style="max-width:120px"><span class="muted">seconds</span></div>'
            .'<div class="hint">A reply from your team shows as an unread count on the launcher (9+ past nine) on the website and in the app. Slower is kinder to your server.</div></div>'
            .'<div><label>Bring a dismissed launcher back after</label><div class="row"><input type="number" name="launcher_reappear_minutes" min="0" max="1440" value="'.$e($g('launcher_reappear_minutes', '10')).'" style="max-width:120px"><span class="muted">minutes</span></div>'
            .'<div class="hint">On the website and in the app a visitor can drag the chat bubble anywhere (it stays where they put it) and close it with its small ×; it returns after this long. 0 = not until they open the app again. A reply from your team always brings it back.</div></div>'
            .'<div><label>Ask guests who they are</label><select name="guest_mode">'
            .'<option value="off"'.$sel('guest_mode', 'off', 'off').'>Off - chat straight away</option>'
            .'<option value="optional"'.$sel('guest_mode', 'optional', 'off').'>Optional - offer, allow skip</option>'
            .'<option value="required"'.$sel('guest_mode', 'required', 'off').'>Required - name and email first</option></select>'
            .'<div class="hint">An email address is what lets us follow up when they leave.</div></div></div>'
            .'<label class="check" style="margin-top:12px"><span class="switch"><input type="checkbox" name="sound" value="1"'.($g('sound', '1') !== '0' ? ' checked' : '').'><span class="sl"></span></span> Play a soft chime when your team replies</label>'
            .'<div class="hint">Browsers only allow it after the visitor has clicked in the chat.</div>'
            .'<label>Note shown when nobody is around <span class="muted">(optional)</span></label>'
            .'<input type="text" name="offline_note" value="'.$e($g('offline_note')).'" placeholder="We usually reply within a few hours.">';

        $chips = '';
        foreach ($starters as $st) {
            $chips .= '<span>'.$e($st).'</span>';
        }
        // what "Reset the look" puts back - the widget's own defaults, not this install's saved values
        $resetTo = json_encode(array_intersect_key($W::DEFAULTS + ['status_line' => ''], array_flip(['color', 'position', 'theme', 'title', 'status_line', 'corner', 'density', 'launcher_icon', 'launcher_label'])), JSON_UNESCAPED_SLASHES);
        $greeting = $g('greeting') !== '' ? $g('greeting') : 'Hi! How can we help you today?';
        $seg = fn (string $name, array $opts) => '<div class="seg" role="group" data-wp-toggle="'.$e($name).'">'
            .implode('', array_map(fn ($v, $l) => '<button type="button" data-v="'.$e($v).'"'.($v === array_key_first($opts) ? ' class="on"' : '').'>'.$e($l).'</button>', array_keys($opts), $opts)).'</div>';
        $preview = '<aside class="wprev" data-widget-preview style="--wc:'.$e($color).'" data-pos="'.$e($g('position', 'right')).'" data-theme="'.$e($g('theme', 'auto')).'"'
            .' data-corner="'.$e($g('corner', 'rounded')).'" data-density="'.$e($g('density', 'comfortable')).'" data-device="desktop" data-hours="open" data-look="'.($g('theme', 'auto') === 'dark' ? 'dark' : 'light').'">'
            .'<div class="wprev-label">Live preview</div>'
            .'<div class="wprev-tools">'.$seg('shade', ['' => 'As set', 'light' => 'Light', 'dark' => 'Dark']).$seg('device', ['desktop' => 'Desktop', 'phone' => 'Phone']).$seg('hours', ['open' => 'Team in', 'away' => 'Out of hours']).'</div>'
            .'<div class="wprev-stage"><div class="wprev-panel">'
            .'<div class="wprev-head"><span class="wprev-av" data-wp-logo>'.($logoSrc !== '' ? '<img src="'.$e($logoSrc).'" alt="">' : '').'</span><b data-wp-title>'.$e($g('title', 'Support')).'</b>'
            .'<small><span class="wprev-dot"></span><span data-wp-status>'.$e($g('status_line') !== '' ? $g('status_line') : 'We typically reply in a moment').'</span></small></div>'
            .'<div class="wprev-body"><div class="wprev-bub" data-wp-greeting data-open="'.$e($greeting).'" data-away="'.$e($g('away_greeting')).'">'.$e($greeting).'</div>'
            .'<div class="wprev-chips" data-wp-starters>'.$chips.'</div>'
            .'<div class="wprev-bub mine">Where is my order?</div></div>'
            .'<div class="wprev-input"><span>Type a message…</span><i></i></div>'
            .($g('hide_brand', '0') === '1' ? '' : '<div class="wprev-brand">Powered by Banimark</div>')
            .'</div><div class="wprev-launch" data-wp-launch data-icon="'.$e($g('launcher_icon', 'chat')).'">'
            .implode('', array_map(fn ($k) => '<i data-for="'.$k.'">'.Icons::get($k === 'chat' ? 'chat' : $k, 22).'</i>', array_keys($W::LAUNCHER_ICONS)))
            .'<span data-wp-label>'.$e($g('launcher_label')).'</span></div></div>'
            .'<div class="row wprev-actions"><button type="button" class="btn2 btn-sm" data-widget-reset=\''.$e($resetTo).'\'>'.Icons::get('refresh', 14).' Reset the look</button>'
            .(!empty($o['try_url']) ? '<a class="btn2 btn-sm" href="'.$e($o['try_url']).'" target="_blank" rel="noopener">'.Icons::get('external', 14).' Try it on a test page</a>' : '').'</div>'
            .'<p class="muted" style="margin:10px 2px 0">Changes show here as you type. Save to put them live. "Reset the look" only fills in the defaults - nothing changes until you save.</p></aside>';

        $form = '<form method="post" action="'.$e($o['save_url']).'" class="set-form" enctype="multipart/form-data">'.$o['csrf']
            .'<div class="bm-card"><h2>Look</h2><p class="muted" style="margin:2px 0 12px">Your colour, title, logo and where it sits on the page.</p>'.$appearance.'</div>'
            .'<div class="bm-card"><h2>Launcher button</h2><p class="muted" style="margin:2px 0 12px">The button in the corner of every page.</p>'.$launcher.'</div>'
            .self::firstRun($cfg)
            .'<div class="bm-card"><h2>When it appears</h2><p class="muted" style="margin:2px 0 12px">Whether it greets people by itself, and which pages carry it.</p>'.$appears.'</div>'
            .'<div class="bm-card"><h2>Behaviour</h2><p class="muted" style="margin:2px 0 12px">How often it checks for replies, whether guests say who they are, and the reply chime.</p>'.$behaviour.'</div>'
            .Layout::saveBar('Save changes', '', true).'</form>';

        $flutter = $o['flutter'];
        $flutterBody = ($flutter ? '<span class="pill active" style="margin-bottom:10px">banimark_flutter '.$e($flutter['version'] ?? '').'</span>' : '');
        if ($o['flutter_lock'] !== null) {
            $flutterBody .= Layout::lockedNote($o['flutter_lock'], $o['upgrade_url']);
        } elseif ($flutter) {
            $flutterBody .= '<div class="row" style="gap:10px;margin:0 0 10px">'
                .(!empty($flutter['url']) ? '<a class="btn2 btn-sm" href="'.$e($flutter['url']).'" target="_blank" rel="noopener">'.Icons::get('widget', 14).' Get the SDK</a>' : '')
                .(!empty($flutter['notes']) ? '<span class="muted">'.$e($flutter['notes']).'</span>' : '').'</div>';
        } else {
            $flutterBody .= '<div class="hint" style="margin:0 0 10px">Your vendor publishes the SDK\'s version and download link here.'.($o['support_email'] !== '' ? ' Ask '.$e($o['support_email']).' for access.' : '').'</div>';
        }
        $flutterBody .= '<label style="margin-top:0">Drop it in a route, a bottom sheet or a tab:</label>'
            .'<textarea readonly rows="5" data-select-all class="code">'.$e($o['flutter_config']).'</textarea>'
            .'<div class="hint">Everything is themeable - colours, radii, avatars, every string. The SDK\'s README covers it.</div>';

        return '<div class="with-preview"><div>'.$form.'</div>'.$preview.'</div>'
            .Layout::section('Put it on your website', 'One line before </body>. For signed-in customers, add a token so the assistant can look up their own records - and only theirs.',
                '<label style="margin-top:0">Anonymous visitors</label>'
                .'<textarea readonly rows="2" data-select-all class="code">&lt;script src="'.$e($o['widget_js']).'" defer&gt;&lt;/script&gt;</textarea>'
                .'<label>Signed-in customers - mint a token on your server</label>'
                .'<textarea readonly rows="4" data-select-all class="code">'.$e($o['token_snippet']).'</textarea>'
                .'<div class="hint">Pass it as <code>data-token</code> on the script tag. The AI can never set these values itself.</div>'
                .'<label>Or pass known details - to label the chat and follow up by email, never to scope a lookup</label>'
                .'<textarea readonly rows="3" data-select-all class="code">window.__BANIMARK_CFG = Object.assign(window.__BANIMARK_CFG || {}, {
  user: { name: "Ada Lovelace", email: "ada@example.com" }
});</textarea>')
            .Layout::section('Share it as a link', 'The same chat as a full page - for email signatures, QR codes, SMS, or anywhere the widget cannot be embedded.',
                '<textarea readonly rows="1" data-select-all class="code">'.$e($o['chat_page_url']).'</textarea>'
                .'<div class="hint">Signed-in users: append <code>?t=</code> and a <code>VisitorToken</code> minted server-side (24 h) so their lookups are scoped. Never put a long-lived token in an email.</div>')
            .Layout::section('Mobile apps (Flutter)', 'The same chat, native in your iOS and Android app - human handover with live replies, resumes where the visitor left off, guest mode.', $flutterBody);
    }

    /**
     * Staff: who can sign in, what each person may do, the 2FA policy and the
     * invitation form. ONE body for both runtimes. Owners only - the caller
     * decides who sees it.
     *
     * @param array{me_id: int, require_2fa: bool, seats: array, upgrade_url: string, csrf: string,
     *   urls: array{save: string, delete: string, reinvite: string, permissions: string, totp_reset: string, totp_require: string, security: string}} $o
     */
    public static function staff(array $rows, array $o): string
    {
        $e = [self::class, 'e'];
        $u = $o['urls'];
        $csrf = $o['csrf'];
        $P = \Banimark\Auth\Permissions::class;
        $permBoxes = function (array $have) use ($e, $P): string {
            $out = '<div class="bm-perms">';
            foreach ($P::ALL as $key => $label) {
                $out .= '<label><input type="checkbox" name="perms[]" value="'.$e($key).'"'.(in_array($key, $have, true) ? ' checked' : '').'> <b>'.$e($key).'</b> <span class="muted">'.$e($label).'</span></label>';
            }
            return $out.'</div>';
        };
        $presetSelect = function (string $current, string $target) use ($e, $P): string {
            $out = '<select name="preset" data-preset-for="'.$e($target).'">';
            foreach ($P::PRESETS as $k => $preset) {
                $out .= '<option value="'.$e($k).'"'.($current === $k ? ' selected' : '').'>'.$e($preset['label']).'</option>';
            }
            return $out.'<option value="custom"'.($current === 'custom' ? ' selected' : '').'>Custom - tick below</option></select>';
        };
        $hidden = fn (int $id) => $csrf.'<input type="hidden" name="id" value="'.$id.'">';

        $people = '';
        foreach ($rows as $a) {
            $id = (int) $a['id'];
            $perms = $P::of($a);
            $preset = $P::presetOf($perms);
            $pending = ($a['status'] ?? 'active') === 'pending';
            $owner = $a['role'] === 'owner';
            $status = $pending ? '<span class="pill expired" title="Invited '.$e($a['invited_at'] ?? '').'">INVITED</span>'
                : '<span class="pill '.($a['enabled'] ? 'good' : 'closed').'">'.($a['enabled'] ? 'ACTIVE' : 'DISABLED').'</span>';
            $people .= '<div class="person">'
                .'<span class="avatar lg '.Layout::tone((string) $a['name']).'">'.$e(strtoupper(mb_substr((string) $a['name'], 0, 1))).'</span>'
                .'<div class="person-id"><b>'.$e($a['name']).($id === (int) $o['me_id'] ? ' <span class="muted">(you)</span>' : '').'</b><small>'.$e($a['email']).'</small></div>'
                .'<div class="person-tags"><span class="pill '.($owner ? 'ai' : 'agent').'">'.($owner ? 'OWNER' : 'STAFF').'</span>'
                .(!$owner ? '<span class="pill closed">'.$e(strtoupper($P::PRESETS[$preset]['label'] ?? 'custom')).'</span>' : '')
                .$status
                .'<span class="pill '.(!empty($a['totp_enabled']) ? 'good' : 'closed').'">2FA '.(!empty($a['totp_enabled']) ? 'ON' : 'OFF').'</span></div>'
                .'<div class="person-a">'
                .(!$owner ? '<button type="button" class="btn2 btn-sm" data-toggle="#access-'.$id.'">Access</button>' : '')
                .($pending ? '<form method="post" action="'.$e($u['reinvite']).'">'.$hidden($id).'<button class="btn2 btn-sm" title="Send a fresh activation link">Resend invite</button></form>' : '')
                .(!empty($a['totp_enabled']) ? '<form method="post" action="'.$e($u['totp_reset']).'">'.$hidden($id).'<button class="btn-ghost btn-sm" data-confirm="Reset 2FA for '.$e($a['name']).'? They sign in with just their password until they enrol again.">Reset 2FA</button></form>' : '')
                .'<form method="post" action="'.$e($u['delete']).'">'.$hidden($id).'<button class="btn-ghost btn-icon danger" data-confirm="Remove this staff account?" title="Remove" aria-label="Remove '.$e($a['name']).'">'.Icons::get('trash', 15).'</button></form>'
                .'</div>'
                .(!$owner ? '<div class="person-access" id="access-'.$id.'" hidden>'
                    .'<form method="post" action="'.$e($u['permissions']).'">'.$hidden($id)
                    .'<div class="grid2"><div><label>Role</label><select name="role"><option value="agent" selected>Staff</option><option value="owner">Owner - full control</option></select></div>'
                    .'<div><label>Preset</label>'.$presetSelect($preset, '#access-'.$id).'</div></div>'
                    .'<label>What '.$e($a['name']).' can do</label>'.$permBoxes($perms)
                    .'<div style="margin-top:12px;text-align:right"><button type="submit" class="btn-sm">'.Icons::get('check', 14).' Save access</button></div></form></div>' : '')
                .'</div>';
        }

        $seats = $o['seats'];
        return Layout::section('Your team', 'Everyone who can sign in to this panel. Owners can do everything; staff get the access you choose.',
                '<div class="people">'.$people.'</div>')
            .Layout::section('Two-factor policy', 'When on, every staff member - owners included - must set up an authenticator app before they can use the panel. Anyone locked out can be reset above.',
                '<form method="post" action="'.$e($u['totp_require']).'" class="row" style="gap:14px;flex-wrap:wrap">'.$csrf
                .'<label class="check" style="margin:0"><span class="switch"><input type="checkbox" name="require_2fa" value="1"'.($o['require_2fa'] ? ' checked' : '').'><span class="sl"></span></span> Require 2FA for all staff</label>'
                .'<span class="spacer" style="margin-left:auto"></span>'
                .'<a class="btn-ghost btn-sm" href="'.$e($u['security']).'">'.Icons::get('shield', 14).' My own 2FA</a>'
                .'<button type="submit" class="btn2 btn-sm">Save policy</button></form>')
            .Layout::section('Invite a colleague', 'They get an email with a link to choose their own password. The account stays pending and cannot sign in until they do.',
                (!$seats['full'] ? '<div style="margin-bottom:10px">'.Layout::allowance($seats).'</div>' : Layout::allowance($seats, $o['upgrade_url']))
                .'<form method="post" action="'.$e($u['save']).'">'.$csrf
                .'<fieldset class="bare '.($seats['full'] ? 'is-locked' : '').'"'.($seats['full'] ? ' disabled' : '').'>'
                .'<div class="grid2"><div><label>Name</label><input type="text" name="name" required></div>'
                .'<div><label>Email <span class="muted">(their login - the invitation goes here)</span></label><input type="text" name="email" required></div>'
                .'<div><label>Role</label><select name="role"><option value="agent">Staff - access set below</option><option value="owner">Owner - full control</option></select></div>'
                .'<div><label>Access preset</label>'.$presetSelect('agent', '#invite-perms').'</div></div>'
                .'<div id="invite-perms" style="margin-top:12px">'.$permBoxes($P::preset('agent')).'</div>'
                .'<div style="margin-top:16px;text-align:right"><button type="submit">'.Icons::get('send', 15).' Send invitation</button></div></fieldset></form>');
    }

    /**
     * AI providers: which one answers the chat, and the form to add or edit
     * one. ONE body for both runtimes.
     *
     * @param array<int, array{slug: string, driver: string, model: string, base_url: string, temperature: float|string, enabled: bool, has_key: bool}> $rows
     * @param array|null $editing the provider being edited (its key never reaches the form)
     * @param array{csrf: string, urls: array{save: string, activate: string, delete: string, page: string}} $o
     */
    public static function providers(array $rows, ?array $editing, array $o): string
    {
        $e = [self::class, 'e'];
        $u = $o['urls'];
        $csrf = $o['csrf'];
        $edit = fn (string $slug) => $u['page'].(str_contains($u['page'], '?') ? '&' : '?').'edit='.rawurlencode($slug).'#edit';

        $cards = '';
        foreach ($rows as $r) {
            $on = (bool) $r['enabled'];
            $label = (\Banimark\Ai\ProviderPresets::MODELS[$r['driver']][$r['model']] ?? $r['model'])
                .' — '.\Banimark\Ai\ProviderPresets::capabilityClause((string) $r['driver'], (string) $r['model']);
            $cards .= '<div class="prov'.($on ? ' on' : '').'">'
                .'<div class="prov-h"><span class="prov-ic">'.Icons::get('providers', 18).'</span>'
                .'<div><b>'.$e($r['slug']).'</b><small>'.$e(['gemini' => 'Google Gemini', 'anthropic' => 'Anthropic Claude', 'openai-compat' => 'OpenAI-compatible'][$r['driver']] ?? $r['driver']).'</small></div>'
                .($on ? '<span class="pill good">ANSWERING</span>' : '<span class="pill closed">STANDBY</span>').'</div>'
                .'<dl class="facts"><div><dt>Model</dt><dd>'.$e($label).'</dd></div>'
                .'<div><dt>Key</dt><dd>'.($r['has_key'] ? 'stored on this server' : '<span class="bad-text">missing - add one</span>').'</dd></div>'
                .'<div><dt>Temperature</dt><dd>'.$e($r['temperature']).'</dd></div>'
                .(($r['base_url'] ?? '') !== '' ? '<div><dt>Address</dt><dd>'.$e($r['base_url']).'</dd></div>' : '')
                .'</dl><div class="prov-a">'
                .(!$on ? '<form method="post" action="'.$e($u['activate']).'">'.$csrf.'<input type="hidden" name="slug" value="'.$e($r['slug']).'"><button class="btn-sm">Use this one</button></form>' : '')
                .'<a class="btn2 btn-sm" href="'.$e($edit($r['slug'])).'">Edit</a>'
                .'<form method="post" action="'.$e($u['delete']).'">'.$csrf.'<input type="hidden" name="slug" value="'.$e($r['slug']).'">'
                .'<button class="btn-ghost btn-icon danger" data-confirm="Remove this provider?" title="Remove" aria-label="Remove '.$e($r['slug']).'">'.Icons::get('trash', 15).'</button></form>'
                .'</div></div>';
        }
        $list = $cards !== '' ? '<div class="prov-grid">'.$cards.'</div>'
            : '<div class="bm-card">'.Chart::empty('No provider yet', 'The chat cannot answer until you add one below.').'</div>';

        $ed = $editing;
        $form = '<form method="post" action="'.$e($u['save']).'" data-provider-form>'.$csrf
            .'<div class="grid2">'
            .'<div><label>Name <span class="muted">(how it is listed here)</span></label><input type="text" name="slug" required placeholder="gemini" value="'.$e($ed['slug'] ?? '').'"'.($ed ? ' readonly' : '').'></div>'
            // Gemini only for now - the offer lives in ProviderPresets (OFFERED_DRIVERS / MODELS).
            // The old hand-written menu, kept for when the others come back:
            // <div><label>Driver</label><select name="driver">
            //   <option value="gemini">Google Gemini</option>
            //   <option value="anthropic">Anthropic Claude</option>
            //   <option value="openai-compat">OpenAI, DeepSeek, Groq, Mistral, OpenRouter, local… (OpenAI-compatible)</option>
            // </select></div>
            // <div><label>Model</label><input type="text" name="model" required placeholder="gemini-2.5-flash"></div>
            .Layout::providerDriverSelect((string) ($ed['driver'] ?? 'gemini'))
            .Layout::providerServiceBlock((string) ($ed['driver'] ?? 'gemini'), $ed['base_url'] ?? '', $ed['model'] ?? '')
            .Layout::providerModelSelect((string) ($ed['driver'] ?? 'gemini'), (string) ($ed['model'] ?? ''))
            .'<div><label>API key '.($ed ? '<span class="muted">('.(!empty($ed['has_key']) ? 'a key is stored - blank keeps it' : 'none stored yet').')</span>' : '')
            .' <a class="muted" data-key-link href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener" style="text-decoration:underline;margin-left:6px">Where do I get one?</a></label>'
            .'<input type="password" name="api_key" autocomplete="new-password" placeholder="'.($ed && !empty($ed['has_key']) ? '•••••••• (unchanged)' : 'paste your API key').'"></div>'
            .'<div><label>Temperature <span class="muted">(0 = precise, 1 = creative)</span></label><input type="number" name="temperature" step="0.05" min="0" max="2" value="'.$e($ed['temperature'] ?? '0.4').'"></div>'
            .'</div>'
            .'<label class="check"><input type="checkbox" name="enabled" value="1"'.(($ed ? !empty($ed['enabled']) : true) ? ' checked' : '').'> This provider answers the chat <span class="muted">(switches the others off)</span></label>'
            .'<div class="row" style="margin-top:18px;gap:10px;justify-content:flex-end">'
            .($ed ? '<a class="btn-ghost" href="'.$e($u['page']).'">Cancel</a>' : '')
            .'<button type="submit">'.Icons::get('check', 15).' '.($ed ? 'Save changes' : 'Add provider').'</button></div></form>';

        return Layout::section('Answering your chat', 'Only one provider answers at a time. Keys are stored on this server and never shown again - not even to you.', $list)
            .Layout::section($ed ? 'Edit '.$ed['slug'] : 'Add a provider', $ed ? 'Leave the key blank to keep the stored one.' : 'Paste a Gemini key and pick a model. Every model in the list has been tested with Banimark.', $form, 'edit');
    }

    /**
     * Files: whether visitors and staff may attach, what, and where it is
     * stored. ONE body for both runtimes.
     *
     * @param array{problem: string, default_dir: string, stats: array{count: int, size: int}, s3_lock: ?string,
     *   upgrade_url: string, csrf: string, test: ?array{ok: bool, message: string}, urls: array{save: string, test: string}} $o
     */
    public static function files(array $s, array $o): string
    {
        $e = [self::class, 'e'];
        $g = fn (string $k, string $d = '') => $e((string) ($s[$k] ?? $d));
        $csrf = $o['csrf'];
        $lock = $o['s3_lock'];
        $driver = ($s['files_driver'] ?? 'local') === 's3' ? 's3' : 'local';
        $hasSecret = trim((string) ($s['files_s3_secret'] ?? '')) !== '';
        $size = (int) $o['stats']['size'];
        $test = $o['test'] ?? null;

        return ($o['problem'] !== '' ? '<div class="flash-err">'.Icons::get('escalation', 16).'<span>'.$e($o['problem']).'</span></div>' : '')
            .'<form method="post" action="'.$e($o['urls']['save']).'" class="set-form">'.$csrf
            .Layout::section('File sharing', 'Visitors and staff can attach files to a message. Turn it off and the paperclip disappears everywhere.',
                '<label class="check" style="margin-top:0"><span class="switch"><input type="checkbox" name="files_enabled" value="1"'.(($s['files_enabled'] ?? '1') === '1' ? ' checked' : '').'><span class="sl"></span></span> Allow files</label>'
                .'<label class="check" style="margin-top:10px"><span class="switch"><input type="checkbox" name="files_ai_read" value="1"'.(($s['files_ai_read'] ?? '1') !== '0' ? ' checked' : '').'><span class="sl"></span></span> Let the assistant read attachments</label>'
                .'<div class="hint">Images, PDFs and plain-text files a visitor attaches are sent to your AI provider so the assistant can answer from them (only with a model marked "reads images &amp; PDFs"). Switch this off and the assistant only sees that a file was attached.</div>'
                .'<div class="grid2" style="margin-top:6px"><div><label>Largest file (MB)</label>'
                .'<input type="number" name="files_max_mb" min="1" max="100" value="'.$g('files_max_mb', (string) \Banimark\Files\UploadPolicy::DEFAULT_MAX_MB).'"></div>'
                .'<div><label>Accepted types <span class="muted">(comma-separated, blank = the default list)</span></label>'
                .'<input type="text" name="files_types" value="'.$g('files_types').'" placeholder="png, jpg, pdf, docx"></div></div>'
                .'<div class="hint">Default: '.$e(implode(', ', array_keys(\Banimark\Files\UploadPolicy::TYPES))).'. Programs and scripts are never accepted, whatever you type here.</div>')
            .Layout::section('Where they are stored', 'Files are never put in a public folder. Visitors fetch them back through short-lived signed links.',
                '<div class="choices two">'
                .Layout::choice('files_driver', 'local', $driver === 'local', 'This server', 'Simplest. Files sit in a folder only Banimark reads - never in a public directory.')
                .str_replace('<input type="radio"', '<input type="radio"'.($lock ? ' disabled' : ''), Layout::choice('files_driver', 's3', $driver === 's3', 'S3-compatible storage', 'AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO.'))
                .'</div>'
                .Layout::lockedNote($lock, $o['upgrade_url'])
                .'<label>Folder on this server <span class="muted">(blank = '.$e($o['default_dir']).')</span></label>'
                .'<input type="text" name="files_local_path" value="'.$g('files_local_path').'" placeholder="'.$e($o['default_dir']).'">'
                // shown but switched off when the plan does not cover it: the owner sees
                // what S3 storage is instead of meeting it first in a refusal at the save
                .'<fieldset class="bare subpanel '.($lock ? 'is-locked' : '').'"'.($lock ? ' disabled' : '').'><legend>S3 settings</legend><div class="grid2">'
                .'<div><label>Bucket</label><input type="text" name="files_s3_bucket" value="'.$g('files_s3_bucket').'" placeholder="my-support-files"></div>'
                .'<div><label>Region</label><input type="text" name="files_s3_region" value="'.$g('files_s3_region', 'us-east-1').'" placeholder="eu-west-1"></div>'
                .'<div><label>Access key ID</label><input type="text" name="files_s3_key" value="'.$g('files_s3_key').'" autocomplete="off"></div>'
                .'<div><label>Secret access key '.($hasSecret ? '<span class="muted">(stored - blank keeps it)</span>' : '').'</label>'
                .'<input type="password" name="files_s3_secret" value="" autocomplete="new-password" placeholder="'.($hasSecret ? '•••••••• (unchanged)' : '').'"></div>'
                .'<div><label>Endpoint <span class="muted">(only for R2 / Spaces / MinIO)</span></label>'
                .'<input type="text" name="files_s3_endpoint" value="'.$g('files_s3_endpoint').'" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com"></div>'
                .'<div><label>Key prefix <span class="muted">(optional)</span></label><input type="text" name="files_s3_prefix" value="'.$g('files_s3_prefix').'" placeholder="support"></div></div>'
                .'<label class="check"><input type="checkbox" name="files_s3_path_style" value="1"'.(($s['files_s3_path_style'] ?? '0') === '1' ? ' checked' : '').'> Put the bucket in the path, not the hostname <span class="muted">(MinIO and some proxies need this)</span></label>'
                .'<div class="hint">Your keys never leave this server.</div></fieldset>')
            .Layout::saveBar('Save changes', '', true).'</form>'

            .Layout::section('Check it works', 'Writes a small test file with your saved settings, reads it back and deletes it. Nothing is added to any conversation.',
                ($test !== null ? '<div class="'.($test['ok'] ? 'flash-ok' : 'flash-err').'">'.Icons::get($test['ok'] ? 'check' : 'escalation', 16).'<span>'.$e($test['message']).'</span></div>' : '')
                .'<form method="post" action="'.$e($o['urls']['test']).'">'.$csrf.'<button type="submit" class="btn2">'.Icons::get('play', 15).' Send a test file</button></form>')
            .Layout::section('What is stored now', 'Changing store does not move existing files: anything already uploaded is still served from where it was written.',
                '<div class="statrow">'
                .'<div><small>Files</small><b>'.number_format((int) $o['stats']['count']).'</b></div>'
                .'<div><small>Total size</small><b>'.($size > 1048576 ? round($size / 1048576, 1).' MB' : round($size / 1024).' KB').'</b></div>'
                .'<div><small>Current store</small><b>'.($driver === 's3' ? 'S3' : 'This server').'</b></div></div>');
    }

    /**
     * Security: two-factor authentication for the signed-in person. ONE body
     * for both runtimes.
     *
     * @param array{enabled: bool, pending: string, required: bool, uri: string, csrf: string,
     *   urls: array{begin: string, confirm: string, disable: string, staff: string}} $o
     */
    public static function security(array $o): string
    {
        $e = [self::class, 'e'];
        $u = $o['urls'];
        $csrf = $o['csrf'];
        $on = (bool) $o['enabled'];
        $pending = (string) $o['pending'];
        $code = fn (bool $big = false) => '<input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123 456" autocomplete="one-time-code"'
            .($big ? ' autofocus' : '').' class="otp'.($big ? ' big' : '').'" aria-label="6-digit code">';

        $banner = '<div class="bm-card state-banner '.($on ? 'on' : 'off').'">'
            .'<span class="state-ic">'.Icons::get($on ? 'shield' : 'lock', 22).'</span>'
            .'<div><h2>Two-factor authentication is '.($on ? 'on' : 'off').'</h2>'
            .'<p>'.($on ? 'A code from your phone is needed alongside your password every time you sign in.'
                : 'Anyone with your password can sign in. Add a code from your phone to change that.').'</p></div>'
            .'<span class="pill '.($on ? 'good' : 'closed').'">'.($on ? 'PROTECTED' : 'NOT SET UP').'</span></div>'
            .($o['required'] && !$on ? '<div class="flash-err">'.Icons::get('escalation', 16).'<span>Your owner requires two-factor authentication for everyone. Finish the setup below to keep using the panel.</span></div>' : '');

        if ($on) {
            $main = Layout::section('Turn it off', 'Confirm with a current code from your app. We recommend keeping it on.',
                '<form method="post" action="'.$e($u['disable']).'" class="row" style="gap:10px;flex-wrap:wrap">'.$csrf.$code()
                .'<button type="submit" class="btn-danger" data-confirm="Turn off two-factor authentication for your account?">Turn off 2FA</button></form>');
        } elseif ($pending === '') {
            $main = Layout::section('Set it up', 'You will need an authenticator app: Google Authenticator, Authy, 1Password or Microsoft Authenticator - any of them works. It takes about a minute.',
                '<ol class="steps-list"><li>Start the setup to get your personal key.</li><li>Add it to your authenticator app.</li><li>Type the 6-digit code it shows to confirm.</li></ol>'
                .'<form method="post" action="'.$e($u['begin']).'">'.$csrf.'<button type="submit">'.Icons::get('shield', 15).' Start setup</button></form>');
        } else {
            $main = Layout::section('Finish setup', 'Nothing changes until you confirm a code, so you cannot lock yourself out halfway.',
                '<ol class="steps-list">'
                .'<li>Open your authenticator app and choose <b>Add account</b> &rarr; <b>Enter a setup key</b>.</li>'
                .'<li>Type this key (account name: your email, type: time-based):<div class="bm-secret">'.$e(trim(chunk_split($pending, 4, ' '))).'</div>'
                .'<div class="hint">On this device? <a href="'.$e($o['uri']).'">Open it in your authenticator app</a>.</div></li>'
                .'<li>Enter the 6-digit code the app shows now:'
                .'<form method="post" action="'.$e($u['confirm']).'" class="row" style="gap:10px;margin-top:10px;flex-wrap:wrap">'.$csrf.$code(true)
                .'<button type="submit">'.Icons::get('check', 15).' Confirm and turn on</button></form></li></ol>');
        }

        return $banner.$main
            .Layout::section('How it works', 'Good to know before you rely on it.',
                '<ul class="ticks-list">'
                .'<li>'.Icons::get('check', 15).'<span>Codes are made on your phone and change every 30 seconds - nothing is sent by SMS or email.</span></li>'
                .'<li>'.Icons::get('check', 15).'<span>Owners can require two-factor authentication for every staff member from <a href="'.$e($u['staff']).'">Staff</a>.</span></li>'
                .'<li>'.Icons::get('check', 15).'<span>Lost your phone? An owner can reset your 2FA, and you enrol again with a new key.</span></li></ul>');
    }

    /**
     * Notifications: working hours, what happens on a handover, the visitor
     * follow-up, outgoing email, a test send, quick replies. ONE body for
     * both runtimes; field names and actions unchanged.
     *
     * @param array{hours: string, save: string, test: string, quick: string} $urls
     */
    public static function notifications(array $s, array $urls, string $csrf): string
    {
        $e = [self::class, 'e'];
        $g = fn (string $k, string $d = '') => $e((string) ($s[$k] ?? $d));
        $mode = ($s['escalation_mode'] ?? 'staff') === 'email' ? 'email' : 'staff';
        $enc = (string) ($s['smtp_encryption'] ?? 'tls');
        $sel = fn (string $v) => $enc === $v ? ' selected' : '';
        $hasPass = trim((string) ($s['smtp_pass'] ?? '')) !== '';
        $switch = fn (string $name, bool $on, string $text) => '<label class="check" style="margin-top:0"><span class="switch"><input type="checkbox" name="'.$name.'" value="1"'.($on ? ' checked' : '').'><span class="sl"></span></span> '.$text.'</label>';

        return self::workingHours($s, $urls['hours'], $csrf)
            .'<form method="post" action="'.$e($urls['save']).'" class="set-form" id="notify-form">'.$csrf
            .Layout::section('When the AI hands over', 'What should happen the moment a conversation needs a person.',
                '<div class="choices">'
                .Layout::choice('escalation_mode', 'staff', $mode === 'staff', 'Staff inbox', 'It appears in the inbox for any staff member to pick up. (Default)')
                .Layout::choice('escalation_mode', 'email', $mode === 'email', 'Email alert too', 'Email your team as well. Needs the outgoing email settings below.')
                .'</div>'
                .'<label>Alert these addresses <span class="muted">(comma separated - blank means all staff)</span></label>'
                .'<input type="text" name="escalation_email" value="'.$g('escalation_email').'" placeholder="support@yourco.com, ops@yourco.com">')
            .Layout::section('Visitor follow-up', 'The widget reports whether the visitor is still watching. If they have closed the tab when your team replies, we can email the reply to them - provided we have their address.',
                $switch('visitor_followup', ($s['visitor_followup'] ?? '1') === '1', 'Email the visitor when they have left the chat')
                .'<label>Consider them gone after</label><div class="row">'
                .'<input type="number" name="visitor_followup_after" min="30" step="30" value="'.$g('visitor_followup_after', '120').'" style="max-width:140px">'
                .'<span class="muted">seconds without a heartbeat</span></div>'
                .'<div class="hint">One email per absence - they will not be mailed again until they come back.</div>')
            .Layout::section('Outgoing email', 'Banimark sends with its own SMTP settings, so it never depends on the host app\'s mail configuration.',
                $switch('smtp_enabled', ($s['smtp_enabled'] ?? '') === '1', 'Use SMTP <span class="muted">(otherwise PHP mail(), which most cloud hosts silently drop)</span>')
                .'<div class="grid2" style="margin-top:6px">'
                .'<div><label>Host</label><input type="text" name="smtp_host" value="'.$g('smtp_host').'" placeholder="smtp.mailgun.org"></div>'
                .'<div><label>Port</label><input type="number" name="smtp_port" value="'.$g('smtp_port', '587').'"></div>'
                .'<div><label>Username</label><input type="text" name="smtp_user" value="'.$g('smtp_user').'" autocomplete="off"></div>'
                .'<div><label>Password</label><input type="password" name="smtp_pass" autocomplete="new-password" placeholder="'.($hasPass ? '•••••••• (unchanged)' : '').'"></div>'
                .'<div><label>Encryption</label><select name="smtp_encryption">'
                .'<option value="tls"'.$sel('tls').'>STARTTLS (587)</option><option value="ssl"'.$sel('ssl').'>SSL/TLS (465)</option><option value="none"'.$sel('none').'>None (25)</option></select></div>'
                .'<div><label>From name</label><input type="text" name="smtp_from_name" value="'.$g('smtp_from_name', 'Support').'"></div>'
                .'<div><label>From address</label><input type="text" name="smtp_from_email" value="'.$g('smtp_from_email').'" placeholder="support@yourco.com"></div>'
                .'</div><div class="hint">Leave the password blank to keep the stored one.</div>')
            .Layout::saveBar().'</form>'

            .Layout::section('Send a test', 'Confirm the details work before a handover depends on them. Save first - the test uses what is stored.',
                '<form method="post" action="'.$e($urls['test']).'" class="row" style="gap:10px;flex-wrap:wrap">'.$csrf
                .'<input type="text" name="test_email" placeholder="you@yourco.com" style="flex:1;min-width:200px;margin:0">'
                .'<button type="submit" class="btn2">'.Icons::get('send', 15).' Send test</button></form>')
            .Layout::section('Quick replies', 'One per line. Your team taps these in a live conversation to answer in one click.',
                '<form method="post" action="'.$e($urls['quick']).'">'.$csrf
                .'<textarea name="quick_replies" rows="5">'.$e(implode("\n", \Banimark\Desk\QuickReplies::fromSettings($s))).'</textarea>'
                .'<div style="margin-top:12px;text-align:right"><button type="submit" class="btn2">'.Icons::get('check', 15).' Save quick replies</button></div></form>');
    }

    /**
     * One conversation: the thread in the middle, who the visitor is and what
     * to do about it on the side. ONE body for both runtimes; the live-chat
     * script (chat.js) finds everything through the data-* hooks, unchanged.
     *
     * @param array{session_id: string, mode: string, rows: array, presence: array, quick: string[], files_on: bool,
     *   can_delete: bool, csrf_field: string, csrf_name: string, csrf_value: string, urls: array{inbox: string, mode: string,
     *   delete: string, forget: string, messages: string, reply: string, upload: string, file: string}} $o
     */
    public static function conversation(array $o): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $u = $o['urls'];
        $mode = (string) $o['mode'];
        $rows = $o['rows'];
        $p = $o['presence'] ?? [];
        $label = (string) ($p['visitor_label'] ?? '') ?: 'Visitor';
        $seen = (int) ($p['last_seen_at'] ?? 0);
        $online = $seen > time() - 45;
        $lastId = $rows === [] ? 0 : (int) end($rows)['id'];

        $msgs = '';
        $count = 0;
        $files = 0;
        $started = 0;
        foreach ($rows as $m) {
            $started = $started ?: (int) ($m['at'] ?? 0);
            if ($m['role'] === 'tool') {
                $msgs .= '<div class="msg tool" data-id="'.(int) $m['id'].'">'.Icons::get('bolt', 12).' '.$e($m['text']).'</div>';
                continue;
            }
            if ($m['role'] === 'system') {
                $msgs .= '<div class="msg system" data-id="'.(int) $m['id'].'">'.$e($m['text']).'</div>';
                continue;
            }
            $count++;
            $atts = '';
            foreach ($m['files'] ?? [] as $f) {
                $files++;
                $url = $e($u['file'].$f['token']);
                $atts .= $f['is_image']
                    ? '<a class="msg-att" href="'.$url.'" target="_blank" rel="noopener"><img src="'.$url.'" alt="'.$e($f['name']).'" loading="lazy"></a>'
                    : '<a class="msg-att file" href="'.$url.'?download=1" target="_blank" rel="noopener">📎 <b>'.$e($f['name']).'</b> <span>'
                        .($f['size'] > 1048576 ? round($f['size'] / 1048576, 1).' MB' : round($f['size'] / 1024).' KB').'</span></a>';
            }
            $who = $m['role'] === 'agent' ? (($m['by'] ?? '') !== '' ? $m['by'] : 'human agent').' · ' : ($m['role'] === 'assistant' ? 'AI · ' : '');
            $msgs .= '<div class="msg '.$e($m['role']).'" data-id="'.(int) $m['id'].'">'.Markdown::toHtml((string) $m['text']).$atts
                .'<div class="msg-meta">'.$e($who).($m['at'] ? date('H:i', (int) $m['at']) : '').'</div></div>';
        }
        if ($msgs === '') {
            $msgs = Chart::empty('No messages yet', 'The visitor opened the chat but has not written anything.');
        }

        $quick = '';
        foreach ($o['quick'] as $q) {
            $quick .= '<button type="button" data-quick="'.$e($q).'">'.$e(mb_strimwidth((string) $q, 0, 42, '…')).'</button>';
        }
        $modeForm = fn (string $to, string $text, string $cls, string $confirm = '', string $icon = '') => '<form method="post" action="'.$e($u['mode']).'">'.$o['csrf_field']
            .'<input type="hidden" name="mode" value="'.$to.'"><button class="'.$cls.'"'.($confirm !== '' ? ' data-confirm="'.$e($confirm).'"' : '').'>'
            .($icon !== '' ? Icons::get($icon, 15) : '').$text.'</button></form>';

        $stateText = ['ai' => 'The AI is answering. Replying takes over - it stays silent until you hand it back.',
            'agent' => 'Your team has this one. The AI stays silent until you hand it back.',
            'closed' => 'Closed. A new message from the visitor opens it again.'][$mode] ?? '';

        $main = '<section class="bm-card convo-main" data-live-chat data-session="'.$e($o['session_id']).'" data-mode="'.$e($mode).'" data-after="'.$lastId.'"'
            .' data-messages-url="'.$e($u['messages']).'" data-reply-url="'.$e($u['reply']).'"'
            .' data-csrf-name="'.$e($o['csrf_name']).'" data-csrf="'.$e($o['csrf_value']).'"'
            .' data-upload-url="'.$e($u['upload']).'" data-file-url="'.$e($u['file']).'">'
            .'<header class="convo-h"><span class="avatar lg '.Layout::tone($label).'">'.$e(strtoupper(mb_substr($label, 0, 1))).'</span>'
            .'<div class="convo-who"><h2>'.$e($label).' <span class="pill '.$e($mode).'" data-mode-pill>'.strtoupper($e($mode)).'</span></h2>'
            .'<span class="bm-presence '.($online ? 'on' : 'off').'" data-presence>'.$e($label).($online ? ' · online now' : ($seen ? ' · left the chat' : '')).'</span></div></header>'
            .'<div class="msgs" data-thread data-autoscroll>'.$msgs.'</div>'
            .'<div class="bm-typing" data-typing hidden><i></i><i></i><i></i></div><div class="flash-ok" data-flash hidden></div>'
            .'<div class="convo-foot">'
            .($quick !== '' ? '<div class="bm-quick">'.$quick.'</div>' : '')
            .'<div data-pending hidden style="padding:6px 0 0"></div>'
            .'<form method="post" action="'.$e($u['reply']).'" class="bm-compose" data-reply>'.$o['csrf_field']
            .'<button type="button" class="btn-ghost btn-icon" data-emoji title="Emoji" aria-label="Emoji">🙂</button>'
            .($o['files_on'] ? '<button type="button" class="btn-ghost btn-icon" data-attach title="Attach a file" aria-label="Attach a file">📎</button><input type="file" data-file hidden>' : '')
            .'<textarea name="message" rows="1" placeholder="Reply as a person… (Enter sends, Shift+Enter for a new line)" autofocus autocomplete="off" aria-label="Your reply"></textarea>'
            .'<button type="submit">'.Icons::get('send', 15).' Send</button></form></div></section>';

        $facts = '';
        foreach ([
            ['Email', ($p['visitor_email'] ?? '') !== '' ? '<a href="mailto:'.$e($p['visitor_email']).'">'.$e($p['visitor_email']).'</a>' : '<span class="muted">not given</span>'],
            ['Phone', ($p['visitor_phone'] ?? '') !== '' ? '<a href="tel:'.$e(preg_replace('/[^0-9+]/', '', (string) $p['visitor_phone'])).'">'.$e($p['visitor_phone']).'</a>' : '<span class="muted">not given</span>'],
            ['Started', $started ? $e(date('j M Y, H:i', $started)) : '-'],
            ['Last seen', $seen ? $e(Chart::ago($seen)).($online ? ' · now' : ' ago') : '-'],
            ['Messages', (string) $count],
            ['Files', (string) $files],
        ] as [$k, $v]) {
            $facts .= '<div><dt>'.$e($k).'</dt><dd>'.$v.'</dd></div>';
        }
        // the visitor deleted it (widget / app): hidden from them, erased on a
        // date unless someone here keeps it
        $gone = (int) ($p['visitor_deleted_at'] ?? 0);
        $deletedCard = '';
        if ($gone > 0) {
            $kept = !empty($p['kept']);
            $eraseOn = $gone + max(1, (int) ($o['visitor_delete_days'] ?? \Banimark\Storage\Retention::VISITOR_DELETE_DEFAULT)) * 86400;
            $deletedCard = '<div class="bm-card danger-zone" data-visitor-deleted><h2>Deleted by the visitor</h2>'
                .'<p class="muted" style="margin:4px 0 12px">The visitor deleted this conversation on '.$e(date('j M Y, H:i', $gone)).'. They can no longer see it. '
                .($kept ? 'Your team chose to <b>keep</b> it, so it is not erased automatically.'
                        : 'It will be <b>erased permanently on '.$e(date('j M Y', $eraseOn)).'</b>, with its files, unless you keep it.').'</p>'
                .(!empty($o['can_delete']) && !empty($u['keep'])
                    ? '<form method="post" action="'.$e($u['keep']).'">'.$o['csrf_field'].'<input type="hidden" name="keep" value="'.($kept ? '0' : '1').'">'
                        .($kept ? '<button class="btn-ghost wide" data-confirm="Let this conversation be erased automatically again?">'.Icons::get('trash', 14).' Let it be erased</button>'
                                : '<button class="btn2 wide">'.Icons::get('check', 14).' Keep this conversation</button>')
                        .'</form>'
                    : '')
                .'</div>';
        }
        $side = '<aside class="convo-side">'
            .$deletedCard
            .'<div class="bm-card"><h2>Visitor</h2><dl class="facts">'.$facts.'</dl></div>'
            .'<div class="bm-card"><h2>This conversation</h2><p class="muted" style="margin:4px 0 12px">'.$e($stateText).'</p>'
            .'<div class="stack">'
            .($mode === 'agent' ? $modeForm('ai', 'Hand back to the AI', 'btn2 wide', '', 'bolt') : $modeForm('agent', 'Take over', 'wide', '', 'staff'))
            .($mode !== 'closed' ? $modeForm('closed', 'Close conversation', 'btn2 wide', 'Close this conversation?', 'check') : '')
            .'<a class="btn-ghost wide" href="'.$e($u['inbox']).'">'.Icons::get('back', 15).' Back to the inbox</a>'
            .'</div></div>'
            .($o['can_delete']
                ? '<div class="bm-card danger-zone"><h2>Delete</h2><p class="muted" style="margin:4px 0 12px">For privacy requests. This cannot be undone.</p><div class="stack">'
                    .'<form method="post" action="'.$e($u['delete']).'">'.$o['csrf_field'].'<button class="btn-ghost wide danger" data-confirm="Delete this conversation and its files? This cannot be undone.">'.Icons::get('trash', 14).' Delete this conversation</button></form>'
                    .'<form method="post" action="'.$e($u['forget']).'">'.$o['csrf_field'].'<button class="btn-ghost wide danger" data-confirm="Delete EVERY conversation from this visitor? This cannot be undone.">'.Icons::get('trash', 14).' Forget this visitor</button></form>'
                    .'</div></div>'
                : '')
            .'</aside>';

        return '<div class="convo">'.$main.$side.'</div>'.Layout::chatScript();
    }

    /**
     * The customer dashboard body - ONE implementation for both runtimes.
     *
     * @param array $p      Analytics::period()
     * @param array $recent PdoStore::listConversations()
     * @param array{period_url: callable, conversation_url: callable, inbox: string, providers: string, tools: string,
     *   widget: string, insights_html: string, insights: ?array, has_provider: bool, tools_count: int, owner: bool} $o
     */
    public static function dashboard(array $p, array $recent, array $o): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $days = (int) $p['days'];
        $series = $p['series'];

        $head = '<div class="page-head"><div><h2>Your desk at a glance</h2>'
            .'<p>Last '.$days.' days, each figure compared with the '.$days.' days before.</p></div>'
            .'<div class="row" style="gap:10px"><span class="live-dot">Live</span>'
            .Layout::periodSwitch(\Banimark\Storage\Analytics::PERIODS, $days, $o['period_url']).'</div></div>';

        $kpis = '<div class="bm-kpis">'
            .Layout::stat('Conversations', number_format($p['conversations']), 'chat', $p['conversations_delta'],
                number_format($p['total']).' all time', Chart::spark(array_column($series, 'conversations'), 'var(--brand)'))
            .Layout::stat('Answered by AI', $p['ai_rate'].'%', 'bolt', $p['ai_rate_delta'],
                number_format($p['ai_handled']).' needed no person', '', 'pts')
            .Layout::stat('Handed to your team', number_format($p['handed_over']), 'escalation', $p['handed_over_delta'],
                $p['waiting'] > 0 ? $p['waiting'].' waiting now' : 'none waiting now', '', '%', true)
            .Layout::stat('Lookups on your data', number_format($p['lookups']), 'tools', $p['lookups_delta'], 'answers from your own records')
            .'</div>';

        $activity = '<div class="bm-card"><div class="bm-sec-h"><div><h2>Activity</h2>'
            .'<div class="muted">Conversations started and messages exchanged per day</div></div></div>'
            .Chart::area(array_column($series, 'label'), [
                ['name' => 'Conversations', 'color' => 'var(--s1)', 'values' => array_column($series, 'conversations')],
                ['name' => 'Messages', 'color' => 'var(--s3)', 'values' => array_column($series, 'messages')],
            ], 260).'</div>';

        $m = $p['modes'];
        $handled = '<div class="bm-card spot"><div class="spot-head"><div><h2>Who handled it</h2>'
            .'<div class="muted">Conversations started in the period, by who has them now</div></div>'
            .'<div class="spot-big">'.(int) $p['ai_rate'].'<small>%</small></div></div>'
            .'<div class="spot-body">'
            .Chart::ring([
                ['name' => 'AI', 'value' => $m['ai'], 'color' => '#5fe0cf'],
                ['name' => 'Your team', 'value' => $m['agent'], 'color' => '#f4b04d'],
                ['name' => 'Closed', 'value' => $m['closed'], 'color' => 'rgba(255,255,255,.38)'],
            ], number_format($p['conversations']), 'chats', 120, 14)
            .'<div class="spot-legend">'
            .'<span><i style="background:#5fe0cf"></i>AI is answering<b>'.number_format($m['ai']).'</b></span>'
            .'<span><i style="background:#f4b04d"></i>With your team<b>'.number_format($m['agent']).'</b></span>'
            .'<span><i style="background:rgba(255,255,255,.38)"></i>Closed<b>'.number_format($m['closed']).'</b></span>'
            .'</div></div></div>';

        $lookups = '<div class="bm-card"><h2>Top lookups</h2><div class="muted">What the assistant looks up in your data most</div>'
            .'<div style="margin-top:14px">'.Chart::hbars($p['tools'], 'var(--s3)', 'No lookups yet').'</div></div>';

        // what customers talk about - from the last insights report, if any
        $topicsBody = '';
        $report = $o['insights'] ?? null;
        if (is_array($report) && ($report['topics'] ?? []) !== []) {
            $rows = [];
            foreach ($report['topics'] as $t) {
                $rows[] = ['name' => $t['name'], 'value' => $t['share']];
            }
            $topicsBody = '<div class="muted" style="margin-bottom:12px">% of conversations, from your last analysis ('.(int) $report['days'].' days)</div>'
                .Chart::hbars($rows, 'var(--s1)');
        } else {
            $topicsBody = Chart::empty('Not analysed yet', $o['owner'] ? 'Run "Customer insights" below to see what people ask about.' : 'An owner can run Customer insights to fill this in.');
        }
        $topics = '<div class="bm-card"><div class="bm-sec-h"><div><h2>What customers talk about</h2></div><div class="spacer"></div>'
            .'<a class="btn2 btn-sm" href="#insights">Insights</a></div>'.$topicsBody.'</div>';

        $items = [];
        if ($p['waiting'] > 0) {
            $items[] = ['title' => $p['waiting'].' '.($p['waiting'] === 1 ? 'conversation is' : 'conversations are').' waiting for your team',
                'sub' => 'The visitor wrote last - reply from the inbox', 'href' => $o['inbox'], 'icon' => 'inbox', 'tone' => 'bad'];
        }
        if (empty($o['has_provider'])) {
            $items[] = ['title' => 'Connect an AI provider', 'sub' => 'The chat cannot answer until one is set up', 'href' => $o['providers'], 'icon' => 'providers', 'tone' => 'bad'];
        }
        if ((int) $o['tools_count'] === 0) {
            $items[] = ['title' => 'Let the assistant look things up', 'sub' => 'Add a tool so it can answer from your own data', 'href' => $o['tools'], 'icon' => 'tools', 'tone' => 'warn'];
        }
        if ($p['conversations'] >= 10 && $p['ai_rate'] < 60) {
            $items[] = ['title' => 'Most chats end with a person', 'sub' => 'Only '.$p['ai_rate'].'% were answered by AI - a tool or a rule could help', 'href' => $o['tools'], 'icon' => 'bolt', 'tone' => 'warn'];
        }
        if ((int) $p['total'] === 0) {
            $items[] = ['title' => 'Put the chat on your website', 'sub' => 'Copy one line from the Widget page', 'href' => $o['widget'], 'icon' => 'widget'];
        }
        foreach (array_slice((array) ($report['suggestions'] ?? []), 0, 2) as $idea) {
            $items[] = ['title' => mb_strimwidth((string) $idea, 0, 90, '…'), 'sub' => 'From your customer insights', 'href' => '#insights', 'icon' => 'chat'];
        }
        if (!is_array($report) && $o['owner'] && (int) $p['total'] > 0) {
            $items[] = ['title' => 'Find out what customers ask for', 'sub' => 'Your AI can read the conversations and sum them up', 'href' => '#insights', 'icon' => 'chat'];
        }
        $attention = '<div class="bm-card"><h2>Needs your attention</h2><div class="muted" style="margin-bottom:10px">What would make the biggest difference next</div>'
            .Layout::attention(array_slice($items, 0, 5), 'All clear - nothing needs you right now.').'</div>';

        $rows = '';
        foreach ($recent as $r) {
            $rows .= '<tr><td><div class="row"><span class="avatar '.Layout::tone((string) ($r['visitor_label'] ?: $r['session_id'])).'">'.$e(strtoupper(substr($r['visitor_label'] ?: 'A', 0, 1))).'</span>'
                .'<span><b>'.$e($r['visitor_label'] ?: 'Anonymous').'</b><div class="muted mono" style="background:none;padding:0">'.$e(substr($r['session_id'], 0, 8)).'</div></span></div></td>'
                .'<td><span class="pill '.$e($r['mode']).'">'.strtoupper($e($r['mode'])).'</span></td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['message_count'].'</td>'
                .'<td class="muted">'.$e(mb_substr((string) $r['last_message'], 0, 58)).'</td>'
                .'<td class="muted">'.($r['last_message_at'] ? $e(Chart::ago((int) $r['last_message_at'])) : '-').'</td>'
                .'<td><a class="btn2 btn-sm" href="'.$e(($o['conversation_url'])($r['session_id'])).'">Open</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">'.Chart::empty('No conversations yet', 'Embed the widget on your site and say hello.').'</td></tr>';
        }
        $latest = '<div class="bm-card pad0"><div class="bm-sec-h" style="padding:18px 20px 0"><div><h2>Latest conversations</h2>'
            .'<div class="muted">Newest first</div></div><div class="spacer"></div>'
            .'<a class="btn2 btn-sm" href="'.$e($o['inbox']).'">View all</a></div>'
            .'<div class="t-wrap"><table><tr><th>Visitor</th><th>State</th><th>Messages</th><th>Last message</th><th>When</th><th></th></tr>'
            .$rows.'</table></div></div>';

        return $head.$kpis
            .'<div class="bm-grid main">'.$activity.'<div>'.$handled.$lookups.'</div></div>'
            .'<div class="bm-grid c2">'.$topics.$attention.'</div>'
            .$o['insights_html']
            .$latest;
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /* -------------------------------------------------------------- UPDATE */

    /**
     * The update card, shared by both panels.
     *
     * Written for an owner who has never opened a terminal: one button when we
     * can do it for them, and when we cannot, the reason in words they can take
     * to their host - never a silent missing button.
     *
     * @param array  $st    Update\Status::build()
     * @param array  $urls  ['update' => ..., 'schema' => ..., 'rollback' => ...]
     * @param string $csrf  the ready-made hidden field, like every other shared
     *                      page here: csrf_field() in Laravel, Html's own in the
     *                      standalone - the two runtimes name the field differently
     */
    public static function updateCard(array $st, array $urls, string $csrf): string
    {
        $e = fn ($v) => self::e($v);
        $form = fn (string $url, string $body) => '<form method="post" action="'.$e($url).'" style="display:inline">'
            .$csrf.$body.'</form>';

        $out = '';

        /* ---- the database step comes FIRST when it is outstanding: files
                without their columns is the one state that actually breaks ---- */
        if ($st['schema_behind']) {
            $out .= '<div class="bm-card" style="border-color:color-mix(in srgb, var(--warn) 45%, transparent)">'
                .'<h2>One more step: update your database</h2>'
                .'<div class="muted">Banimark\'s files are on '.$e($st['current']).', but its tables have not caught up yet. '
                .'This adds any new columns the new version needs. It only ever adds - nothing is deleted, and it is safe to press twice.</div>'
                .'<div style="margin-top:14px" data-update-run data-schema="'.$e($urls['schema'] ?? '').'">'
                .$form($urls['schema'] ?? '', '<button type="submit">Update the database now</button>')
                .'</div></div>';
        }

        // "check again" belongs on every state: the answer is cached for hours,
        // and the first thing anyone does after fixing a connection is retry
        $recheck = ($urls['recheck'] ?? '') !== ''
            ? '<span data-update-run data-check="'.$e($urls['recheck']).'">'
                .$form($urls['recheck'], '<button class="btn2 btn-sm" type="submit">Check for updates now</button>')
                .'</span>'
            : '';

        // Reinstall / leave a TEST build: the ways out the updater used to lack,
        // because it only ever offers something NEWER.
        $switch = '';
        if (($urls['switch'] ?? '') !== '' && !empty($st['alternatives'])) {
            $buttons = '';
            foreach ($st['alternatives'] as $alt) {
                $buttons .= $form($urls['switch'],
                    '<input type="hidden" name="version" value="'.$e($alt['version']).'">'
                    .'<button class="btn2 btn-sm" type="submit" data-confirm="'.$e($alt['confirm']).'">'.$e($alt['label']).'</button>').' ';
            }
            $switch = '<div class="divider"></div><div class="muted" style="margin-bottom:8px">Or install a different release:</div>'.$buttons;
        }

        /* ---- could not ask at all ---- */
        if (!$st['reachable']) {
            return $out.'<div class="bm-card" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">'
                .'<div class="row" style="gap:10px;align-items:flex-start">'
                .Icons::get('escalation', 17)
                .'<div><b>Could not check for updates</b>'
                .'<div class="muted">You are running '.$e($st['current']).'. We could not reach Banimark to ask '
                .'whether anything newer exists, so this is not a clean bill of health &mdash; just silence.</div>'
                .($st['endpoint'] !== '' ? '<div class="muted" style="margin-top:6px;font-size:12.5px">Tried <code>'.$e($st['endpoint']).'</code></div>' : '')
                .'<div style="margin-top:12px">'.$recheck.'</div>'
                .'</div></div></div>';
        }

        /* ---- up to date ---- */
        if (!$st['outdated']) {
            return $out.'<div class="bm-card"><div class="row" style="gap:10px;align-items:flex-start">'
                .Icons::get('check', 17)
                .'<div><b>You are up to date</b><div class="muted">Running '.$e($st['current']).'</div>'
                .($recheck !== '' ? '<div style="margin-top:12px">'.$recheck.'</div>' : '')
                .'</div></div>'.$switch.'</div>';
        }

        /* ---- an update exists ---- */
        $head = '<div class="bm-sec-h"><div class="row" style="gap:10px;align-items:flex-start">'
            .Icons::get('bolt', 18)
            .'<div><h2 style="margin:0">Update available — '.$e($st['latest'])
                .(!empty($st['test']) ? ' <span class="pill expired">TEST BUILD</span>' : '').'</h2>'
            .'<div class="muted">You are running '.$e($st['current']).'.</div></div></div></div>'
            // only a test install is ever shown one of these; say what it means
            .(!empty($st['test'])
                ? '<div class="flash-warn" style="margin-top:10px">'.Icons::get('escalation', 16).'<span><b>This is a TEST build from your vendor.</b>'
                    .' It is offered only to installs marked for testing, and it stops working 36 hours after it was built.'
                    .' Do not leave it running on a site your customers depend on.</span></div>'
                : '');

        $notes = $st['notes'] !== ''
            ? '<div class="muted" style="white-space:pre-wrap;margin:10px 0 4px">'.$e($st['notes']).'</div>'
            : '';

        // the manual instructions are ALWAYS shown, folded away - a one-click
        // update that fails must never leave someone with no way forward
        $manual = '<details style="margin-top:14px"><summary class="muted" style="cursor:pointer">Or update it yourself</summary>'
            .'<textarea readonly rows="2" data-select-all style="margin-top:10px">'.$e($st['command']).'</textarea></details>';

        if ($st['one_click'] && $st['ready']) {
            $body = '<div class="muted" style="margin-top:10px">We will download this version, check it really came from us, '
                .'keep a copy of your current one, and swap it over. It takes a few seconds.</div>'
                // data-update-run lets panel.js take the form over and report each
                // stage as the server finishes it; without JS the form posts and
                // the page reloads, exactly as it always did
                .'<div style="margin-top:14px" data-update-run'
                .' data-fetch="'.$e($urls['fetch'] ?? '').'"'
                .' data-apply="'.$e($urls['apply'] ?? '').'"'
                .' data-schema="'.$e($urls['schema'] ?? '').'"'
                .' data-version="'.$e($st['latest']).'">'
                .$form($urls['update'] ?? '', '<button type="submit" data-confirm="Update Banimark to '.$e($st['latest']).' now?">'
                    .Icons::get('bolt', 15).' Update to '.$e($st['latest']).'</button>')
                .'</div> '.$recheck;
        } else {
            $why = $st['blocked_reason'] !== ''
                ? '<div class="muted" style="margin-top:10px">'.$e($st['blocked_reason']).'</div>'
                : '<div class="muted" style="margin-top:10px">This server cannot install updates by itself yet. '
                    .'Everything below has to be green - your host can fix the red ones.</div>';
            // Every state needs a way to ask HQ again. This one had none: the
            // answer is remembered for hours, so a release HQ withdrew (or has
            // since made one-click) stayed on screen with no way to refresh it.
            $body = $why.self::preflightRows($st['preflight'])
                .($recheck !== '' ? '<div style="margin-top:12px">'.$recheck.'</div>' : '');
        }

        $out .= '<div class="bm-card" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">'
            .$head.$notes.$body.$manual.$switch.'</div>';

        return $out.self::backupCard($st, $urls, $csrf);
    }

    /** @param array<int, array{label: string, ok: bool, hint: string}> $rows */
    private static function preflightRows(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $html = '<div style="margin-top:12px">';
        foreach ($rows as $r) {
            $html .= '<div class="row" style="gap:9px;align-items:flex-start;padding:7px 0;border-top:1px solid var(--border)">'
                .'<span style="color:var('.($r['ok'] ? '--ok' : '--warn').');flex:none;line-height:1.3">'
                .Icons::get($r['ok'] ? 'check' : 'escalation', 15).'</span>'
                .'<div><div>'.self::e($r['label']).'</div>'
                .($r['ok'] ? '' : '<div class="muted" style="font-size:12.5px">'.self::e($r['hint']).'</div>')
                .'</div></div>';
        }
        return $html.'</div>';
    }

    private static function backupCard(array $st, array $urls, string $csrf): string
    {
        if (($st['backups'] ?? []) === [] || ($urls['rollback'] ?? '') === '') {
            return '';
        }
        $rows = '';
        foreach ($st['backups'] as $b) {
            $rows .= '<div class="row" style="gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--border)">'
                .'<code style="flex:1">'.self::e(basename($b)).'</code>'
                .'<form method="post" action="'.self::e($urls['rollback']).'">'
                .$csrf
                .'<input type="hidden" name="backup" value="'.self::e($b).'">'
                .'<button class="btn2 btn-sm" type="submit" data-confirm="Put this version back?">Restore this one</button>'
                .'</form></div>';
        }
        return '<div class="bm-card"><h2>Previous versions</h2>'
            .'<div class="muted">Kept on the server after an update, in case something is not right. '
            .'Restoring is the same swap in reverse.</div>'.$rows.'</div>';
    }

    /* ------------------------------------------------------------------ AI */

    public static function aiSettings(array $s, string $action, string $csrf): string
    {
        $e = [self::class, 'e'];
        $sel = fn (string $k, string $v, string $default = '') => (($s[$k] ?? $default) === $v ? ' selected' : '');
        $chk = fn (string $k, string $default = '1') => (($s[$k] ?? $default) !== '0' ? ' checked' : '');
        $len = '';
        $tokens = Behaviour::maxTokens($s);
        foreach (Behaviour::LENGTHS as $k => $l) {
            $len .= '<option value="'.$l['tokens'].'"'.($tokens === $l['tokens'] ? ' selected' : '').'>'.$e($l['label']).' (about '.round($l['tokens'] * 0.75).' words)</option>';
        }
        $tone = '';
        foreach (Behaviour::TONES as $k => $label) {
            $tone .= '<option value="'.$k.'"'.$sel('ai_tone', $k, 'friendly').'>'.$e($label).'</option>';
        }
        $current = Behaviour::escalation($s);
        $escalation = '';
        foreach (Behaviour::ESCALATION as $k => $opt) {
            $escalation .= Layout::choice('ai_escalation', $k, $current === $k, $opt['label'], $opt['hint']);
        }
        $history = Behaviour::historyWindow($s);
        return '<form method="post" action="'.$e($action).'" class="set-form">'.$csrf
            .Layout::section('How the assistant behaves', 'Its name, tone and language. It always follows your rules first.',
                '<div class="grid2">'
                .'<div><label>Its name <span class="muted">(optional)</span></label><input type="text" name="ai_name" value="'.$e($s['ai_name'] ?? '').'" placeholder="e.g. Ada" maxlength="40"></div>'
                .'<div><label>Tone</label><select name="ai_tone">'.$tone.'</select></div>'
                .'<div><label>Language</label><input type="text" name="ai_language" value="'.$e($s['ai_language'] ?? '').'" placeholder="blank = the visitor\'s own language">'
                .'<div class="hint">Type a language (e.g. French) to always answer in it.</div></div>'
                .'</div>'
                .'<label class="check"><input type="checkbox" name="ai_formatting" value="1"'.$chk('ai_formatting').'> Allow light formatting in replies <span class="muted">(bold, lists, links)</span></label>')
            .Layout::section('When to bring in a person', 'A conversation handed over waits for your team, so handing over too readily costs you a reply the assistant could have given. Whatever you choose, it never promises anything your rules forbid.',
                '<div class="choices">'.$escalation.'</div>'
                .'<div class="hint">The assistant always hands over on its own if the AI provider fails, so nobody is left without an answer.</div>')
            .Layout::section('Memory, length and cost', 'These three decide most of what a conversation costs you. Bigger is not better: a long memory sends more of the chat to the provider on every turn.',
                '<div class="grid2">'
                .'<div><label>How much of the conversation it remembers</label><input type="number" name="ai_history_messages" min="6" max="200" value="'.$history.'">'
                .'<div class="hint">Messages sent back to the provider each turn. 40 is plenty for support; 12 keeps costs low.</div></div>'
                .'<div><label>Longest reply</label><select name="ai_max_tokens">'.$len.'</select>'
                .'<div class="hint">A hard stop on reply length. Short answers are cheaper and read better in a chat bubble.</div></div>'
                .'<div><label>Daily limit on AI answers <span class="muted">(0 = no limit)</span></label><input type="number" name="ai_daily_cap" min="0" max="1000000" value="'.(int) Behaviour::dailyCap($s).'">'
                .'<div class="hint">Past this, visitors go straight to your team for the rest of the day and the thread says why. A safety net against a runaway bill.</div></div>'
                .'</div>')
            .Layout::saveBar('Save changes', '', true).'</form>';
    }

    /** Store the AI form. @param callable(string,string):void $set */
    public static function saveAiSettings(array $p, callable $set): void
    {
        $set('ai_name', mb_substr(trim((string) ($p['ai_name'] ?? '')), 0, 40));
        $set('ai_tone', array_key_exists((string) ($p['ai_tone'] ?? ''), Behaviour::TONES) ? (string) $p['ai_tone'] : 'friendly');
        $set('ai_language', mb_substr(trim((string) ($p['ai_language'] ?? '')), 0, 40));
        $set('ai_escalation', isset(Behaviour::ESCALATION[(string) ($p['ai_escalation'] ?? '')]) ? (string) $p['ai_escalation'] : 'offer');
        $set('ai_formatting', !empty($p['ai_formatting']) ? '1' : '0');
        $set('ai_history_messages', (string) max(6, min(200, (int) ($p['ai_history_messages'] ?? Behaviour::DEFAULT_HISTORY))));
        $set('ai_max_tokens', (string) max(256, min(8192, (int) ($p['ai_max_tokens'] ?? Behaviour::DEFAULT_MAX_TOKENS))));
        $set('ai_daily_cap', (string) max(0, min(1000000, (int) ($p['ai_daily_cap'] ?? 0))));
    }

    /* ---------------------------------------------------------------- inbox */

    /**
     * The whole inbox body: state tabs, toggle filters, and a row per
     * conversation whose colour, pill and flags say what it needs. ONE
     * implementation for both runtimes - the Blade view and the standalone
     * panel are thin wrappers around this.
     *
     * @param array    $rows     PdoStore::listConversations()
     * @param array    $counts   PdoStore::inboxCounts()
     * @param array    $f        the filters in force: mode,q,unread,waiting,files,known,sort
     * @param string   $self     this page's URL
     * @param callable $convUrl  session id -> conversation URL
     * @param string   $me       the signed-in staff member's name ("You" in previews)
     */
    public static function inbox(array $rows, array $counts, array $f, string $self, callable $convUrl, string $me, ?int $now = null): string
    {
        $now = $now ?? time();
        $e = [self::class, 'e'];
        $link = function (array $changes) use ($self, $f) {
            $q = array_filter(array_merge($f, $changes), fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== false && $v !== '0');
            return $self.($q === [] ? '' : '?'.http_build_query($q));
        };

        // state tabs: what kind of conversation
        $tabs = '';
        foreach ([null => ['All', $counts['all']], 'agent' => ['Needs a person', $counts['agent']], 'ai' => ['AI handled', $counts['ai']], 'closed' => ['Closed', $counts['closed']]] as $k => $t) {
            $on = ($f['mode'] ?? null) === ($k ?: null);
            $tabs .= '<a class="bm-tab'.($on ? ' on' : '').'" href="'.$e($link(['mode' => $k])).'">'.$e($t[0]).' <span>'.(int) $t[1].'</span></a>';
        }

        // toggle filters: what needs doing. Each one flips on its own and keeps the rest.
        $toggles = [
            ['key' => 'waiting', 'label' => 'Waiting for a reply', 'count' => (int) ($counts['waiting'] ?? 0), 'tone' => 'urgent', 'title' => 'Nobody has answered the visitor\'s last message.'],
            ['key' => 'unread', 'label' => 'New to you', 'count' => (int) ($counts['unread'] ?? 0), 'tone' => '', 'title' => 'Something was said since you last opened it.'],
            ['key' => 'files', 'label' => 'Has files', 'count' => null, 'tone' => '', 'title' => 'A file was shared in the chat.'],
            ['key' => 'known', 'label' => 'Signed in', 'count' => null, 'tone' => '', 'title' => 'The visitor is signed in to your app, so lookups can be scoped to their account.'],
        ];
        $chips = '';
        foreach ($toggles as $t) {
            $on = !empty($f[$t['key']]);
            $chips .= '<a class="bm-chip'.($on ? ' on' : '').($t['tone'] !== '' && $t['count'] ? ' '.$t['tone'] : '').'" title="'.$e($t['title']).'"'
                .' href="'.$e($link([$t['key'] => $on ? 0 : 1])).'">'.$e($t['label'])
                .($t['count'] !== null ? '<span>'.$t['count'].'</span>' : '').'</a>';
        }
        $sorted = ($f['sort'] ?? '') === 'waiting';
        $chips .= '<span class="spacer"></span><a class="bm-chip'.($sorted ? ' on' : '').'" href="'.$e($link(['sort' => $sorted ? '' : 'waiting'])).'"'
            .' title="Put the person who has waited longest at the top.">'.Icons::get('clock', 13).' Longest waiting</a>';

        $anyFilter = !empty($f['unread']) || !empty($f['waiting']) || !empty($f['files']) || !empty($f['known']);
        if ($anyFilter || ($f['q'] ?? '') !== '') {
            $chips .= '<a class="bm-chip clear" href="'.$e($self.(($f['mode'] ?? null) ? '?mode='.$f['mode'] : '')).'">Clear filters</a>';
        }

        $search = '<form method="get" action="'.$e($self).'" class="bm-search">'
            .(($f['mode'] ?? null) ? '<input type="hidden" name="mode" value="'.$e($f['mode']).'">' : '')
            .implode('', array_map(
                fn ($k) => !empty($f[$k]) ? '<input type="hidden" name="'.$k.'" value="1">' : '',
                ['unread', 'waiting', 'files', 'known']
            ))
            .Icons::get('inbox', 14)
            .'<input type="text" name="q" value="'.$e($f['q'] ?? '').'" placeholder="Search people and messages…" autocomplete="off">'
            .(($f['q'] ?? '') !== '' ? '<a class="btn-ghost btn-sm" href="'.$e($link(['q' => ''])).'">Clear</a>' : '').'</form>';

        $threads = '';
        foreach ($rows as $r) {
            $state = Triage::state($r, $now);
            $unread = Triage::isUnread($r);
            $preview = \Banimark\Files\Markers::parse((string) ($r['last_message'] ?? ''))['text'];
            $who = ($r['last_role'] ?? '') === 'agent'
                ? '<span class="muted">'.$e(($r['last_agent'] ?? '') !== '' && $r['last_agent'] !== $me ? $r['last_agent'] : 'You').':</span> '
                : (($r['last_role'] ?? '') === 'assistant' ? '<span class="muted">AI:</span> ' : '');
            $flags = '';
            foreach (Triage::flags($r, $now) as $flag) {
                $flags .= '<span class="bm-flag'.(($flag['tone'] ?? '') !== '' ? ' '.$flag['tone'] : '').'" title="'.$e($flag['title']).'">'
                    .Icons::get($flag['icon'], 12).($flag['label'] !== '' ? '<i>'.$e($flag['label']).'</i>' : '').'</span>';
            }
            $threads .= '<a class="bm-thread t-'.$e($state['tone']).($unread ? ' unread' : '').'" href="'.$e($convUrl((string) $r['session_id'])).'">'
                .'<span class="bm-thread-av"><span class="avatar '.Layout::tone((string) ($r['visitor_label'] ?: $r['session_id'] ?? 'a')).'">'.$e(strtoupper(substr((string) ($r['visitor_label'] ?: 'A'), 0, 1))).'</span>'
                .(Triage::isOnline($r, $now) ? '<i class="bm-online" title="In the chat right now"></i>' : '').'</span>'
                .'<span class="bm-thread-main">'
                .'<span class="bm-thread-head"><b>'.$e($r['visitor_label'] ?: 'Anonymous visitor').'</b>'
                .'<span class="pill s-'.$e($state['tone']).'" title="'.$e($state['title']).'">'.$e($state['label']).'</span>'
                .((int) ($r['visitor_deleted_at'] ?? 0) > 0
                    ? '<span class="pill closed" title="The visitor deleted this conversation'.(!empty($r['kept']) ? ' - kept by your team' : ' - it is erased automatically unless you keep it').'">'.(!empty($r['kept']) ? 'Deleted by visitor · kept' : 'Deleted by visitor').'</span>' : '')
                .$flags
                // while someone is waiting, the pill already carries that number -
                // repeating it as "last message" just says the same thing twice
                .'<span class="spacer"></span>'
                .($state['waiting'] > 0 ? '' : '<span class="muted bm-when" title="Last message">'.($r['last_message_at'] ? $e(Chart::ago((int) $r['last_message_at'], $now)) : '—').'</span>')
                .'</span>'
                .'<span class="bm-thread-line">'.$who.$e(mb_strimwidth($preview !== '' ? $preview : '(a file)', 0, 110, '…')).'</span>'
                .(($r['visitor_email'] || ($r['visitor_phone'] ?? '')) ? '<span class="bm-thread-sub muted">'
                    .$e(implode(' · ', array_filter([(string) $r['visitor_email'], (string) ($r['visitor_phone'] ?? '')]))).'</span>' : '')
                .'</span>'
                .'<span class="bm-thread-end">'.($unread ? '<i class="bm-dot" title="New since you last looked"></i>' : '')
                .'<span class="muted">'.(int) ($r['message_count'] ?? 0).' msg</span></span></a>';
        }
        if ($threads === '') {
            $threads = '<div style="padding:8px">'.Chart::empty(
                ($f['q'] ?? '') !== '' ? 'Nothing matches “'.$e($f['q']).'”' : ($anyFilter ? 'Nothing needs that right now' : 'No conversations yet'),
                ($f['q'] ?? '') !== '' ? 'Try a different word, or clear the search.' : ($anyFilter ? 'Clear the filters to see everything.' : 'Embed the widget on your site and say hello.')
            ).'</div>';
        }

        return '<div class="bm-card pad0">'
            .'<div class="bm-inbox-top"><div class="row" style="gap:6px;flex-wrap:wrap">'.$tabs.'</div><div class="spacer"></div>'.$search.'</div>'
            .'<div class="bm-inbox-filters">'.$chips.'</div>'
            .'<div class="bm-threads">'.$threads.'</div></div>';
    }

    /** The one-line summary under the page title. */
    public static function inboxSubtitle(array $counts): string
    {
        $waiting = (int) ($counts['waiting'] ?? 0);
        if ($waiting > 0) {
            return $waiting === 1 ? '1 person is waiting for a reply' : $waiting.' people are waiting for a reply';
        }
        $unread = (int) ($counts['unread'] ?? 0);
        return $unread > 0 ? $unread.' new since you last looked' : 'Nobody is waiting - everything is answered';
    }

    /* ------------------------------------------------- the first-run screen */

    /**
     * What a visitor meets before they have typed anything: the greeting, what
     * a guest is asked for, and a few phrases they can tap instead of staring
     * at an empty box. Shared by both panels; the widget, the chat link and the
     * Flutter SDK all render from the same resolved config.
     */
    public static function firstRun(array $s): string
    {
        $e = [self::class, 'e'];
        $mode = fn (string $key) => (string) ($s['guest_ask_'.$key] ?? ($key === 'phone' ? 'off' : 'optional'));

        $fields = '';
        foreach (\Banimark\Http\WidgetConfig::GUEST_FIELDS as $key => $meta) {
            $options = '';
            foreach (\Banimark\Http\WidgetConfig::FIELD_MODES as $value => $label) {
                $options .= '<option value="'.$e($value).'"'.($mode($key) === $value ? ' selected' : '').'>'.$e($label).'</option>';
            }
            $fields .= '<div class="guestrow"><span>'.$e(ucfirst($key)).'</span>'
                .'<select name="guest_ask_'.$e($key).'" aria-label="Ask for '.$e($key).'">'.$options.'</select>'
                .'<input type="text" name="guest_label_'.$e($key).'" value="'.$e($s['guest_label_'.$key] ?? '').'" placeholder="'.$e($meta['label']).'" aria-label="What to call the '.$e($key).' field">'
                .'</div>';
        }

        return '<div class="bm-card"><h2>The first thing a visitor sees</h2>'
            .'<div class="muted">Before anyone has typed a word. The same on your website, the shareable chat link and your app.</div>'
            .'<label style="margin-top:12px">Welcome message</label>'
            .'<input type="text" name="greeting" value="'.$e($s['greeting'] ?? '').'" placeholder="Hi! How can we help you today?" data-wp-greeting-in>'
            .'<div class="hint">Shown as the first message, and as the little bubble that peeks out before the chat is opened.</div>'
            .'<label style="margin-top:12px">Welcome message out of hours <span class="muted">(optional)</span></label>'
            .'<input type="text" name="away_greeting" value="'.$e($s['away_greeting'] ?? '').'" placeholder="We\'re away right now - leave a message and we\'ll reply first thing." data-wp-away-in>'
            .'<div class="hint">Used instead while your working hours say nobody is in. Empty = the same message all day.</div>'

            .'<label style="margin-top:14px">Things people can tap to start <span class="muted">(one per line, up to six)</span></label>'
            .'<textarea name="starters" rows="4" placeholder="Where is my order?&#10;I need a refund&#10;Change my delivery address" data-wp-starters-in>'.$e($s['starters'] ?? '').'</textarea>'
            .'<div class="hint">Phrase them the way a customer would. Tapping one sends it as their first message - so write what they would ask, not what you would call it. Leave this empty for none.</div>'

            .'<div class="divider"></div>'
            .'<h3 style="margin:0 0 4px;font-size:14px">Before a guest starts</h3>'
            .'<div class="muted">Only for visitors you have not signed in yourself. Someone your site already knows is never asked.</div>'
            .'<label style="margin-top:10px">What to say above the form</label>'
            .'<input type="text" name="guest_intro" value="'.$e($s['guest_intro'] ?? '').'" placeholder="Tell us where to reach you and we can follow up even if you close this tab.">'
            .'<label style="margin-top:12px">What to ask for <span class="muted">(and what to call it)</span></label>'
            .$fields
            .'<div class="hint">Ask for as little as you can: every extra box loses people. A required field must be filled before the chat starts - a phone number is worth asking for only if you will actually ring them.</div>'
            .'</div>';
    }

    /** Store the first-run form. @param callable(string,string):void $set */
    public static function saveFirstRun(array $p, callable $set): void
    {
        $set('greeting', mb_substr(trim(strip_tags((string) ($p['greeting'] ?? ''))), 0, 200));
        $set('away_greeting', mb_substr(trim(strip_tags((string) ($p['away_greeting'] ?? ''))), 0, 200));
        $set('guest_intro', mb_substr(trim(strip_tags((string) ($p['guest_intro'] ?? ''))), 0, 200));
        $lines = [];
        foreach (preg_split('/\r?\n/', (string) ($p['starters'] ?? '')) ?: [] as $line) {
            $line = trim(strip_tags($line));
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 80);
            }
        }
        $set('starters', implode("\n", array_slice($lines, 0, 6)));
        foreach (array_keys(\Banimark\Http\WidgetConfig::GUEST_FIELDS) as $key) {
            $mode = (string) ($p['guest_ask_'.$key] ?? '');
            $set('guest_ask_'.$key, isset(\Banimark\Http\WidgetConfig::FIELD_MODES[$mode]) ? $mode : ($key === 'phone' ? 'off' : 'optional'));
            $set('guest_label_'.$key, mb_substr(trim(strip_tags((string) ($p['guest_label_'.$key] ?? ''))), 0, 60));
        }
    }

    /**
     * "Try it on a test page": a plain sample website with the REAL widget on
     * it, straight from widget.js - launcher, bubble, auto-open and all, which
     * the editor's picture cannot show. data-preview makes the widget skip the
     * page rules and the once-per-visit memory, so every reload shows it.
     */
    public static function widgetTryPage(string $widgetJs, string $back): string
    {
        $e = [self::class, 'e'];
        $src = $widgetJs.(str_contains($widgetJs, '?') ? '&' : '?').'preview='.time(); // never a cached copy
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Widget test page</title><meta name="robots" content="noindex">'
            .'<style>body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d2530;background:#f6f7f9}'
            .'header{background:#fff;border-bottom:1px solid #e5e8ec;padding:16px 24px;display:flex;justify-content:space-between;align-items:center}'
            .'header b{font-size:18px}header a{color:#1d2530;font-size:14px}main{max-width:760px;margin:40px auto;padding:0 24px}'
            .'.card{background:#fff;border:1px solid #e5e8ec;border-radius:14px;padding:22px 24px;margin:0 0 16px}.muted{color:#6b7580;font-size:14px}'
            .'.ph{height:12px;border-radius:6px;background:#e9edf1;margin:10px 0}.ph.s{width:60%}</style></head><body>'
            .'<header><b>Your website</b><a href="'.$e($back).'">&larr; Back to the widget settings</a></header>'
            .'<main><div class="card"><h1 style="margin:0 0 6px;font-size:26px">This is a test page</h1>'
            .'<p class="muted" style="margin:0">The chat in the corner is your real widget with your SAVED settings. Messages you send here are real conversations and appear in your inbox. Page rules ("only on" / "never on") are ignored here, and it greets you on every reload.</p></div>'
            .'<div class="card"><div class="ph"></div><div class="ph"></div><div class="ph s"></div></div>'
            .'<div class="card"><div class="ph"></div><div class="ph s"></div></div></main>'
            .'<script src="'.$e($src).'" defer data-preview="1"></script></body></html>';
    }

    /**
     * Store the look, the launcher, when it appears and the chime - ONE
     * implementation for both runtimes, like saveFirstRun.
     *
     * @param callable(string,string):void $set
     * @param string|null $logoUpload raw bytes of an uploaded logo file (no-JS path), if any
     * @return string|null why the logo was not changed, or null
     */
    public static function saveLook(array $p, callable $set, ?string $logoUpload = null): ?string
    {
        $W = \Banimark\Http\WidgetConfig::class;
        $one = fn (string $k, array $allowed) => isset($allowed[(string) ($p[$k] ?? '')]) ? (string) $p[$k] : (string) $W::DEFAULTS[$k];
        $line = fn (string $k, int $max) => mb_substr(trim(strip_tags((string) ($p[$k] ?? ''))), 0, $max);
        $set('status_line', $line('status_line', 80));
        $set('launcher_label', $line('launcher_label', 30));
        $set('launcher_icon', $one('launcher_icon', $W::LAUNCHER_ICONS));
        $set('corner', $one('corner', $W::CORNERS));
        $set('density', $one('density', $W::DENSITIES));
        $set('auto_open', $one('auto_open', $W::AUTO_OPEN));
        $set('auto_open_after', $one('auto_open_after', $W::AUTO_OPEN_AFTER));
        $set('sound', !empty($p['sound']) ? '1' : '0');
        foreach (['show_on', 'hide_on', 'auto_open_pages'] as $k) {
            $set($k, implode("\n", $W::pageRules((string) ($p[$k] ?? ''))));
        }

        // the logo: the panel's shrunk copy wins, then a raw upload, then "remove"
        $bytes = \Banimark\Http\WidgetLogo::fromDataUrl((string) ($p['logo_data'] ?? ''));
        if ($bytes === null && $logoUpload !== null && $logoUpload !== '') {
            $bytes = $logoUpload;
        }
        if ($bytes !== null) {
            $problem = \Banimark\Http\WidgetLogo::problem($bytes);
            if ($problem !== null) {
                return $problem;
            }
            $set('logo', base64_encode($bytes));
        } elseif (!empty($p['logo_remove'])) {
            $set('logo', '');
        }
        return null;
    }

    /* ------------------------------------------------------- working hours */

    /**
     * When the team is in, and what the assistant does when they are not.
     * "I'll get someone for you" is a promise; at 2am on a Sunday it is a
     * promise nobody keeps for another thirty hours, so the owner decides
     * whether it is still made and the visitor is told either way.
     */
    public static function workingHours(array $s, string $action, string $csrf, ?int $now = null): string
    {
        $e = [self::class, 'e'];
        $hours = \Banimark\Desk\BusinessHours::fromSettings($s);
        $on = $hours->enabled();
        $open = $hours->isOpen($now);

        $zones = '';
        $current = $hours->timezone();
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $zones .= '<option value="'.$e($zone).'"'.($zone === $current ? ' selected' : '').'>'.$e(str_replace('_', ' ', $zone)).'</option>';
        }

        $rows = '';
        foreach (\Banimark\Desk\BusinessHours::DAYS as $key => $label) {
            $value = trim((string) ($s['hours_'.$key] ?? ''));
            $rows .= '<div class="dayrow"><span>'.$e($label).'</span>'
                .'<input type="text" name="hours_'.$key.'" value="'.$e($value).'" placeholder="closed" aria-label="'.$e($label).' hours"></div>';
        }

        $policies = '';
        foreach (\Banimark\Desk\BusinessHours::POLICIES as $key => $opt) {
            $policies .= Layout::choice('hours_policy', (string) $key, $hours->policy() === $key, $opt['label'], $opt['hint']);
        }

        $state = !$on
            ? '<span class="pill ai">ALWAYS OPEN</span>'
            : ($open ? '<span class="pill good">OPEN NOW</span>' : '<span class="pill agent">CLOSED NOW</span>');
        $back = $on && !$open ? $hours->backAt($now) : '';

        return '<form method="post" action="'.$e($action).'" class="set-form">'.$csrf
            .Layout::section('Working hours', 'When someone is actually at their desk. The assistant answers around the clock either way - this is about what happens when it wants to fetch a person.',
                '<div class="row" style="justify-content:space-between;gap:10px;flex-wrap:wrap">'
                .'<label class="check" style="margin:0"><span class="switch"><input type="checkbox" name="hours_enabled" value="1"'.($on ? ' checked' : '').'><span class="sl"></span></span> We have working hours</label>'
                .$state.'</div>'
                .'<div class="grid2" style="margin-top:6px">'
                .'<div><label>Your timezone</label><select name="hours_timezone">'.$zones.'</select></div>'
                .'<div><label>Days off <span class="muted">(one date per line, 2026-12-25)</span></label>'
                .'<textarea name="hours_holidays" rows="2" placeholder="2026-12-25">'.$e($s['hours_holidays'] ?? '').'</textarea></div>'
                .'</div>'
                .'<label>Open hours <span class="muted">Leave a day blank if you are closed. Two shifts: <code>09:00-13:00, 14:00-17:00</code></span></label>'
                .'<div class="days">'.$rows.'</div>'
                .'<div class="hint">'.$e($hours->summary()).($back !== '' ? ' Someone is back '.$e($back).'.' : '').'</div>')
            .Layout::section('Out of hours', 'The assistant always says when the team is back. This is whether the conversation reaches your inbox before then.',
                '<div class="choices">'.$policies.'</div>'
                .'<div class="hint">Whatever you choose, the assistant keeps answering, and a conversation already with a person is unaffected. '
                .'If the AI provider fails, it still hands over - nobody is ever left without a reply.</div>')
            .Layout::saveBar().'</form>';
    }

    /* ---------------------------------------------------- data & protection */

    /**
     * @param array{conversations:int, messages:int, files:int, oldest:int} $stats
     * @param array{save:string, delete_all:string} $urls
     */
    public static function dataPage(array $s, array $stats, array $urls, string $csrf): string
    {
        $e = [self::class, 'e'];
        $days = Retention::days($s);
        $lim = fn (string $k) => Flood::limit($s, $k);
        $stat = fn (string $k, string $v) => '<div><small>'.$e($k).'</small><b>'.$v.'</b></div>';
        return '<form method="post" action="'.$e($urls['save']).'" class="set-form">'.$csrf
            .Layout::section('How long chats are kept', 'Old conversations are deleted automatically - messages and any files in them. This keeps storage small and is what a privacy policy usually promises.',
                '<label style="margin-top:0">Delete conversations after (days) <span class="muted">(0 = keep forever)</span></label>'
                .'<input type="number" name="retention_days" min="0" max="3650" value="'.$days.'" style="max-width:200px">'
                .'<div class="hint">'.($days > 0 ? 'Runs once a day; anything quiet for more than '.$days.' days goes.' : 'Nothing is deleted automatically.').'</div>'
                .'<label>When a visitor deletes their conversation, erase it after (days)</label>'
                .'<input type="number" name="visitor_delete_days" min="1" max="3650" value="'.Retention::visitorDeleteDays($s).'" style="max-width:200px">'
                .'<div class="hint">Visitors can delete a conversation from the chat on your website and in the app. It disappears for them at once; your team still sees it, marked "Deleted by visitor", until this many days pass - then it is erased for good with its files. Open it and press <b>Keep this conversation</b> to stop that.</div>')
            .Layout::section('Protection against floods', 'Limits on the public chat so a script or a bot cannot run up your AI bill or fill the inbox. Real visitors never notice these numbers.',
                '<label class="check" style="margin:0 0 6px"><input type="checkbox" name="flood_enabled" value="1"'.(($s['flood_enabled'] ?? '1') !== '0' ? ' checked' : '').'> Protection on</label>'
                .'<div class="grid2">'
                .'<div><label>Messages per minute, per visitor</label><input type="number" name="flood_msgs_per_min" min="1" max="10000" value="'.$lim('flood_msgs_per_min').'"></div>'
                .'<div><label>Messages per minute, per connection (IP)</label><input type="number" name="flood_ip_msgs_per_min" min="1" max="10000" value="'.$lim('flood_ip_msgs_per_min').'"></div>'
                .'<div><label>New conversations per hour, per connection</label><input type="number" name="flood_sessions_per_hour" min="1" max="10000" value="'.$lim('flood_sessions_per_hour').'"></div>'
                .'<div><label>Files per 10 minutes, per visitor</label><input type="number" name="flood_uploads_per_10min" min="1" max="10000" value="'.$lim('flood_uploads_per_10min').'"></div>'
                .'</div>'
                .'<div class="hint">The daily limit on AI answers is on the AI settings page. Messages are also capped at 2,000 characters, and files by the rules on the Files page.</div>')
            .Layout::saveBar('Save changes', '', true).'</form>'

            .Layout::section('What is stored now', 'To delete one conversation, or everything from one visitor, open it in the inbox - the buttons are on its side panel.',
                '<div class="statrow">'
                .$stat('Conversations', number_format((int) $stats['conversations']))
                .$stat('Messages', number_format((int) $stats['messages']))
                .$stat('Files', number_format((int) $stats['files']))
                .$stat('Oldest', $stats['oldest'] > 0 ? $e(date('j M Y', (int) $stats['oldest'])) : '—')
                .'</div>')
            .'<section class="set"><div class="set-intro"><h2>Delete all chat history</h2><p>Every conversation, message and shared file, for every visitor. There is no undo.</p></div>'
            .'<div class="bm-card set-body danger-zone">'
            .'<form method="post" action="'.$e($urls['delete_all']).'" class="row" style="gap:10px;align-items:center;flex-wrap:wrap">'.$csrf
            .'<input type="text" name="confirm" placeholder="type DELETE to confirm" autocomplete="off" style="max-width:240px;margin:0">'
            .'<button type="submit" class="btn-danger" data-confirm="Delete ALL chat history? This cannot be undone.">'.Icons::get('trash', 15).' Delete everything</button></form></div></section>';
    }

    public static function saveDataSettings(array $p, callable $set): void
    {
        $set('retention_days', (string) max(0, min(3650, (int) ($p['retention_days'] ?? 0))));
        if (isset($p['visitor_delete_days']) && $p['visitor_delete_days'] !== '') {
            $set('visitor_delete_days', (string) max(1, min(3650, (int) $p['visitor_delete_days'])));
        }
        $set('flood_enabled', !empty($p['flood_enabled']) ? '1' : '0');
        foreach (array_keys(Flood::DEFAULTS) as $k) {
            $set($k, (string) max(1, min(10000, (int) ($p[$k] ?? Flood::DEFAULTS[$k]))));
        }
    }

    /* ----------------------------------------------------------------- team */

    /**
     * @param array $rows      TeamStats::summary()
     * @param array $recent    TeamStats::recent()
     * @param array $overview  TeamStats::overview()
     * @param callable(string):string $convUrl session id -> conversation URL
     */
    public static function team(array $rows, array $recent, array $overview, int $days, string $selfUrl, callable $convUrl): string
    {
        $e = [self::class, 'e'];
        $d = [TeamStats::class, 'duration'];
        $pill = fn (string $st) => match ($st) {
            'online' => '<span class="pill good">ONLINE</span>',
            'away' => '<span class="pill agent">AWAY</span>',
            'disabled' => '<span class="pill closed">DISABLED</span>',
            'never' => '<span class="pill closed">NEVER SIGNED IN</span>',
            default => '<span class="pill closed">OFFLINE</span>',
        };
        $tr = '';
        foreach ($rows as $r) {
            $tr .= '<tr><td><div class="row"><span class="avatar">'.$e(strtoupper(substr($r['name'], 0, 1))).'</span><div><b>'.$e($r['name']).'</b><div class="muted" style="font-size:11.5px">'.$e($r['email']).' · '.$e($r['role']).'</div></div></div></td>'
                .'<td>'.$pill($r['status']).($r['last_active_at'] > 0 ? '<div class="muted" style="font-size:11.5px;margin-top:3px">'.$e(Chart::ago($r['last_active_at'])).'</div>' : '').'</td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['replies'].'</td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['conversations'].'</td>'
                .'<td style="font-variant-numeric:tabular-nums" title="Average '.$e($d($r['avg_wait'])).'">'.$e($d($r['median_wait'])).'</td>'
                .'<td style="font-variant-numeric:tabular-nums" title="Average '.$e($d($r['first_avg'])).' over '.(int) $r['handovers'].' handover(s)">'.$e($d($r['first_median'])).'</td></tr>';
        }
        if ($tr === '') {
            $tr = '<tr><td colspan="6">'.Chart::empty('No staff activity yet', 'Replies sent from the inbox will show up here, per person.').'</td></tr>';
        }
        $feed = '';
        foreach ($recent as $m) {
            $feed .= '<a class="bm-thread" href="'.$e($convUrl($m['session_id'])).'"><span class="bm-thread-main">'
                .'<span class="bm-thread-head"><b>'.$e($m['agent']).'</b><span class="muted">replied to</span><b>'.$e($m['visitor']).'</b><span class="spacer"></span><span class="muted bm-when">'.$e(Chart::ago($m['at'])).'</span></span>'
                .'<span class="bm-thread-line">'.$e($m['snippet']).'</span></span></a>';
        }
        if ($feed === '') {
            $feed = '<div style="padding:8px">'.Chart::empty('No replies yet', '').'</div>';
        }
        $periods = [7 => '7 days', 30 => '30 days', 90 => '90 days'];
        return '<div class="page-head"><div><h2>Your team</h2><p>Last '.(isset($periods[$days]) ? $days : 7).' days. Response times are medians - hover a figure for the average.</p></div>'
            .Layout::periodSwitch($periods, $days, fn (int $n) => $selfUrl.'?days='.$n).'</div>'
            .'<div class="bm-kpis">'
            .Layout::stat('Online now', number_format((int) $overview['online']), 'staff', null, 'signed in and active')
            .Layout::stat('Waiting for a person', number_format((int) $overview['waiting']), 'inbox', null, 'the visitor wrote last')
            .Layout::stat('Handovers', number_format((int) $overview['handovers']), 'escalation', null, 'passed from the AI to your team')
            .Layout::stat('Replies sent', number_format((int) $overview['replies']), 'send', null, 'by people, not the AI')
            .'</div>'
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:14px 18px 0"><div><h2>Who is answering</h2>'
            .'<div class="muted">Response time = how long a visitor waited for that person\'s reply (median; hover for the average). First reply = after a handover to a human.</div></div></div>'
            .'<div class="t-wrap"><table><tr><th>Staff</th><th>Status</th><th>Replies</th><th>Conversations</th><th>Response time</th><th>First reply after handover</th></tr>'.$tr.'</table></div></div>'
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:14px 18px"><div><h2>Latest replies</h2><div class="muted">Who answered whom, most recent first.</div></div></div>'
            .'<div class="bm-threads">'.$feed.'</div></div>';
    }
}
