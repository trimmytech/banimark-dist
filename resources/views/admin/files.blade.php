@php use Banimark\Ui\Icons; use Banimark\Files\UploadPolicy; @endphp
@extends('banimark::admin.layout')
@section('title', 'Files')
@section('sub', 'Where files shared in a chat are kept')
@section('content')
    @if($problem !== '')
        <div class="flash-err">{!! Icons::get('escalation', 16) !!}<span>{{ $problem }}</span></div>
    @endif

    <form method="post" action="{{ route('banimark.admin.files.save') }}">
        @csrf
        <div class="bm-card">
            <div class="bm-sec-h">
                <div><h2>File sharing</h2>
                    <div class="muted">Visitors and staff can attach files to a message. Turn it off and the paperclip disappears everywhere.</div>
                </div>
                <div class="spacer"></div>
                <label style="display:flex;align-items:center;gap:10px;margin:0">
                    <span class="switch"><input type="checkbox" name="files_enabled" value="1" @checked($s['files_enabled'] ?? '1')><span class="sl"></span></span>
                    Allow files
                </label>
            </div>
            <div class="grid2" style="margin-top:14px">
                <div><label>Largest file (MB)</label><input type="number" name="files_max_mb" min="1" max="100" value="{{ $s['files_max_mb'] ?? UploadPolicy::DEFAULT_MAX_MB }}"></div>
                <div><label>Accepted types <span class="muted">(comma-separated, blank = the list below)</span></label>
                    <input type="text" name="files_types" value="{{ $s['files_types'] ?? '' }}" placeholder="png, jpg, pdf, docx">
                </div>
            </div>
            <div class="hint">Default: {{ implode(', ', array_keys(UploadPolicy::TYPES)) }}. Programs and scripts are never accepted, whatever you type here.</div>
        </div>

        <div class="bm-card">
            <h2>Where they are stored</h2>
            <div class="row" style="gap:10px;margin:12px 0">
                <label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">
                    <input type="radio" name="files_driver" value="local" @checked(($s['files_driver'] ?? 'local') !== 's3') style="margin-top:2px">
                    <span><b>This server</b><div class="muted">Simplest. Files sit in a folder only Banimark reads — never in a public directory.</div></span>
                </label>
                <label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">
                    <input type="radio" name="files_driver" value="s3" @checked(($s['files_driver'] ?? '') === 's3') style="margin-top:2px">
                    <span><b>S3-compatible storage</b><div class="muted">AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO.</div></span>
                </label>
            </div>

            <label>Folder on this server <span class="muted">(blank = {{ $defaultDir }})</span></label>
            <input type="text" name="files_local_path" value="{{ $s['files_local_path'] ?? '' }}" placeholder="{{ $defaultDir }}">

            <div class="divider"></div>
            <div class="grid2">
                <div><label>Bucket</label><input type="text" name="files_s3_bucket" value="{{ $s['files_s3_bucket'] ?? '' }}" placeholder="my-support-files"></div>
                <div><label>Region</label><input type="text" name="files_s3_region" value="{{ $s['files_s3_region'] ?? 'us-east-1' }}" placeholder="eu-west-1"></div>
                <div><label>Access key ID</label><input type="text" name="files_s3_key" value="{{ $s['files_s3_key'] ?? '' }}" autocomplete="off"></div>
                <div><label>Secret access key <span class="muted">{{ ($s['files_s3_secret'] ?? '') !== '' ? '(stored — blank keeps it)' : '' }}</span></label>
                    <input type="password" name="files_s3_secret" value="" autocomplete="new-password" placeholder="{{ ($s['files_s3_secret'] ?? '') !== '' ? '•••••••• (unchanged)' : '' }}"></div>
                <div><label>Endpoint <span class="muted">(only for R2 / Spaces / MinIO)</span></label>
                    <input type="text" name="files_s3_endpoint" value="{{ $s['files_s3_endpoint'] ?? '' }}" placeholder="https://<account>.r2.cloudflarestorage.com"></div>
                <div><label>Key prefix <span class="muted">(optional)</span></label><input type="text" name="files_s3_prefix" value="{{ $s['files_s3_prefix'] ?? '' }}" placeholder="support"></div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px">
                <input type="checkbox" name="files_s3_path_style" value="1" @checked(($s['files_s3_path_style'] ?? '0') === '1')>
                Put the bucket in the path, not the hostname <span class="muted">(MinIO and some proxies need this)</span>
            </label>
            <div class="hint">Your keys never leave this server; files are fetched back through short-lived signed links.</div>
            <div style="margin-top:16px"><button type="submit">{!! Icons::get('check', 15) !!} Save</button></div>
        </div>
    </form>

    <div class="bm-card">
        <h2>Check it works</h2>
        <div class="muted">Writes a small test file with your saved settings, reads it back and deletes it. Nothing is added to any conversation.</div>
        @if(session('bm_files_test'))
            <div class="{{ session('bm_files_ok') ? 'flash-ok' : 'flash-err' }}" style="margin-top:12px">
                {!! Icons::get(session('bm_files_ok') ? 'check' : 'escalation', 16) !!}<span>{{ session('bm_files_test') }}</span>
            </div>
        @endif
        <form method="post" action="{{ route('banimark.admin.files.test') }}" style="margin-top:12px">@csrf
            <button type="submit" class="btn2">Send a test file</button>
        </form>
    </div>

    <div class="bm-card">
        <h2>What is stored now</h2>
        <div class="row" style="gap:26px;margin-top:8px">
            <div><div class="muted">Files</div><b style="font-size:20px">{{ $stats['count'] }}</b></div>
            <div><div class="muted">Total size</div><b style="font-size:20px">{{ $stats['size'] > 1048576 ? round($stats['size'] / 1048576, 1).' MB' : round($stats['size'] / 1024).' KB' }}</b></div>
            <div><div class="muted">Current store</div><b style="font-size:20px">{{ ($s['files_driver'] ?? 'local') === 's3' ? 'S3' : 'This server' }}</b></div>
        </div>
        <div class="hint">Changing store does not move existing files: anything already uploaded is still served from where it was written.</div>
    </div>
@endsection
