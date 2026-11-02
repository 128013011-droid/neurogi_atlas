const $ = s => document.querySelector(s);
const input = $('#q'), form = $('#searchForm'), results = $('#results'), summary = $('#summary');

let state = { table: '', page: 1 };
let controller = null;
let view = 'cards';

/* ---------- helpers ---------- */
const ACCENTS = [
  [/brain|mri|imaging/i, '#8b7bff'],
  [/clinical|symptom|patient/i, '#ffb454'],
  [/gene|expression|microb/i, '#5be584'],
  [/evidence|link/i, '#3de0c5'],
  [/image/i, '#ff7ac6'],
  [/stud/i, '#5aa9ff'],
  [/sample/i, '#ff8a5b']
];
const accent = t => (ACCENTS.find(([re]) => re.test(t)) || [0, '#9aa7c7'])[1];
const label  = t => t.replace(/_/g, ' ');

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function highlight(text, words) {
  const s = String(text);
  if (!words.length) return esc(s);
  const re = new RegExp('(' + words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'gi');
  return s.split(re).map((p, i) => (i % 2 ? '<mark>' + esc(p) + '</mark>' : esc(p))).join('');
}

// Shorten long decimals so cards stay readable (full value stays in the tooltip)
function fmt(s) {
  if (/^-?\d+\.\d{5,}$/.test(s)) {
    const n = Number(s);
    return Math.abs(n) >= 1000
      ? n.toLocaleString(undefined, { maximumFractionDigits: 2 })
      : String(parseFloat(n.toPrecision(5)));
  }
  return s;
}

function valueHTML(v, words) {
  if (v === null || v === undefined || v === '') return '<span class="na">not available</span>';
  const s = String(v);
  if (/^[^\s]+\.(png|jpe?g|gif|webp)$/i.test(s)) {
    const src = /^https?:\/\//i.test(s) ? s : 'images/' + s;
    return '<img loading="lazy" src="' + esc(src) + '" alt="' + esc(s) + '" onerror="this.replaceWith(document.createTextNode(this.alt))">';
  }
  if (/^https?:\/\/\S+$/i.test(s)) return '<a href="' + esc(s) + '" target="_blank" rel="noopener">open source ↗</a>';
  return '<span title="' + esc(s) + '">' + highlight(fmt(s), words) + '</span>';
}

function pickTitle(cols) {
  const prefer = /^(subject|participant_id|participant|name|title|gene|gene_symbol|symbol|sample_id|study_id|study)$/i;
  return cols.find(c => prefer.test(c)) || cols.find(c => !/^id$/i.test(c)) || cols[0];
}
const pickBadge = (cols, title) => cols.find(c => c !== title && /^(group|category|disease|condition|type|modality)$/i.test(c));

/* ---------- search ---------- */
function startSearch() { state = { table: '', page: 1 }; run(); }

form.addEventListener('submit', e => { e.preventDefault(); startSearch(); });   // Enter or Search button only

async function run() {
  if (controller) controller.abort();
  if (!input.value.trim() && !state.table) {          // empty box = nothing shown
    results.innerHTML = ''; summary.innerHTML = '';
    document.body.classList.remove('active');
    form.classList.remove('loading');
    return;
  }
  const ctrl = controller = new AbortController();
  form.classList.add('loading');
  const params = new URLSearchParams({ q: input.value.trim(), table: state.table, page: state.page });
  try {
    const res  = await fetch('api.php?' + params.toString(), { signal: ctrl.signal });
    const raw  = await res.text();
    let data;
    try { data = JSON.parse(raw); }
    catch (e) { throw new Error('The server did not return valid data. It said: ' + raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 300)); }
    if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
    render(data);
  } catch (err) {
    if (err.name === 'AbortError') return;
    document.body.classList.add('active');
    summary.innerHTML = '';
    results.innerHTML = '<p class="error">' + esc(err.message) + '</p>';
  } finally {
    if (ctrl === controller) form.classList.remove('loading');
  }
}

/* ---------- display ---------- */
function cardHTML(g, row, idx, words) {
  const title = pickTitle(g.columns);
  const badge = pickBadge(g.columns, title);
  const fields = g.columns.filter(c => c !== title && c !== badge && !/^id$/i.test(c))
                          .map(c => [c, row[c]])
                          .sort((a, b) => (a[1] == null || a[1] === '') - (b[1] == null || b[1] === ''));  // filled first
  const extra = fields.length - 8;
  return '<article class="rec" style="--i:' + idx + '">' +
    '<header><h3>' + valueHTML(row[title], words) + '</h3>' +
    (badge && row[badge] ? '<span class="tag">' + highlight(row[badge], words) + '</span>' : '') + '</header>' +
    '<dl>' + fields.map(([c, v]) => '<div><dt title="' + esc(c) + '">' + esc(label(c)) + '</dt><dd>' + valueHTML(v, words) + '</dd></div>').join('') + '</dl>' +
    (extra > 0 ? '<button type="button" class="more" data-label="+ ' + extra + ' more fields">+ ' + extra + ' more fields</button>' : '') +
    '</article>';
}

function render(data) {
  const { results: groups, words, single, page, limit } = data;
  document.body.classList.add('active');
  document.body.classList.toggle('view-table', view === 'table');

  const skippedNote = (data.skipped && data.skipped.length)
    ? '<p class="error">Some tables could not be searched: ' + esc(data.skipped.join(' | ')) + '</p>' : '';

  if (!groups.length) {
    summary.innerHTML = skippedNote;
    results.innerHTML = '<div class="empty"><div class="ring"></div><h3>No signal found</h3><p>Nothing matched “' + esc(data.query) + '”. Try another word or a shorter term.</p></div>';
    return;
  }

  const total = groups.reduce((n, g) => n + g.total, 0);
  const left = single
    ? '<button type="button" class="btn" id="backBtn">← All tables</button>'
    : '<div class="stat"><b>' + total + '</b> matching records in <b>' + groups.length + '</b> table' + (groups.length > 1 ? 's' : '') + ' for “' + esc(data.query) + '”</div>';
  const jump = single ? '' : '<div class="jump">' + groups.map(g =>
    '<button type="button" data-jump="g-' + esc(g.table) + '" style="--accent:' + accent(g.table) + '"><i></i>' + esc(label(g.table)) + '<em>' + g.total + '</em></button>').join('') + '</div>';
  const toggle = '<div class="toggle"><button type="button" data-view="cards" class="' + (view === 'cards' ? 'on' : '') + '">Cards</button>' +
                 '<button type="button" data-view="table" class="' + (view === 'table' ? 'on' : '') + '">Table</button></div>';
  summary.innerHTML = skippedNote + '<div class="bar">' + left + jump + toggle + '</div>';

  results.innerHTML = groups.map(g => {
    const cards = g.rows.map((r, i) => cardHTML(g, r, i, words)).join('');
    const thead = '<tr>' + g.columns.map(c => '<th>' + esc(label(c)) + '</th>').join('') + '</tr>';
    const tbody = g.rows.map(r => '<tr>' + g.columns.map(c => '<td>' + valueHTML(r[c], words) + '</td>').join('') + '</tr>').join('');
    let action = '', pager = '';
    if (single) {
      const pages = Math.ceil(g.total / limit);
      pager = '<div class="pager"><button type="button" class="btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>← Prev</button>' +
              '<span>Page ' + page + ' of ' + pages + '</span>' +
              '<button type="button" class="btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next →</button></div>';
    } else if (g.total > g.rows.length) {
      action = '<button type="button" class="btn" data-table="' + esc(g.table) + '">View all ' + g.total + ' →</button>';
    }
    return '<section class="group" id="g-' + esc(g.table) + '" style="--accent:' + accent(g.table) + '">' +
      '<div class="group-head"><h2>' + esc(label(g.table)) + '<span class="count">' + g.total + '</span></h2>' + action + '</div>' +
      '<div class="cards">' + cards + '</div>' +
      '<div class="table-wrap"><table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>' + pager + '</section>';
  }).join('');
}

/* ---------- clicks ---------- */
document.addEventListener('click', e => {
  const t = e.target.closest('button');
  if (!t) return;
  if (t.dataset.q !== undefined) { input.value = t.dataset.q; startSearch(); }
  else if (t.dataset.table) { state = { table: t.dataset.table, page: 1 }; run(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  else if (t.dataset.page) { state.page = parseInt(t.dataset.page, 10); run(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  else if (t.dataset.view) {
    view = t.dataset.view;
    document.body.classList.toggle('view-table', view === 'table');
    document.querySelectorAll('.toggle button').forEach(b => b.classList.toggle('on', b.dataset.view === view));
  }
  else if (t.dataset.jump) { const el = document.getElementById(t.dataset.jump); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  else if (t.classList.contains('more')) {
    const card = t.closest('.rec');
    card.classList.toggle('open');
    t.textContent = card.classList.contains('open') ? 'Show less' : t.dataset.label;
  }
  else if (t.id === 'backBtn') { startSearch(); }
});

/* press "/" anywhere to jump to the search box */
document.addEventListener('keydown', e => {
  if (e.key === '/' && document.activeElement !== input) { e.preventDefault(); input.focus(); }
});
