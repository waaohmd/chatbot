import fs from "node:fs/promises";
import path from "node:path";
import crypto from "node:crypto";

export const dynamic = "force-dynamic";

// Keep the target configurable so a local admin can be used for each
// WordPress deployment without rebuilding the application.
const DEFAULT_WEBSITE_ORIGIN = String(
  process.env.MDH_WEBSITE_ORIGIN || "https://mustdohr.com/wp-json/mustdohr-search/v1"
).replace(/\/$/, "");
const DATA_DIR = path.resolve(process.env.MDH_LOCAL_DATA_DIR || path.join(process.cwd(), "data"));
const ARCHIVE_FILES = {
  records: path.join(DATA_DIR, "chat-records.json"),
  contacts: path.join(DATA_DIR, "contact-submissions.json"),
};

const state = globalThis.__mustdohrLocalAdminState || (globalThis.__mustdohrLocalAdminState = {
  sessions: new Map(),
  error: "",
  lastSync: null,
});

// Keep the process-wide state compatible with hot reloads and older local
// sessions. A previous module instance may have left an incomplete object on
// globalThis; always restore the Map before any request touches it.
if (!state.sessions || typeof state.sessions.get !== "function" || typeof state.sessions.set !== "function" || typeof state.sessions.delete !== "function") {
  state.sessions = new Map();
}
if (typeof state.error !== "string") state.error = "";
if (!("lastSync" in state)) state.lastSync = null;

const SESSION_COOKIE = "mdh_records_session";
const SESSION_TTL_MS = 15 * 60 * 1000;

function noStore(body, status = 200, extraHeaders = {}) {
  return Response.json(body, { status, headers: { "Cache-Control": "no-store", ...extraHeaders } });
}

function cookieValue(request, name) {
  const match = String(request?.headers.get("cookie") || "").match(new RegExp(`(?:^|;\\s*)${name}=([^;]+)`));
  if (!match) return "";
  try { return decodeURIComponent(match[1]); } catch { return ""; }
}

function sessionFor(request) {
  const token = cookieValue(request, SESSION_COOKIE);
  if (!token) return null;
  const session = state.sessions.get(token);
  if (!session || session.expiresAt <= Date.now()) {
    state.sessions.delete(token);
    return null;
  }
  return session;
}

function requireSession(request) {
  const session = sessionFor(request);
  if (!session) throw new Error("The local admin session has expired. Connect again.");
  return session;
}

function websiteHeaders(request, scope = "read") {
  const session = requireSession(request);
  const key = session.keys?.connection;
  if (!key) throw new Error("The connected session does not include a private connection code.");
  return {
    "X-Mustdohr-Records-Key": key,
    "Cache-Control": "no-cache, no-store, max-age=0",
  };
}

function normalizeWebsiteOrigin(value) {
  const raw = String(value || DEFAULT_WEBSITE_ORIGIN).trim().replace(/\/$/, "");
  const url = new URL(raw);
  if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Website API URL must use HTTP or HTTPS.');
  return raw;
}

function websiteOriginFor(request) {
  const session = requireSession(request);
  return normalizeWebsiteOrigin(session.websiteOrigin || DEFAULT_WEBSITE_ORIGIN);
}

function restRouteUrl(endpoint, origin) {
  const [route, query = ""] = String(endpoint).split("?");
  const api = new URL(origin);
  const url = new URL(`${api.protocol}//${api.host}/`);
  url.searchParams.set("rest_route", `/mustdohr-search/v1/${route}`);
  for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
  url.searchParams.set("_", String(Date.now()));
  return url;
}

async function websiteRequest(endpoint, options = {}, request, scope = "read") {
  const headers = {
    ...websiteHeaders(request, scope),
    "Cache-Control": "no-cache, no-store, max-age=0",
    Pragma: "no-cache",
    ...(options.headers || {}),
  };
  const requestOptions = { cache: "no-store", ...options, headers };
  const origin = websiteOriginFor(request);
  let url = new URL(`${origin}/${endpoint}`);
  url.searchParams.set("_", String(Date.now()));
  let response = await fetch(url, requestOptions);
  if (response.status === 404) response = await fetch(restRouteUrl(endpoint, origin), requestOptions);
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || `WordPress request failed (${response.status}).`);
  return payload;
}

async function ensureLocalData() {
  await fs.mkdir(DATA_DIR, { recursive: true });
  await fs.mkdir(path.join(DATA_DIR, "backups"), { recursive: true });
}

async function readArchive(kind) {
  await ensureLocalData();
  try {
    const parsed = JSON.parse(await fs.readFile(ARCHIVE_FILES[kind], "utf8"));
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    if (error.code === "ENOENT") return [];
    throw error;
  }
}

function archiveKey(row) {
  const website = String(row?.website || row?.source_website || "mustdohr.com").trim().toLowerCase();
  const id = row?.id !== undefined && row?.id !== null ? String(row.id).trim() : "";
  const createdAt = String(row?.created_at || "").trim();
  const sessionId = String(row?.session_id || "").trim();
  const message = String(row?.visitor_message || row?.message || "").trim();

  // WordPress and the former server archive can reuse numeric IDs after a
  // reset. Include the immutable timestamp/content fingerprint so records
  // from both sources are retained instead of one silently replacing another.
  if (id) return `${website}:${id}:${createdAt}:${sessionId}:${message}`;
  return JSON.stringify([website, createdAt, sessionId, message, row?.email || ""]);
}

function mergeRows(existing, incoming) {
  const byKey = new Map();
  [...existing, ...incoming].forEach(row => {
    const key = archiveKey(row);
    byKey.set(key, { ...(byKey.get(key) || {}), ...row });
  });
  return [...byKey.values()].sort((a, b) => {
    const aTime = Date.parse(String(a?.created_at || ""));
    const bTime = Date.parse(String(b?.created_at || ""));
    const byTime = (Number.isNaN(bTime) ? 0 : bTime) - (Number.isNaN(aTime) ? 0 : aTime);
    if (byTime !== 0) return byTime;
    return Number(b?.id || 0) - Number(a?.id || 0);
  });
}

async function writeArchive(kind, rows) {
  await ensureLocalData();
  const serialized = JSON.stringify(rows, null, 2) + "\n";
  const tempPath = `${ARCHIVE_FILES[kind]}.tmp`;
  await fs.writeFile(tempPath, serialized, "utf8");
  await fs.rename(tempPath, ARCHIVE_FILES[kind]);
  return rows;
}

async function backupArchive(kind, rows) {
  if (!rows.length) return null;
  await ensureLocalData();
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const target = path.join(DATA_DIR, "backups", `${kind}-${stamp}.json`);
  await fs.writeFile(target, JSON.stringify(rows, null, 2) + "\n", "utf8");
  return target;
}

function csvValue(value) {
  return `"${String(value ?? "").replaceAll('"', '""')}"`;
}

const recordColumns = ["id", "created_at", "website", "page_url", "visitor_message", "bot_reply", "sensitive_blocked", "question_limit_reached", "contact_submitted", "contact_trigger", "mode", "status", "session_id"];
const contactColumns = ["id", "created_at", "source_website", "name", "company", "email", "country", "request_type", "message", "trigger_reason", "page_url", "session_id", "chat_record_id", "chat_record_count", "chat_question", "chat_transcript"];

function rowsCsv(rows, columns) {
  return [columns.join(","), ...rows.map(row => columns.map(key => csvValue(row[key])).join(","))].join("\n");
}

const CSV_IMPORT_LIMIT_BYTES = 5 * 1024 * 1024;
const CSV_IMPORT_LIMIT_ROWS = 5000;

function csvHeader(value) {
  return String(value || "").replace(/^\uFEFF/, "").trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "");
}

function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = "";
  let quoted = false;
  for (let index = 0; index < text.length; index += 1) {
    const character = text[index];
    if (character === '"') {
      if (quoted && text[index + 1] === '"') {
        field += '"';
        index += 1;
      } else {
        quoted = !quoted;
      }
    } else if (character === "," && !quoted) {
      row.push(field);
      field = "";
    } else if ((character === "\n" || character === "\r") && !quoted) {
      if (character === "\r" && text[index + 1] === "\n") index += 1;
      row.push(field);
      if (row.some(value => String(value).trim() !== "")) rows.push(row);
      row = [];
      field = "";
    } else {
      field += character;
    }
  }
  if (quoted) throw new Error("The CSV contains an unclosed quoted field.");
  if (field !== "" || row.length) {
    row.push(field);
    if (row.some(value => String(value).trim() !== "")) rows.push(row);
  }
  if (rows.length < 2) throw new Error("The CSV must include a header row and at least one data row.");
  const headers = rows.shift().map(csvHeader);
  if (!headers.some(Boolean)) throw new Error("The CSV header row is empty.");
  return rows.map(values => Object.fromEntries(headers.map((header, index) => [header, String(values[index] ?? "")])))
    .filter(row => Object.values(row).some(value => value.trim() !== ""));
}

function csvField(row, names, limit = 50000) {
  const value = names.map(name => row[csvHeader(name)]).find(item => item !== undefined && String(item).trim() !== "");
  return String(value ?? "").trim().slice(0, limit);
}

function csvBoolean(value) {
  return /^(1|true|yes|y|on)$/i.test(String(value || "").trim()) ? 1 : 0;
}

function normalizeImportedRow(kind, row, index) {
  const createdAt = csvField(row, ["created_at", "created", "time", "timestamp"], 80) || new Date().toISOString();
  const id = csvField(row, ["id", "record_id", "submission_id"], 80) || `import-${Date.now()}-${index}`;
  if (kind === "records") {
    const question = csvField(row, ["visitor_message", "question", "message"]);
    if (!question) return null;
    return {
      id,
      created_at: createdAt,
      website: csvField(row, ["website", "source_website"], 190) || "mustdohr.com",
      page_url: csvField(row, ["page_url", "page", "url"], 500),
      visitor_message: question,
      bot_reply: csvField(row, ["bot_reply", "answer", "assistant_reply"]),
      sensitive_blocked: csvBoolean(csvField(row, ["sensitive_blocked", "sensitive"])),
      question_limit_reached: csvBoolean(csvField(row, ["question_limit_reached", "limit_reached"])),
      contact_submitted: csvBoolean(csvField(row, ["contact_submitted", "contact"])),
      contact_trigger: csvField(row, ["contact_trigger", "trigger_reason"], 500),
      mode: csvField(row, ["mode"], 40) || "ai",
      status: csvField(row, ["status"], 80) || "answered",
      session_id: csvField(row, ["session_id", "session"], 190),
      language: csvField(row, ["language", "lang"], 20) || "en",
    };
  }
  const message = csvField(row, ["message", "request", "notes"]);
  const name = csvField(row, ["name", "customer_name", "client_name"], 160);
  const email = csvField(row, ["email", "email_address"], 190);
  if (!name && !email && !message) return null;
  return {
    id,
    created_at: createdAt,
    source_website: csvField(row, ["source_website", "website", "source"], 190) || "mustdohr.com",
    name,
    company: csvField(row, ["company", "company_name"], 190),
    email,
    country: csvField(row, ["country", "region", "location"], 120),
    request_type: csvField(row, ["request_type", "category", "type"], 120) || "General enquiry",
    message,
    trigger_reason: csvField(row, ["trigger_reason", "contact_trigger"], 500),
    page_url: csvField(row, ["page_url", "page", "url"], 500),
    session_id: csvField(row, ["session_id", "session"], 190),
    chat_record_id: csvField(row, ["chat_record_id", "record_id"], 80),
    chat_record_count: csvField(row, ["chat_record_count"], 20),
    chat_question: csvField(row, ["chat_question", "question"], 50000),
    chat_transcript: csvField(row, ["chat_transcript", "transcript"], 50000),
  };
}

async function importCsv(formData) {
  const sessionFile = formData.get("file");
  const kind = String(formData.get("kind") || "records");
  if (!sessionFile || typeof sessionFile.text !== "function") throw new Error("Choose a CSV file first.");
  if (!ARCHIVE_FILES[kind]) throw new Error("Choose chat records or Contact submissions.");
  if (Number(sessionFile.size || 0) > CSV_IMPORT_LIMIT_BYTES) throw new Error("CSV files are limited to 5 MB.");
  const rows = parseCsv(await sessionFile.text());
  if (rows.length > CSV_IMPORT_LIMIT_ROWS) throw new Error("CSV imports are limited to 5,000 rows.");
  const incoming = rows.map((row, index) => normalizeImportedRow(kind, row, index)).filter(Boolean);
  if (!incoming.length) throw new Error("No usable rows were found in this CSV.");
  const existing = await readArchive(kind);
  const backup = await backupArchive(kind, existing);
  const merged = mergeRows(existing, incoming);
  await writeArchive(kind, merged);
  return { kind, imported: incoming.length, total: merged.length, backup, dataDir: DATA_DIR, rows: merged };
}

async function syncKind(kind, request) {
  const endpoint = kind === "records" ? "records?limit=500" : "contact-submissions?limit=500";
  const payload = await websiteRequest(endpoint, {}, request, "read");
  const incoming = kind === "records" ? (payload.records || []) : (payload.submissions || []);
  if (!Array.isArray(incoming)) throw new Error(`WordPress returned invalid ${kind} data.`);
  const existing = await readArchive(kind);
  const merged = mergeRows(existing, incoming);
  await writeArchive(kind, merged);
  const backup = await backupArchive(kind, incoming);

  // Delete only the exact IDs just written locally. New rows created during
  // this sync remain in WordPress and will be picked up on the next sync.
  const ids = incoming.map(row => Number(row.id)).filter(Number.isInteger).filter(id => id > 0);
  let deleted = 0;
  if (ids.length) {
    const deleteEndpoint = kind === "records" ? "records/delete" : "contact-submissions/delete";
    const deletedPayload = await websiteRequest(deleteEndpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids }),
    }, request, "delete");
    deleted = Number(deletedPayload.deleted || 0);
  }
  return { kind, fetched: incoming.length, archived: merged.length, deleted, backup };
}

async function syncWebsite(request) {
  const results = [];
  const errors = [];
  for (const kind of ["records", "contacts"]) {
    try { results.push(await syncKind(kind, request)); }
    catch (error) { errors.push(`${kind}: ${error.message || "sync failed"}`); }
  }
  state.lastSync = new Date().toISOString();
  state.error = errors.join(" | ");
  return { results, errors, syncedAt: state.lastSync, dataDir: DATA_DIR };
}

async function websiteCsv(kind, request) {
  requireSession(request);
  const rows = await readArchive(kind);
  return rowsCsv(rows, kind === "records" ? recordColumns : contactColumns);
}

export async function GET(request) {
  const query = new URL(request.url).searchParams;
  const pathName = query.get("path") || "";
  try {
    if (pathName === "") {
      const connectedSession = sessionFor(request);
      return noStore({
        status: connectedSession ? "connected" : "disconnected",
        websiteConnected: Boolean(connectedSession),
        websiteOrigin: connectedSession?.websiteOrigin || DEFAULT_WEBSITE_ORIGIN,
        localUrl: "local filesystem only",
        dataDir: DATA_DIR,
        lastSync: state.lastSync,
        error: state.error || null,
      });
    }
    if (pathName === "website-records") {
      const sync = await syncWebsite(request);
      return noStore({ records: await readArchive("records"), sync });
    }
    if (pathName === "website-contacts") {
      const sync = await syncWebsite(request);
      return noStore({ submissions: await readArchive("contacts"), sync });
    }
    if (pathName === "website-config") return noStore({ config: (await websiteRequest("config", {}, request, "config")).config || {} });
    if (pathName === "website-records.csv") return new Response(await websiteCsv("records", request), { headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=mustdohr-local-chat-records.csv" } });
    if (pathName === "website-contacts.csv") return new Response(await websiteCsv("contacts", request), { headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=mustdohr-local-contact-submissions.csv" } });
    if (pathName === "local-records") { requireSession(request); return noStore({ records: await readArchive("records"), dataDir: DATA_DIR }); }
    if (pathName === "local-contacts") { requireSession(request); return noStore({ submissions: await readArchive("contacts"), dataDir: DATA_DIR }); }
    requireSession(request);
    return noStore({ error: "Not found." }, 404);
  } catch (error) {
    return noStore({ error: error.message || "Could not load local records." }, 503);
  }
}

export async function POST(request) {
  let pendingSessionToken = "";
  try {
    const contentType = String(request.headers.get("content-type") || "").toLowerCase();
    if (contentType.includes("multipart/form-data")) {
      const formData = await request.formData();
      if (String(formData.get("action") || "") !== "importCsv") return noStore({ error: "Unsupported upload action." }, 400);
      requireSession(request);
      return noStore({ ok: true, ...(await importCsv(formData)) });
    }
    const body = await request.json();
    if (body.action === "connectWebsite") {
      const connectionKey = String(body.recordsConnectionKey || "").trim();
      if (!connectionKey) return noStore({ error: "Enter the private WordPress connection code." }, 400);
      let websiteOrigin;
      try { websiteOrigin = normalizeWebsiteOrigin(body.websiteOrigin || DEFAULT_WEBSITE_ORIGIN); }
      catch (error) { return noStore({ error: error.message }, 400); }
      const sessionToken = crypto.randomBytes(32).toString("base64url");
      pendingSessionToken = sessionToken;
      state.sessions.set(sessionToken, { keys: { connection: connectionKey }, websiteOrigin, expiresAt: Date.now() + SESSION_TTL_MS });
      const connectedRequest = new Request(request.url, { headers: { cookie: `${SESSION_COOKIE}=${encodeURIComponent(sessionToken)}` } });
      const sync = await syncWebsite(connectedRequest);
      await websiteRequest("records/export?limit=1", {}, connectedRequest, "export");
      await websiteRequest("config", {}, connectedRequest, "config");
      if (sync.errors.length && !sync.results.length) {
        state.sessions.delete(sessionToken);
        throw new Error(sync.errors.join(" | "));
      }
      return noStore({ ok: true, status: "connected", websiteConnected: true, websiteOrigin, sync, dataDir: DATA_DIR }, 200, {
        "Set-Cookie": `${SESSION_COOKIE}=${encodeURIComponent(sessionToken)}; Path=/; HttpOnly; SameSite=Strict; Max-Age=${SESSION_TTL_MS / 1000}`,
      });
    }
    if (body.action === "syncWebsite") {
      const sync = await syncWebsite(request);
      return noStore({ ok: sync.errors.length === 0, sync, dataDir: DATA_DIR }, sync.results.length ? 200 : 503);
    }
    if (body.action === "disconnectWebsite") {
      const token = cookieValue(request, SESSION_COOKIE);
      if (token) state.sessions.delete(token);
      state.error = "";
      return noStore({ ok: true }, 200, { "Set-Cookie": `${SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0` });
    }
    if (body.action === "saveWebsiteConfig") {
      const payload = await websiteRequest("config", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body.config || {}) }, request, "config");
      return noStore({ ok: true, config: payload.config || {} });
    }
    return noStore({ error: "Unsupported action." }, 400);
  } catch (error) {
    state.error = error.message || "Unable to sync local records.";
    if (pendingSessionToken) state.sessions.delete(pendingSessionToken);
    return noStore({ error: state.error }, 503);
  }
}
