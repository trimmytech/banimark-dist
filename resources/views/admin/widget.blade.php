@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Widget')
@section('sub', 'How the chat bubble looks on your site')
@section('content')
    <div class="bm-grid c2">
        <div class="bm-card">
            <h2>Appearance</h2>
            <form method="post" action="{{ route('banimark.admin.widget.save') }}">
                @csrf
                <div class="grid2">
                    <div><label>Accent colour</label><input type="text" name="color" value="{{ $cfg['color'] ?? '#6F04D9' }}" placeholder="#6F04D9"></div>
                    <div><label>Position</label>
                        <select name="position">
                            <option value="right" @selected(($cfg['position'] ?? 'right') === 'right')>Bottom right</option>
                            <option value="left" @selected(($cfg['position'] ?? '') === 'left')>Bottom left</option>
                        </select>
                    </div>
                </div>
                <label>Header title</label>
                <input type="text" name="title" value="{{ $cfg['title'] ?? 'Support' }}">
                <label>Greeting bubble</label>
                <input type="text" name="greeting" value="{{ $cfg['greeting'] ?? '' }}">

                <div class="divider"></div>
                <div class="grid2">
                    <div>
                        <label>Check for replies every</label>
                        <div class="row">
                            <input type="number" name="poll_seconds" min="3" max="600" value="{{ $cfg['poll_seconds'] ?? 10 }}" style="max-width:120px">
                            <span class="muted">seconds</span>
                        </div>
                        <div class="hint">Only while the chat is open. This is also the visitor's heartbeat.</div>
                    </div>
                    <div>
                        <label>Ask guests who they are</label>
                        <select name="guest_mode">
                            <option value="off" @selected(($cfg['guest_mode'] ?? 'off') === 'off')>Off — chat straight away</option>
                            <option value="optional" @selected(($cfg['guest_mode'] ?? '') === 'optional')>Optional — offer, allow skip</option>
                            <option value="required" @selected(($cfg['guest_mode'] ?? '') === 'required')>Required — name &amp; email first</option>
                        </select>
                        <div class="hint">An email address is what lets us follow up when they leave.</div>
                    </div>
                </div>
                <label>Note shown when nobody is around <span class="muted">(optional)</span></label>
                <input type="text" name="offline_note" value="{{ $cfg['offline_note'] ?? '' }}" placeholder="We usually reply within a few hours.">

                <div style="margin-top:16px"><button type="submit">Save widget</button></div>
            </form>
        </div>

        <div class="bm-card">
            <h2>Embed</h2>
            <div class="muted">Anonymous visitors — drop this before <code>&lt;/body&gt;</code>:</div>
            <textarea readonly rows="2" onclick="this.select()">&lt;script src="{{ url('banimark/widget.js') }}" defer&gt;&lt;/script&gt;</textarea>

            <div class="divider"></div>
            <div class="muted">Signed-in users — mint a token server-side so the AI can look up <em>their</em> data:</div>
            <textarea readonly rows="4" onclick="this.select()">$token = \Banimark\Identity\VisitorToken::mint(
    ['user_id' => auth()->id()],
    config('banimark.identity_secret')
);</textarea>
            <div class="hint">Pass it as <code>data-token</code> on the script tag. The AI can never set these values itself.</div>

            <div class="divider"></div>
            <div class="muted">Or pass known details at init — used to label the chat and to follow up by email, never to scope a tool query:</div>
            <textarea readonly rows="4" onclick="this.select()">window.__BANIMARK_CFG = Object.assign(window.__BANIMARK_CFG || {}, {
  user: { name: "Ada Lovelace", email: "ada@example.com" }
});</textarea>
        </div>
    </div>
@endsection
