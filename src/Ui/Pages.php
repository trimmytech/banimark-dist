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
    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
        $history = Behaviour::historyWindow($s);
        return '<form method="post" action="'.$e($action).'">'.$csrf
            .'<div class="bm-card"><h2>How the assistant behaves</h2>'
            .'<div class="grid2" style="margin-top:10px">'
            .'<div><label>Its name <span class="muted">(optional)</span></label><input type="text" name="ai_name" value="'.$e($s['ai_name'] ?? '').'" placeholder="e.g. Ada" maxlength="40"></div>'
            .'<div><label>Tone</label><select name="ai_tone">'.$tone.'</select></div>'
            .'<div><label>Language</label><input type="text" name="ai_language" value="'.$e($s['ai_language'] ?? '').'" placeholder="blank = the visitor\'s own language">'
            .'<div class="hint">Type a language (e.g. French) to always answer in it.</div></div>'
            .'</div>'
            .'<label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="ai_cautious" value="1"'.$chk('ai_cautious').'> When unsure, hand over to a person instead of guessing</label>'
            .'<label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="ai_formatting" value="1"'.$chk('ai_formatting').'> Allow light formatting in replies <span class="muted">(bold, lists, links)</span></label>'
            .'</div>'

            .'<div class="bm-card"><h2>Memory, length and cost</h2>'
            .'<div class="muted">These three decide most of what a conversation costs you. Bigger is not better: a long memory sends more of the chat to the provider on every turn.</div>'
            .'<div class="grid2" style="margin-top:12px">'
            .'<div><label>How much of the conversation it remembers</label><input type="number" name="ai_history_messages" min="6" max="200" value="'.$history.'">'
            .'<div class="hint">Messages sent back to the provider each turn. 40 is plenty for support; 12 keeps costs low.</div></div>'
            .'<div><label>Longest reply</label><select name="ai_max_tokens">'.$len.'</select>'
            .'<div class="hint">A hard stop on reply length. Short answers are cheaper and read better in a chat bubble.</div></div>'
            .'<div><label>Daily limit on AI answers <span class="muted">(0 = no limit)</span></label><input type="number" name="ai_daily_cap" min="0" max="1000000" value="'.(int) Behaviour::dailyCap($s).'">'
            .'<div class="hint">Past this, visitors go straight to your team for the rest of the day and the thread says why. A safety net against a runaway bill.</div></div>'
            .'</div></div>'
            .'<div style="margin-top:4px"><button type="submit">'.Icons::get('check', 15).' Save</button></div></form>';
    }

    /** Store the AI form. @param callable(string,string):void $set */
    public static function saveAiSettings(array $p, callable $set): void
    {
        $set('ai_name', mb_substr(trim((string) ($p['ai_name'] ?? '')), 0, 40));
        $set('ai_tone', array_key_exists((string) ($p['ai_tone'] ?? ''), Behaviour::TONES) ? (string) $p['ai_tone'] : 'friendly');
        $set('ai_language', mb_substr(trim((string) ($p['ai_language'] ?? '')), 0, 40));
        $set('ai_cautious', !empty($p['ai_cautious']) ? '1' : '0');
        $set('ai_formatting', !empty($p['ai_formatting']) ? '1' : '0');
        $set('ai_history_messages', (string) max(6, min(200, (int) ($p['ai_history_messages'] ?? Behaviour::DEFAULT_HISTORY))));
        $set('ai_max_tokens', (string) max(256, min(8192, (int) ($p['ai_max_tokens'] ?? Behaviour::DEFAULT_MAX_TOKENS))));
        $set('ai_daily_cap', (string) max(0, min(1000000, (int) ($p['ai_daily_cap'] ?? 0))));
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
        return '<form method="post" action="'.$e($urls['save']).'">'.$csrf
            .'<div class="bm-card"><h2>How long chats are kept</h2>'
            .'<div class="muted">Old conversations are deleted automatically - messages and any files in them. This keeps storage small and is what a privacy policy usually promises.</div>'
            .'<div class="row" style="gap:14px;margin-top:12px;align-items:flex-end">'
            .'<div><label>Delete conversations after (days) <span class="muted">(0 = keep forever)</span></label><input type="number" name="retention_days" min="0" max="3650" value="'.$days.'" style="width:160px"></div>'
            .'<div class="muted" style="padding-bottom:10px">'.($days > 0 ? 'Runs once a day; anything quiet for more than '.$days.' days goes.' : 'Nothing is deleted automatically.').'</div>'
            .'</div></div>'

            .'<div class="bm-card"><h2>Protection against floods</h2>'
            .'<div class="muted">Limits on the public chat so a script or a bot cannot run up your AI bill or fill the inbox. Real visitors never notice these numbers.</div>'
            .'<label style="display:flex;align-items:center;gap:8px;margin:12px 0"><input type="checkbox" name="flood_enabled" value="1"'.(($s['flood_enabled'] ?? '1') !== '0' ? ' checked' : '').'> Protection on</label>'
            .'<div class="grid2">'
            .'<div><label>Messages per minute, per visitor</label><input type="number" name="flood_msgs_per_min" min="1" max="10000" value="'.$lim('flood_msgs_per_min').'"></div>'
            .'<div><label>Messages per minute, per connection (IP)</label><input type="number" name="flood_ip_msgs_per_min" min="1" max="10000" value="'.$lim('flood_ip_msgs_per_min').'"></div>'
            .'<div><label>New conversations per hour, per connection</label><input type="number" name="flood_sessions_per_hour" min="1" max="10000" value="'.$lim('flood_sessions_per_hour').'"></div>'
            .'<div><label>Files per 10 minutes, per visitor</label><input type="number" name="flood_uploads_per_10min" min="1" max="10000" value="'.$lim('flood_uploads_per_10min').'"></div>'
            .'</div>'
            .'<div class="hint">The daily limit on AI answers is on the AI settings page. Messages are also capped at 2,000 characters, and files by the rules on the Files page.</div>'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('check', 15).' Save</button></div></div></form>'

            .'<div class="bm-card"><h2>What is stored now</h2>'
            .'<div class="row" style="gap:26px;margin-top:8px">'
            .'<div><div class="muted">Conversations</div><b style="font-size:20px">'.(int) $stats['conversations'].'</b></div>'
            .'<div><div class="muted">Messages</div><b style="font-size:20px">'.(int) $stats['messages'].'</b></div>'
            .'<div><div class="muted">Files</div><b style="font-size:20px">'.(int) $stats['files'].'</b></div>'
            .'<div><div class="muted">Oldest</div><b style="font-size:20px">'.($stats['oldest'] > 0 ? $e(date('j M Y', (int) $stats['oldest'])) : '—').'</b></div>'
            .'</div>'
            .'<div class="hint">To delete one conversation, or everything from one visitor, open it in the inbox - the buttons are at the top.</div></div>'

            .'<div class="bm-card" style="border-color:color-mix(in srgb, var(--danger, #d33) 40%, var(--border))"><h2>Delete all chat history</h2>'
            .'<div class="muted">Every conversation, message and shared file, for every visitor. There is no undo.</div>'
            .'<form method="post" action="'.$e($urls['delete_all']).'" class="row" style="gap:10px;margin-top:12px;align-items:center">'.$csrf
            .'<input type="text" name="confirm" placeholder="type DELETE to confirm" autocomplete="off" style="width:220px;margin:0">'
            .'<button type="submit" class="btn-danger" data-confirm="Delete ALL chat history? This cannot be undone.">'.Icons::get('trash', 15).' Delete everything</button></form></div>';
    }

    public static function saveDataSettings(array $p, callable $set): void
    {
        $set('retention_days', (string) max(0, min(3650, (int) ($p['retention_days'] ?? 0))));
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
        $tabs = '';
        foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $n => $label) {
            $tabs .= '<a class="bm-tab'.($days === $n ? ' on' : '').'" href="'.$e($selfUrl.'?days='.$n).'">'.$label.'</a>';
        }
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
        $stat = fn (string $label, int $n) => '<div><div class="muted">'.$label.'</div><b style="font-size:22px">'.$n.'</b></div>';
        return '<div class="bm-card"><div class="row" style="gap:26px">'
            .$stat('Online now', $overview['online']).$stat('Waiting for a human', $overview['waiting'])
            .$stat('Handovers', $overview['handovers']).$stat('Replies sent', $overview['replies'])
            .'<div class="spacer"></div><div class="row" style="gap:6px">'.$tabs.'</div></div></div>'
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:14px 18px 0"><div><h2>Who is answering</h2>'
            .'<div class="muted">Response time = how long a visitor waited for that person\'s reply (median; hover for the average). First reply = after a handover to a human.</div></div></div>'
            .'<div class="t-wrap"><table><tr><th>Staff</th><th>Status</th><th>Replies</th><th>Conversations</th><th>Response time</th><th>First reply after handover</th></tr>'.$tr.'</table></div></div>'
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:14px 18px"><div><h2>Latest replies</h2><div class="muted">Who answered whom, most recent first.</div></div></div>'
            .'<div class="bm-threads">'.$feed.'</div></div>';
    }
}
