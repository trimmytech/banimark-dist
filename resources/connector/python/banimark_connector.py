"""
Banimark connector - Python
===========================
Lets the Banimark assistant for "__BANIMARK_DESK__" look things up in this
website's database, read-only, without Banimark ever holding a database
password. One file; it uses the SQLAlchemy your app already has (1.4 or 2.x)
and the database driver that comes with it (psycopg2 / psycopg for
PostgreSQL, PyMySQL or mysqlclient for MySQL, SQLite built in).

Three ways to run it:

  1. Inside FastAPI (or any Starlette app) - drop the file next to main.py:

         from banimark_connector import fastapi_router
         app.include_router(fastapi_router(engine))      # your SQLAlchemy engine

     The connector answers at /banimark-connector. Pass nothing instead of
     `engine` to have it open BANIMARK_DB_URL (or DATABASE_URL) itself.

  2. Inside Flask, Django or any WSGI app:

         Flask:   app.add_url_rule('/banimark-connector', 'banimark', flask_view(engine), methods=['GET', 'POST'])
         Django:  path('banimark-connector', django_view(engine))      # in urls.py
         WSGI:    the `wsgi_app(engine)` callable, mounted at any path

  3. On its own:

         BANIMARK_DB_URL=postgresql://user:pass@127.0.0.1/shop python banimark_connector.py

     It listens on PORT (default 8787). Put it behind your web server or
     reverse proxy, then open the address in a browser and paste what the
     page shows into Banimark -> Tools.

What it does, and does not do:
 - answers only requests signed by your desk (the secret below); anything
   else gets a 401 and nothing more;
 - runs only a single SELECT, with values bound, capped at 100 rows and
   5 seconds; never writes (PostgreSQL: a read-only transaction with a
   statement timeout; MySQL: a MAX_EXECUTION_TIME hint);
 - never lists or returns columns that look like secrets (password, token,
   api key, card number ...), whatever the query asks for;
 - never changes the session of a connection your app shares with it: every
   limit is per statement or per transaction.

Contract: https://github.com/trimmytech/banimark-dist/blob/main/docs/connector.md
"""

# ---- settings ---------------------------------------------------------------
# The secret is already filled in: it matches the desk this file was downloaded from.
BANIMARK_SECRET = '__BANIMARK_SECRET__'

# Database. Leave blank to use the engine your app passes in, or the
# BANIMARK_DB_URL / DATABASE_URL environment variable.
#   PostgreSQL:    'postgresql://user:password@127.0.0.1:5432/shop'
#   MySQL/MariaDB: 'mysql+pymysql://user:password@127.0.0.1:3306/shop'
#   SQLite:        'sqlite:////var/www/data/shop.sqlite'
BANIMARK_DB_URL = ''
# A read-only database account is the safest choice - this file never writes anyway.
# -----------------------------------------------------------------------------

import base64
import datetime
import decimal
import hashlib
import hmac
import html
import json
import os
import re
import threading
import time
import uuid

BANIMARK_CONNECTOR_VERSION = 1
BANIMARK_MAX_ROWS = 100
BANIMARK_TIMEOUT_MS = 5000
BANIMARK_ACTIONS = ('health', 'schema', 'query')

# ---- the contract, in one place --------------------------------------------

_SENSITIVE = re.compile(r'pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$', re.I)
_FORBIDDEN = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'replace', 'create', 'grant', 'revoke', 'rename',
              'call', 'handler', 'do', 'into', 'outfile', 'dumpfile', 'load_file', 'load', 'benchmark', 'sleep', 'get_lock', 'release_lock',
              'union', 'information_schema', 'performance_schema', 'mysql', 'sys', 'set', 'prepare', 'execute', 'deallocate', 'lock',
              'unlock', 'for update', 'procedure', 'pg_sleep', 'pg_catalog']
_FORBIDDEN_RE = [re.compile(r'\b' + re.escape(w) + r'\b', re.I) for w in _FORBIDDEN]
_LITERAL_RE = re.compile(r"'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"")
_PLUMBING = {'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_resets',
             'password_reset_tokens', 'personal_access_tokens', 'doctrine_migration_versions', 'migration_versions', 'phinxlog', 'ci_sessions',
             'alembic_version', 'django_migrations', 'django_session', 'django_admin_log', 'django_content_type', 'apscheduler_jobs'}
_PLUMBING_RE = re.compile(r'^(telescope_|pulse_|horizon_|oauth_|celery_|kombu_)|(^|_)(usermeta|options|sessions|transients)$')


def _b64url_decode(s):
    s = str(s)
    return base64.urlsafe_b64decode(s + '=' * (-len(s) % 4))


def _safe_equal(a, b):
    return hmac.compare_digest(str(a).encode('utf-8'), str(b).encode('utf-8'))


def banimark_verify(secret, jwt, body, now):
    """{'ok': True, 'action': ...} or {'ok': False, 'error': ...}. `body` is the exact bytes received."""
    parts = str(jwt).split('.')
    # (the download replaces the placeholder everywhere, so the secret is checked by length, never by comparing to it)
    if len(parts) != 3 or not isinstance(secret, str) or len(secret) < 32:
        return {'ok': False, 'error': 'malformed token'}
    h, p, s = parts
    expected = base64.urlsafe_b64encode(hmac.new(secret.encode('utf-8'), (h + '.' + p).encode('utf-8'), hashlib.sha256).digest()).rstrip(b'=').decode('ascii')
    if not _safe_equal(expected, s.rstrip('=')):
        return {'ok': False, 'error': 'bad signature'}
    try:
        claims = json.loads(_b64url_decode(p).decode('utf-8'))
    except Exception:
        claims = None
    if not isinstance(claims, dict) or claims.get('iss') != 'banimark' or claims.get('aud') != 'banimark-connector':
        return {'ok': False, 'error': 'wrong issuer or audience'}
    try:
        exp = int(claims.get('exp') or 0)
        iat = int(claims.get('iat') or 0)
    except (TypeError, ValueError):
        exp, iat = 0, 0
    if exp < now or iat > now + 30:
        return {'ok': False, 'error': 'token expired'}
    body_hash = hashlib.sha256(body).hexdigest()
    if not _safe_equal('' if claims.get('bh') is None else claims.get('bh'), body_hash):
        return {'ok': False, 'error': 'body does not match the token'}
    action = '' if claims.get('act') is None else str(claims.get('act'))
    if action not in BANIMARK_ACTIONS:
        return {'ok': False, 'error': 'unknown action'}
    return {'ok': True, 'action': action}


def banimark_guard(sql):
    """None = the statement may run; otherwise why not."""
    sql = str(sql).strip()
    if sql == '' or len(sql.encode('utf-8')) > 4000:
        return 'empty or too long'
    if not re.match(r'^select\s', sql, re.I):
        return 'only SELECT is allowed'
    if ';' in sql:
        return 'one statement only'
    if re.search(r'--|#|/\*', sql):
        return 'no comments'
    stripped = _LITERAL_RE.sub("''", sql)
    for word, rx in zip(_FORBIDDEN, _FORBIDDEN_RE):
        if rx.search(stripped):
            return 'forbidden keyword: ' + word
    return None


def banimark_sensitive(column):
    return _SENSITIVE.search(str(column)) is not None


def banimark_placeholders(sql):
    """
    (names, sql): the named placeholders a statement uses, in order of first
    appearance, skipping string literals and PostgreSQL's `::type` casts.
    The returned SQL is the statement ready for SQLAlchemy's text(): a colon
    inside a string literal, and the two of a cast, are escaped (`\\:`) so
    text() never takes them for placeholders (and `:id::int` still binds `id`).
    """
    names = []
    out = []
    i, n = 0, len(sql)
    name_re = re.compile(r':([A-Za-z_][A-Za-z0-9_]*)')
    while i < n:
        c = sql[i]
        if c == "'" or c == '"':
            j = i + 1
            while j < n:
                if sql[j] == '\\':
                    j += 2
                    continue
                if sql[j] == c:
                    j += 1
                    break
                j += 1
            out.append(sql[i:j].replace(':', '\\:'))
            i = j
            continue
        if c == ':':
            prev = sql[i - 1] if i > 0 else ''
            m = name_re.match(sql, i)
            nxt = sql[i + 1] if i + 1 < n else ''
            if m and prev != ':' and nxt != ':':
                if m.group(1) not in names:
                    names.append(m.group(1))
                out.append(m.group(0))
                i += len(m.group(0))
                continue
            if nxt == ':':  # a `::type` cast: both colons escaped, so text() leaves them alone
                out.append('\\:\\:')
                i += 2
                continue
        out.append(c)
        i += 1
    return names, ''.join(out)


# ---- the database -----------------------------------------------------------

_engine = None
_engine_lock = threading.Lock()


def configure(engine=None, url=None):
    """Use the app's own SQLAlchemy engine (preferred), or a database URL."""
    global _engine
    with _engine_lock:
        if engine is not None:
            _engine = engine
        elif url:
            _engine = _make_engine(url)


def _db_url():
    return BANIMARK_DB_URL or os.environ.get('BANIMARK_DB_URL') or os.environ.get('DATABASE_URL') or ''


def _make_engine(url):
    import sqlalchemy
    url = str(url).strip()
    if re.match(r'^postgres://', url, re.I):  # Heroku-style; SQLAlchemy 2 only knows postgresql://
        url = 'postgresql://' + url[len('postgres://'):]
    engine = sqlalchemy.create_engine(url, pool_size=2, max_overflow=2, pool_pre_ping=True) if not url.startswith('sqlite') \
        else sqlalchemy.create_engine(url)
    with engine.connect() as conn:  # fail now, not on the first lookup
        conn.execute(sqlalchemy.text('SELECT 1'))
    return engine


def banimark_db():
    """The engine, opened once from the URL when the app did not pass one."""
    global _engine
    if _engine is not None:
        return _engine
    with _engine_lock:
        if _engine is None:
            url = _db_url()
            if url == '':
                raise RuntimeError('no database settings - pass your SQLAlchemy engine to the connector, fill in BANIMARK_DB_URL at the top of banimark_connector.py, or set the BANIMARK_DB_URL (or DATABASE_URL) environment variable')
            _engine = _make_engine(url)
    return _engine


def banimark_dialect(engine):
    name = str(engine.dialect.name).lower()
    if name.startswith('postgres'):
        return 'pgsql'
    if name in ('mysql', 'mariadb'):
        return 'mysql'
    if name == 'sqlite':
        return 'sqlite'
    return name


def _is_mariadb(engine):
    return bool(getattr(engine.dialect, 'is_mariadb', False))


def banimark_tables(engine):
    """The owner's tables: plumbing (migrations, sessions, caches, queues, Banimark's own) left out."""
    import sqlalchemy
    out = []
    for t in sqlalchemy.inspect(engine).get_table_names():
        low = str(t).lower()
        if low.startswith('banimark_') or low.startswith('hq_') or low in _PLUMBING or _PLUMBING_RE.search(low):
            continue
        out.append(str(t))
    out.sort()
    return out


def banimark_columns(engine, table):
    import sqlalchemy
    if not re.match(r'^[A-Za-z0-9_]+$', table):
        return []
    cols = []
    for c in sqlalchemy.inspect(engine).get_columns(table):
        if not banimark_sensitive(c['name']):
            cols.append({'name': str(c['name']), 'type': str(c['type']).lower()})
    return cols


def _bind_value(bindings, name):
    """The value to bind for one placeholder: the desk may send ints, strings, bools, null; missing = null."""
    value = bindings.get(name)
    if value is None or isinstance(value, (bool, int, float, str)):
        return value
    return json.dumps(value)


def banimark_query(engine, sql, bindings, max_rows):
    import sqlalchemy
    names, prepared = banimark_placeholders(sql)
    params = {name: _bind_value(bindings, name) for name in names}
    dialect = banimark_dialect(engine)
    # the time cap and read-only promise, scoped to this statement or transaction
    # so a connection the app shares with the connector is left as it was
    if dialect == 'mysql':
        prepared = ('SET STATEMENT max_statement_time=%d FOR ' % (BANIMARK_TIMEOUT_MS // 1000)) + prepared if _is_mariadb(engine) \
            else re.sub(r'^select\s', 'SELECT /*+ MAX_EXECUTION_TIME(%d) */ ' % BANIMARK_TIMEOUT_MS, prepared, count=1, flags=re.I)
    rows = []
    with engine.connect() as conn:
        tx = conn.begin()
        try:
            if dialect == 'pgsql':
                conn.execute(sqlalchemy.text('SET LOCAL statement_timeout = %d' % BANIMARK_TIMEOUT_MS))
                conn.execute(sqlalchemy.text('SET TRANSACTION READ ONLY'))
            result = conn.execute(sqlalchemy.text(prepared), params)
            for row in result.mappings().fetchmany(max_rows):
                rows.append({str(k): v for k, v in row.items() if not banimark_sensitive(k)})
        finally:
            tx.rollback()  # never a commit: nothing was written, nothing to keep
    return rows


# ---- the endpoint -------------------------------------------------------------

def _jsonable(v):
    if isinstance(v, (datetime.datetime, datetime.date, datetime.time)):
        return v.isoformat()
    if isinstance(v, decimal.Decimal):
        return float(v) if v == v.to_integral_value() and abs(v) < 1e15 else str(v)
    if isinstance(v, uuid.UUID):
        return str(v)
    if isinstance(v, (bytes, bytearray, memoryview)):
        return base64.b64encode(bytes(v)).decode('ascii')
    if isinstance(v, (set, frozenset)):
        return sorted(v, key=str)
    return str(v)


def _json(status, data):
    return status, 'application/json; charset=utf-8', json.dumps(data, default=_jsonable).encode('utf-8')


def scrub(text):
    """A database error without anything that looks like a credential in it."""
    text = '' if text is None else str(text)
    text = re.sub(r'(password|pwd|pass)=\S+', r'\1=***', text, flags=re.I)
    return re.sub(r'://[^\s@/]+@', '://***@', text)


def handle(method, path, headers, body, scheme='http'):
    """
    The whole contract, framework-free: (status, content type, bytes).
    `headers` is a dict with lower-case names; `body` the exact bytes received.
    """
    method = str(method or 'GET').upper()
    headers = {str(k).lower(): v for k, v in (headers or {}).items()}
    if method in ('GET', 'HEAD'):
        return _status_page(path, headers, scheme)
    if method != 'POST':
        return _json(405, {'ok': False, 'error': 'POST only'})
    if body is None or len(body) > 1048576:
        return _json(400, {'ok': False, 'error': 'could not read the body'})
    auth = str(headers.get('authorization') or '')
    if not re.match(r'^bearer ', auth, re.I):
        return _json(401, {'ok': False, 'error': 'no token'})
    check = banimark_verify(BANIMARK_SECRET, auth[7:].strip(), body, int(time.time()))
    if not check['ok']:
        return _json(401, {'ok': False, 'error': check['error']})
    try:
        data = json.loads(body.decode('utf-8'))
    except Exception:
        data = None
    if not isinstance(data, dict) or data.get('action') != check['action']:
        return _json(400, {'ok': False, 'error': 'the body is not JSON, or its action does not match the token'})
    try:
        engine = banimark_db()
    except Exception as e:
        return _json(500, {'ok': False, 'error': 'database connection failed: ' + scrub(e)})
    try:
        action = data['action']
        if action == 'health':
            return _json(200, {'ok': True, 'connector': 'banimark', 'version': BANIMARK_CONNECTOR_VERSION, 'platform': 'python',
                               'dialect': banimark_dialect(engine), 'tables': len(banimark_tables(engine))})
        if action == 'schema':
            return _json(200, {'ok': True, 'tables': {t: banimark_columns(engine, t) for t in banimark_tables(engine)}})
        if action == 'query':
            sql = '' if data.get('sql') is None else str(data.get('sql'))
            why = banimark_guard(sql)
            if why is not None:
                return _json(422, {'ok': False, 'error': 'refused: ' + why})
            try:
                max_rows = int(data.get('max_rows'))
            except (TypeError, ValueError):
                max_rows = 10
            max_rows = max(1, min(BANIMARK_MAX_ROWS, max_rows))
            bindings = data.get('bindings') if isinstance(data.get('bindings'), dict) else {}
            try:
                rows = banimark_query(engine, sql, bindings, max_rows)
            except Exception as e:
                return _json(500, {'ok': False, 'error': 'the database rejected the query: ' + scrub(e)})
            return _json(200, {'ok': True, 'rows': rows, 'count': len(rows)})
        return _json(400, {'ok': False, 'error': 'unknown action'})
    except Exception as e:
        return _json(500, {'ok': False, 'error': 'the database failed: ' + scrub(e)})


def _status_page(path, headers, scheme):
    forwarded = str(headers.get('x-forwarded-proto') or '').split(',')[0].strip()
    scheme = 'https' if forwarded == 'https' or scheme == 'https' else 'http'
    host = str(headers.get('host') or 'localhost')
    path = (str(path or '/').split('?')[0]) or '/'
    address = scheme + '://' + host + path
    try:
        engine = banimark_db()
        n = len(banimark_tables(engine))
        db = 'Connected to the database (%s, %d table%s).' % (banimark_dialect(engine), n, '' if n == 1 else 's')
        ok = True
    except Exception as e:
        db = 'The database is not reachable yet: ' + scrub(e)
        ok = False
    page = ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banimark connector</title>'
            '<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#1b1f24}code{background:#f1f3f5;padding:3px 7px;border-radius:6px;font-size:14px;word-break:break-all}.ok{color:#0a7d3e}.bad{color:#b42318}</style></head><body>'
            '<h1>Banimark connector is installed</h1>'
            '<p>For the desk <b>' + html.escape('__BANIMARK_DESK__') + '</b>. Copy this address into Banimark &rarr; Tools &rarr; "Where your data lives":</p>'
            '<p><code>' + html.escape(address) + '</code></p>'
            '<p class="' + ('ok' if ok else 'bad') + '">' + html.escape(db) + '</p>'
            '<p style="color:#666;font-size:13px">This page shows no data. The assistant&#039;s lookups are signed by your desk, read-only, and limited to the columns you tick in Banimark.</p>'
            '</body></html>')
    return 200, 'text/html; charset=utf-8', page.encode('utf-8')


# ---- the frameworks -------------------------------------------------------------

def fastapi_router(engine=None, path='/banimark-connector'):
    """A FastAPI / Starlette router answering GET and POST at `path`. `app.include_router(fastapi_router(engine))`."""
    from fastapi import APIRouter, Request, Response
    from starlette.concurrency import run_in_threadpool
    if engine is not None:
        configure(engine=engine)
    router = APIRouter()

    async def banimark_connector(request: Request):
        body = await request.body() if request.method == 'POST' else b''
        status, ctype, out = await run_in_threadpool(handle, request.method, request.url.path, dict(request.headers), body, request.url.scheme)
        return Response(content=b'' if request.method == 'HEAD' else out, status_code=status, media_type=ctype, headers={'Cache-Control': 'no-store'})

    router.add_api_route(path, banimark_connector, methods=['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], include_in_schema=False)
    return router


def wsgi_app(engine=None):
    """A WSGI callable (Flask, Django, gunicorn, any mount) for the connector."""
    if engine is not None:
        configure(engine=engine)

    def application(environ, start_response):
        try:
            length = int(environ.get('CONTENT_LENGTH') or 0)
        except ValueError:
            length = 0
        body = environ['wsgi.input'].read(length) if environ.get('REQUEST_METHOD') == 'POST' and length > 0 else b''
        headers = {k[5:].replace('_', '-').lower(): v for k, v in environ.items() if k.startswith('HTTP_')}
        path = (environ.get('SCRIPT_NAME') or '') + (environ.get('PATH_INFO') or '/')
        status, ctype, out = handle(environ.get('REQUEST_METHOD'), path, headers, body, environ.get('wsgi.url_scheme', 'http'))
        start_response('%d %s' % (status, {200: 'OK', 400: 'Bad Request', 401: 'Unauthorized', 405: 'Method Not Allowed', 422: 'Unprocessable Entity', 500: 'Internal Server Error'}.get(status, 'OK')),
                       [('Content-Type', ctype), ('Cache-Control', 'no-store'), ('Content-Length', str(len(out)))])
        return [out]
    return application


def flask_view(engine=None):
    """A Flask view: `app.add_url_rule('/banimark-connector', 'banimark', flask_view(engine), methods=['GET', 'POST'])`."""
    if engine is not None:
        configure(engine=engine)

    def banimark_connector():
        from flask import request, Response
        status, ctype, out = handle(request.method, request.path, dict(request.headers), request.get_data(), request.scheme)
        return Response(out, status=status, content_type=ctype, headers={'Cache-Control': 'no-store'})
    return banimark_connector


def django_view(engine=None):
    """A Django view, CSRF-exempt (the request is signed): `path('banimark-connector', django_view(engine))`."""
    from django.http import HttpResponse
    from django.views.decorators.csrf import csrf_exempt
    if engine is not None:
        configure(engine=engine)

    @csrf_exempt
    def banimark_connector(request):
        status, ctype, out = handle(request.method, request.path, dict(request.headers), request.body, request.scheme)
        response = HttpResponse(out, status=status, content_type=ctype)
        response['Cache-Control'] = 'no-store'
        return response
    return banimark_connector


# `python banimark_connector.py` serves the connector at every path on PORT (default 8787).
if __name__ == '__main__':
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

    class _Handler(BaseHTTPRequestHandler):
        def _answer(self):
            length = int(self.headers.get('Content-Length') or 0)
            body = self.rfile.read(length) if self.command == 'POST' and length > 0 else b''
            status, ctype, out = handle(self.command, self.path, dict(self.headers.items()), body, 'http')
            self.send_response(status)
            self.send_header('Content-Type', ctype)
            self.send_header('Cache-Control', 'no-store')
            self.send_header('Content-Length', str(len(out)))
            self.end_headers()
            if self.command != 'HEAD':
                self.wfile.write(out)

        do_GET = do_HEAD = do_POST = do_PUT = do_PATCH = do_DELETE = _answer

        def log_message(self, fmt, *args):  # quiet: the app's own logs are enough
            pass

    port = int(os.environ.get('PORT') or 8787)
    print('Banimark connector listening on http://127.0.0.1:%d/banimark-connector' % port)
    ThreadingHTTPServer(('0.0.0.0', port), _Handler).serve_forever()
