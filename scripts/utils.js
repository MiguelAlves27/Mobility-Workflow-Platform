const BASE_PATH = "";
const SERVER = "/server";

async function whoami() {
  const r = await fetch(`${SERVER}/whoami.php`, { credentials: "include" });
  const data = await r.json();
  if (!r.ok || !data.authenticated) return null;
  return data;
}

async function getRole() {
  const r = await fetch(`${SERVER}/role.php`, { credentials: "include" });
  const data = await r.json();
  if (!r.ok || !data.ok) return null;
  return safeText(data.role);
}

function safeText(v, fallback = "") {
  if (v === null || v === undefined) return fallback;
  const s = String(v).trim();
  return s.length ? s : fallback;
}

function truthy(v) {
  if (typeof v === "boolean") return v;
  if (typeof v === "number") return v !== 0;
  const s = String(v).trim().toLowerCase();
  return s === "1" || s === "true" || s === "yes";
}

function prettifyKey(k) {
  return safeText(k)
    .replace(/_/g, " ")
    .replace(/\b\w/g, m => m.toUpperCase());
}

function formatDate(dateStr) {
  if (!dateStr) return "";
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = String(d.getFullYear());
  return `${dd}/${mm}/${yyyy}`;
}

function setMsg(t) {
  const el = document.getElementById("msg");
  if (el) el.textContent = t || "";
}

function setStatus(t) {
  const el = document.getElementById("status");
  if (el) el.textContent = t || "";
}

function sortByDate(arr, desc = false) {
  const copy = Array.isArray(arr) ? arr.slice() : [];
  copy.sort((a, b) => {
    const ta = Date.parse(a.submittedAt || "") || 0;
    const tb = Date.parse(b.submittedAt || "") || 0;
    return desc ? tb - ta : ta - tb;
  });
  return copy;
}

const sortOldestFirst = arr => sortByDate(arr, false);
const sortNewestFirst = arr => sortByDate(arr, true);

function objectToRows(obj, labelMap) {
  const o = obj && typeof obj === "object" ? obj : {};
  const rows = [];
  const keys = Object.keys(o).sort((a, b) => a.localeCompare(b));

  for (const k of keys) {
    if (k.includes("base64")) continue;
    const v = o[k];
    if (v && typeof v === "object") {
      rows.push({
        label: (labelMap && labelMap[k]) || prettifyKey(k),
        value: Array.isArray(v)
          ? (v.length ? v.map(x => (typeof x === "string" ? x : JSON.stringify(x))).join(", ") : "")
          : JSON.stringify(v)
      });
      continue;
    }
    rows.push({ label: (labelMap && labelMap[k]) || prettifyKey(k), value: v });
  }

  return rows;
}

function renderRows(containerId, rows) {
  const root = document.getElementById(containerId);
  if (!root) return;
  root.innerHTML = "";

  rows.forEach(r => {
    const label = safeText(r && r.label);
    const value = safeText(r && r.value);
    if (!value) return;

    const row = document.createElement("div");
    row.className = "row";

    const l = document.createElement("div");
    l.className = "label";
    l.textContent = label;

    const v = document.createElement("div");
    v.className = "value";
    v.textContent = value;

    row.appendChild(l);
    row.appendChild(v);
    root.appendChild(row);
  });
}

function applyStatusPill(pill, status) {
  if (!pill) return;
  pill.textContent = status || "-";
  pill.classList.remove("draft", "submitted", "changes", "approved", "archived");
  if (status === "DRAFT")                                          pill.classList.add("draft");
  else if (status === "SUBMITTED" || status === "PENDING")         pill.classList.add("submitted");
  else if (status === "STAFF_APPROVED")                            pill.classList.add("submitted");
  else if (status === "CHANGES_REQUESTED")                         pill.classList.add("changes");
  else if (status === "APPROVED" || status === "DONE")             pill.classList.add("approved");
  else if (status === "ARCHIVED")                                  pill.classList.add("archived");
}

