"use client";

import { useEffect, useMemo, useState } from "react";
import "./local-admin.css";
import "./assistant-settings.css";

const CONNECTOR_URL = "/api/local-connector";
const REMOTE_API = "http://127.0.0.1:3033";
const RECORDS_KEY_STORAGE = "mustdohr-local-admin-records-key";
const DEFAULT_CONFIG = {
  enabled: true,
  brand_name: "Mustdohr search",
  welcome_message: "Search the public Mustdohr website and open the closest pages.",
  ai_intro: "Ask AI to summarize public Mustdohr content. AI answers may be incomplete.",
  faqs: [], question_limit: 0, sensitive_keywords: "",
  sensitive_reply: "I cannot help with that request. For information about Mustdohr services, please use our contact form.",
  contact_mode: "embedded", contact_url: "", notification_emails: "nnroademail@gmail.com", knowledge_urls: "", excluded_urls: "",
  source_website: "Mustdohr", contact_trigger_keywords: "", contact_trigger_reply: "",
  no_answer_reply: "", limit_reply: "", show_contact_for: ["contact", "unanswered", "limit", "sensitive"],
};

function mergeAssistantConfig(value = {}) {
  const merged = { ...DEFAULT_CONFIG, ...(value || {}) };
  // Keep a usable notification destination when the website has not configured one yet.
  if (!String(merged.notification_emails || "").trim()) {
    merged.notification_emails = DEFAULT_CONFIG.notification_emails;
  }
  return merged;
}

const FIELD_DESCRIPTIONS = {
  "Private records key": "Read-only key used to load private WordPress records.",
  "SSH username": "Server account name used for the private connection.",
  "SSH password": "Used only for this connection and never saved by the page.",
  "Source website label": "Name shown beside records from this website.",
  "Brand name": "Name displayed in the public assistant.",
  "Welcome message": "Opening message shown when visitors start a chat.",
  "AI description": "Short explanation shown before visitors ask AI.",
  "Visitor question limit": "Maximum AI questions per visitor; use 0 for unlimited.",
  "Sensitive keywords / phrases": "One phrase per line; a match starts the sensitive flow.",
  "Sensitive-question reply": "Standard response shown for blocked questions.",
  "Contact-trigger keywords": "One phrase per line; a match recommends the contact form.",
  "Contact-trigger reply": "Response shown when a contact keyword is detected.",
  "No public-answer reply": "Response used when the public knowledge base has no answer.",
  "Question-limit reply": "Response shown after a visitor reaches the AI limit.",
  "Contact guidance": "Choose an embedded form or a link to your contact page.",
  "Contact form URL": "Destination used when contact guidance is set to link.",
  "Notification email addresses": "Addresses that receive contact and assistant alerts.",
  "Knowledge base public page URLs": "Public pages included in website search and AI context.",
  "Exclude public page URLs": "Public pages deliberately omitted from the knowledge base.",
  "Question": "Short question shown as a quick FAQ option.",
  "Answer": "Preset response shown after a visitor selects this FAQ.",
};

function formatDate(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function connectorEndpoint(path = "", refresh = false) {
  const params = new URLSearchParams();
  if (path) params.set("path", path);
  if (refresh) params.set("_", String(Date.now()));
  const query = params.toString();
  return query ? `${CONNECTOR_URL}?${query}` : CONNECTOR_URL;
}

function savedRecordsKey() {
  if (typeof window === "undefined") return "";
  // Older local-admin tabs used sessionStorage. Move that existing tab value
  // to localStorage so every localhost tab can restore the same read-only view.
  const key = window.localStorage.getItem(RECORDS_KEY_STORAGE) || window.sessionStorage.getItem(RECORDS_KEY_STORAGE) || "";
  if (key) {
    window.localStorage.setItem(RECORDS_KEY_STORAGE, key);
    window.sessionStorage.removeItem(RECORDS_KEY_STORAGE);
  }
  return key;
}

function liveRecordsHeaders() {
  if (typeof window === "undefined") return {};
  const key = savedRecordsKey();
  return key ? { "X-Mustdohr-Records-Key": key } : {};
}

function getAnalyticsWebsite(item) {
  return String(item?.website || item?.source_website || "Mustdohr").trim() || "Mustdohr";
}

function analyticsText(value) {
  return String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
}

function isUnansweredRecord(record) {
  const status = analyticsText(record.status);
  const trigger = analyticsText(record.contact_trigger);
  const answer = analyticsText(record.bot_reply);
  return ["unanswered", "no_answer", "no-answer", "failed"].some(value => status.includes(value) || trigger.includes(value))
    || answer.includes("could not confirm")
    || answer.includes("cannot confirm")
    || answer.includes("no public answer");
}

function buildAnalytics(records, contacts) {
  const sessions = new Set(records.map(record => String(record.session_id || "").trim()).filter(Boolean));
  const questions = new Map();
  records.forEach(record => {
    const question = String(record.visitor_message || record.question || "").replace(/\s+/g, " ").trim();
    if (!question) return;
    const key = analyticsText(question);
    const entry = questions.get(key) || { question, count: 0 };
    entry.count += 1;
    questions.set(key, entry);
  });
  const guidance = records.filter(record => String(record.contact_trigger || "").trim() !== "").length;
  const pages = new Map();
  records.forEach(record => {
    const page = String(record.page_url || "").trim();
    if (page) pages.set(page, (pages.get(page) || 0) + 1);
  });
  const countries = new Map();
  contacts.forEach(contact => {
    const country = String(contact.country || "").trim();
    if (country) countries.set(country, (countries.get(country) || 0) + 1);
  });
  const days = new Map();
  records.forEach(record => {
    const day = String(record.created_at || "").slice(0, 10);
    if (day) days.set(day, (days.get(day) || 0) + 1);
  });
  const submissions = contacts.length;
  return {
    uses: records.length,
    sessions: sessions.size || (records.length ? records.length : 0),
    questions: records.length,
    commonQuestions: [...questions.values()].sort((a, b) => b.count - a.count).slice(0, 5),
    unanswered: records.filter(isUnansweredRecord).length,
    guidance,
    submissions,
    conversion: guidance ? Math.round((submissions / guidance) * 100) : 0,
    sensitive: records.filter(record => Boolean(record.sensitive_blocked)).length,
    limits: records.filter(record => Boolean(record.question_limit_reached)).length,
    pages: [...pages.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5),
    countries: [...countries.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5),
    days: [...days.entries()].sort((a, b) => b[0].localeCompare(a[0])).slice(0, 7),
  };
}

export default function LocalAdminPage() {
  const [username, setUsername] = useState("yunhao");
  const [password, setPassword] = useState("");
  const [recordsKey, setRecordsKey] = useState("");
  const [websiteConnected, setWebsiteConnected] = useState(false);
  const [status, setStatus] = useState("checking");
  const [message, setMessage] = useState("");
  const [records, setRecords] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [archivePassword, setArchivePassword] = useState("");
  const [config, setConfig] = useState(DEFAULT_CONFIG);
  const [analyticsWebsite, setAnalyticsWebsite] = useState("all");
  const [loading, setLoading] = useState(false);
  const connected = status === "connected";

  const analyticsSites = useMemo(() => {
    const names = new Set(["Mustdohr", "NNRoad", "TOPFDI"]);
    [...records, ...contacts].forEach(item => names.add(getAnalyticsWebsite(item)));
    return [...names].sort((a, b) => a.localeCompare(b));
  }, [records, contacts]);

  const analyticsData = useMemo(() => {
    const matchesWebsite = item => getAnalyticsWebsite(item).toLowerCase() === analyticsWebsite.toLowerCase();
    const scopedRecords = analyticsWebsite === "all" ? records : records.filter(matchesWebsite);
    const scopedContacts = analyticsWebsite === "all" ? contacts : contacts.filter(matchesWebsite);
    return buildAnalytics(scopedRecords, scopedContacts);
  }, [records, contacts, analyticsWebsite]);

  async function connectorStatus() {
    const response = await fetch(CONNECTOR_URL, { cache: "no-store", headers: liveRecordsHeaders() });
    const data = await response.json();
    setStatus(data.status || "disconnected");
    setWebsiteConnected(Boolean(data.websiteConnected));
    if (data.error) setMessage(data.error);
    return data;
  }

  async function loadRecords() {
    setLoading(true);
    try {
      const response = await fetch(connectorEndpoint("server-records", true), { cache: "no-store" });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "The secure connection is unavailable.");
      setRecords(data.records || []);
      setMessage("");
    } catch (error) {
      setMessage(error.message || "Could not load chat records.");
    } finally { setLoading(false); }
  }

  async function loadWebsiteRecords() {
    setLoading(true);
    try {
      const response = await fetch(connectorEndpoint("website-records", true), { cache: "no-store", headers: liveRecordsHeaders() });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not load live website records.");
      let next = data.records || [];
      // If the private tunnel is also open, show archive-only items too.
      try {
        const archiveResponse = await fetch(connectorEndpoint("server-records", true), { cache: "no-store" });
        if (archiveResponse.ok) {
          const archive = await archiveResponse.json();
          const seen = new Set(next.map(record => `${record.visitor_message}|${record.created_at}`));
          next = [...next, ...(archive.records || []).filter(record => {
            const key = `${record.visitor_message}|${record.created_at}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
          })].sort((a, b) => String(b.created_at || "").localeCompare(String(a.created_at || "")));
        }
      } catch (_) {}
      setRecords(next);
      setMessage("");
    } catch (error) {
      setMessage(error.message || "Could not load live website records.");
    } finally { setLoading(false); }
  }

  async function loadWebsiteContacts() {
    try {
      const response = await fetch(connectorEndpoint("website-contacts", true), { cache: "no-store", headers: liveRecordsHeaders() });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not load contact submissions.");
      setContacts(data.submissions || []);
    } catch (error) { setMessage(error.message || "Could not load contact submissions."); }
  }

  async function loadWebsiteConfig() {
    try {
      const response = await fetch(connectorEndpoint("website-config", true), { cache: "no-store", headers: liveRecordsHeaders() });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not load assistant settings.");
      setConfig(mergeAssistantConfig(data.config));
    } catch (error) { setMessage(error.message || "Could not load assistant settings."); }
  }

  useEffect(() => {
    const addFieldDescriptions = () => {
      const labels = document.querySelectorAll(".local-admin-page label");
      labels.forEach(label => {
        const control = label.querySelector("input, textarea, select");
        if (!control || label.querySelector(".field-help")) return;
        const text = label.textContent.replace(/\s+/g, " ").trim();
        const key = Object.keys(FIELD_DESCRIPTIONS).find(item => text.startsWith(item));
        if (!key) return;
        const help = document.createElement("small");
        help.className = "field-help";
        help.textContent = FIELD_DESCRIPTIONS[key];
        label.insertBefore(help, control);
      });
      const archiveInput = document.querySelector('.local-admin-page input[placeholder="SSH password to install archive"]');
      if (archiveInput && !archiveInput.previousElementSibling?.classList.contains("field-help")) {
        const help = document.createElement("small");
        help.className = "field-help";
        help.textContent = "Used to install the server-side Contact JSON archive.";
        archiveInput.parentElement.insertBefore(help, archiveInput);
      }
    };
    addFieldDescriptions();
  }, [websiteConnected, connected, config]);

  useEffect(() => {
    async function restoreConnections() {
      try {
        let current = await connectorStatus();
        // The local development server can restart after a code change. Keep
        // the private WordPress records key on this local browser, then
        // quietly restore the read-only website connection when that happens.
        if (!current.websiteConnected) {
          const savedKey = savedRecordsKey();
          if (savedKey) {
            const response = await fetch(CONNECTOR_URL, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ action: "connectWebsite", recordsKey: savedKey }),
            });
            if (response.ok) current = { ...current, websiteConnected: true };
            else window.localStorage.removeItem(RECORDS_KEY_STORAGE);
          }
        }
        if (current.websiteConnected) { await Promise.all([loadWebsiteRecords(), loadWebsiteContacts(), loadWebsiteConfig()]); }
        else if (current.status === "connected") await loadRecords();
      } catch (_) { setStatus("disconnected"); }
    }
    restoreConnections();
  }, []);

  useEffect(() => {
    if (!websiteConnected) return undefined;
    const timer = window.setInterval(() => {
      if (document.hidden) return;
      loadWebsiteRecords();
      loadWebsiteContacts();
    }, 10000);
    return () => window.clearInterval(timer);
  }, [websiteConnected]);

  async function connect(event) {
    event.preventDefault(); setLoading(true); setMessage("");
    try {
      const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "connect", username, password }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Unable to connect.");
      setPassword(""); setStatus(data.status); await loadRecords();
    } catch (error) { setStatus("disconnected"); setMessage(error.message || "Unable to connect."); }
    finally { setLoading(false); }
  }

  async function disconnect() {
    await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "disconnect" }) });
    setStatus("disconnected"); setRecords([]); setMessage("Connection closed.");
  }

  async function connectWebsite(event) {
    event.preventDefault(); setLoading(true); setMessage("");
    try {
      const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "connectWebsite", recordsKey }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not connect to live website records.");
      window.localStorage.setItem(RECORDS_KEY_STORAGE, recordsKey);
      window.sessionStorage.removeItem(RECORDS_KEY_STORAGE);
      setRecordsKey(""); setWebsiteConnected(true);
      await Promise.all([loadWebsiteRecords(), loadWebsiteContacts(), loadWebsiteConfig()]);
    } catch (error) { setMessage(error.message || "Could not connect to live website records."); }
    finally { setLoading(false); }
  }

  async function disconnectWebsite() {
    await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json", ...liveRecordsHeaders() }, body: JSON.stringify({ action: "disconnectWebsite" }) });
    window.localStorage.removeItem(RECORDS_KEY_STORAGE);
    window.sessionStorage.removeItem(RECORDS_KEY_STORAGE);
    setWebsiteConnected(false); setRecords([]); setContacts([]); setMessage("Website records disconnected.");
  }

  async function deployContactArchive() {
    setLoading(true); setMessage("");
    try {
      const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "deployContactArchive", username, password: archivePassword }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not install the contact archive.");
      setArchivePassword(""); setMessage(`Contact archive is active: ${data.folder}`);
    } catch (error) { setMessage(error.message || "Could not install the contact archive."); }
    finally { setLoading(false); }
  }

  async function saveAssistantSettings(event) {
    event.preventDefault(); setLoading(true); setMessage("");
    try {
      const response = await fetch(CONNECTOR_URL, { method: "POST", headers: { "Content-Type": "application/json", ...liveRecordsHeaders() }, body: JSON.stringify({ action: "saveWebsiteConfig", config }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Could not save assistant settings.");
      setConfig(mergeAssistantConfig(data.config)); setMessage("Assistant settings saved to mustdohr.com.");
    } catch (error) { setMessage(error.message || "Could not save assistant settings."); }
    finally { setLoading(false); }
  }

  const updateConfig = (field, value) => setConfig(current => ({ ...current, [field]: value }));
  const updateFaq = (index, field, value) => setConfig(current => ({ ...current, faqs: current.faqs.map((faq, item) => item === index ? { ...faq, [field]: value } : faq) }));

  return <main className="local-admin-page">
    <section className="local-admin-hero local-admin-status-only"><div className={`connection-status ${connected ? "is-connected" : ""}`}><span />{connected ? "Secure connection active" : "Connection required"}</div></section>
    <section className="local-admin-grid">
      <form className="connector-card" onSubmit={connectWebsite}><div className="card-label">LIVE WEBSITE</div><h2>{websiteConnected ? "Live records connected" : "View real chatbot records"}</h2><p>Use the private connection key from WordPress: Settings → Mustdohr Chat Records.</p>{!websiteConnected ? <><label>Private records key<input type="password" value={recordsKey} onChange={event => setRecordsKey(event.target.value)} autoComplete="off" required /></label><button className="connect-button" disabled={loading}>{loading ? "Connecting…" : "View live records"}</button></> : <><div className="secure-detail">Reading private records directly from mustdohr.com</div><button className="disconnect-button" type="button" onClick={disconnectWebsite}>Disconnect</button></>}</form>
      <form className="connector-card" onSubmit={connect}><div className="card-label">SERVER CONNECTION</div><h2>{connected ? "Connected to Mustdohr" : "Connect privately"}</h2><p>Server: 158.69.253.60 · Port: 222</p>{!connected ? <><label>SSH username<input value={username} onChange={event => setUsername(event.target.value)} autoComplete="username" required /></label><label>SSH password<input type="password" value={password} onChange={event => setPassword(event.target.value)} autoComplete="current-password" required /></label><button className="connect-button" disabled={loading}>{loading ? "Connecting…" : "Connect to records"}</button></> : <><div className="secure-detail">Local address: 127.0.0.1:3033</div><button className="disconnect-button" type="button" onClick={disconnect}>Disconnect</button></>}{message && <p className="connector-message">{message}</p>}</form>
      <section className="records-card"><div className="records-head"><div><div className="card-label">CHAT RECORDS</div><h2>{websiteConnected ? `${records.length} live conversations` : connected ? `${records.length} saved conversations` : "Connect to view records"}</h2></div>{(connected || websiteConnected) && <div className="record-actions"><button type="button" onClick={websiteConnected ? loadWebsiteRecords : loadRecords} disabled={loading}>Refresh</button><a href={websiteConnected ? connectorEndpoint("website-records.csv") : connectorEndpoint("server-records.csv")}>Download CSV</a></div>}</div>{(connected || websiteConnected) ? <div className="records-list">{records.length ? records.map(record => <article key={`${record.website}-${record.id}`}><div className="record-meta"><strong>{record.website || "Mustdohr"}</strong><span>{formatDate(record.created_at)}</span></div><p><b>Visitor</b>{record.visitor_message}</p><p><b>Assistant</b>{record.bot_reply || "No reply saved"}</p><p><b>Flow</b>{record.contact_trigger || "Public-content answer"}</p><p><b>Flags</b>{[record.sensitive_blocked && "Sensitive blocked", record.question_limit_reached && "Limit reached", record.contact_submitted && "Contact submitted"].filter(Boolean).join(" · ") || "None"}</p><p><b>Page</b>{record.page_url || "Not available"}</p></article>) : <div className="empty-records">No chat records have been saved yet.</div>}</div> : <div className="records-placeholder">Once connected, chat records and CSV export will appear here.</div>}</section>
      {websiteConnected && <section className="records-card"><div className="records-head"><div><div className="card-label">CONTACT SUBMISSIONS</div><h2>{contacts.length} customer enquiries</h2></div><div className="record-actions"><button type="button" onClick={loadWebsiteContacts}>Refresh</button></div></div><div className="records-list">{contacts.length ? contacts.map(contact => <article key={contact.id}><div className="record-meta"><strong>{contact.name} · {contact.company || "Individual"}</strong><span>{formatDate(contact.created_at)}</span></div><p><b>Email</b>{contact.email}</p><p><b>Request</b>{contact.request_type}{contact.country ? ` · ${contact.country}` : ""}</p><p><b>Message</b>{contact.message}</p></article>) : <div className="empty-records">No contact submissions yet.</div>}</div><div className="contact-archive"><strong>Server archive</strong><span>A private JSON copy is saved to the server every minute after setup.</span><input type="password" placeholder="SSH password to install archive" value={archivePassword} onChange={event => setArchivePassword(event.target.value)} autoComplete="current-password" /><button type="button" onClick={deployContactArchive} disabled={loading || !archivePassword}>Install server archive</button></div></section>}
      {websiteConnected && <form className="records-card assistant-settings" onSubmit={saveAssistantSettings}><div className="records-head"><div><div className="card-label">WEBSITE ASSISTANT</div><h2>Configure the public chatbot</h2></div><button className="connect-button settings-save" disabled={loading}>Save changes</button></div><div className="settings-grid"><label className="switch-row"><input type="checkbox" checked={Boolean(config.enabled)} onChange={event => updateConfig("enabled", event.target.checked)} /> <span>Chatbot enabled</span></label><label>Source website label<input value={config.source_website} onChange={event => updateConfig("source_website", event.target.value)} /></label><label>Brand name<input value={config.brand_name} onChange={event => updateConfig("brand_name", event.target.value)} /></label><label>Welcome message<textarea value={config.welcome_message} onChange={event => updateConfig("welcome_message", event.target.value)} /></label><label>AI description<textarea value={config.ai_intro} onChange={event => updateConfig("ai_intro", event.target.value)} /></label><label>Visitor question limit <small>Use 0 for unlimited.</small><input type="number" min="0" max="100" value={config.question_limit} onChange={event => updateConfig("question_limit", event.target.value)} /></label><label>Sensitive keywords / phrases <small>One per line.</small><textarea value={config.sensitive_keywords} onChange={event => updateConfig("sensitive_keywords", event.target.value)} /></label><label>Sensitive-question reply<textarea value={config.sensitive_reply} onChange={event => updateConfig("sensitive_reply", event.target.value)} /></label><label>Contact-trigger keywords <small>Pricing, sales, partnership etc. One per line.</small><textarea value={config.contact_trigger_keywords} onChange={event => updateConfig("contact_trigger_keywords", event.target.value)} /></label><label>Contact-trigger reply<textarea value={config.contact_trigger_reply} onChange={event => updateConfig("contact_trigger_reply", event.target.value)} /></label><label>No public-answer reply<textarea value={config.no_answer_reply} onChange={event => updateConfig("no_answer_reply", event.target.value)} /></label><label>Question-limit reply<textarea value={config.limit_reply} onChange={event => updateConfig("limit_reply", event.target.value)} /></label><label>Contact guidance<select value={config.contact_mode} onChange={event => updateConfig("contact_mode", event.target.value)}><option value="embedded">Show the embedded form</option><option value="link">Open a contact page link</option></select></label>{config.contact_mode === "link" && <label>Contact form URL<input type="url" placeholder="https://mustdohr.com/contact/" value={config.contact_url} onChange={event => updateConfig("contact_url", event.target.value)} /></label>}<label className="workflow-checks">Show the contact step for {[["contact", "sales / pricing / partnership"], ["unanswered", "no public answer"], ["sensitive", "sensitive question"], ["limit", "question limit"]].map(([key, label]) => <span key={key}><input type="checkbox" checked={(config.show_contact_for || []).includes(key)} onChange={event => updateConfig("show_contact_for", event.target.checked ? [...new Set([...(config.show_contact_for || []), key])] : (config.show_contact_for || []).filter(item => item !== key))} /> {label}</span>)}</label><label>Notification email addresses <small>Separate addresses with commas or new lines.</small><textarea value={config.notification_emails} onChange={event => updateConfig("notification_emails", event.target.value)} /></label><label>Knowledge base public page URLs <small>Only Mustdohr public pages; one URL per line.</small><textarea value={config.knowledge_urls} onChange={event => updateConfig("knowledge_urls", event.target.value)} /></label><label>Exclude public page URLs <small>One URL per line.</small><textarea value={config.excluded_urls} onChange={event => updateConfig("excluded_urls", event.target.value)} /></label></div><div className="faq-settings"><div className="card-label">COMMON Q&A</div><p>Shown as quick answers when visitors open the assistant.</p>{config.faqs.map((faq, index) => <div className="faq-edit" key={index}><input placeholder="Question" value={faq.question} onChange={event => updateFaq(index, "question", event.target.value)} /><textarea placeholder="Answer" value={faq.answer} onChange={event => updateFaq(index, "answer", event.target.value)} /><button type="button" onClick={() => setConfig(current => ({ ...current, faqs: current.faqs.filter((_, item) => item !== index) }))}>Remove</button></div>)}<button type="button" className="disconnect-button" onClick={() => setConfig(current => ({ ...current, faqs: [...current.faqs, { question: "", answer: "" }] }))} disabled={config.faqs.length >= 12}>Add Q&A</button></div></form>}
      {websiteConnected && <section className="records-card analytics-card">
        <div className="records-head analytics-head">
          <div><div className="card-label">CHATBOT ANALYTICS</div><h2>Conversation signals at a glance</h2><p>Review usage, visitor questions, workflow triggers and conversion signals by website.</p></div>
          <label className="analytics-filter">View website<select value={analyticsWebsite} onChange={event => setAnalyticsWebsite(event.target.value)}><option value="all">All websites</option>{analyticsSites.map(site => <option value={site} key={site}>{site}</option>)}</select></label>
        </div>
        <div className="analytics-metrics">
          {[
            ["Chatbot uses", analyticsData.uses, `${analyticsData.sessions} unique sessions`],
            ["Customer questions", analyticsData.questions, "saved AI questions"],
            ["Contact guidance", analyticsData.guidance, "visitors prompted to contact"],
            ["Form submissions", analyticsData.submissions, `${analyticsData.conversion}% guidance conversion`],
            ["Unanswered", analyticsData.unanswered, "questions needing follow-up"],
            ["Sensitive triggers", analyticsData.sensitive, "questions intercepted"],
            ["Question limits", analyticsData.limits, "visits reaching the limit"],
          ].map(([label, value, detail]) => <div className="analytics-metric" key={label}><span>{label}</span><strong>{value}</strong><small>{detail}</small></div>)}
        </div>
        <div className="analytics-detail-grid">
          <div className="analytics-detail"><h3>Most common questions</h3>{analyticsData.commonQuestions.length ? <ol>{analyticsData.commonQuestions.map(item => <li key={item.question}><span>{item.question}</span><b>{item.count}</b></li>)}</ol> : <p className="analytics-empty">No questions for this website yet.</p>}</div>
          <div className="analytics-detail"><h3>Top visitor pages</h3>{analyticsData.pages.length ? <ul>{analyticsData.pages.map(([page, count]) => <li key={page}><span title={page}>{page.replace(/^https?:\/\/[^/]+/, "") || "/"}</span><b>{count}</b></li>)}</ul> : <p className="analytics-empty">No page data yet.</p>}</div>
          <div className="analytics-detail"><h3>Visitor country signals</h3>{analyticsData.countries.length ? <ul>{analyticsData.countries.map(([country, count]) => <li key={country}><span>{country}</span><b>{count}</b></li>)}</ul> : <p className="analytics-empty">Country data appears when visitors submit the contact form.</p>}</div>
          <div className="analytics-detail"><h3>Recent activity trend</h3>{analyticsData.days.length ? <ul>{analyticsData.days.map(([day, count]) => <li key={day}><span>{day}</span><b>{count}</b></li>)}</ul> : <p className="analytics-empty">Daily activity will appear after conversations are saved.</p>}</div>
          <div className="analytics-detail analytics-note"><h3>How to read this</h3><p>Chatbot uses count saved AI interactions. Contact conversion is calculated from contact-form submissions divided by contact guidance events. Search-only interactions are intentionally excluded from these AI metrics.</p></div>
        </div>
      </section>}
      {websiteConnected && <section className="records-card audit-records">
        <div className="records-head"><div><div className="card-label">CHAT AUDIT DETAILS</div><h2>Complete conversation record</h2></div><div className="record-actions"><button type="button" onClick={loadWebsiteRecords} disabled={loading}>Refresh</button><a href={connectorEndpoint("website-records.csv")}>Export chats</a></div></div>
        <div className="records-list">{records.map(record => <article key={`audit-${record.website}-${record.id}`}>
          <div className="record-meta"><strong>Source website: {record.website || "Mustdohr"}</strong><span>Chat time: {formatDate(record.created_at)}</span></div>
          <p><b>Customer page</b>{record.page_url || "Not available"}</p>
          <p><b>Customer question</b>{record.visitor_message || "Not available"}</p>
          <p><b>Chatbot reply</b>{record.bot_reply || "No reply saved"}</p>
          <p><b>Sensitive block</b>{record.sensitive_blocked ? "Yes" : "No"}</p>
          <p><b>Question limit</b>{record.question_limit_reached ? "Reached" : "Not reached"}</p>
          <p><b>Contact form</b>{record.contact_submitted ? "Submitted" : "Not submitted"}</p>
          <p><b>Workflow</b>{record.contact_trigger || "Public-content answer"}</p>
        </article>)}</div>
      </section>}
      {websiteConnected && <section className="records-card contact-audit">
        <div className="records-head"><div><div className="card-label">CONTACT SOURCE & EXPORT</div><h2>{contacts.length} contact submissions</h2></div><div className="record-actions"><button type="button" onClick={loadWebsiteContacts} disabled={loading}>Refresh</button><a href={connectorEndpoint("website-contacts.csv")}>Export contacts</a></div></div>
        <div className="records-list">{contacts.map(contact => <article key={`contact-audit-${contact.id}`}>
          <div className="record-meta"><strong>{contact.name || "Website visitor"} · {contact.source_website || "Mustdohr"}</strong><span>{formatDate(contact.created_at)}</span></div>
          <p><b>Linked chat</b>{contact.chat_record_count ? `${contact.chat_record_count} message${contact.chat_record_count === 1 ? "" : "s"} · latest #${contact.chat_record_id}` : "No matching chat session"}</p>
          <p><b>Last question</b>{contact.chat_question || "Not available"}</p>
          <p><b>Triggered by</b>{contact.trigger_reason || "Manual contact"}</p>
          <p><b>Visitor page</b>{contact.page_url || "Not available"}</p>
          <p><b>Request</b>{contact.request_type || "General enquiry"}</p>
          <p><b>Message</b>{contact.message || "Not available"}</p>
          <p className="contact-transcript"><b>Chat transcript</b>{contact.chat_transcript || "No chat transcript saved"}</p>
        </article>)}</div>
      </section>}
    </section>
  </main>;
}
