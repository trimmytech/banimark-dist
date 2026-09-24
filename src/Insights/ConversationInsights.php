<?php

namespace Banimark\Insights;

use Banimark\Ai\AiRequest;
use Banimark\Ai\Message;
use Banimark\Contracts\AiDriver;

/**
 * "What are my customers talking about, and what do they want?" - the owner's
 * AI provider reads what VISITORS wrote over a period and returns a short
 * business report: the topics, the requests, where people get stuck, and what
 * to change. Shown on the dashboard of both runtimes (render()).
 *
 * Run only when the owner asks (it costs a call to their provider), and never
 * for the visitor's eyes. What goes to the provider is the visitors' own
 * messages - the same text the chat already sends it - with email addresses,
 * phone numbers and long digit runs (cards, accounts) replaced first. Only
 * visitor turns: the assistant's answers and tool results say nothing about
 * what customers want, and tool results can hold the host's data.
 *
 * The report is validated into a fixed shape before it is stored or shown:
 * the model's JSON is data, rendered escaped, never markup.
 */
final class ConversationInsights
{
    /** what the prompt may carry - big enough to be representative, small enough for a quick answer */
    public const CHAR_BUDGET = 40000;
    public const PER_CONVERSATION = 700;
    public const MAX_CONVERSATIONS = 250;
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
    public const SETTING = 'insights_report';

    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /**
     * The visitors' side of recent conversations, newest first, as one block
     * of text within the budget.
     *
     * @return array{total: int, used: int, text: string}
     */
    public function collect(int $days, ?int $now = null): array
    {
        $since = ($now ?? time()) - max(1, $days) * 86400;
        $st = $this->pdo->prepare(
            "SELECT m.conversation_id AS cid, m.content, c.escalated_at
               FROM {$this->prefix}messages m
               JOIN {$this->prefix}conversations c ON c.id = m.conversation_id
              WHERE m.role = 'user' AND m.created_at >= ?
              ORDER BY m.conversation_id DESC, m.id ASC"
        );
        $st->execute([$since]);

        $byConversation = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $cid = (int) $row['cid'];
            $text = self::redact(trim(preg_replace('/\s+/u', ' ', (string) $row['content']) ?? ''));
            if ($text === '') {
                continue;
            }
            $byConversation[$cid]['parts'][] = $text;
            $byConversation[$cid]['human'] = ((int) $row['escalated_at']) > 0;
        }

        $total = count($byConversation);
        $out = [];
        $size = 0;
        foreach ($byConversation as $c) {
            if (count($out) >= self::MAX_CONVERSATIONS) {
                break;
            }
            $line = '- '.($c['human'] ? '[handed to a person] ' : '')
                .mb_substr(implode(' / ', $c['parts']), 0, self::PER_CONVERSATION);
            if ($size + mb_strlen($line) > self::CHAR_BUDGET) {
                break;
            }
            $out[] = $line;
            $size += mb_strlen($line) + 1;
        }
        return ['total' => $total, 'used' => count($out), 'text' => implode("\n", $out)];
    }

    /** Contact details and long numbers out, before anything leaves for the provider. */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text);
        $text = (string) preg_replace('/(?<!\w)\+?\d[\d\s().\-]{7,}\d(?!\w)/u', '[number]', $text);
        return $text;
    }

    /**
     * Ask the provider for the report. One retry when the answer is not the
     * JSON asked for, or ran out of room.
     *
     * @return array{ok: bool, report: ?array, error: string}
     */
    public function analyse(AiDriver $driver, string $model, int $days, ?array $collected = null, string $business = '', ?int $now = null): array
    {
        $collected ??= $this->collect($days, $now);
        if ($collected['used'] === 0) {
            return ['ok' => false, 'report' => null, 'error' => 'There are no visitor messages in this period to analyse yet.'];
        }
        $budget = 3000;
        $ask = [Message::user("Visitor messages from {$collected['used']} support conversations (one line per conversation, newest first):\n\n".$collected['text'])];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $driver->generate(new AiRequest(
                messages: $ask,
                system: self::instruction($business),
                tools: [],
                model: $model,
                temperature: 0.2,
                maxTokens: $budget,
            ));
            if (!$reply->ok) {
                return ['ok' => false, 'report' => null, 'error' => 'Your AI provider did not answer: '.$reply->error];
            }
            $report = self::parse($reply->text);
            if ($report !== null) {
                return ['ok' => true, 'error' => '', 'report' => $report + [
                    'generated_at' => $now ?? time(),
                    'days' => $days,
                    'conversations_used' => $collected['used'],
                    'conversations_total' => $collected['total'],
                    'model' => $model,
                ]];
            }
            $budget = 5000;
            $ask[] = Message::assistant(mb_substr($reply->text, 0, 2000));
            $ask[] = Message::user('That was not the single JSON object asked for (or it was cut off). Send ONLY the JSON object, shorter if needed.');
        }
        return ['ok' => false, 'report' => null, 'error' => 'The AI answered, but not in a form this dashboard can show. Try again, or pick a shorter period.'];
    }

    private static function instruction(string $business): string
    {
        return implode("\n", [
            'You are a business analyst. You read what customers wrote to a company\'s support chat and tell the owner what it means for their business.',
            $business !== '' ? 'The company: '.$business : 'The company\'s line of business is not given - infer it from the messages.',
            '',
            'Answer with ONE JSON object and nothing else - no prose, no code fence:',
            '{',
            '  "summary": "2-3 plain sentences: what customers mostly come for and the single most important thing to act on",',
            '  "topics": [{"name": "short topic name", "share": 35, "examples": ["a short real-sounding paraphrase of what customers wrote"]}],',
            '  "requests": [{"request": "something customers ask the business to do, offer or change", "mentions": 4}],',
            '  "problems": [{"problem": "where customers get stuck or unhappy", "mentions": 3}],',
            '  "suggestions": ["a concrete change the business could make, tied to what customers said"],',
            '  "sentiment": {"positive": 20, "neutral": 60, "negative": 20}',
            '}',
            '',
            'Rules:',
            '- topics: 3 to 8, biggest first; "share" is the approximate percent of conversations about it (they may add up to about 100).',
            '- requests and problems: up to 8 each, most mentioned first; "mentions" = how many conversations raised it.',
            '- suggestions: 3 to 6, specific and actionable (a page to add, a policy to clarify, a product to stock) - not generic advice.',
            '- sentiment: percent of conversations, adding up to 100.',
            '- Lines tagged [handed to a person] needed a human - they often show what the assistant or the website does not cover.',
            '- Never quote personal details. [email] and [number] are removed contact details; ignore them.',
            '- Write in the language most customers used.',
        ]);
    }

    /**
     * The model's answer, validated into the fixed shape the dashboard renders -
     * or null. Everything is clipped: the text is shown to staff and stored.
     */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        $text = (string) preg_replace('~^```(?:json)?\s*|\s*```$~i', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $d = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($d)) {
            return null;
        }
        $str = fn ($v, int $max = 300) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $pct = fn ($v) => max(0, min(100, (int) round((float) (is_numeric($v) ? $v : 0))));

        $topics = [];
        foreach (array_slice((array) ($d['topics'] ?? []), 0, 8) as $t) {
            $name = is_array($t) ? $str($t['name'] ?? '', 80) : '';
            if ($name === '') {
                continue;
            }
            $examples = [];
            foreach (array_slice((array) ($t['examples'] ?? []), 0, 3) as $x) {
                if (($x = $str($x, 200)) !== '') {
                    $examples[] = $x;
                }
            }
            $topics[] = ['name' => $name, 'share' => $pct($t['share'] ?? 0), 'examples' => $examples];
        }
        $counted = function (string $field) use ($d, $str): array {
            $out = [];
            foreach (array_slice((array) ($d[$field.'s'] ?? []), 0, 8) as $r) {
                $what = is_array($r) ? $str($r[$field] ?? '', 200) : $str($r, 200);
                if ($what !== '') {
                    $out[] = [$field => $what, 'mentions' => max(0, (int) (is_array($r) ? ($r['mentions'] ?? 0) : 0))];
                }
            }
            return $out;
        };
        $suggestions = [];
        foreach (array_slice((array) ($d['suggestions'] ?? []), 0, 6) as $s) {
            if (($s = $str(is_array($s) ? ($s['suggestion'] ?? '') : $s, 300)) !== '') {
                $suggestions[] = $s;
            }
        }
        $sent = (array) ($d['sentiment'] ?? []);
        $report = [
            'summary' => $str($d['summary'] ?? '', 800),
            'topics' => $topics,
            'requests' => $counted('request'),
            'problems' => $counted('problem'),
            'suggestions' => $suggestions,
            'sentiment' => ['positive' => $pct($sent['positive'] ?? 0), 'neutral' => $pct($sent['neutral'] ?? 0), 'negative' => $pct($sent['negative'] ?? 0)],
        ];
        // a report with nothing to show is not a report
        return ($report['summary'] === '' && $topics === []) ? null : $report;
    }

    /** The stored report, or null. */
    public static function stored(array $settings): ?array
    {
        $r = json_decode((string) ($settings[self::SETTING] ?? ''), true);
        return is_array($r) && isset($r['generated_at']) ? $r : null;
    }

    /**
     * The dashboard card, for both runtimes.
     *
     * @param array{action: string, csrf: string, can_run: bool, has_provider: bool, days?: int} $o
     *        csrf: the runtime's hidden CSRF field markup (trusted)
     */
    public static function render(?array $report, array $o, ?int $now = null): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $days = (int) ($o['days'] ?? ($report['days'] ?? 30));

        $form = '';
        if (!empty($o['can_run'])) {
            if (empty($o['has_provider'])) {
                $form = '<div class="muted">Connect an AI provider first - the analysis uses the same key your chat does.</div>';
            } else {
                $opts = '';
                foreach (self::PERIODS as $d => $label) {
                    $opts .= '<option value="'.$d.'"'.($d === $days ? ' selected' : '').'>'.$e($label).'</option>';
                }
                $form = '<form method="post" action="'.$e($o['action']).'" class="row" style="gap:8px;flex-wrap:wrap">'.$o['csrf']
                    .'<select name="days" style="width:auto">'.$opts.'</select>'
                    .'<button type="submit" class="btn-sm" data-busy="Analysing… this can take a minute">'
                    .($report ? 'Analyse again' : 'Analyse conversations').'</button></form>';
            }
        }

        $head = '<div class="bm-sec-h"><div><h2>Customer insights</h2>'
            .'<div class="muted">What customers talk about and ask for, read by your AI from their messages</div></div>'
            .'<div class="spacer"></div>'.$form.'</div>';

        if ($report === null) {
            return '<div class="bm-card" id="insights">'.$head
                .\Banimark\Ui\Chart::empty('No analysis yet', 'Pick a period and press Analyse. Your AI provider reads what visitors wrote - contact details removed - and sums it up for your business.')
                .'</div>';
        }

        $list = function (array $items, string $key) use ($e): string {
            if ($items === []) {
                return '<div class="muted">Nothing stood out.</div>';
            }
            $out = '<ol class="ins-list">';
            foreach ($items as $it) {
                $out .= '<li>'.$e($it[$key]).(($it['mentions'] ?? 0) > 0 ? ' <span class="muted">· '.(int) $it['mentions'].'</span>' : '').'</li>';
            }
            return $out.'</ol>';
        };

        $topicRows = [];
        $quotes = '';
        foreach ($report['topics'] as $t) {
            $topicRows[] = ['name' => $t['name'], 'value' => $t['share']];
            foreach (array_slice($t['examples'], 0, 1) as $x) {
                $quotes .= '<li><b>'.$e($t['name']).':</b> <span class="muted">"'.$e($x).'"</span></li>';
            }
        }
        $sug = '';
        foreach ($report['suggestions'] as $s) {
            $sug .= '<li>'.$e($s).'</li>';
        }
        // the stack chart shows a count and its percent: turn the percents
        // into (approximate) conversations so it does not read "10 10%"
        $n = max(1, (int) $report['conversations_used']);
        $s = array_map(fn ($pct) => (int) round($pct * $n / 100), $report['sentiment']);

        return '<div class="bm-card" id="insights">'.$head
            .'<p style="margin:14px 0 4px;font-size:15px">'.$e($report['summary']).'</p>'
            .'<div class="muted" style="margin-bottom:14px">Based on '.(int) $report['conversations_used']
                .((int) $report['conversations_total'] > (int) $report['conversations_used'] ? ' of '.(int) $report['conversations_total'] : '')
                .' conversations from the last '.(int) $report['days'].' days · analysed '.$e(date('j M Y, H:i', (int) $report['generated_at'])).' · '.$e($report['model']).'</div>'
            .'<div class="bm-grid c2">'
                .'<div><h3>What people talk about <span class="muted">(% of conversations)</span></h3>'
                    .\Banimark\Ui\Chart::hbars($topicRows, 'var(--s1)', 'No clear topics')
                    .($quotes !== '' ? '<ul class="ins-list" style="margin-top:10px">'.$quotes.'</ul>' : '').'</div>'
                .'<div><h3>How they feel</h3>'
                    .\Banimark\Ui\Chart::stack([
                        ['name' => 'Positive', 'value' => $s['positive'], 'color' => 'var(--ok, #067647)'],
                        ['name' => 'Neutral', 'value' => $s['neutral'], 'color' => 'var(--surface-3)'],
                        ['name' => 'Negative', 'value' => $s['negative'], 'color' => 'var(--danger, #e5484d)'],
                    ])
                    .'<h3 style="margin-top:18px">Ideas for your business</h3>'
                    .($sug !== '' ? '<ol class="ins-list">'.$sug.'</ol>' : '<div class="muted">Nothing stood out.</div>').'</div>'
                .'<div><h3>What customers ask for</h3>'.$list($report['requests'], 'request').'</div>'
                .'<div><h3>Where they get stuck</h3>'.$list($report['problems'], 'problem').'</div>'
            .'</div></div>';
    }
}
