"use client";

import { useEffect, useMemo, useState } from "react";
import "./local-admin.css";
import "./assistant-settings.css";

const CONNECTOR_URL = "/api/local-connector";
const DEFAULT_WEBSITE_ORIGIN = "https://gongzige.com/wp-json/mustdohr-search/v1";
const DEFAULT_CONFIG = {
  enabled: true,
  brand_name: "Mustdohr search",
  welcome_message: "Search the public Mustdohr website and open the closest pages.",
  ai_intro: "Ask AI to summarize public Mustdohr content. AI answers may be incomplete.",
  faqs: [], question_limit: 0, sensitive_keywords: "",
  sensitive_reply: "I cannot help with that request. For information about Mustdohr services, please use our contact form.",
  contact_mode: "embedded", contact_url: "", notification_emails: "", knowledge_urls: "", excluded_urls: "",
  source_website: "Mustdohr", contact_trigger_keywords: "", contact_trigger_reply: "",
  no_answer_reply: "", limit_reply: "", show_contact_for: ["contact", "unanswered", "limit", "sensitive"],
};

function mergeConfig(value = {}) {
  const merged = { ...DEFAULT_CONFIG, ...(value || {}) };
  if (!String(merged.notification_emails || "").trim()) merged.notification_emails = DEFAULT_CONFIG.notification_emails;
  return merged;
}

function formatDate(value) {
  if (!value) return "Not available";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function timestampValue(value) {
  const parsed = Date.parse(String(value || ""));
  return Number.isNaN(parsed) ? 0 : parsed;
}

function newestFirst(a, b) {
  const byTime = timestampValue(b?.created_at) - timestampValue(a?.created_at);
  if (byTime !== 0) return byTime;
  return Number(b?.id || 0) - Number(a?.id || 0);
}

function formatChatTranscript(value) {
  const raw = String(value || "").trim();
  if (!raw) return "No linked transcript";

  // Normalize the stored JSON transcript into a compact User / AI exchange.
  try {
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      const lines = [];
      parsed.forEach((item) => {
        if (typeof item === "string") {
          if (item.trim()) lines.push(item.trim());
          return;
        }
        const user = item?.question ?? item?.user ?? item?.message;
        const ai = item?.answer ?? item?.ai ?? item?.response;
        if (String(user || "").trim()) lines.push(`User: ${String(user).trim()}`);
        if (String(ai || "").trim()) lines.push(`AI: ${String(ai).trim()}`);
      });
      if (lines.length) return lines.join("\n");
    }
  } catch {
    // Legacy transcripts are plain text and are normalized below.
  }

  return raw
    .replace(/^\s*\[[^\]]+\]\s*(?:Visitor|User)\s*:/gim, "User:")
    .replace(/^\s*\[[^\]]+\]\s*(?:Assistant|AI)\s*:/gim, "AI:")
    .replace(/^\s*Visitor\s*:/gim, "User:")
    .replace(/^\s*Assistant\s*:/gim, "AI:")
    .trim();
}

function endpoint(path = "", refresh = false) {
  const params = new URLSearchParams();
  if (path) params.set("path", path);
  if (refresh) params.set("_", String(Date.now()));
  const query = params.toString();
  return query ? `${CONNECTOR_URL}?${query}` : CONNECTOR_URL;
}

function textValue(value) { return String(value || "").toLowerCase().replace(/\s+/g, " ").trim(); }
function contactCategory(contact) { return String(contact?.request_type || contact?.trigger_reason || "General enquiry").trim() || "General enquiry"; }
function recordCategory(record) {
  if (record?.sensitive_blocked) return "Sensitive question";
  if (record?.question_limit_reached) return "Question limit";
  if (record?.contact_trigger) return String(record.contact_trigger).trim();
  if (/unanswered|no answer|could not confirm/i.test(`${record?.status || ""} ${record?.bot_reply || ""}`)) return "Unanswered";
  return record?.mode === "ai" ? "AI answer" : "Public content search";
}
function clientLabel(contact) { return String(contact?.name || contact?.company || contact?.email || contact?.session_id || "Anonymous visitor").trim() || "Anonymous visitor"; }

function analytics(records, contacts) {
  const questions = new Map();
  records.forEach(row => { const text = String(row.visitor_message || "").replace(/\s+/g, " ").trim(); if (text) questions.set(text, (questions.get(text) || 0) + 1); });
  const guidance = records.filter(row => String(row.contact_trigger || "").trim()).length;
  return {
    uses: records.length, questions: records.length, guidance, submissions: contacts.length,
    conversion: guidance ? Math.round((contacts.length / guidance) * 100) : 0,
    unanswered: records.filter(row => /unanswered|no answer|could not confirm/i.test(`${row.status || ""} ${row.bot_reply || ""}`)).length,
    sensitive: records.filter(row => Boolean(row.sensitive_blocked)).length,
    limits: records.filter(row => Boolean(row.question_limit_reached)).length,
    common: [...questions.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5),
  };
}

export default function LocalAdminPage() {
  const [connectionKey, setConnectionKey] = useState("");
  const [websiteOrigin, setWebsiteOrigin] = useState(DEFAULT_WEBSITE_ORIGIN);
  const [websiteConnected, setWebsiteConnected] = useState(false);
  const [status, setStatus] = useState("checking");
  const [message, setMessage] = useState("");
  const [records, setRecords] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [config, setConfig] = useState(DEFAULT_CONFIG);
  const [localDataDir, setLocalDataDir] = useState("local data folder");
  const [clientQuery, setClientQuery] = useState("");
  const [emailQuery, setEmailQuery] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("all");
  const [groupBy, setGroupBy] = useState("client");
  const [loading, setLoading] = useState(false);
  const [importing, setImporting] = useState(false);
  const [importKind, setImportKind] = useState("records");
  const connected = status === "connected";
  const stats = useMemo(() => analytics(records, contacts), [records, contacts]);
  const contactsBySession = useMemo(() => new Map(contacts.filter(item => item.session_id).map(item => [String(item.session_id), item])), [contacts]);
  const categoryOptions = useMemo(() => [...new Set([...contacts.map(contactCategory), ...records.map(recordCategory)])].filter(Boolean).sort((a, b) => a.localeCompare(b)), [records, contacts]);

  const filteredContacts = useMemo(() => {
    const clientNeedle = textValue(clientQuery); const emailNeedle = textValue(emailQuery);
    return contacts.filter(contact => {
      const clientText = textValue(`${contact.name || ""} ${contact.company || ""} ${contact.session_id || ""}`);
      return (!clientNeedle || clientText.includes(clientNeedle)) && (!emailNeedle || textValue(contact.email).includes(emailNeedle)) && (categoryFilter === "all" || contactCategory(contact) === categoryFilter);
    }).sort(newestFirst);
  }, [contacts, clientQuery, emailQuery, categoryFilter]);

  const filteredRecords = useMemo(() => {
    const clientNeedle = textValue(clientQuery); const emailNeedle = textValue(emailQuery);
    return records.filter(record => {
      const linked = contactsBySession.get(String(record.session_id || ""));
      const clientText = textValue(`${linked?.name || ""} ${linked?.company || ""} ${record.session_id || "Anonymous visitor"}`);
      return (!clientNeedle || clientText.includes(clientNeedle)) && (!emailNeedle || textValue(linked?.email).includes(emailNeedle)) && (categoryFilter === "all" || recordCategory(record) === categoryFilter);
    }).sort(newestFirst);
  }, [records, contactsBySession, clientQuery, emailQuery, categoryFilter]);

  const groupedContacts = useMemo(() => {
    const groups = new Map();
    filteredContacts.forEach(contact => {
      const key = groupBy === "email" ? (contact.email || "No email") : groupBy === "category" ? contactCategory(contact) : clientLabel(contact);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(contact);
    });
    return [...groups.entries()];
  }, [filteredContacts, groupBy]);

  const groupedRecords = useMemo(() => {
    const groups = new Map();
    filteredRecords.forEach(record => {
      const linked = contactsBySession.get(String(record.session_id || ""));
      const key = groupBy === "email" ? (linked?.email || "No email") : groupBy === "category" ? recordCategory(record) : clientLabel(linked);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push({ record, linked });
    });
    return [...groups.entries()];
  }, [filteredRecords, contactsBySession, groupBy]);

  async function statusCheck() {
    const response = await fetch(CONNECTOR_URL, { cache: "no-store" });
    const data = await response.json(); setStatus(data.status || "disconnected"); setWebsiteConnected(Boolean(data.websiteConnected)); if (data.websiteOrigin) setWebsiteOrigin(data.websiteOrigin);
    if (data.dataDir) setLocalDataDir(data.dataDir); if (data.error) setMessage(data.error); return data;
  }

  function showSync(sync) {
    if (sync?.dataDir) setLocalDataDir(sync.dataDir);
    if (sync?.results?.length) setMessage(sync.results.map(item => `${item.kind}: ${item.fetched} downloaded, ${item.deleted} cleared from WordPress`).join(" | "));
    if (sync?.errors?.length) setMessage(`Archive warning: ${sync.errors.join(" | ")}`);
  }

  async function loadRecords() {
    setLoading(true);
    try { const response = await fetch(endpoint("website-records", true), { cache: "no-store" }); const data = await response.json(); if (!response.ok) throw new Error(data.error || "Could not sync chat records."); setRecords(data.records || []); showSync(data.sync); }
    catch (error) { setMessage(error.message || "Could not sync chat records."); } finally { setLoading(false); }
  }

  async function loadContacts() {
    try { const response = await fetch(endpoint("website-contacts", true), { cache: "no-store" }); const data = await response.json(); if (!response.ok) throw new Error(data.error || "Could not sync contact submissions."); setContacts(data.submissions || []); showSync(data.sync); }
    catch (error) { setMessage(error.message || "Could not sync contact submissions."); }
  }

  async function loadConfig() {
    try { const response = await fetch(endpoint("website-config", true), { cache: "no-store" }); const data = await response.json(); if (!response.ok) throw new Error(data.error || "Could not load assistant settings."); setConfig(mergeConfig(data.config)); }
    catch (error) { setMessage(error.message || "Could not load assistant settings."); }
  }

  useEffect(() => { (async () => { try { const current = await statusCheck(); if (current.websiteConnected) { setWebsiteConnected(true); await Promise.all([loadRecords(), loadContacts(), loadConfig()]); } } catch (error) { setStatus("disconnected"); setMessage(error.message || "Local admin is unavailable."); } })(); }, []);
  useEffect(() => { if (!websiteConnected) return undefined; const timer = window.setInterval(() => { if (!document.hidden) { loadRecords(); loadContacts(); } }, 30000); return () => window.clearInterval(timer); }, [websiteConnected]);

  async function connectWebsite(event) {
    event.preventDefault(); setLoading(true); setMessage("");
    try { const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "connectWebsite", recordsConnectionKey: connectionKey, websiteOrigin }) }); const data = await response.json(); if (!response.ok) throw new Error(data.error || "Could not connect to WordPress."); setConnectionKey(""); if (data.websiteOrigin) setWebsiteOrigin(data.websiteOrigin); setWebsiteConnected(true); setStatus("connected"); showSync(data.sync); await Promise.all([loadRecords(), loadContacts(), loadConfig()]); }
    catch (error) { setMessage(error.message || "Could not connect to WordPress."); } finally { setLoading(false); }
  }

  async function importCsv(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!websiteConnected) { setMessage("Connect to the local archive before importing."); return; }
    const file = form.elements.namedItem("file")?.files?.[0];
    if (!file) { setMessage("Choose a CSV file first."); return; }
    setImporting(true); setMessage("");
    try {
      const payload = new FormData();
      payload.append("action", "importCsv");
      payload.append("kind", importKind);
      payload.append("file", file);
      const response = await fetch(CONNECTOR_URL, { method: "POST", body: payload });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not import the CSV file.");
      if (importKind === "records") setRecords(data.rows || []);
      else setContacts(data.rows || []);
      form.reset();
      setMessage(`${data.imported} ${importKind === "records" ? "chat records" : "Contact submissions"} imported. Local total: ${data.total}.`);
    } catch (error) {
      setMessage(error.message || "Could not import the CSV file.");
    } finally {
      setImporting(false);
    }
  }

  async function disconnectWebsite() {
    await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "disconnectWebsite" }) });
    setWebsiteConnected(false); setStatus("disconnected"); setRecords([]); setContacts([]); setMessage("WordPress connection closed. Local archive files were kept.");
  }

  async function saveSettings(event) {
    event.preventDefault(); setLoading(true); setMessage("");
    try { const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "saveWebsiteConfig", config }) }); const data = await response.json(); if (!response.ok) throw new Error(data.error || "Could not save settings."); setConfig(mergeConfig(data.config)); setMessage("Assistant settings saved to WordPress."); }
    catch (error) { setMessage(error.message || "Could not save settings."); } finally { setLoading(false); }
  }

  const updateConfig = (field, value) => setConfig(current => ({ ...current, [field]: value }));
  const updateFaq = (index, field, value) => setConfig(current => ({ ...current, faqs: current.faqs.map((faq, i) => i === index ? { ...faq, [field]: value } : faq) }));

  return <main className="local-admin-page">
    <section className="local-admin-hero local-admin-status-only"><div className={`connection-status ${connected ? "is-connected" : ""}`}><span />{connected ? "Local WordPress archive active" : "Connect with the WordPress passkey"}</div></section>
    <section className="local-admin-grid">
<form className="connector-card" onSubmit={connectWebsite}><div className="card-label">PRIVATE WORDPRESS CONNECTION</div><h2>{websiteConnected ? "Archive connected" : "Archive website records locally"}</h2><p>Use one connection code for the selected WordPress site. The code is held only for the current 15-minute session.</p>{!websiteConnected ? <><label>WordPress API URL<small className="field-help">Use the REST API base for any compatible site. Example: https://example.com/wp-json/mustdohr-search/v1</small><input type="url" value={websiteOrigin} onChange={event => setWebsiteOrigin(event.target.value)} autoComplete="url" required /></label><label>Connection code<small className="field-help">Private code for reading, archiving and chatbot settings.</small><input type="password" value={connectionKey} onChange={event => setConnectionKey(event.target.value)} autoComplete="off" required /></label><button className="connect-button" disabled={loading}>{loading ? "Archiving..." : "Connect and archive"}</button></> : <><div className="secure-detail">Connected site: {websiteOrigin}<br />Local archive: {localDataDir}<br />Connection expires after 15 minutes.</div><button className="disconnect-button" type="button" onClick={disconnectWebsite}>Disconnect</button></>}{message && <p className="connector-message">{message}</p>}</form>
       {websiteConnected && <section className="records-card record-directory"><div className="records-head"><div><div className="card-label">RECORD DIRECTORY</div><h2>Find customer conversations</h2><p className="directory-note">Filter Contact submissions and linked chat records by client, email or workflow category.</p></div><button type="button" className="disconnect-button directory-clear" onClick={() => { setClientQuery(""); setEmailQuery(""); setCategoryFilter("all"); }}>Clear filters</button></div><div className="directory-filters"><label>Client name<input value={clientQuery} onChange={event => setClientQuery(event.target.value)} placeholder="Search name or company" /></label><label>Email<input type="email" value={emailQuery} onChange={event => setEmailQuery(event.target.value)} placeholder="Search email address" /></label><label>Category<select value={categoryFilter} onChange={event => setCategoryFilter(event.target.value)}><option value="all">All categories</option>{categoryOptions.map(category => <option key={category} value={category}>{category}</option>)}</select></label><label>Group results by<select value={groupBy} onChange={event => setGroupBy(event.target.value)}><option value="client">Client name</option><option value="email">Email</option><option value="category">Category</option></select></label></div><div className="directory-summary">Showing {filteredRecords.length} chat records and {filteredContacts.length} Contact submissions{categoryFilter !== "all" ? ` | ${categoryFilter}` : ""}</div></section>}
       <section className="records-card csv-import-card"><div className="card-label">LOCAL ARCHIVE IMPORT</div><h2>Import an existing CSV</h2><p className="directory-note">Bring in a previous chat or Contact export. Imported rows stay on this computer and are merged without replacing existing records.</p><form className="csv-import-form" onSubmit={importCsv}><label>Import type<select value={importKind} onChange={event => setImportKind(event.target.value)}><option value="records">Chat records</option><option value="contacts">Contact submissions</option></select></label><label>CSV file<input name="file" type="file" accept=".csv,text/csv" required /></label><button className="connect-button" type="submit" disabled={!websiteConnected || importing}>{!websiteConnected ? "Connect first" : importing ? "Importing..." : "Import CSV"}</button></form></section>
       <section className="records-card"><div className="records-head"><div><div className="card-label">LOCAL CHAT RECORDS</div><h2>{websiteConnected ? `${filteredRecords.length} of ${records.length} archived conversations` : "Connect to view local records"}</h2><p className="directory-note">Sorted newest first by chat time.</p></div>{websiteConnected && <div className="record-actions"><button type="button" onClick={loadRecords} disabled={loading}>Sync & archive</button><a href={endpoint("website-records.csv")}>Download CSV</a></div>}</div>{websiteConnected ? <div className="records-list">{groupedRecords.length ? groupedRecords.map(([group, items]) => <div className="record-group" key={group}><div className="record-group-title">{groupBy === "client" ? "Client" : groupBy === "email" ? "Email" : "Category"}: {group}<span>{items.length}</span></div>{items.map(({ record, linked }) => <article key={`${record.website}-${record.id}`}><div className="record-meta"><strong>{clientLabel(linked)}</strong><span>{formatDate(record.created_at)}</span></div><p><b>Source</b>{record.website || "Mustdohr"}</p><p><b>Visitor</b>{record.visitor_message}</p><p><b>Assistant</b>{record.bot_reply || "No reply saved"}</p><p><b>Category</b>{recordCategory(record)}{linked?.email ? ` | ${linked.email}` : ""}</p><p><b>Flags</b>{[record.sensitive_blocked && "Sensitive blocked", record.question_limit_reached && "Limit reached", record.contact_submitted && "Contact submitted"].filter(Boolean).join(" | ") || "None"}</p><p><b>Page</b>{record.page_url || "Not available"}</p></article>)}</div>) : <div className="empty-records">No chat records match these filters.</div>}</div> : <div className="records-placeholder">Connect with the WordPress passkey to create the local archive.</div>}</section>
      {websiteConnected && <section className="records-card"><div className="records-head"><div><div className="card-label">LOCAL CONTACT SUBMISSIONS</div><h2>{filteredContacts.length} of {contacts.length} archived customer enquiries</h2></div><div className="record-actions"><button type="button" onClick={loadContacts} disabled={loading}>Sync & archive</button><a href={endpoint("website-contacts.csv")}>Download CSV</a></div></div><div className="records-list">{groupedContacts.length ? groupedContacts.map(([group, items]) => <div className="record-group" key={group}><div className="record-group-title">{groupBy === "client" ? "Client" : groupBy === "email" ? "Email" : "Category"}: {group}<span>{items.length}</span></div>{items.map(contact => <article key={contact.id}><div className="record-meta"><strong>{clientLabel(contact)}</strong><span>{formatDate(contact.created_at)}</span></div><p><b>Email</b>{contact.email || "Not provided"}</p><p><b>Source</b>{contact.source_website || "Mustdohr"} | {contact.trigger_reason || "Manual contact"}</p><p><b>Category</b>{contactCategory(contact)}</p><p><b>Request</b>{contact.request_type || "General enquiry"}{contact.country ? ` | ${contact.country}` : ""}</p><p><b>Message</b>{contact.message}</p><p><b>Chat transcript</b></p><pre className="contact-transcript">{formatChatTranscript(contact.chat_transcript)}</pre></article>)}</div>) : <div className="empty-records">No Contact submissions match these filters.</div>}</div></section>}
      {websiteConnected && <form className="records-card assistant-settings" onSubmit={saveSettings}><div className="records-head"><div><div className="card-label">WEBSITE ASSISTANT</div><h2>Configure the public chatbot</h2></div><button className="connect-button settings-save" disabled={loading}>Save changes</button></div><div className="settings-grid"><label className="switch-row"><input type="checkbox" checked={Boolean(config.enabled)} onChange={event => updateConfig("enabled", event.target.checked)} /> <span>Chatbot enabled</span></label><label>Source website label<input value={config.source_website} onChange={event => updateConfig("source_website", event.target.value)} /></label><label>Brand name<input value={config.brand_name} onChange={event => updateConfig("brand_name", event.target.value)} /></label><label>Welcome message<textarea value={config.welcome_message} onChange={event => updateConfig("welcome_message", event.target.value)} /></label><label>AI description<textarea value={config.ai_intro} onChange={event => updateConfig("ai_intro", event.target.value)} /></label><label>Visitor question limit <small>Use 0 for unlimited.</small><input type="number" min="0" max="100" value={config.question_limit} onChange={event => updateConfig("question_limit", event.target.value)} /></label><label>Sensitive keywords / phrases <small>One phrase per line.</small><textarea value={config.sensitive_keywords} onChange={event => updateConfig("sensitive_keywords", event.target.value)} /></label><label>Sensitive-question reply<textarea value={config.sensitive_reply} onChange={event => updateConfig("sensitive_reply", event.target.value)} /></label><label>Contact-trigger keywords <small>One phrase per line.</small><textarea value={config.contact_trigger_keywords} onChange={event => updateConfig("contact_trigger_keywords", event.target.value)} /></label><label>Contact-trigger reply<textarea value={config.contact_trigger_reply} onChange={event => updateConfig("contact_trigger_reply", event.target.value)} /></label><label>No public-answer reply<textarea value={config.no_answer_reply} onChange={event => updateConfig("no_answer_reply", event.target.value)} /></label><label>Question-limit reply<textarea value={config.limit_reply} onChange={event => updateConfig("limit_reply", event.target.value)} /></label><label>Contact guidance<select value={config.contact_mode} onChange={event => updateConfig("contact_mode", event.target.value)}><option value="embedded">Show the embedded form</option><option value="link">Open a contact page link</option></select></label>{config.contact_mode === "link" && <label>Contact form URL<input type="url" value={config.contact_url} onChange={event => updateConfig("contact_url", event.target.value)} /></label>}<label>Notification email addresses <small>Separate addresses with commas or new lines.</small><textarea value={config.notification_emails} onChange={event => updateConfig("notification_emails", event.target.value)} /></label><label>Knowledge base public page URLs <small>One URL per line.</small><textarea value={config.knowledge_urls} onChange={event => updateConfig("knowledge_urls", event.target.value)} /></label><label>Exclude public page URLs <small>One URL per line.</small><textarea value={config.excluded_urls} onChange={event => updateConfig("excluded_urls", event.target.value)} /></label></div><div className="faq-settings"><div className="card-label">COMMON Q&A</div>{config.faqs.map((faq, index) => <div className="faq-edit" key={index}><input placeholder="Question" value={faq.question} onChange={event => updateFaq(index, "question", event.target.value)} /><textarea placeholder="Answer" value={faq.answer} onChange={event => updateFaq(index, "answer", event.target.value)} /><button type="button" onClick={() => setConfig(current => ({ ...current, faqs: current.faqs.filter((_, item) => item !== index) }))}>Remove</button></div>)}<button type="button" className="disconnect-button" onClick={() => setConfig(current => ({ ...current, faqs: [...current.faqs, { question: "", answer: "" }] }))} disabled={config.faqs.length >= 12}>Add Q&A</button></div></form>}
      {websiteConnected && <section className="records-card analytics-card"><div className="records-head"><div><div className="card-label">CHATBOT ANALYTICS</div><h2>Local archive summary</h2></div></div><div className="analytics-metrics">{[["Chatbot uses", stats.uses], ["Customer questions", stats.questions], ["Contact guidance", stats.guidance], ["Form submissions", stats.submissions], ["Unanswered", stats.unanswered], ["Sensitive triggers", stats.sensitive], ["Question limits", stats.limits]].map(([label, value]) => <div className="analytics-metric" key={label}><span>{label}</span><strong>{value}</strong></div>)}</div><div className="analytics-detail"><h3>Most common questions</h3>{stats.common.length ? <ol>{stats.common.map(([question, count]) => <li key={question}><span>{question}</span><b>{count}</b></li>)}</ol> : <p>No questions saved yet.</p>}<small>Contact conversion: {stats.conversion}%</small></div></section>}
    </section>
  </main>;
}
