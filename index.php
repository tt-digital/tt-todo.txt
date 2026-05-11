<?php
define('TODO_FILE', __DIR__ . '/todo.txt');
define('DONE_FILE', __DIR__ . '/done.txt');

function readLines(string $file): array {
    if (!file_exists($file)) return [];
    return file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
}

function writeLines(string $file, array $lines): void {
    $lines   = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    $content = $lines ? implode("\n", $lines) . "\n" : '';
    $fp = fopen($file, 'c');
    if (!$fp) throw new RuntimeException("Cannot write: $file");
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $content);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function parseLine(string $line, int $id): array {
    $t = [
        'id' => $id, 'completed' => false, 'completion_date' => null,
        'priority' => null, 'creation_date' => null, 'text' => $line,
        'projects' => [], 'contexts' => [], 'due' => null,
    ];
    $rest = $line;

    if (preg_match('/^x (\d{4}-\d{2}-\d{2}) /', $rest, $m)) {
        $t['completed']       = true;
        $t['completion_date'] = $m[1];
        $rest = substr($rest, strlen($m[0]));
    } elseif (str_starts_with($rest, 'x ')) {
        $t['completed'] = true;
        $rest = substr($rest, 2);
    }

    if (!$t['completed'] && preg_match('/^\(([A-Z])\) /', $rest, $m)) {
        $t['priority'] = $m[1];
        $rest = substr($rest, strlen($m[0]));
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2}) /', $rest, $m)) {
        $t['creation_date'] = $m[1];
        $rest = substr($rest, strlen($m[0]));
    }

    $t['text'] = $rest;
    preg_match_all('/(?<!\S)\+(\S+)/', $rest, $pm);
    $t['projects'] = $pm[1];
    preg_match_all('/(?<!\S)@(\S+)/', $rest, $cm);
    $t['contexts'] = $cm[1];
    if (preg_match('/\bdue:(\d{4}-\d{2}-\d{2})\b/', $rest, $dm)) {
        $t['due'] = $dm[1];
    }

    return $t;
}

function parseLines(string $file): array {
    $lines = readLines($file);
    return array_map(fn($line, $i) => parseLine($line, $i), $lines, array_keys($lines));
}

function jsonOk(mixed ...$extra): void {
    echo json_encode(array_merge(['success' => true], $extra[0] ?? []));
}

function jsonErr(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input'), true) ?? [];

    try {
        switch ($_GET['action']) {

            case 'list':
                $tasks = parseLines(TODO_FILE);
                usort($tasks, function($a, $b) {
                    if ($a['completed'] !== $b['completed']) return $a['completed'] ? 1 : -1;
                    return strcmp($a['priority'] ?? 'ZZZ', $b['priority'] ?? 'ZZZ');
                });
                echo json_encode(['success' => true, 'tasks' => $tasks, 'done_file_count' => count(readLines(DONE_FILE))]);
                break;

            case 'add':
                $text = trim($in['text'] ?? '');
                if ($text === '') { jsonErr('Task text is empty'); break; }
                $date = date('Y-m-d');
                $line = preg_match('/^(\([A-Z]\) )/', $text, $m)
                    ? $m[1] . $date . ' ' . substr($text, strlen($m[1]))
                    : "$date $text";
                $lines   = readLines(TODO_FILE);
                $lines[] = $line;
                writeLines(TODO_FILE, $lines);
                jsonOk();
                break;

            case 'complete':
                $id    = (int)($in['id'] ?? -1);
                $lines = readLines(TODO_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                $t = parseLine($lines[$id], $id);
                if (!$t['completed']) {
                    $lines[$id] = 'x ' . date('Y-m-d') . ' ' . ($t['creation_date'] ? $t['creation_date'] . ' ' : '') . $t['text'];
                    writeLines(TODO_FILE, $lines);
                }
                jsonOk();
                break;

            case 'uncomplete':
                $id    = (int)($in['id'] ?? -1);
                $lines = readLines(TODO_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                $t = parseLine($lines[$id], $id);
                if ($t['completed']) {
                    $lines[$id] = ($t['creation_date'] ? $t['creation_date'] . ' ' : '') . $t['text'];
                    writeLines(TODO_FILE, $lines);
                }
                jsonOk();
                break;

            case 'update':
                $id      = (int)($in['id'] ?? -1);
                $newText = trim($in['text'] ?? '');
                if ($newText === '') { jsonErr('Task text is empty'); break; }
                $lines = readLines(TODO_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                $t       = parseLine($lines[$id], $id);
                $newLine = '';
                if ($t['completed']) {
                    $newLine .= 'x ';
                    if ($t['completion_date']) $newLine .= $t['completion_date'] . ' ';
                }
                if (!$t['completed'] && preg_match('/^(\([A-Z]\) )/', $newText, $m)) {
                    $newLine .= $m[1];
                    $body = substr($newText, strlen($m[1]));
                } else {
                    $body = $newText;
                }
                if ($t['creation_date']) $newLine .= $t['creation_date'] . ' ';
                $lines[$id] = $newLine . $body;
                writeLines(TODO_FILE, $lines);
                jsonOk();
                break;

            case 'delete':
                $id    = (int)($in['id'] ?? -1);
                $lines = readLines(TODO_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                array_splice($lines, $id, 1);
                writeLines(TODO_FILE, $lines);
                jsonOk();
                break;

            case 'archive':
                $lines = readLines(TODO_FILE);
                $done = $remaining = [];
                foreach ($lines as $line) {
                    (parseLine($line, 0)['completed'] ? $done : $remaining)[] = $line;
                }
                writeLines(TODO_FILE, $remaining);
                writeLines(DONE_FILE, array_merge(readLines(DONE_FILE), $done));
                echo json_encode(['success' => true, 'archived' => count($done)]);
                break;

            case 'done-list':
                echo json_encode(['success' => true, 'tasks' => parseLines(DONE_FILE)]);
                break;

            case 'done-delete':
                $id    = (int)($in['id'] ?? -1);
                $lines = readLines(DONE_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                array_splice($lines, $id, 1);
                writeLines(DONE_FILE, $lines);
                jsonOk();
                break;

            case 'done-restore':
                $id    = (int)($in['id'] ?? -1);
                $lines = readLines(DONE_FILE);
                if (!array_key_exists($id, $lines)) { jsonErr('Task not found'); break; }
                $t         = parseLine($lines[$id], $id);
                $restored  = ($t['creation_date'] ? $t['creation_date'] . ' ' : '') . $t['text'];
                $todoLines = readLines(TODO_FILE);
                $todoLines[] = $restored;
                writeLines(TODO_FILE, $todoLines);
                array_splice($lines, $id, 1);
                writeLines(DONE_FILE, $lines);
                jsonOk();
                break;

            default:
                jsonErr('Unknown action');
        }
    } catch (Exception $e) {
        http_response_code(500);
        jsonErr($e->getMessage());
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>todo.txt</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
  <script>
    (function(){
      var t = localStorage.getItem('theme');
      if (!t) { var h = new Date().getHours(); t = (h >= 6 && h < 20) ? 'light' : 'dark'; }
      if (t === 'dark') document.documentElement.classList.add('dark');
    })();
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['"DM Sans"', 'ui-sans-serif', 'system-ui'] } } } }
  </script>
  <style>
    body { -webkit-tap-highlight-color: transparent; }
    input, button, select { font-family: inherit; }

    html.dark body                         { background-color: #0f172a; color: #e2e8f0; }
    html.dark .bg-white                    { background-color: #1e293b; }
    html.dark .bg-slate-50                 { background-color: #0f172a; }
    html.dark .border-gray-100             { border-color: #334155; }
    html.dark .border-gray-200             { border-color: #475569; }
    html.dark .text-gray-900               { color: #f1f5f9; }
    html.dark .text-gray-800               { color: #e2e8f0; }
    html.dark .text-gray-700               { color: #cbd5e1; }
    html.dark .text-gray-600               { color: #94a3b8; }
    html.dark .text-gray-500               { color: #64748b; }
    html.dark .text-gray-400               { color: #475569; }
    html.dark .text-gray-300               { color: #334155; }
    html.dark .bg-gray-100                 { background-color: #334155; }
    html.dark .hover\:bg-gray-100:hover    { background-color: #334155; }
    html.dark .hover\:bg-gray-50:hover     { background-color: #2d3e52; }
    html.dark .hover\:text-gray-700:hover  { color: #e2e8f0; }
    html.dark input, html.dark textarea    { background-color: #334155; color: #e2e8f0; border-color: #475569; }
    html.dark input::placeholder           { color: #64748b; }
    html.dark .bg-indigo-50                { background-color: #1e1b4b; }
    html.dark .bg-indigo-100               { background-color: #312e81; }
    html.dark .hover\:bg-indigo-50:hover   { background-color: #1e1b4b; }
    html.dark .text-indigo-700             { color: #818cf8; }
    html.dark .text-indigo-600             { color: #818cf8; }
    html.dark .text-indigo-400             { color: #6366f1; }
    html.dark .bg-emerald-50               { background-color: #052e16; }
    html.dark .hover\:bg-emerald-100:hover { background-color: #14532d; }
    html.dark .text-emerald-700            { color: #34d399; }
    html.dark .bg-green-50                 { background-color: #052e16; }
    html.dark .hover\:bg-green-50:hover    { background-color: #14532d; }
    html.dark .border-green-200            { border-color: #166534; }
    html.dark .text-green-600              { color: #4ade80; }
    html.dark .bg-red-50                   { background-color: #450a0a; }
    html.dark .bg-red-100                  { background-color: #450a0a; }
    html.dark .hover\:bg-red-50:hover      { background-color: #7f1d1d; }
    html.dark .border-red-100              { border-color: #7f1d1d; }
    html.dark .text-red-400                { color: #f87171; }
    html.dark .text-red-500                { color: #f87171; }
    html.dark .text-red-600                { color: #f87171; }
    html.dark .bg-amber-100                { background-color: #422006; }
    html.dark .text-amber-600              { color: #fbbf24; }
    html.dark .hover\:bg-amber-50:hover    { background-color: #422006; }
    html.dark .bg-sky-100                  { background-color: #082f49; }
    html.dark .text-sky-600                { color: #38bdf8; }
    html.dark .shadow-sm                   { box-shadow: 0 1px 2px 0 rgba(0,0,0,0.5); }
    html.dark .shadow-2xl                  { box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8); }
    html.dark .shadow-xl                   { box-shadow: 0 20px 25px -5px rgba(0,0,0,0.6); }
  </style>
</head>
<body class="bg-slate-50 min-h-screen text-gray-800 antialiased">

<!-- ── Desktop sidebar ───────────────────────────────────────────── -->
<aside id="sidebar" class="hidden md:flex flex-col fixed inset-y-0 left-0 w-56 bg-white border-r border-gray-100 p-4 z-10 overflow-y-auto">
  <div class="flex items-center justify-between mb-5">
    <div class="text-[10px] font-bold uppercase tracking-[.15em] text-gray-400">todo.txt</div>
    <button id="theme-btn" class="w-7 h-7 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-400 transition-colors text-base" title="Toggle theme">🌙</button>
  </div>

  <a id="filter-all" href="#" class="flex items-center justify-between px-2.5 py-2 rounded-lg text-sm font-medium text-indigo-700 bg-indigo-50 mb-0.5 transition-colors no-underline">
    All tasks
    <span id="active-count" class="text-xs bg-indigo-100 text-indigo-600 rounded-full px-2 py-0.5 tabular-nums">0</span>
  </a>
  <a id="view-done" href="#" class="flex items-center justify-between px-2.5 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-50 mb-0.5 transition-colors no-underline">
    Archived
    <span id="done-file-count" class="text-xs bg-gray-100 text-gray-500 rounded-full px-2 py-0.5 tabular-nums">0</span>
  </a>

  <div id="sb-projects"></div>
  <div id="sb-contexts"></div>

  <div class="mt-auto pt-4 border-t border-gray-100">
    <button id="archive-btn" disabled
      class="w-full text-left px-2.5 py-2 rounded-lg text-sm text-amber-600 hover:bg-amber-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
      Archive done&nbsp;(<span id="done-count">0</span>)
    </button>
  </div>
</aside>

<!-- ── Main ──────────────────────────────────────────────────────── -->
<main class="md:ml-56 p-4 pb-12">
<div class="max-w-2xl mx-auto md:mx-0">

  <!-- Mobile tabs -->
  <div class="md:hidden flex rounded-2xl bg-white shadow-sm overflow-hidden mb-4">
    <button id="mob-tab-active"
      class="flex-1 py-3 text-sm font-semibold text-indigo-700 border-b-2 border-indigo-600 transition-colors">
      Active&nbsp;<span id="mob-active-count" class="text-xs bg-indigo-100 text-indigo-600 rounded-full px-1.5 py-0.5">0</span>
    </button>
    <button id="mob-tab-done"
      class="flex-1 py-3 text-sm font-medium text-gray-400 border-b-2 border-transparent transition-colors">
      Archived&nbsp;<span id="mob-done-count" class="text-xs bg-gray-100 text-gray-400 rounded-full px-1.5 py-0.5">0</span>
    </button>
  </div>

  <!-- Mobile filter chips -->
  <div id="mob-filter-chips" class="md:hidden flex gap-1.5 overflow-x-auto pb-0.5 mb-3" style="scrollbar-width:none;-webkit-overflow-scrolling:touch"></div>

  <!-- Filter indicator -->
  <div id="filter-bar" class="hidden items-center gap-2 bg-indigo-50 border border-indigo-100 text-indigo-700 rounded-xl px-4 py-2.5 mb-4 text-sm">
    Filtered:&nbsp;<strong id="filter-label"></strong>
    <button id="clear-filter" class="ml-auto text-indigo-400 hover:text-indigo-700 text-xl leading-none w-6 h-6 flex items-center justify-center">&times;</button>
  </div>

  <!-- Add task -->
  <div id="add-panel" class="bg-white rounded-2xl shadow-sm p-4 mb-4">
    <form id="add-form" class="flex gap-2">
      <input id="new-task" type="text" autocomplete="off"
        class="flex-1 text-sm rounded-xl border border-gray-200 px-3.5 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 placeholder:text-gray-300 min-w-0"
        placeholder="Add task… e.g. (A) Buy milk +Shopping @errands due:2026-05-15">
      <button type="submit"
        class="bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-all shrink-0">
        Add
      </button>
    </form>
    <p class="text-[11px] text-gray-400 mt-2.5 leading-relaxed">
      <kbd class="bg-gray-100 px-1.5 py-0.5 rounded text-[10px] font-mono">n</kbd> focus
      &nbsp;·&nbsp; <code class="text-gray-400">(A)</code>&thinsp;<code class="text-gray-400">(B)</code>&thinsp;<code class="text-gray-400">(C)</code> priority
      &nbsp;·&nbsp; <code class="text-gray-400">+Project</code>
      &nbsp;·&nbsp; <code class="text-gray-400">@context</code>
      &nbsp;·&nbsp; <code class="text-gray-400">due:YYYY-MM-DD</code>
    </p>
  </div>

  <!-- Task list -->
  <div id="task-list" class="space-y-1.5">
    <div class="text-center text-gray-400 py-16 text-sm">Loading…</div>
  </div>

</div>
</main>

<!-- ── Footer ─────────────────────────────────────────────────────── -->
<footer class="md:ml-56 px-4 py-3 text-center text-[11px] text-gray-300">
  &copy; 2026 tt-digital.de
</footer>

<!-- ── Mobile theme toggle ────────────────────────────────────────── -->
<button id="theme-btn-mob" class="md:hidden fixed top-3 right-3 z-20 w-9 h-9 rounded-full bg-white shadow-sm border border-gray-100 flex items-center justify-center text-base transition-colors" title="Toggle theme">🌙</button>

<!-- ── Edit modal ─────────────────────────────────────────────────── -->
<div id="edit-modal" class="hidden fixed inset-0 bg-black/40 z-50 items-end sm:items-center justify-center p-0 sm:p-4">
  <div id="edit-modal-card" class="bg-white w-full sm:max-w-lg rounded-t-3xl sm:rounded-2xl shadow-2xl">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
      <h2 class="font-semibold text-gray-900">Edit task</h2>
      <button id="modal-close" class="text-gray-400 hover:text-gray-700 text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors">&times;</button>
    </div>
    <div class="px-5 py-4">
      <input id="edit-input" type="text" autocomplete="off"
        class="w-full text-sm rounded-xl border border-gray-200 px-3.5 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400">
      <p class="text-[11px] text-gray-400 mt-2">
        <code>(A)</code> priority &nbsp;·&nbsp; <code>+Project</code> &nbsp;·&nbsp; <code>@context</code> &nbsp;·&nbsp; <code>due:YYYY-MM-DD</code>
      </p>
    </div>
    <div class="px-5 pb-6 sm:pb-4 flex gap-2 justify-end">
      <button id="modal-cancel"
        class="px-4 py-2.5 text-sm rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 transition-colors font-medium">Cancel</button>
      <button id="edit-save"
        class="px-4 py-2.5 text-sm rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold transition-colors">Save</button>
    </div>
  </div>
</div>

<!-- ── Toasts ─────────────────────────────────────────────────────── -->
<div id="toast-wrap" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-xs w-full pointer-events-none"></div>

<script>
const TODAY = new Date().toISOString().slice(0, 10);
const SOON  = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);

const $ = id => document.getElementById(id);

// ── Theme ────────────────────────────────────────────────────────
function applyTheme(theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark');
  const icon = theme === 'dark' ? '☀️' : '🌙';
  $('theme-btn').textContent     = icon;
  $('theme-btn-mob').textContent = icon;
  localStorage.setItem('theme', theme);
}
function toggleTheme() {
  applyTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
}
$('theme-btn').addEventListener('click', toggleTheme);
$('theme-btn-mob').addEventListener('click', toggleTheme);
applyTheme(document.documentElement.classList.contains('dark') ? 'dark' : 'light');

// ── Constants ────────────────────────────────────────────────────
const SB_ON  = 'flex items-center justify-between px-2.5 py-2 rounded-lg text-sm font-medium text-indigo-700 bg-indigo-50 mb-0.5 transition-colors no-underline';
const SB_OFF = 'flex items-center justify-between px-2.5 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-50 mb-0.5 transition-colors no-underline';

const PRI_COLOR = { A: '#ef4444', B: '#f59e0b', C: '#38bdf8' };
const PRI_BADGE = { A: 'bg-red-100 text-red-600', B: 'bg-amber-100 text-amber-600', C: 'bg-sky-100 text-sky-600' };
const DUE_CLS   = { overdue: 'bg-red-100 text-red-600', today: 'bg-amber-100 text-amber-600', soon: 'bg-sky-100 text-sky-600', future: 'bg-gray-100 text-gray-500' };

const state = { view: 'active', filter: null, filterType: null, tasks: [], doneTasks: [] };

// ── Utilities ────────────────────────────────────────────────────
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function apiFetch(action, body) {
  const r = await fetch('?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body ?? {}),
  });
  if (!r.ok) throw new Error('Server error ' + r.status);
  return r.json();
}

function toast(msg, type) {
  const bg = { success: 'bg-emerald-600', danger: 'bg-red-500', info: 'bg-gray-800' }[type] || 'bg-gray-800';
  const el = document.createElement('div');
  el.className = `pointer-events-auto ${bg} text-white rounded-2xl px-4 py-3 shadow-xl flex items-start gap-3 text-sm`;
  el.innerHTML = `<span class="flex-1 pt-px">${escHtml(msg)}</span><button class="opacity-60 hover:opacity-100 text-lg leading-none shrink-0 mt-px">&times;</button>`;
  el.querySelector('button').onclick = () => el.remove();
  $('toast-wrap').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

function renderTags(text, linked) {
  return escHtml(text)
    .replace(/\bdue:\d{4}-\d{2}-\d{2}\b/g, '')
    .replace(/(?<!\S)\+(\S+)/g, (_, p) => linked
      ? `<a href="#" class="inline-block align-middle text-[11px] bg-emerald-50 text-emerald-700 hover:bg-emerald-100 rounded px-1.5 py-0.5 mr-0.5 tag-link" data-ft="project" data-fv="${p}">+${p}</a>`
      : `<span class="inline-block align-middle text-[11px] bg-gray-100 text-gray-400 rounded px-1.5 py-0.5 mr-0.5">+${p}</span>`)
    .replace(/(?<!\S)@(\S+)/g, (_, c) => linked
      ? `<a href="#" class="inline-block align-middle text-[11px] bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded px-1.5 py-0.5 mr-0.5 tag-link" data-ft="context" data-fv="${c}">@${c}</a>`
      : `<span class="inline-block align-middle text-[11px] bg-gray-100 text-gray-400 rounded px-1.5 py-0.5 mr-0.5">@${c}</span>`);
}

// ── Modal ────────────────────────────────────────────────────────
const editModalEl   = $('edit-modal');
const editModalCard = $('edit-modal-card');
const editInputEl   = $('edit-input');
let editingId = null;

function openEditModal(id, val) {
  editingId = id;
  editInputEl.value = val;
  editModalEl.classList.remove('hidden');
  editModalEl.classList.add('flex');
  document.body.style.overflow = 'hidden';
  setTimeout(() => { editInputEl.focus(); editInputEl.setSelectionRange(val.length, val.length); }, 50);
}

function closeEditModal() {
  editModalEl.classList.add('hidden');
  editModalEl.classList.remove('flex');
  document.body.style.overflow = '';
  editingId = null;
}

editModalEl.addEventListener('click', closeEditModal);
editModalCard.addEventListener('click', e => e.stopPropagation());
$('modal-close').addEventListener('click', closeEditModal);
$('modal-cancel').addEventListener('click', closeEditModal);

$('edit-save').addEventListener('click', async function() {
  const text = editInputEl.value.trim();
  if (!text || editingId === null) return;
  this.disabled = true;
  try {
    const d = await apiFetch('update', { id: editingId, text });
    if (d.success) { closeEditModal(); await loadTasks(); }
    else toast(d.message || 'Save failed', 'danger');
  } catch (err) { toast(err.message, 'danger'); }
  finally { this.disabled = false; }
});

editInputEl.addEventListener('keydown', e => { if (e.key === 'Enter') $('edit-save').click(); });

// ── Data ─────────────────────────────────────────────────────────
async function loadTasks() {
  try {
    const r    = await fetch('?action=list');
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch {
      $('task-list').innerHTML =
        '<div class="text-center text-red-500 py-8 text-sm p-4">JSON error — server returned:<br><pre class="mt-2 text-left text-xs bg-red-50 rounded p-2 overflow-auto">' + escHtml(text.slice(0, 500)) + '</pre></div>';
      return;
    }
    if (d.success) {
      state.tasks = d.tasks;
      const dfc = d.done_file_count || 0;
      $('done-file-count').textContent = dfc;
      $('mob-done-count').textContent  = dfc;
      render();
    } else {
      $('task-list').innerHTML = '<div class="text-center text-red-500 py-8 text-sm">' + escHtml(d.message || 'Load failed') + '</div>';
    }
  } catch (err) {
    $('task-list').innerHTML = '<div class="text-center text-red-500 py-8 text-sm">Fetch error: ' + escHtml(err.message) + '</div>';
  }
}

async function loadDone() {
  try {
    const r = await fetch('?action=done-list');
    const d = await r.json();
    if (d.success) { state.doneTasks = d.tasks; render(); }
    else toast(d.message || 'Load failed', 'danger');
  } catch (err) { toast(err.message, 'danger'); }
}

// ── Render ───────────────────────────────────────────────────────
function render() {
  if (state.view === 'done') { renderDoneSidebar(); renderDoneList(); }
  else                       { renderSidebar();     renderList();     }
}

function updateMobileTabs() {
  const on  = 'flex-1 py-3 text-sm font-semibold text-indigo-700 border-b-2 border-indigo-600 transition-colors';
  const off = 'flex-1 py-3 text-sm font-medium  text-gray-400   border-b-2 border-transparent transition-colors';
  $('mob-tab-active').className = state.view === 'active' ? on : off;
  $('mob-tab-done').className   = state.view === 'done'   ? on : off;
}

function renderSidebar() {
  let doneCount = 0, activeN = 0;
  const projects = new Set(), contexts = new Set();
  for (const t of state.tasks) {
    t.completed ? doneCount++ : activeN++;
    t.projects.forEach(p => projects.add(p));
    t.contexts.forEach(c => contexts.add(c));
  }
  const sortedProjects = [...projects].sort();
  const sortedContexts = [...contexts].sort();

  $('active-count').textContent     = activeN;
  $('mob-active-count').textContent = activeN;
  $('done-count').textContent       = doneCount;
  $('archive-btn').disabled         = doneCount === 0;
  $('filter-all').className         = state.filter === null ? SB_ON : SB_OFF;
  $('view-done').className          = SB_OFF;
  updateMobileTabs();

  $('sb-projects').innerHTML = sortedProjects.length
    ? '<p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-5 mb-1 px-1">Projects</p>' +
      sortedProjects.map(p => {
        const safe = escHtml(p);
        return '<a class="' + (state.filterType === 'project' && state.filter === p ? SB_ON : SB_OFF) + '" href="#" data-ft="project" data-fv="' + safe + '">+' + safe + '</a>';
      }).join('')
    : '';

  $('sb-contexts').innerHTML = sortedContexts.length
    ? '<p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-4 mb-1 px-1">Contexts</p>' +
      sortedContexts.map(c => {
        const safe = escHtml(c);
        return '<a class="' + (state.filterType === 'context' && state.filter === c ? SB_ON : SB_OFF) + '" href="#" data-ft="context" data-fv="' + safe + '">@' + safe + '</a>';
      }).join('')
    : '';

  const chipEl  = $('mob-filter-chips');
  const base    = 'text-xs px-2.5 py-1 rounded-full whitespace-nowrap transition-colors shrink-0 ';
  let chips = '<button class="' + base + (state.filter === null ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600') + '" data-ft="" data-fv="">All</button>';
  sortedProjects.forEach(p => {
    const on = state.filterType === 'project' && state.filter === p;
    chips += '<button class="' + base + (on ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700') + '" data-ft="project" data-fv="' + escHtml(p) + '">+' + escHtml(p) + '</button>';
  });
  sortedContexts.forEach(c => {
    const on = state.filterType === 'context' && state.filter === c;
    chips += '<button class="' + base + (on ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-600') + '" data-ft="context" data-fv="' + escHtml(c) + '">@' + escHtml(c) + '</button>';
  });
  chipEl.innerHTML = chips;
  chipEl.classList.toggle('hidden', sortedProjects.length === 0 && sortedContexts.length === 0);
}

function renderDoneSidebar() {
  $('filter-all').className      = SB_OFF;
  $('view-done').className       = SB_ON;
  $('sb-projects').innerHTML     = '';
  $('sb-contexts').innerHTML     = '';
  $('mob-filter-chips').classList.add('hidden');
  updateMobileTabs();
}

function renderList() {
  $('add-panel').classList.remove('hidden');
  const hasFilter = state.filter !== null;
  const bar = $('filter-bar');
  bar.classList.toggle('hidden', !hasFilter);
  bar.classList.toggle('flex', hasFilter);
  if (hasFilter) $('filter-label').textContent = (state.filterType === 'project' ? '+' : '@') + state.filter;

  const visible = hasFilter
    ? state.tasks.filter(t => state.filterType === 'project' ? t.projects.includes(state.filter) : t.contexts.includes(state.filter))
    : state.tasks;

  $('task-list').innerHTML = visible.length
    ? visible.map(buildTaskHtml).join('')
    : '<div class="text-center text-gray-400 py-16 text-sm">' + (hasFilter ? 'No tasks match this filter.' : 'No tasks yet — add one above!') + '</div>';
}

function renderDoneList() {
  $('add-panel').classList.add('hidden');
  const bar = $('filter-bar');
  bar.classList.add('hidden');
  bar.classList.remove('flex');

  const tasks = state.doneTasks.slice().sort((a, b) => (b.completion_date || '').localeCompare(a.completion_date || ''));
  $('task-list').innerHTML = tasks.length
    ? tasks.map(buildDoneTaskHtml).join('')
    : '<div class="text-center text-gray-400 py-16 text-sm">No archived tasks yet.</div>';
}

// ── Task card builders ───────────────────────────────────────────
function buildTaskHtml(t) {
  const borderColor = PRI_COLOR[t.priority] || 'transparent';
  const priBadge    = t.priority
    ? '<span class="inline-block align-middle text-[10px] font-bold rounded px-1.5 py-0.5 mr-1.5 ' + (PRI_BADGE[t.priority] || 'bg-gray-100 text-gray-500') + '">' + escHtml(t.priority) + '</span>'
    : '';

  let dueBadge = '';
  if (t.due) {
    const dc = t.due < TODAY ? DUE_CLS.overdue : t.due === TODAY ? DUE_CLS.today : t.due <= SOON ? DUE_CLS.soon : DUE_CLS.future;
    const dl = t.due < TODAY ? 'Overdue: ' + t.due : 'Due: ' + t.due;
    dueBadge = '<span class="inline-block align-middle text-[10px] rounded px-1.5 py-0.5 ml-1 ' + dc + '">' + escHtml(dl) + '</span>';
  }

  const finishedBtn = t.completed
    ? '<button class="px-2.5 py-1 rounded-lg border border-gray-200 text-gray-400 hover:bg-gray-50 text-xs font-medium transition-colors" data-action="uncomplete" data-id="' + t.id + '">U</button>'
    : '<button class="px-2.5 py-1 rounded-lg border border-green-200 text-green-600 hover:bg-green-50 text-xs font-medium transition-colors" data-action="complete" data-id="' + t.id + '">F</button>';

  const editVal = escHtml((t.priority && !t.completed ? '(' + t.priority + ') ' : '') + t.text);
  const textCls = t.completed ? 'text-sm text-gray-400 line-through' : 'text-sm text-gray-800';

  return '<div class="bg-white rounded-xl shadow-sm px-3.5 py-2.5 flex gap-3 items-start border-l-4 ' + (t.completed ? 'opacity-60' : '') + '" style="border-left-color:' + borderColor + '">' +
    '<div class="flex-1 min-w-0">' +
      priBadge +
      '<span class="' + textCls + '">' + renderTags(t.text, true).trim() + '</span>' +
      dueBadge +
      (t.creation_date ? '<span class="text-[11px] text-gray-300 ml-2">' + escHtml(t.creation_date) + '</span>' : '') +
    '</div>' +
    '<div class="flex gap-1.5 shrink-0 items-center">' +
      '<button class="px-2.5 py-1 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 text-xs font-medium transition-colors" data-action="edit" data-id="' + t.id + '" data-val="' + editVal + '">E</button>' +
      finishedBtn +
      '<button class="px-2.5 py-1 rounded-lg border border-red-100 text-red-400 hover:bg-red-50 text-xs font-medium transition-colors" data-action="delete" data-id="' + t.id + '">D</button>' +
    '</div>' +
  '</div>';
}

function buildDoneTaskHtml(t) {
  const datePart = t.completion_date
    ? '<span class="text-[11px] text-gray-300 ml-2">done ' + escHtml(t.completion_date) + '</span>'
    : '';
  return '<div class="bg-white rounded-xl shadow-sm px-3.5 py-2.5 flex gap-3 items-start border-l-4 opacity-50" style="border-left-color:transparent">' +
    '<div class="flex-1 min-w-0">' +
      '<span class="text-sm text-gray-400 line-through">' + renderTags(t.text, false).trim() + '</span>' + datePart +
    '</div>' +
    '<div class="flex gap-1.5 shrink-0">' +
      '<button class="w-8 h-8 rounded-lg border border-green-200 text-green-600 hover:bg-green-50 flex items-center justify-center transition-colors" data-done-action="restore" data-id="' + t.id + '" title="Restore">↩</button>' +
      '<button class="w-8 h-8 rounded-lg border border-red-100 text-red-400 hover:bg-red-50 flex items-center justify-center transition-colors" data-done-action="delete" data-id="' + t.id + '" title="Delete permanently">×</button>' +
    '</div>' +
  '</div>';
}

// ── Actions ──────────────────────────────────────────────────────
function setFilter(type, val) {
  state.filterType = type;
  state.filter     = val;
  render();
}

$('task-list').addEventListener('click', async function(e) {
  const tag = e.target.closest('.tag-link');
  if (tag) { e.preventDefault(); setFilter(tag.dataset.ft, tag.dataset.fv); return; }

  const btn = e.target.closest('[data-action]');
  if (btn) {
    const action = btn.dataset.action;
    const id     = parseInt(btn.dataset.id, 10);
    if (action === 'edit') { openEditModal(id, btn.dataset.val); return; }
    if (action === 'delete' && !confirm('Delete this task?')) return;
    btn.disabled = true;
    try {
      const d = await apiFetch(action, { id });
      if (!d.success) toast(d.message || action + ' failed', 'danger');
      await loadTasks();
    } catch (err) { toast(err.message, 'danger'); btn.disabled = false; }
    return;
  }

  const doneBtn = e.target.closest('[data-done-action]');
  if (doneBtn) {
    const doneAction = doneBtn.dataset.doneAction;
    const doneId     = parseInt(doneBtn.dataset.id, 10);
    if (doneAction === 'delete' && !confirm('Permanently delete this archived task?')) return;
    doneBtn.disabled = true;
    try {
      const d = await apiFetch('done-' + doneAction, { id: doneId });
      if (!d.success) toast(d.message || doneAction + ' failed', 'danger');
      else if (doneAction === 'restore') toast('Task restored to todo list', 'success');
      await loadDone();
      const d2 = await apiFetch('list', null);
      if (d2.success) {
        state.tasks = d2.tasks;
        const dfc = d2.done_file_count || 0;
        $('done-file-count').textContent = dfc;
        $('mob-done-count').textContent  = dfc;
      }
    } catch (err) { toast(err.message, 'danger'); doneBtn.disabled = false; }
  }
});

$('sidebar').addEventListener('click', async function(e) {
  const link = e.target.closest('[data-ft]');
  if (link) { e.preventDefault(); setFilter(link.dataset.ft, link.dataset.fv); return; }
  if (e.target.closest('#filter-all')) {
    e.preventDefault(); state.view = 'active'; state.filter = null; state.filterType = null; render(); return;
  }
  if (e.target.closest('#view-done')) {
    e.preventDefault(); state.view = 'done'; state.filter = null; state.filterType = null; await loadDone();
  }
});

$('mob-filter-chips').addEventListener('click', function(e) {
  const btn = e.target.closest('[data-ft]');
  if (!btn) return;
  const ft = btn.dataset.ft, fv = btn.dataset.fv;
  if (!ft) { state.filter = null; state.filterType = null; }
  else { state.filterType = ft; state.filter = fv; }
  render();
});

$('mob-tab-active').addEventListener('click', () => { state.view = 'active'; state.filter = null; state.filterType = null; render(); });
$('mob-tab-done').addEventListener('click', async () => { state.view = 'done'; state.filter = null; state.filterType = null; await loadDone(); });

$('add-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const inp  = $('new-task');
  const text = inp.value.trim();
  if (!text) return;
  try {
    const d = await apiFetch('add', { text });
    if (d.success) { inp.value = ''; await loadTasks(); }
    else toast(d.message || 'Add failed', 'danger');
  } catch (err) { toast(err.message, 'danger'); }
});

$('clear-filter').addEventListener('click', () => setFilter(null, null));

$('archive-btn').addEventListener('click', async function() {
  const n = state.tasks.filter(t => t.completed).length;
  if (!n) return;
  if (!confirm('Move ' + n + ' completed task' + (n > 1 ? 's' : '') + ' to done.txt?')) return;
  try {
    const d = await apiFetch('archive', {});
    if (d.success) { toast('Archived ' + d.archived + ' task' + (d.archived !== 1 ? 's' : ''), 'success'); await loadTasks(); }
    else toast(d.message || 'Archive failed', 'danger');
  } catch (err) { toast(err.message, 'danger'); }
});

document.addEventListener('keydown', function(e) {
  const inInput = document.activeElement?.tagName === 'INPUT' || document.activeElement?.tagName === 'TEXTAREA';
  if (e.key === 'n' && !inInput) { e.preventDefault(); $('new-task').focus(); return; }
  if (e.key === 'Escape') {
    if (!editModalEl.classList.contains('hidden')) { closeEditModal(); return; }
    if (state.filter !== null) setFilter(null, null);
  }
});

loadTasks();
</script>
</body>
</html>
