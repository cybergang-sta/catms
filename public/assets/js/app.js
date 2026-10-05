import { extraView, failureText, handleClick, handleSubmit, homePath, install, isExtra, isPublic, mobileTabs, navGroups, profileExtras, publicHtml, roomAdminForm } from './manage.js?v=24';

const API = '/api/v1';

const NAV = [
  { href: '/today', id: 'today', label: 'Today' },
  { href: '/week', id: 'week', label: 'Week' },
  { href: '/term', id: 'term', label: 'Term' },
  { href: '/rooms', id: 'rooms', label: 'Rooms' },
  { href: '/alerts', id: 'alerts', label: 'Alerts' },
];

const DEMOS = [
  { role: 'Administrator', email: 'admin@utas.edu.gh', password: 'Admin@1234' },
  { role: 'Lecturer', email: 'lecturer@utas.edu.gh', password: 'Lecturer@1234' },
  { role: 'Student', email: 'student@utas.edu.gh', password: 'Student@1234' },
];

const ROLES = { admin: 'Administrator', lecturer: 'Lecturer', student: 'Student' };

const state = {
  cards: [],
  token: 0,
  lastFocus: null,
};

const root = document.getElementById('app');

class ApiError extends Error {
  constructor(message, status, code, details = null) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

function session() {
  try {
    return JSON.parse(localStorage.getItem('catms.session') || 'null');
  } catch {
    return null;
  }
}

function saveSession(patch) {
  const prev = session() || {};
  const next = {
    access_token: patch.access_token || prev.access_token,
    refresh_token: patch.refresh_token || prev.refresh_token,
    user: patch.user || prev.user,
    permissions: patch.permissions || prev.permissions || [],
    must_change_password: Object.prototype.hasOwnProperty.call(patch, 'must_change_password')
      ? patch.must_change_password
      : Boolean(prev.must_change_password),
  };
  localStorage.setItem('catms.session', JSON.stringify(next));
}

function clearSession() {
  const userId = session()?.user?.user_id;
  localStorage.removeItem('catms.session');
  if (userId == null) {
    return;
  }
  const prefix = `catms.cache.${userId}.`;
  for (let index = localStorage.length - 1; index >= 0; index -= 1) {
    const key = localStorage.key(index);
    if (key && key.startsWith(prefix)) {
      localStorage.removeItem(key);
    }
  }
}

function remember(userId, key, payload) {
  try {
    localStorage.setItem(`catms.cache.${userId}.${key}`, JSON.stringify({
      at: new Date().toISOString(),
      payload,
    }));
  } catch {
    /* A full disk should not block the screen that just loaded. */
  }
}

function recall(userId, key) {
  try {
    return JSON.parse(localStorage.getItem(`catms.cache.${userId}.${key}`) || 'null');
  } catch {
    return null;
  }
}

async function api(path, options = {}) {
  const run = async (token) => {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body !== undefined) {
      headers.set('Content-Type', 'application/json');
    }
    if (token) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    const response = await fetch(API + path, {
      method: options.method || 'GET',
      headers,
      cache: 'no-store',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
    const text = await response.text();
    let json = null;
    if (text) {
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
    }
    return { response, json };
  };

  let current = session();
  let result;
  try {
    result = await run(current?.access_token);
  } catch {
    throw new ApiError('You appear to be offline.', 0, 'OFFLINE');
  }

  if (result.response.status === 401 && current?.refresh_token && !path.startsWith('/auth/')) {
    const refreshed = await refresh(current.refresh_token);
    if (!refreshed) {
      clearSession();
      throw new ApiError('Your session ended. Sign in again.', 401, 'UNAUTHORIZED');
    }
    current = session();
    try {
      result = await run(current?.access_token);
    } catch {
      throw new ApiError('You appear to be offline.', 0, 'OFFLINE');
    }
  }

  if (!result.response.ok) {
    throw new ApiError(
      result.json?.error?.message || 'The request could not be completed.',
      result.response.status,
      result.json?.error?.code || 'ERROR',
      result.json?.error?.details || null,
    );
  }

  return { data: result.json?.data ?? null, meta: result.json?.meta || {} };
}

async function refresh(token) {
  try {
    const response = await fetch(`${API}/auth/refresh`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: token }),
    });
    if (!response.ok) {
      return false;
    }
    const json = await response.json();
    saveSession(json.data || {});
    return true;
  } catch {
    return false;
  }
}

async function load(path, cacheKey) {
  const userId = session()?.user?.user_id;
  try {
    const result = await api(path);
    if (userId != null && cacheKey) {
      remember(userId, cacheKey, result);
    }
    return { ...result, cachedAt: null };
  } catch (error) {
    const cached = userId != null && cacheKey ? recall(userId, cacheKey) : null;
    if (cached?.payload && (error.code === 'OFFLINE' || error.status === 0)) {
      return { ...cached.payload, cachedAt: cached.at };
    }
    throw error;
  }
}

function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function icon(name) {
  const paths = {
    today: '<circle cx="12" cy="12" r="4"/><path d="M12 3v1.5M12 19.5V21M4.9 4.9l1.1 1.1M18 18l1.1 1.1M3 12h1.5M19.5 12H21M4.9 19.1L6 18M18 6l1.1-1.1"/>',
    week: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
    term: '<path d="M4 7h16M4 12h16M4 17h10"/>',
    rooms: '<path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-8h6v8"/>',
    alerts: '<path d="M6 16V10a6 6 0 1 1 12 0v6l1.5 2H4.5L6 16z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
    manage: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="3" rx="1"/><rect x="13" y="9" width="7" height="2" rx="1"/><rect x="4" y="13" width="16" height="7" rx="1.5"/>',
    allocate: '<path d="M12 5v14M5 12h14"/>',
    courses: '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h5"/>',
    lecturers: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.4"/><path d="M15 19a4.2 4.2 0 0 1 6-3.5"/>',
    conflicts: '<path d="M12 4l9 16H3L12 4z"/><path d="M12 10v5M12 17.5h.01"/>',
    profile: '<circle cx="12" cy="8" r="3.5"/><path d="M5 19a7 7 0 0 1 14 0"/>',
    cap: '<path d="M3 10l9-5 9 5-9 5-9-5z"/><path d="M7 12.2V16c0 1.4 2.2 2.6 5 2.6s5-1.2 5-2.6v-3.8"/>',
    prev: '<path d="M15 6 L9 12 L15 18"/>',
    next: '<path d="M9 6 L15 12 L9 18"/>',
    availability: '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
    calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h3M14 14h2M8 17h2"/>',
    reports: '<path d="M5 19V10M12 19V5M19 19v-7"/>',
    find: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-3.5-3.5"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
  };
  return `<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${paths[name] || ''}</svg>`;
}

function clock(value) {
  return String(value || '').slice(0, 5);
}

function utcDate(iso) {
  const [year, month, day] = String(iso).split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day));
}

function longDate(iso) {
  return new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  }).format(utcDate(iso));
}

function spanLabel(start, end) {
  const fmt = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
  return `${fmt.format(utcDate(start))} – ${fmt.format(utcDate(end))} ${String(end).slice(0, 4)}`;
}

function shiftDate(iso, days) {
  const date = utcDate(iso);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function stamp(value) {
  if (!value) {
    return '';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function pretty(value) {
  const text = String(value || '').replace(/[._]/g, ' ').trim();
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function swatch(code) {
  let hash = 0;
  for (const char of String(code || '')) {
    hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
  }
  return hash % 8;
}

function roleLabel(role) {
  return ROLES[role] || pretty(role) || 'Member';
}

function scopeLine(role) {
  if (role === 'lecturer') {
    return 'Sessions you are teaching.';
  }
  if (role === 'admin') {
    return 'Published sittings for your department.';
  }
  return 'Classes you are enrolled in.';
}

function greeting(name) {
  const hour = new Date().getHours();
  const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  return `${part}, ${name || 'there'}`;
}

function firstName(user) {
  return user?.first_name || String(user?.display_name || 'there').split(' ')[0];
}

function initials(user) {
  const first = (user?.first_name || user?.display_name || '?').charAt(0);
  const last = (user?.last_name || '').charAt(0);
  return (first + last).toUpperCase();
}

function chip(status) {
  const key = String(status || 'info');
  return `<span class="chip chip-${esc(key)}">${esc(pretty(key))}</span>`;
}

function notice(title, body) {
  return `<div class="notice"><h2>${esc(title)}</h2><p>${esc(body)}</p></div>`;
}

function failureNotice(error, subject) {
  if (error?.code === 'NOT_IMPLEMENTED' || error?.status === 501) {
    return notice(
      `${subject} is not switched on yet`,
      'Your timetable is. This part of CATMS is still being connected on the server.',
    );
  }
  if (error?.status === 403) {
    return notice('This is outside your role', 'You can open your timetable. This screen is reserved for another role.');
  }
  if (error?.status === 404) {
    return notice('Nothing is published yet', 'When a term is published for your department, it will show up here.');
  }
  if (error?.code === 'OFFLINE' || error?.status === 0) {
    return notice('You are offline', error.message || 'This view has not been saved on this device yet.');
  }
  return notice('Something went wrong', failureText(error, 'Try again in a moment.'));
}

function fromGrid(entry) {
  return {
    id: entry.id,
    code: entry.course?.code,
    title: entry.course?.title,
    level: entry.course?.level,
    start: entry.slot?.start,
    end: entry.slot?.end,
    label: entry.slot?.label,
    roomId: entry.room?.id,
    slotId: entry.slot?.id,
    room: entry.room?.name,
    roomCode: entry.room?.code,
    building: entry.room?.building,
    floor: entry.room?.floor,
    capacity: entry.room?.capacity,
    type: entry.room?.type,
    lecturer: entry.lecturer?.name,
    cohort: entry.cohort?.name,
    headcount: entry.cohort?.headcount,
    status: entry.status,
    override: entry.override,
    why: entry.why,
  };
}

function fromDetail(entry) {
  return {
    id: entry.allocation_id,
    code: entry.course_code,
    title: entry.course_title,
    level: entry.course_level,
    start: entry.start_time,
    end: entry.end_time,
    label: entry.slot_label,
    room: entry.room_name,
    roomCode: entry.room_code,
    building: entry.building,
    floor: entry.floor,
    capacity: entry.capacity,
    lecturer: entry.lecturer_name,
    cohort: entry.cohort_name,
    headcount: entry.headcount,
    status: entry.status,
    override: entry.is_override,
    why: entry.override_reason,
  };
}

function sessionButton(card, index, compact) {
  const tone = `swatch-${swatch(card.code)}`;
  const when = `${clock(card.start)}–${clock(card.end)}`;
  const lastName = String(card.lecturer || '').split(' ').filter(Boolean).pop() || '';
  const roomKind = pretty(card.type || 'lecture');
  return `<button type="button" class="session ${tone}" data-open="${index}">
    ${compact ? '' : `<span class="when">${esc(when)}</span>`}
    <span>
      <span class="session-row">
        <span class="code">${esc(card.code)}</span>
        ${compact ? '' : chip(card.status)}
      </span>
      ${compact ? '' : `<strong>${esc(card.title)}</strong>`}
      ${compact
        ? `<span class="session-place">${icon('rooms')} ${esc(card.roomCode || card.room || '')}${roomKind ? ` · ${esc(roomKind)}` : ''}</span>
           ${lastName ? `<span class="session-who">${icon('profile')} ${esc(lastName)}</span>` : ''}`
        : `<span class="meta">${esc(card.roomCode)} · ${esc(card.room)}${card.lecturer ? ` · ${esc(card.lecturer)}` : ''}</span>`}
    </span>
  </button>`;
}

function indexCards(cards) {
  state.cards = cards;
  return new Map(cards.map((card, index) => [String(card.id), index]));
}

function pager(prevHref, nextHref, prevLabel, nextLabel) {
  const prev = prevHref
    ? `<a class="icon-btn" data-nav href="${esc(prevHref)}" aria-label="${esc(prevLabel)}">${icon('prev')}</a>`
    : `<span class="icon-btn is-disabled">${icon('prev')}</span>`;
  const next = nextHref
    ? `<a class="icon-btn" data-nav href="${esc(nextHref)}" aria-label="${esc(nextLabel)}">${icon('next')}</a>`
    : `<span class="icon-btn is-disabled">${icon('next')}</span>`;
  return `<div class="pager">${prev}${next}</div>`;
}

function stats(items) {
  return `<div class="stats">${items.map(([value, label]) => `<article class="stat"><span>${esc(value)}</span><small>${esc(label)}</small></article>`).join('')}</div>`;
}

function skeleton() {
  return `<div class="skeleton" aria-hidden="true"><div class="sk sk-title"></div><div class="sk sk-line"></div><div class="sk sk-card"></div><div class="sk sk-card"></div><div class="sk sk-card"></div></div>`;
}

function authHtml() {
  return `<div class="auth">
    <section class="auth-story">
      <div class="auth-story-body">
        <span class="cap-mark" aria-hidden="true">${icon('cap')}</span>
        <h1>UTAS Timetable System</h1>
        <p class="lede">University of Technology and Applied Science — Intelligent Lecture Scheduling Platform</p>
        <ul class="points">
          <li>${icon('conflicts')} Smart Conflict Detection</li>
          <li>${icon('rooms')} Hall Availability Tracking</li>
          <li>${icon('lecturers')} Lecturer Schedule Management</li>
          <li>${icon('manage')} Real-time Dashboard</li>
        </ul>
      </div>
    </section>
    <section class="auth-panel" id="main">
      <div class="auth-card">
        <h2>Welcome back</h2>
        <p class="muted">Sign in to access the timetable management system</p>
        <div class="demo-row" role="group" aria-label="Demo roles">
          ${DEMOS.map((account, index) => `<button type="button" class="demo${index === 0 ? ' is-active' : ''}" data-demo="${index}">${icon(index === 0 ? 'manage' : index === 1 ? 'lecturers' : 'profile')}${esc(account.role.replace('Administrator', 'Admin'))}</button>`).join('')}
        </div>
        <form id="login-form" novalidate>
          <div class="alert" data-error hidden role="alert"></div>
          <div class="field">
            <div class="field-label"><label for="email">Email Address</label></div>
            <input id="email" name="email" type="email" autocomplete="username" inputmode="email" spellcheck="false" required>
          </div>
          <div class="field">
            <div class="field-label">
              <label for="password">Password</label>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary" type="submit">Sign In</button>
        </form>
        <p class="muted auth-links">Don’t have an account? <a data-nav href="/register">Register here</a></p>
      </div>
    </section>
  </div>`;
}

function linkItem(item, className) {
  const badge = item.id === 'alerts' ? '<span class="badge" data-badge hidden></span>' : '';
  return `<a class="${className}" data-nav data-item href="${item.href}">${icon(item.id)}${esc(item.label)}${badge}</a>`;
}

function navLinks(className) {
  if (className === 'tab') {
    return mobileTabs().map((item) => linkItem(item, className)).join('');
  }
  return navGroups().map((group) => `<div class="nav-group"><p class="nav-label">${esc(group.label)}</p>${group.items.map((item) => linkItem(item, className)).join('')}</div>`).join('');
}

function shellHtml() {
  const user = session()?.user || {};
  const must = session()?.must_change_password;
  const home = homePath();
  return `<div class="shell">
    <header class="topbar">
      <a class="brand" href="${home}" data-nav>
        <span class="brand-mark" aria-hidden="true">${icon('cap')}</span>
        <span class="brand-copy"><strong>UTAS Timetable System</strong><small>University of Technology &amp; Applied Science</small></span>
      </a>
      <div class="topbar-spacer"></div>
      <a class="user-chip" href="/profile" data-nav>
        <span class="avatar">${esc((user.first_name || user.display_name || 'A').charAt(0).toUpperCase())}</span>
        <span class="user-chip-name">${esc(user.role === 'admin' ? 'Admin' : (user.display_name || roleLabel(user.role)))}</span>
      </a>
      <button class="sign-out" type="button" data-logout>Sign Out</button>
    </header>
    <aside class="sidebar">
      <nav aria-label="Primary">${navLinks('nav-link')}</nav>
    </aside>
    <div class="workspace">
      <div id="sync-note" class="sync-note" hidden role="status"></div>
      <div id="account-note" class="account-note"${must ? '' : ' hidden'}><a data-nav href="/profile">This account must change its password. Open your profile to set a new one.</a></div>
      <main id="main"></main>
    </div>
    <nav class="tabbar" aria-label="Primary">${navLinks('tab')}</nav>
    <div id="dialog-root"></div>
  </div>`;
}

function showSync(cachedAt) {
  const note = document.getElementById('sync-note');
  if (!note) {
    return;
  }
  if (!cachedAt) {
    note.hidden = true;
    return;
  }
  note.hidden = false;
  note.textContent = `Showing your last synced copy · ${stamp(cachedAt)}`;
}

async function todayView() {
  const params = new URLSearchParams(location.search);
  const date = params.get('date');
  const query = date ? `?date=${encodeURIComponent(date)}` : '';
  const result = await load(`/timetable/day${query}`, `today:${date || 'current'}`);
  const data = result.data || {};
  const entries = Array.isArray(data.entries) ? data.entries.map(fromDetail) : [];
  const lookup = indexCards(entries);
  const user = session()?.user || {};
  const current = data.date || date || new Date().toISOString().slice(0, 10);
  const onToday = current === new Date().toISOString().slice(0, 10);
  const title = onToday ? greeting(firstName(user)) : longDate(current);
  const body = entries.length
    ? `<ol class="timeline">${entries.map((card) => `<li>${sessionButton(card, lookup.get(String(card.id)), false)}</li>`).join('')}</ol>`
    : notice(data.is_non_teaching ? 'No teaching today' : 'Nothing is on', data.note || 'This day has no published sessions for you.');

  return {
    cachedAt: result.cachedAt,
    title: onToday ? 'Today' : 'Day',
    html: `<header class="page-head">
      <div>
        <h1>${esc(title)}</h1>
        <p class="muted">${esc(onToday ? longDate(current) : data.label || current)} · ${esc(scopeLine(user.role))}</p>
      </div>
      ${pager(`/today?date=${shiftDate(current, -1)}`, `/today?date=${shiftDate(current, 1)}`, 'Previous day', 'Next day')}
    </header>
    ${stats([[entries.length, entries.length === 1 ? 'Session' : 'Sessions'], [new Set(entries.map((card) => card.roomCode).filter(Boolean)).size, 'Rooms']])}
    ${data.note && entries.length ? `<div class="account-note">${esc(data.note)}</div>` : ''}
    ${body}`,
  };
}

function weekdayName(iso) {
  return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][Number(iso)] || '';
}

function visibleDays(days) {
  const byIso = new Map((days || []).map((day) => [Number(day.iso), day]));
  return [1, 2, 3, 4, 5].map((iso) => {
    const day = byIso.get(iso);
    return day
      ? { ...day, label: weekdayName(iso) }
      : { iso, date: '', label: weekdayName(iso), entries: [], is_non_teaching: false };
  });
}

async function weekView() {
  const params = new URLSearchParams(location.search);
  const week = params.get('week');
  const query = week ? `?week=${encodeURIComponent(week)}` : '';
  const result = await load(`/timetable${query}`, `week:${week || 'current'}`);
  const data = result.data || {};
  const days = visibleDays(data.days);
  const cards = [];
  days.forEach((day) => {
    (day.entries || []).forEach((entry) => {
      cards.push({ ...fromGrid(entry), dayLabel: day.label });
    });
  });
  const lookup = indexCards(cards);
  const weekMeta = data.week || {};
  const board = cards.length ? weekBoard(days, lookup, data.semester?.id, weekMeta.number) : notice('Nothing is published this week', 'Move to another week, or wait until the timetable is generated.');
  const mobile = dayList(days, lookup);

  return {
    cachedAt: result.cachedAt,
    title: 'Weekly Timetable',
    html: `<header class="page-head">
      <div>
        <h1>Weekly Timetable</h1>
        <p class="muted">Full semester schedule — all courses and halls</p>
      </div>
    </header>
    <div class="board-wrap${cards.length ? '' : ' is-empty'}">${board}</div>
    ${mobile}`,
  };
}

function weekBoard(days, lookup, semesterId, weekNumber) {
  const times = [...new Set(days.flatMap((day) => (day.entries || []).map((entry) => entry.slot?.start)))].filter(Boolean).sort();
  const head = days.map((day) => `<div class="board-day${day.is_non_teaching ? ' is-off' : ''}"><span>${esc(day.label)}</span>${day.note ? `<em>${esc(day.note)}</em>` : ''}</div>`).join('');
  const rows = times.map((time) => {
    const sample = days.flatMap((day) => day.entries || []).find((entry) => entry.slot?.start === time);
    const cells = days.map((day) => {
      const items = (day.entries || []).filter((entry) => entry.slot?.start === time);
      const buttons = items.map((entry) => sessionButton(fromGrid(entry), lookup.get(String(entry.id)), true)).join('');
      return `<div class="board-cell">${buttons}</div>`;
    }).join('');
    return `<div class="board-time"><span>${esc(clock(time))}</span>${sample?.slot?.end ? `<small>${esc(clock(sample.slot.end))}</small>` : ''}</div>${cells}`;
  }).join('');
  return `<section class="panel grid-panel">
    <div class="grid-toolbar">
      <h2>${icon('week')} Weekly Schedule Grid</h2>
      <div class="grid-tools">
        <span class="legend"><i class="dot-it"></i> IT <i class="dot-cs"></i> CS</span>
        ${semesterId ? `<button class="btn btn-ghost" type="button" data-export="${esc(semesterId)}" data-export-week="${esc(weekNumber || '')}">Download CSV</button>` : ''}
      </div>
    </div>
    <div class="board days-${days.length}" aria-label="Week timetable"><div class="board-corner">Time</div>${head}${rows}</div>
  </section>`;
}

function dayList(days, lookup) {
  if (!days.length) {
    return '';
  }
  const todayIso = (() => {
    const day = new Date().getDay();
    return day === 0 ? 7 : day;
  })();
  const selected = days.some((day) => day.iso === todayIso) ? todayIso : days[0].iso;
  const tabs = days.map((day) => `<button type="button" data-day="${day.iso}" role="tab" aria-selected="${day.iso === selected ? 'true' : 'false'}">${esc(String(day.label || '').slice(0, 3))}</button>`).join('');
  const panels = days.map((day) => {
    const items = day.entries || [];
    const inner = items.length
      ? items.map((entry) => sessionButton(fromGrid(entry), lookup.get(String(entry.id)), false)).join('')
      : notice(day.is_non_teaching ? 'No teaching' : 'Clear day', day.note || 'Nothing is scheduled.');
    return `<div class="day-panel" data-panel="${day.iso}" role="tabpanel"${day.iso === selected ? '' : ' hidden'}>${inner}</div>`;
  }).join('');
  return `<div class="day-list"><div class="day-switch" role="tablist" aria-label="Days">${tabs}</div>${panels}</div>`;
}

async function termView() {
  const result = await load('/timetable/semester', 'term');
  const data = result.data || {};
  const weeks = Array.isArray(data.weeks) ? data.weeks : [];
  const max = Math.max(1, ...weeks.map((week) => week.sessions || 0));
  const rows = weeks.map((week) => {
    const step = week.sessions ? Math.min(10, Math.max(1, Math.round((week.sessions / max) * 10))) : 0;
    return `<a class="week-row" data-nav href="/week?week=${week.week}">
      <span class="week-meta">Week ${esc(week.week)}</span>
      <span class="bar bar-${step}" aria-hidden="true"><span></span></span>
      <span class="week-count">${esc(week.sessions)} ${week.sessions === 1 ? 'session' : 'sessions'}</span>
    </a>`;
  }).join('');

  return {
    cachedAt: result.cachedAt,
    title: 'Term',
    html: `<header class="page-head">
      <div>
        <h1>${esc(data.semester?.name || 'Semester')}</h1>
        <p class="muted">${esc(data.total ?? 0)} published sessions${data.semester?.academic_year ? ` · ${esc(data.semester.academic_year)}` : ''}</p>
      </div>
      ${data.semester?.id ? `<button class="btn btn-ghost" type="button" data-export="${esc(data.semester.id)}">Download CSV</button>` : ''}
    </header>
    <div class="week-list">${rows || notice('No weeks yet', 'The semester has no teaching weeks to show.')}</div>`,
  };
}

function listOf(data) {
  if (Array.isArray(data)) {
    return data;
  }
  if (data && Array.isArray(data.items)) {
    return data.items;
  }
  return [];
}

async function roomsView() {
  const params = new URLSearchParams(location.search);
  const q = params.get('q') || '';
  let body;
  try {
    const query = new URLSearchParams({ per_page: '48' });
    if (q) query.set('q', q);
    if (params.get('min_capacity')) query.set('min_capacity', params.get('min_capacity'));
    if (params.get('features')) query.set('features', params.get('features'));
    const [result, allocRes] = await Promise.all([
      api(`/rooms?${query.toString()}`),
      api('/allocations?per_page=200').catch(() => ({ data: [] })),
    ]);
    const rooms = listOf(result.data);
    const booked = {};
    listOf(allocRes.data).forEach((row) => {
      const id = row.room?.id;
      if (id) booked[id] = (booked[id] || 0) + 1;
    });
    body = rooms.length
      ? `<div class="panel"><h2>${icon('rooms')} Hall Directory</h2><div class="table-wrap"><table class="data-table">
          <thead><tr><th>Hall</th><th>Building</th><th>Capacity</th><th>Facilities</th><th>Sessions Booked</th><th>Status</th></tr></thead>
          <tbody>${rooms.map((room) => {
            const count = booked[room.id] || 0;
            const on = room.status === 'available' || !room.status;
            return `<tr>
              <td>${esc(room.code)}</td>
              <td>${esc(room.building || 'Campus')}</td>
              <td>${esc(room.capacity ?? '—')} seats</td>
              <td>${esc((room.features || []).map(pretty).join(', ') || '—')}</td>
              <td><span class="soft-pill">${esc(count)}</span></td>
              <td><span class="status-dot${on ? ' is-on' : ''}">${esc(pretty(room.status || 'available'))}</span></td>
            </tr>`;
          }).join('')}</tbody>
        </table></div></div>`
      : notice(q ? 'No rooms match' : 'No rooms yet', q ? 'Try a building, a code, or part of the name.' : 'Rooms will appear here once they are added.');
  } catch (error) {
    body = failureNotice(error, 'Room search');
  }

  return {
    cachedAt: null,
    title: 'Lecture Halls',
    html: `<header class="page-head"><div><h1>Lecture Halls</h1><p class="muted">Available rooms and capacity information</p></div></header>
      <form class="search" data-search="rooms" role="search">
        <input type="search" name="q" value="${esc(q)}" placeholder="Lab 1, Block A, lecture hall" aria-label="Search rooms">
        <input name="min_capacity" type="number" min="1" value="${esc(params.get('min_capacity') || '')}" placeholder="Min seats" aria-label="Minimum capacity">
        <input name="features" value="${esc(params.get('features') || '')}" placeholder="projector, lab" aria-label="Required features">
        <button class="btn btn-primary" type="submit">Search</button>
      </form>
      ${body}
      ${roomAdminForm()}`,
  };
}

function roomCard(room) {
  const floor = room.floor == null || room.floor === '' ? '' : ` · Floor ${room.floor}`;
  return `<article class="room">
    <header><span class="code">${esc(room.code)}</span>${chip(room.status || 'available')}</header>
    <h2>${esc(room.name)}</h2>
    <p>${esc(room.building || 'Campus')}${esc(floor)}</p>
    <dl>
      <div><dt>Capacity</dt><dd>${esc(room.capacity ?? '—')}</dd></div>
      <div><dt>Type</dt><dd>${esc(pretty(room.room_type || room.type || 'room'))}</dd></div>
      <div class="span"><dt>Features</dt><dd>${esc((room.features || []).map(pretty).join(' · ') || '—')}</dd></div>
    </dl>
  </article>`;
}

async function alertsView() {
  let body;
  let unreadCount = 0;
  try {
    const [result, count] = await Promise.all([
      api('/notifications?per_page=40'),
      api('/notifications/unread-count'),
    ]);
    const items = listOf(result.data);
    unreadCount = Number(count.data?.unread_count || 0);
    body = items.length
      ? `<div class="alert-list">${items.map(alertCard).join('')}</div>`
      : notice('You are up to date', 'Room changes, cancellations, and confirmations will land here.');
  } catch (error) {
    body = failureNotice(error, 'Alerts');
  }

  const markAll = unreadCount
    ? '<button class="btn btn-ghost" type="button" data-read-all>Mark all read</button>'
    : '';
  const countBadge = unreadCount
    ? `<span class="badge" data-badge>${esc(unreadCount)}</span>`
    : '';

  return {
    cachedAt: null,
    title: 'Alerts',
    html: `<header class="page-head"><div><h1>Alerts${countBadge}</h1><p class="muted">Changes to the classes that involve you.</p></div>${markAll}</header>${body}`,
  };
}

function alertCard(item) {
  const unread = !item.read_at;
  return `<article class="alert-card${unread ? ' is-unread' : ''} sev-${esc(item.severity || 'info')}">
    <header>${chip(item.severity || 'info')}<time datetime="${esc(item.created_at || '')}">${esc(stamp(item.created_at))}</time></header>
    <h2>${esc(item.title)}</h2>
    <p>${esc(item.body)}</p>
    ${unread ? `<button class="btn btn-ghost" type="button" data-read="${esc(item.id)}">Mark read</button>` : ''}
  </article>`;
}

async function profileView() {
  try {
    const result = await api('/auth/me');
    saveSession({
      user: result.data?.user,
      permissions: result.data?.permissions || [],
    });
  } catch (error) {
    if (!session()?.user) {
      throw error;
    }
  }
  const user = session()?.user || {};
  let teaching = '';
  if (user.role === 'lecturer' && user.user_id) {
    try {
      const load = await api(`/users/${user.user_id}/load`);
      const rows = Array.isArray(load.data?.load) ? load.data.load : [];
      teaching = rows.length
        ? `<h2>Teaching</h2><div class="record-list">${rows.map((row) => `<article class="record"><p>${esc(row.session_count)} sessions · ${esc(row.distinct_cohorts)} cohorts · ${esc(row.students_taught ?? 0)} students</p></article>`).join('')}</div><p><a data-nav href="/week">Open your teaching week</a></p>`
        : `<h2>Teaching</h2><p class="muted">No classes are assigned yet. Your week lists them as soon as they are published.</p>`;
    } catch {
      teaching = '';
    }
  }
  const rows = [
    ['Email', user.email],
    ['Phone', user.phone],
    ['Student ID', user.student_index],
    ['Status', pretty(user.status)],
  ].filter(([, value]) => value);

  return {
    cachedAt: null,
    title: 'Profile',
    html: `<header class="page-head"><div><h1>My Profile</h1><p class="muted">Account details for this university login</p></div></header>
      <section class="profile-card">
        <div class="who">
          <span class="avatar">${esc(initials(user))}</span>
          <div><strong>${esc(user.display_name || '')}</strong><div class="muted">${esc(roleLabel(user.role))}</div></div>
        </div>
        <dl>${rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>
        ${teaching}
        ${profileExtras()}
      </section>`,
  };
}

function dialogHtml(card) {
  const place = [card.room, card.building, card.floor == null || card.floor === '' ? '' : `Floor ${card.floor}`].filter(Boolean).join(' · ');
  const fields = [
    ['When', `${card.dayLabel ? `${card.dayLabel}, ` : ''}${clock(card.start)}–${clock(card.end)}`],
    ['Room', `${card.roomCode || ''} ${place}`.trim()],
    ['Lecturer', card.lecturer],
    ['Cohort', card.cohort ? `${card.cohort}${card.headcount ? ` · ${card.headcount}` : ''}` : ''],
    ['Capacity', card.capacity],
    ['Type', pretty(card.type)],
  ].filter(([, value]) => value !== undefined && value !== null && value !== '');

  return `<div class="modal" data-backdrop>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
      <header>
        <div>
          <div class="code">${esc(card.code)}</div>
          <h2 id="dialog-title">${esc(card.title)}</h2>
        </div>
        <button class="icon-btn" type="button" data-close aria-label="Close">${icon('close')}</button>
      </header>
      ${chip(card.status)}
      <dl>${fields.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>
      ${session()?.user?.role === 'admin' && card.id ? `<div class="stack">
        ${card.status === 'confirmed' || card.status === 'cancelled' ? '' : `<button class="btn btn-primary" type="button" data-confirm="${esc(card.id)}">Confirm</button>`}
        <button class="btn btn-ghost" type="button" data-cancel="${esc(card.id)}">Cancel class</button>
        <form id="move-form" class="stack">
          <input type="hidden" name="id" value="${esc(card.id)}">
          <div class="field"><label class="field-label" for="move-room">Room id</label><input id="move-room" name="room_id" type="number" value="${esc(card.roomId || '')}"></div>
          <div class="field"><label class="field-label" for="move-slot">Time slot id</label><input id="move-slot" name="time_slot_id" type="number" value="${esc(card.slotId || '')}"></div>
          <div class="field"><label class="field-label" for="move-reason">Reason</label><input id="move-reason" name="reason" required></div>
          <label class="check"><input type="checkbox" name="repair" value="1"> Recalculate this day</label>
          <button class="btn btn-primary" type="submit">Save change</button>
        </form>
      </div>` : ''}
      ${card.override && card.why ? `<p class="why">${esc(card.why)}</p>` : ''}
    </div>
  </div>`;
}

function openDialog(card) {
  const host = document.getElementById('dialog-root');
  if (!host) {
    return;
  }
  state.lastFocus = document.activeElement;
  host.innerHTML = dialogHtml(card);
  document.body.classList.add('modal-open');
  host.querySelector('[data-close]')?.focus();
}

function closeDialog() {
  const host = document.getElementById('dialog-root');
  if (host) {
    host.innerHTML = '';
  }
  document.body.classList.remove('modal-open');
  if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
    state.lastFocus.focus();
  }
}

function toast(message) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    el.setAttribute('role', 'status');
    document.body.appendChild(el);
  }
  el.hidden = false;
  el.textContent = message;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => {
    el.hidden = true;
  }, 4200);
}

function currentPath() {
  let path = location.pathname;
  if (path.length > 1 && path.endsWith('/')) {
    path = path.slice(0, -1);
  }
  return path || '/';
}

function go(href) {
  const url = new URL(href, location.origin);
  const next = `${url.pathname}${url.search}`;
  const current = `${location.pathname}${location.search}`;
  if (next === current) {
    render();
    return;
  }
  history.pushState({}, '', next);
  render();
}

function setActive(path) {
  const here = `${location.pathname}${location.search || ''}`;
  document.querySelectorAll('[data-item]').forEach((link) => {
    const href = link.getAttribute('href') || '';
    const target = href.split('?')[0];
    const match = href.includes('?') ? here === href || (path === target && here.startsWith(href)) : path === target;
    if (match) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  });
}

let badgeTicket = 0;

function setBadge(count) {
  const total = Math.max(0, Number(count) || 0);
  document.querySelectorAll('[data-badge]').forEach((badge) => {
    if (!total) {
      badge.hidden = true;
      badge.textContent = '';
      return;
    }
    badge.hidden = false;
    badge.textContent = String(total);
  });
}

async function refreshBadge() {
  const ticket = ++badgeTicket;
  try {
    const result = await api('/notifications/unread-count');
    if (ticket !== badgeTicket) {
      return;
    }
    setBadge(Number(result.data?.unread_count || 0));
  } catch {
    if (ticket !== badgeTicket) {
      return;
    }
    setBadge(0);
  }
}

async function signIn(form) {
  const error = form.querySelector('[data-error]');
  const button = form.querySelector('[type="submit"]');
  error.hidden = true;
  button.disabled = true;
  try {
    const result = await api('/auth/login', {
      method: 'POST',
      body: {
        email: form.elements.email.value.trim(),
        password: form.elements.password.value,
      },
    });
    saveSession({
      access_token: result.data?.access_token,
      refresh_token: result.data?.refresh_token,
      user: result.data?.user,
      must_change_password: Boolean(result.data?.must_change_password),
    });
    try {
      const me = await api('/auth/me');
      saveSession({ user: me.data?.user, permissions: me.data?.permissions || [] });
    } catch {
      /* The login payload already has the profile. */
    }
    go(homePath());
  } catch (reason) {
    error.hidden = false;
    error.textContent = failureText(reason, 'Sign-in failed.');
  } finally {
    button.disabled = false;
  }
}

async function signOut() {
  try {
    await api('/auth/logout', { method: 'POST', body: {} });
  } catch {
    /* Local sign-out still stands if the server is unreachable. */
  }
  clearSession();
  go('/login');
}

function paintAlertRead(id) {
  const button = document.querySelector(`[data-read="${CSS.escape(String(id))}"]`);
  const card = button?.closest('.alert-card');
  if (card) {
    card.classList.remove('is-unread');
    button.remove();
  }
  if (!document.querySelector('.alert-card.is-unread')) {
    document.querySelector('[data-read-all]')?.remove();
  }
}

async function markRead(id) {
  try {
    await api(`/notifications/${encodeURIComponent(id)}/read`, { method: 'POST', body: {} });
    paintAlertRead(id);
    await render();
    await refreshBadge();
  } catch (error) {
    toast(failureText(error, 'Could not mark that alert as read.'));
  }
}

async function downloadCsv(semesterId, week) {
  const token = session()?.access_token;
  const query = week ? `?week=${encodeURIComponent(week)}` : '';
  try {
    const response = await fetch(`${API}/timetable/${encodeURIComponent(semesterId)}/export.csv${query}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!response.ok) {
      let message = 'The export could not be downloaded.';
      let details = null;
      try {
        const json = JSON.parse(await response.text());
        message = json?.error?.message || message;
        details = json?.error?.details || null;
      } catch {
        /* A failed export may not be JSON. */
      }
      throw new ApiError(message, response.status, 'ERROR', details);
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = week ? `timetable-${semesterId}-week-${week}.csv` : `timetable-${semesterId}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  } catch (error) {
    toast(failureText(error, 'The export could not be downloaded.'));
  }
}

async function viewFor(path) {
  if (path === '/today') return todayView();
  if (path === '/week') return weekView();
  if (path === '/term') return termView();
  if (path === '/rooms') return roomsView();
  if (path === '/alerts') return alertsView();
  if (path === '/profile') return profileView();
  const extra = await extraView(path);
  if (extra) return extra;
  return todayView();
}

function known(path) {
  return path === '/profile' || isExtra(path) || NAV.some((item) => item.href === path);
}

async function render() {
  const token = state.token + 1;
  state.token = token;
  const authed = Boolean(session()?.access_token);
  let path = currentPath();

  if (!authed && path !== '/login' && !isPublic(path)) {
    history.replaceState({}, '', '/login');
    path = '/login';
  }
  if (authed && (path === '/' || path === '/login')) {
    const home = homePath();
    history.replaceState({}, '', home);
    path = home;
  }
  if (authed && !known(path)) {
    const home = homePath();
    history.replaceState({}, '', home);
    path = home;
  }

  if (!authed) {
    document.title = path === '/login' ? 'Sign in · UTAS Timetable' : 'UTAS Timetable';
    root.innerHTML = path === '/login' ? authHtml() : publicHtml(path);
    if (path === '/login') {
      const first = DEMOS[0];
      const form = document.getElementById('login-form');
      if (form && first) {
        form.elements.email.value = first.email;
        form.elements.password.value = first.password;
      }
    }
    return;
  }

  if (!document.querySelector('.shell')) {
    root.innerHTML = shellHtml();
    refreshBadge();
  }

  setActive(path);
  const main = document.getElementById('main');
  if (!main) {
    return;
  }
  main.innerHTML = skeleton();
  main.setAttribute('aria-busy', 'true');

  try {
    const view = await viewFor(path);
    if (token !== state.token) {
      return;
    }
    document.title = `${view.title} · UTAS Timetable`;
    main.innerHTML = view.html;
    showSync(view.cachedAt);
  } catch (error) {
    if (token !== state.token) {
      return;
    }
    if (error.status === 401) {
      clearSession();
      render();
      return;
    }
    document.title = 'UTAS Timetable';
    main.innerHTML = failureNotice(error, 'This view');
    showSync(null);
  }

  main.setAttribute('aria-busy', 'false');
  const heading = main.querySelector('h1');
  if (heading && render.booted) {
    heading.tabIndex = -1;
    heading.focus();
  }
  render.booted = true;
}

function onClick(event) {
  if (handleClick(event)) {
    return;
  }
  const toggle = event.target.closest('[data-toggle-password]');
  if (toggle) {
    const input = document.getElementById('password');
    if (!input) {
      return;
    }
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Hide' : 'Show';
    return;
  }

  const demo = event.target.closest('[data-demo]');
  if (demo) {
    const account = DEMOS[Number(demo.dataset.demo)];
    const form = document.getElementById('login-form');
    if (!account || !form) {
      return;
    }
    form.elements.email.value = account.email;
    form.elements.password.value = account.password;
    document.querySelectorAll('[data-demo]').forEach((button) => {
      button.classList.toggle('is-active', button === demo);
    });
    form.elements.email.focus();
    return;
  }

  const day = event.target.closest('[data-day]');
  if (day) {
    const iso = day.dataset.day;
    document.querySelectorAll('[data-day]').forEach((button) => {
      button.setAttribute('aria-selected', button === day ? 'true' : 'false');
    });
    document.querySelectorAll('[data-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.panel !== iso;
    });
    return;
  }

  const opener = event.target.closest('[data-open]');
  if (opener) {
    const card = state.cards[Number(opener.dataset.open)];
    if (card) {
      openDialog(card);
    }
    return;
  }

  if (event.target.closest('[data-close]') || event.target.hasAttribute?.('data-backdrop')) {
    closeDialog();
    return;
  }

  const read = event.target.closest('[data-read]');
  if (read) {
    markRead(read.dataset.read);
    return;
  }

  const exp = event.target.closest('[data-export]');
  if (exp) {
    downloadCsv(exp.dataset.export, exp.dataset.exportWeek);
    return;
  }

  if (event.target.closest('[data-logout]')) {
    signOut();
    return;
  }

  const link = event.target.closest('[data-nav]');
  if (link) {
    event.preventDefault();
    closeDialog();
    go(link.getAttribute('href'));
  }
}

function onSubmit(event) {
  const form = event.target;
  if (handleSubmit(event)) {
    return;
  }
  if (form.id === 'login-form') {
    event.preventDefault();
    signIn(form);
  }
  if (form.dataset.search === 'rooms') {
    event.preventDefault();
    const data = new FormData(form);
    const params = new URLSearchParams();
    ['q', 'min_capacity', 'features'].forEach((key) => {
      const value = String(data.get(key) || '').trim();
      if (value) params.set(key, value);
    });
    go(`/rooms?${params.toString()}`);
  }
}

function onKey(event) {
  if (event.key === 'Escape') {
    closeDialog();
    return;
  }

  if (event.key !== 'Tab') {
    return;
  }

  const dialog = document.querySelector('[role="dialog"]');
  if (!dialog) {
    return;
  }

  const items = [...dialog.querySelectorAll('button, a, input')];
  if (!items.length) {
    return;
  }

  const first = items[0];
  const last = items[items.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

install({
  api, esc, go, notice, failureNotice, chip, pretty, session, saveSession, toast, listOf, render, closeDialog, refreshBadge, icon,
});

root.addEventListener('click', onClick);
root.addEventListener('submit', onSubmit);
window.addEventListener('popstate', () => render());
document.addEventListener('keydown', onKey);

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/service-worker.js').catch(() => {
    /* Install is optional. The app still works as a normal site. */
  });
}

render();
