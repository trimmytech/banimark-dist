'use strict';
/**
 * Banimark connector - Node
 * =========================
 * Lets the Banimark assistant for "__BANIMARK_DESK__" look things up in this
 * website's database, read-only, without Banimark ever holding a database
 * password. One file, no package to install.
 *
 * Two ways to run it:
 *
 *   1. On its own:     BANIMARK_DB_URL=mysql://user:pass@127.0.0.1/shop node banimark-connector.js
 *      It listens on PORT (default 8787). Put it behind your web server or
 *      reverse proxy, then open the address in a browser and paste what the
 *      page shows into Banimark -> Tools.
 *
 *   2. Inside Express:  app.post('/banimark-connector', require('./banimark-connector').express());
 *                       app.get('/banimark-connector',  require('./banimark-connector').express());
 *      Mount it BEFORE app.use(express.json()) (or mount it with
 *      express.raw({ type: '*\/*' }) on the route). The request is signed over
 *      the exact bytes of the body, so a body that was already parsed and
 *      re-serialised would never verify.
 *
 * What it does, and does not do:
 *  - answers only requests signed by your desk (the secret below); anything
 *    else gets a 401 and nothing more;
 *  - runs only a single SELECT, with values bound, capped at 100 rows and
 *    5 seconds; never writes;
 *  - never lists or returns columns that look like secrets (password, token,
 *    card number ...), whatever the query asks for.
 *
 * Database drivers: it uses the one your app already has - `mysql2` for
 * mysql://, `pg` for postgres:// or postgresql://, and Node's built-in
 * `node:sqlite` for sqlite: (Node 22.13 / 23.4 or later; older 22.x needs
 * --experimental-sqlite). Nothing else is required. Node 18 or later.
 *
 * Contract: https://github.com/trimmytech/banimark-dist/blob/main/docs/connector.md
 */

// ---- settings -------------------------------------------------------------
// The secret is already filled in: it matches the desk this file was downloaded from.
const BANIMARK_SECRET = '__BANIMARK_SECRET__';

// Database. Leave blank to use the BANIMARK_DB_URL or DATABASE_URL environment variable.
//   MySQL/MariaDB: 'mysql://user:password@127.0.0.1:3306/shop'
//   PostgreSQL:    'postgres://user:password@127.0.0.1:5432/shop'
//   SQLite:        'sqlite:/var/www/data/shop.sqlite'
const BANIMARK_DB_URL = '';
// A read-only database account is the safest choice - this file never writes anyway.
// ---------------------------------------------------------------------------

const http = require('node:http');
const crypto = require('node:crypto');

const BANIMARK_CONNECTOR_VERSION = 1;
const BANIMARK_MAX_ROWS = 100;
const BANIMARK_TIMEOUT_MS = 5000;
const BANIMARK_ACTIONS = ['health', 'schema', 'query'];

// ---- the contract, in one place ------------------------------------------

/** @returns {{ok: boolean, error?: string, action?: string}} */
function banimarkVerify(secret, jwt, body, now) {
  const parts = String(jwt).split('.');
  // (the download replaces the placeholder everywhere, so the secret is checked by length, never by comparing to it)
  if (parts.length !== 3 || typeof secret !== 'string' || secret.length < 32) {
    return { ok: false, error: 'malformed token' };
  }
  const [h, p, s] = parts;
  const expected = crypto.createHmac('sha256', secret).update(h + '.' + p).digest('base64url');
  if (!safeEqual(expected, s)) {
    return { ok: false, error: 'bad signature' };
  }
  let claims = null;
  try {
    claims = JSON.parse(Buffer.from(p, 'base64url').toString('utf8'));
  } catch (e) {
    claims = null;
  }
  if (!claims || typeof claims !== 'object' || claims.iss !== 'banimark' || claims.aud !== 'banimark-connector') {
    return { ok: false, error: 'wrong issuer or audience' };
  }
  const exp = parseInt(claims.exp, 10) || 0;
  const iat = parseInt(claims.iat, 10) || 0;
  if (exp < now || iat > now + 30) {
    return { ok: false, error: 'token expired' };
  }
  const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
  if (!safeEqual(String(claims.bh == null ? '' : claims.bh), bodyHash)) {
    return { ok: false, error: 'body does not match the token' };
  }
  const action = String(claims.act == null ? '' : claims.act);
  if (!BANIMARK_ACTIONS.includes(action)) {
    return { ok: false, error: 'unknown action' };
  }
  return { ok: true, action };
}

/** null = the statement may run; otherwise why not. */
function banimarkGuard(sql) {
  sql = String(sql).trim();
  if (sql === '' || Buffer.byteLength(sql) > 4000) {
    return 'empty or too long';
  }
  if (!/^select\s/i.test(sql)) {
    return 'only SELECT is allowed';
  }
  if (sql.includes(';')) {
    return 'one statement only';
  }
  if (/--|#|\/\*/.test(sql)) {
    return 'no comments';
  }
  const stripped = sql.replace(/'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g, "''");
  const forbidden = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'replace', 'create', 'grant', 'revoke', 'rename',
    'call', 'handler', 'do', 'into', 'outfile', 'dumpfile', 'load_file', 'load', 'benchmark', 'sleep', 'get_lock', 'release_lock',
    'union', 'information_schema', 'performance_schema', 'mysql', 'sys', 'set', 'prepare', 'execute', 'deallocate', 'lock',
    'unlock', 'for update', 'procedure', 'pg_sleep', 'pg_catalog'];
  for (const word of forbidden) {
    const re = new RegExp('\\b' + word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i');
    if (re.test(stripped)) {
      return 'forbidden keyword: ' + word;
    }
  }
  return null;
}

function banimarkSensitive(column) {
  return /pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i.test(String(column));
}

/**
 * The named placeholders a statement uses, in order of first appearance,
 * skipping string literals and PostgreSQL's `::type` casts. `replace`, when
 * given, is called for each occurrence and its result takes the place of the
 * placeholder (used to turn `:name` into `$1` for pg).
 */
function banimarkPlaceholders(sql, replace) {
  const names = [];
  let out = '';
  let i = 0;
  const n = sql.length;
  while (i < n) {
    const c = sql[i];
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < n) {
        if (sql[j] === '\\') { j += 2; continue; }
        if (sql[j] === c) { j++; break; }
        j++;
      }
      out += sql.slice(i, j);
      i = j;
      continue;
    }
    if (c === ':') {
      const prev = i > 0 ? sql[i - 1] : '';
      const re = /:([a-zA-Z_][a-zA-Z0-9_]*)/y;
      re.lastIndex = i;
      const m = re.exec(sql);
      if (m && prev !== ':' && sql[i + 1] !== ':') {
        const name = m[1];
        if (!names.includes(name)) {
          names.push(name);
        }
        out += replace ? replace(name) : m[0];
        i += m[0].length;
        continue;
      }
      if (sql[i + 1] === ':') { // a `::type` cast: copy both colons untouched
        out += '::';
        i += 2;
        continue;
      }
    }
    out += c;
    i++;
  }
  return { names, sql: out };
}

// ---- the database ---------------------------------------------------------

let dbPromise = null;

function banimarkDbUrl() {
  return BANIMARK_DB_URL !== '' ? BANIMARK_DB_URL : (process.env.BANIMARK_DB_URL || process.env.DATABASE_URL || '');
}

/** One adapter per dialect: { dialect, tables(), columns(table), run(sql, bindings) }. Connected once, reused. */
function banimarkDb() {
  if (dbPromise === null) {
    dbPromise = openDb().catch((e) => {
      dbPromise = null; // try again on the next request
      throw e;
    });
  }
  return dbPromise;
}

async function openDb() {
  const url = banimarkDbUrl();
  if (url === '') {
    throw new Error('no database settings - fill in BANIMARK_DB_URL at the top of banimark-connector.js, or set the BANIMARK_DB_URL (or DATABASE_URL) environment variable');
  }
  if (/^mysql:\/\//i.test(url)) {
    return openMysql(url);
  }
  if (/^postgres(ql)?:\/\//i.test(url)) {
    return openPg(url);
  }
  if (/^sqlite:/i.test(url) || /\.(sqlite|sqlite3|db)$/i.test(url)) {
    return openSqlite(url);
  }
  throw new Error('unsupported database URL - use mysql://, postgres:// or sqlite:');
}

async function openMysql(url) {
  const mysql = require('mysql2/promise'); // the app's own driver, loaded only when needed
  const pool = mysql.createPool({ uri: url, namedPlaceholders: true, connectionLimit: 2, waitForConnections: true });
  pool.on('connection', (conn) => {
    // best effort: an older engine without the setting still answers
    conn.query('SET SESSION MAX_EXECUTION_TIME=' + BANIMARK_TIMEOUT_MS, () => {});          // MySQL 5.7+
    conn.query('SET SESSION max_statement_time=' + (BANIMARK_TIMEOUT_MS / 1000), () => {}); // MariaDB
  });
  await pool.query('SELECT 1'); // fail now, not on the first lookup
  return {
    dialect: 'mysql',
    async tables() {
      const [rows] = await pool.query('SHOW TABLES');
      return rows.map((r) => String(Object.values(r)[0]));
    },
    async columns(table) {
      const [rows] = await pool.query('SHOW COLUMNS FROM `' + table + '`');
      return rows.map((r) => ({ name: String(r.Field), type: String(r.Type).toLowerCase() }));
    },
    async run(sql, bindings) {
      const { names } = banimarkPlaceholders(sql);
      const params = {};
      for (const name of names) {
        params[name] = bindValue(bindings, name, 'mysql');
      }
      const [rows] = await pool.execute(sql, params);
      return Array.isArray(rows) ? rows : [];
    },
  };
}

async function openPg(url) {
  const pg = require('pg'); // the app's own driver, loaded only when needed
  const pool = new pg.Pool({ connectionString: url, max: 2 });
  pool.on('connect', (client) => {
    // best effort: statement time cap and read-only transactions
    client.query('SET statement_timeout = ' + BANIMARK_TIMEOUT_MS).catch(() => {});
    client.query('SET default_transaction_read_only = on').catch(() => {});
  });
  pool.on('error', () => {});
  await pool.query('SELECT 1');
  return {
    dialect: 'pgsql',
    async tables() {
      const r = await pool.query("SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_name");
      return r.rows.map((row) => String(row.table_name));
    },
    async columns(table) {
      const r = await pool.query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = $1 AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY ordinal_position", [table]);
      return r.rows.map((row) => ({ name: String(row.column_name), type: String(row.data_type).toLowerCase() }));
    },
    async run(sql, bindings) {
      // `:name` -> `$1..$n` positional, one number per distinct name; `::type` casts are left alone
      const order = [];
      const converted = banimarkPlaceholders(sql, (name) => {
        let idx = order.indexOf(name);
        if (idx === -1) {
          order.push(name);
          idx = order.length - 1;
        }
        return '$' + (idx + 1);
      });
      const values = order.map((name) => bindValue(bindings, name, 'pgsql'));
      const r = await pool.query(converted.sql, values);
      return r.rows;
    },
  };
}

async function openSqlite(url) {
  const { DatabaseSync } = require('node:sqlite'); // built into Node, loaded only when needed
  let file = url.replace(/^sqlite:/i, '');
  if (file.startsWith('//')) {
    file = file.slice(2);
  }
  const db = new DatabaseSync(file, { readOnly: true });
  return {
    dialect: 'sqlite',
    async tables() {
      return db.prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name").all().map((r) => String(r.name));
    },
    async columns(table) {
      return db.prepare('PRAGMA table_info("' + table + '")').all().map((r) => ({ name: String(r.name), type: String(r.type).toLowerCase() }));
    },
    async run(sql, bindings) {
      const { names } = banimarkPlaceholders(sql);
      const params = {};
      for (const name of names) {
        params[name] = bindValue(bindings, name, 'sqlite');
      }
      const rows = db.prepare(sql).all(params);
      return rows.map((r) => Object.assign({}, r));
    },
  };
}

/** The value to bind for one placeholder: the desk may send ints, strings, bools, null; missing = null. */
function bindValue(bindings, name, dialect) {
  const value = Object.prototype.hasOwnProperty.call(bindings, name) ? bindings[name] : null;
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value === 'boolean') {
    return dialect === 'pgsql' ? value : (value ? 1 : 0);
  }
  if (typeof value === 'number' || typeof value === 'string' || typeof value === 'bigint') {
    return value;
  }
  return JSON.stringify(value);
}

/** The owner's tables: plumbing (migrations, sessions, caches, queues, Banimark's own) left out. */
async function banimarkTables(db) {
  const plumbing = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_resets',
    'password_reset_tokens', 'personal_access_tokens', 'doctrine_migration_versions', 'migration_versions', 'phinxlog', 'ci_sessions'];
  const out = [];
  for (const t of await db.tables()) {
    const l = t.toLowerCase();
    if (l.startsWith('banimark_') || l.startsWith('hq_')) {
      continue;
    }
    if (plumbing.includes(l)) {
      continue;
    }
    if (/^(telescope_|pulse_|horizon_|oauth_)/.test(l) || /(^|_)(usermeta|options|sessions|transients)$/.test(l)) {
      continue;
    }
    out.push(t);
  }
  return out;
}

async function banimarkColumns(db, table) {
  if (!/^[A-Za-z0-9_]+$/.test(table)) {
    return [];
  }
  return (await db.columns(table)).filter((c) => !banimarkSensitive(c.name));
}

async function banimarkQuery(db, sql, bindings, max) {
  const rows = await db.run(sql, bindings);
  const out = [];
  for (const row of rows) {
    const clean = {};
    for (const k of Object.keys(row)) {
      if (!banimarkSensitive(k)) {
        clean[k] = row[k];
      }
    }
    out.push(clean);
    if (out.length >= max) {
      break;
    }
  }
  return out;
}

// ---- the endpoint -----------------------------------------------------------

/** Raw Node http handler: GET -> status page, POST -> the contract. `rawBody` is optional (Express middleware passes it). */
async function serve(req, res, rawBody) {
  const method = String(req.method || 'GET').toUpperCase();
  if (method === 'GET' || method === 'HEAD') {
    return statusPage(req, res);
  }
  if (method !== 'POST') {
    return sendJson(res, 405, { ok: false, error: 'POST only' });
  }
  let body;
  try {
    body = rawBody !== undefined ? rawBody : await readBody(req);
  } catch (e) {
    return sendJson(res, 400, { ok: false, error: 'could not read the body' });
  }
  const auth = String(req.headers.authorization || '');
  if (!/^bearer /i.test(auth)) {
    return sendJson(res, 401, { ok: false, error: 'no token' });
  }
  const check = banimarkVerify(BANIMARK_SECRET, auth.slice(7).trim(), body, Math.floor(Date.now() / 1000));
  if (!check.ok) {
    return sendJson(res, 401, { ok: false, error: check.error });
  }
  let data = null;
  try {
    data = JSON.parse(body.toString('utf8'));
  } catch (e) {
    data = null;
  }
  if (!data || typeof data !== 'object' || Array.isArray(data) || data.action !== check.action) {
    return sendJson(res, 400, { ok: false, error: 'the body is not JSON, or its action does not match the token' });
  }
  let db;
  try {
    db = await banimarkDb();
  } catch (e) {
    return sendJson(res, 500, { ok: false, error: 'database connection failed: ' + scrub(e && e.message) });
  }
  try {
    switch (data.action) {
      case 'health':
        return sendJson(res, 200, { ok: true, connector: 'banimark', version: BANIMARK_CONNECTOR_VERSION, platform: 'node',
          dialect: db.dialect, tables: (await banimarkTables(db)).length });
      case 'schema': {
        const out = {};
        for (const t of await banimarkTables(db)) {
          out[t] = await banimarkColumns(db, t);
        }
        return sendJson(res, 200, { ok: true, tables: out });
      }
      case 'query': {
        const sql = data.sql === undefined || data.sql === null ? '' : String(data.sql);
        const why = banimarkGuard(sql);
        if (why !== null) {
          return sendJson(res, 422, { ok: false, error: 'refused: ' + why });
        }
        let max = parseInt(data.max_rows, 10);
        if (!Number.isFinite(max)) {
          max = 10;
        }
        max = Math.max(1, Math.min(BANIMARK_MAX_ROWS, max));
        const bindings = data.bindings && typeof data.bindings === 'object' && !Array.isArray(data.bindings) ? data.bindings : {};
        let rows;
        try {
          rows = await banimarkQuery(db, sql, bindings, max);
        } catch (e) {
          return sendJson(res, 500, { ok: false, error: 'the database rejected the query: ' + scrub(e && e.message) });
        }
        return sendJson(res, 200, { ok: true, rows, count: rows.length });
      }
      default:
        return sendJson(res, 400, { ok: false, error: 'unknown action' });
    }
  } catch (e) {
    return sendJson(res, 500, { ok: false, error: 'the database failed: ' + scrub(e && e.message) });
  }
}

function handler(req, res) {
  serve(req, res).catch((e) => {
    try {
      sendJson(res, 500, { ok: false, error: scrub(e && e.message) });
    } catch (ignored) {
      // the response was already sent
    }
  });
}

/**
 * Express middleware. Works whether or not a body parser ran first, as long
 * as what it left is the raw bytes: a Buffer (express.raw) or a string
 * (express.text). If express.json() already parsed the body there are no raw
 * bytes left to verify the signature against - mount this route before it.
 */
function expressMiddleware() {
  return function banimarkConnector(req, res) {
    let raw;
    if (Buffer.isBuffer(req.body)) {
      raw = req.body;
    } else if (typeof req.body === 'string') {
      raw = Buffer.from(req.body, 'utf8');
    }
    if (raw === undefined && req.body !== undefined && req.body !== null && typeof req.body === 'object' && req.method === 'POST') {
      return sendJson(res, 400, { ok: false, error: 'the body was already parsed by another middleware - mount the Banimark connector before express.json(), or with express.raw({ type: "*/*" })' });
    }
    serve(req, res, raw).catch((e) => {
      try {
        sendJson(res, 500, { ok: false, error: scrub(e && e.message) });
      } catch (ignored) {
        // the response was already sent
      }
    });
  };
}

// ---- plumbing ---------------------------------------------------------------

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > 1048576) {
        reject(new Error('body too large'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function sendJson(res, status, data) {
  const text = JSON.stringify(data);
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(text);
}

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

/** A database error without anything that looks like a credential in it. */
function scrub(text) {
  return String(text == null ? '' : text)
    .replace(/(password|pwd|pass)=\S+/gi, '$1=***')
    .replace(/:\/\/[^\s@/]+@/g, '://***@');
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function statusPage(req, res) {
  const forwarded = String(req.headers['x-forwarded-proto'] || '').split(',')[0].trim();
  const scheme = forwarded === 'https' || (req.socket && req.socket.encrypted) ? 'https' : 'http';
  const host = String(req.headers.host || 'localhost');
  const path = String(req.originalUrl || req.url || '/').split('?')[0] || '/';
  const address = scheme + '://' + host + path;
  let db = '';
  let ok = false;
  try {
    const conn = await banimarkDb();
    const n = (await banimarkTables(conn)).length;
    db = 'Connected to the database (' + conn.dialect + ', ' + n + ' table' + (n === 1 ? '' : 's') + ').';
    ok = true;
  } catch (e) {
    db = 'The database is not reachable yet: ' + scrub(e && e.message);
  }
  res.statusCode = 200;
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banimark connector</title>'
    + '<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#1b1f24}code{background:#f1f3f5;padding:3px 7px;border-radius:6px;font-size:14px;word-break:break-all}.ok{color:#0a7d3e}.bad{color:#b42318}</style></head><body>'
    + '<h1>Banimark connector is installed</h1>'
    + '<p>For the desk <b>' + escapeHtml('__BANIMARK_DESK__') + '</b>. Copy this address into Banimark &rarr; Tools &rarr; "Where your data lives":</p>'
    + '<p><code>' + escapeHtml(address) + '</code></p>'
    + '<p class="' + (ok ? 'ok' : 'bad') + '">' + escapeHtml(db) + '</p>'
    + '<p style="color:#666;font-size:13px">This page shows no data. The assistant&#039;s lookups are signed by your desk, read-only, and limited to the columns you tick in Banimark.</p>'
    + '</body></html>');
}

module.exports = { handler, express: expressMiddleware, verify: banimarkVerify, guard: banimarkGuard, sensitive: banimarkSensitive };

// `node banimark-connector.js` serves the connector at every path on PORT (default 8787).
if (require.main === module) {
  const port = parseInt(process.env.PORT, 10) || 8787;
  http.createServer(handler).listen(port, () => {
    console.log('Banimark connector listening on http://127.0.0.1:' + port + '/banimark-connector');
  });
}
