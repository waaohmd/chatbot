export const dynamic = "force-dynamic";

const WEBSITE_ORIGIN = "https://mustdohr.com/wp-json/mustdohr-search/v1";
const SERVER = {
  host: "158.69.253.60",
  port: 222,
  fingerprint: "7798929ac9550fbba06728d4d0562a3506dbef627d3671f19d07f844a115b0b0",
};

const state = globalThis.__mustdohrLocalAdminState || (globalThis.__mustdohrLocalAdminState = {
  recordsKey: "",
  ssh: null,
  username: "",
  error: "",
});

function noStore(body, status = 200, extraHeaders = {}) {
  return Response.json(body, { status, headers: { "Cache-Control": "no-store", ...extraHeaders } });
}

function cookieValue(request, name) {
  const match = String(request?.headers.get("cookie") || "").match(new RegExp(`(?:^|;\\s*)${name}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : "";
}

function websiteHeaders(request) {
  // The local dev server can distribute browser requests between workers.
  // Accept the tab-only key on every request so records do not disappear when
  // a different worker handles the next refresh.
  const recordsKey = request?.headers.get("x-mustdohr-records-key") || cookieValue(request, "mdh_records_key") || state.recordsKey || "";
  if (!recordsKey) throw new Error("Enter the private records key from WordPress first.");
  return { "X-Mustdohr-Records-Key": recordsKey, "Cache-Control": "no-cache, no-store, max-age=0" };
}

async function websiteRequest(path, options = {}, request) {
  // SiteGround can cache WordPress REST responses even when the local caller
  // uses fetch({ cache: "no-store" }). Give every upstream read a distinct URL
  // so the private admin always receives the current database rows.
  const url = new URL(`${WEBSITE_ORIGIN}/${path}`);
  url.searchParams.set("_", String(Date.now()));
  const response = await fetch(url, {
    cache: "no-store",
    ...options,
    headers: {
      ...websiteHeaders(request),
      "Cache-Control": "no-cache, no-store, max-age=0",
      Pragma: "no-cache",
      ...(options.headers || {}),
    },
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || "The website did not accept the records key.");
  return payload;
}

function runSsh(command) {
  return new Promise((resolve, reject) => {
    if (!state.ssh) return reject(new Error("Connect to the server first."));
    state.ssh.exec(command, (error, stream) => {
      if (error) return reject(error);
      let output = "";
      let errors = "";
      stream.on("data", chunk => { output += chunk; });
      stream.stderr.on("data", chunk => { errors += chunk; });
      stream.on("close", code => code === 0 ? resolve(output) : reject(new Error(errors || output || `Remote command failed (${code}).`)));
    });
  });
}

async function connectSsh(username, password) {
  const { createRequire } = await import("node:module");
  const Client = createRequire(import.meta.url)("ssh2").Client;
  if (!Client) throw new Error("The SSH client is unavailable in this local runtime.");
  return new Promise((resolve, reject) => {
    const client = new Client();
    client.once("ready", () => resolve(client));
    client.once("error", reject);
    client.connect({
      host: SERVER.host,
      port: SERVER.port,
      username,
      password,
      readyTimeout: 15000,
      hostHash: "sha256",
      hostVerifier: hash => hash === SERVER.fingerprint,
      algorithms: { serverHostKey: ["ecdsa-sha2-nistp256"] },
    });
  });
}

async function serverRecords() {
  const output = await runSsh("curl -fsS --max-time 8 http://127.0.0.1:3030/api/chats");
  const records = JSON.parse(output);
  if (!Array.isArray(records)) throw new Error("The server returned an invalid records response.");
  return records;
}

function csvValue(value) {
  return `"${String(value ?? "").replaceAll('"', '""')}"`;
}

async function websiteCsv(kind, request) {
  const payload = await websiteRequest(kind === "records" ? "records?limit=500" : "contact-submissions?limit=500", {}, request);
  const rows = kind === "records" ? payload.records || [] : payload.submissions || [];
  const columns = kind === "records"
    ? ["id", "created_at", "website", "page_url", "visitor_message", "bot_reply", "sensitive_blocked", "question_limit_reached", "contact_submitted", "contact_trigger", "mode", "status"]
    : ["id", "created_at", "source_website", "name", "company", "email", "country", "request_type", "message", "trigger_reason", "page_url", "session_id", "chat_record_id", "chat_record_count", "chat_question", "chat_transcript"];
  return [columns.join(","), ...rows.map(row => columns.map(key => csvValue(row[key])).join(","))].join("\n");
}

export async function GET(request) {
  const path = new URL(request.url).searchParams.get("path") || "";
  try {
    if (path === "") {
      const requestKey = request.headers.get("x-mustdohr-records-key") || cookieValue(request, "mdh_records_key");
      return noStore({
        status: state.ssh ? "connected" : "disconnected",
        username: state.username || null,
        websiteConnected: Boolean(requestKey || state.recordsKey),
        localUrl: "server connection through localhost",
        error: state.error || null,
      });
    }
    if (path === "website-records") return noStore({ records: (await websiteRequest("records?limit=500", {}, request)).records || [] });
    if (path === "website-contacts") return noStore({ submissions: (await websiteRequest("contact-submissions?limit=500", {}, request)).submissions || [] });
    if (path === "website-config") return noStore({ config: (await websiteRequest("config", {}, request)).config || {} });
    if (path === "server-records") return noStore({ records: await serverRecords() });
    if (path === "website-records.csv") return new Response(await websiteCsv("records", request), { headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=mustdohr-live-chat-records.csv" } });
    if (path === "website-contacts.csv") return new Response(await websiteCsv("contacts", request), { headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=mustdohr-contact-submissions.csv" } });
    if (path === "server-records.csv") {
      const records = await serverRecords();
      const columns = ["id", "website", "session_id", "visitor_message", "bot_reply", "page_url", "created_at"];
      const csv = [columns.join(","), ...records.map(row => columns.map(key => csvValue(row[key])).join(","))].join("\n");
      return new Response(csv, { headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=mustdohr-server-chat-records.csv" } });
    }
    return noStore({ error: "Not found." }, 404);
  } catch (error) {
    return noStore({ error: error.message || "Could not load records." }, 503);
  }
}

export async function POST(request) {
  try {
    const body = await request.json();
    if (body.action === "connectWebsite") {
      state.recordsKey = String(body.recordsKey || "").trim();
      await websiteRequest("records?limit=1", {}, request);
      return noStore(
        { status: state.ssh ? "connected" : "disconnected", websiteConnected: true },
        200,
        { "Set-Cookie": `mdh_records_key=${encodeURIComponent(state.recordsKey)}; Path=/; HttpOnly; SameSite=Strict` }
      );
    }
    if (body.action === "disconnectWebsite") {
      state.recordsKey = "";
      return noStore({ ok: true }, 200, { "Set-Cookie": "mdh_records_key=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0" });
    }
    if (body.action === "saveWebsiteConfig") {
      const payload = await websiteRequest("config", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body.config || {}) }, request);
      return noStore({ ok: true, config: payload.config || {} });
    }
    if (body.action === "disconnect") {
      state.ssh?.end();
      state.ssh = null;
      state.username = "";
      return noStore({ ok: true });
    }
    if (body.action === "connect") {
      const username = String(body.username || "").trim();
      const password = String(body.password || "");
      if (!username || !password) return noStore({ error: "Enter an SSH username and password." }, 400);
      state.ssh?.end();
      state.ssh = await connectSsh(username, password);
      state.username = username;
      state.error = "";
      state.ssh.on("close", () => { state.ssh = null; state.username = ""; });
      return noStore({ status: "connected", username, websiteConnected: Boolean(state.recordsKey) });
    }
    return noStore({ error: "Unsupported action." }, 400);
  } catch (error) {
    state.error = error.message || "Unable to connect.";
    return noStore({ error: state.error }, 503);
  }
}
