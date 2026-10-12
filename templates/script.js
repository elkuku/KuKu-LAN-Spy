const KEY = "lan-spy-open";
const rows = [...document.querySelectorAll("tbody tr")];
let open;
try { open = new Set(JSON.parse(sessionStorage.getItem(KEY) || "[]")); } catch (e) { open = new Set(); }
const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify([...open])); } catch (e) {} };
const toggleAll = document.getElementById("toggle-all");
const typeButton = document.getElementById("type-button");
const typeList = document.getElementById("type-list");
const typeOptions = [...typeList.children];
const macFilter = document.getElementById("mac-filter");
const noMatch = document.getElementById("no-match");
let typeValue = "";
const shown = () => rows.filter(r => !r.hidden);
function set(tr, on) {
  tr.classList.toggle("open", on);
  tr.setAttribute("aria-expanded", on);
  on ? open.add(tr.dataset.key) : open.delete(tr.dataset.key);
}
function sync() {
  toggleAll.textContent = shown().every(r => r.classList.contains("open")) ? "Collapse all" : "Expand all";
  toggleAll.disabled = shown().length === 0;
  save();
}
rows.forEach(tr => {
  set(tr, open.has(tr.dataset.key));
  tr.addEventListener("click", () => {
    if (getSelection().toString()) return;  // let people select and copy text
    set(tr, !tr.classList.contains("open")); sync();
  });
  tr.addEventListener("keydown", e => {
    if (e.target !== tr) return;  // keys on the copy button are its own
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); tr.click(); }
  });
});
toggleAll.addEventListener("click", () => {
  const on = toggleAll.textContent === "Expand all";
  shown().forEach(tr => set(tr, on)); sync();
});
// Filters: a row shows when it matches both the type and the MAC filter.
// MACs are compared as bare hex digits, so "584b", "58:4b" and "58-4B" all match.
const hex = s => s.toLowerCase().replace(/[^0-9a-f]/g, "");
function applyFilters() {
  const mac = hex(macFilter.value);
  rows.forEach(tr => {
    tr.hidden = (typeValue !== "" && tr.dataset.type !== typeValue) || (mac !== "" && !hex(tr.dataset.mac).includes(mac));
  });
  macFilter.classList.toggle("active", mac !== "");
  noMatch.hidden = shown().length > 0;
  try {
    sessionStorage.setItem("lan-spy-type", typeValue);
    sessionStorage.setItem("lan-spy-mac", macFilter.value);
  } catch (e) {}
  sync();
}
macFilter.addEventListener("input", applyFilters);
document.getElementById("clear-filters").addEventListener("click", () => { macFilter.value = ""; pickType(""); });
// Type filter: a listbox instead of a <select>, so the options can show the device icons.
function pickType(value) {
  const opt = typeOptions.find(o => o.dataset.value === value) || typeOptions[0];
  typeOptions.forEach(o => o.setAttribute("aria-selected", o === opt));
  typeButton.innerHTML = opt.innerHTML;
  typeButton.classList.toggle("active", opt.dataset.value !== "");
  typeValue = opt.dataset.value;
  applyFilters();
}
function showTypes(on) {
  typeList.hidden = !on;
  typeButton.setAttribute("aria-expanded", on);
  if (on) typeOptions.find(o => o.getAttribute("aria-selected") === "true").focus();
}
typeButton.addEventListener("click", () => showTypes(typeList.hidden));
typeButton.addEventListener("keydown", e => {
  if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); showTypes(true); }
});
typeOptions.forEach(o => o.addEventListener("click", () => {
  pickType(o.dataset.value); showTypes(false); typeButton.focus();
}));
typeList.addEventListener("keydown", e => {
  const i = typeOptions.indexOf(document.activeElement), n = typeOptions.length;
  const move = { ArrowDown: i + 1, ArrowUp: i - 1 + n, Home: 0, End: n - 1 }[e.key];
  if (move !== undefined) { e.preventDefault(); typeOptions[move % n].focus(); }
  else if (e.key === "Enter" || e.key === " ") { e.preventDefault(); document.activeElement.click(); }
  else if (e.key === "Escape") { showTypes(false); typeButton.focus(); }
  else if (e.key === "Tab") showTypes(false);
});
document.addEventListener("click", e => { if (!typeList.parentElement.contains(e.target)) showTypes(false); });
let savedType = "";
try {
  savedType = sessionStorage.getItem("lan-spy-type") || "";
  macFilter.value = sessionStorage.getItem("lan-spy-mac") || "";
} catch (e) {}
pickType(savedType);
// Copy buttons. navigator.clipboard needs a secure context; fall back to execCommand otherwise.
function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
  const ta = Object.assign(document.createElement("textarea"), { value: text });
  ta.style.cssText = "position:fixed;opacity:0";
  document.body.append(ta);
  ta.select();
  const ok = document.execCommand("copy");
  ta.remove();
  return ok ? Promise.resolve() : Promise.reject(new Error("copy failed"));
}
document.querySelectorAll(".copy").forEach(b => b.addEventListener("click", e => {
  e.stopPropagation();  // don't expand or collapse the row
  copyText(b.dataset.copy).then(() => {
    b.classList.add("done");
    setTimeout(() => b.classList.remove("done"), 1500);
  }, () => { b.title = "Copying failed"; });
}));
