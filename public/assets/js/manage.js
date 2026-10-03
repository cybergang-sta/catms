/**
 * Screens beyond the timetable: accounts, department tools, and reports.
 * The timetable module installs the shared helpers before the first render.
 */
const kit = {};

export function install(helpers) {
  Object.assign(kit, helpers);
}

const EXTRA = ['/manage', '/users', '/courses', '/calendar', '/generate', '/reports', '/audit', '/availability', '/find'];
const PUBLIC = ['/register', '/forgot', '/reset'];

export function isExtra(path) {
  return EXTRA.includes(path);
}

export function isPublic(path) {
  return PUBLIC.includes(path);
}

export function homePath() {
  return role() === 'admin' || can('user:view') ? '/manage' : '/today';
}

export function navGroups() {
  if (role() === 'admin') {
    return [
      {
        label: 'Main',
        items: [
          { href: '/manage', id: 'manage', label: 'Dashboard' },
          { href: '/week', id: 'week', label: 'Timetable View' },
        ],
      },
      {
        label: 'Management',
        items: [
          { href: '/generate', id: 'allocate', label: 'Allocate Schedule' },
          { href: '/courses', id: 'courses', label: 'Courses' },
          { href: '/rooms', id: 'rooms', label: 'Lecture Halls' },
          { href: '/users', id: 'lecturers', label: 'Lecturers' },
        ],
      },
      {
        label: 'Reports',
        items: [
          { href: '/reports?report=conflicts', id: 'conflicts', label: 'Conflict Report' },
          { href: '/profile', id: 'profile', label: 'My Profile' },
        ],
      },
    ];
  }
  const main = [
    { href: '/today', id: 'today', label: 'Today' },
    { href: '/week', id: 'week', label: 'Timetable View' },
    { href: '/term', id: 'term', label: 'Term' },
  ];
  const campus = [
    { href: '/rooms', id: 'rooms', label: 'Lecture Halls' },
    { href: '/alerts', id: 'alerts', label: 'Alerts' },
  ];
  if (role() === 'lecturer') {
    campus.unshift({ href: '/availability', id: 'availability', label: 'Availability' });
    campus.push({ href: '/calendar', id: 'calendar', label: 'Calendar' });
  }
  const reports = [];
  if (role() === 'lecturer' || can('report:view')) {
    reports.push({ href: '/reports', id: 'reports', label: 'Reports' });
  }
  reports.push({ href: '/find', id: 'find', label: 'Find' });
  reports.push({ href: '/profile', id: 'profile', label: 'My Profile' });
  return [
    { label: 'Main', items: main },
    { label: 'Campus', items: campus },
    { label: 'Account', items: reports },
  ];
}

export function sidebarLinks() {
  return navGroups().flatMap((group) => group.items);
}

export function mobileTabs() {
  const home = homePath();
  return [
    { href: home, id: home === '/manage' ? 'manage' : 'today', label: home === '/manage' ? 'Home' : 'Today' },
    { href: '/week', id: 'week', label: 'Week' },
    { href: '/rooms', id: 'rooms', label: 'Halls' },
    { href: role() === 'admin' ? '/reports?report=conflicts' : '/alerts', id: role() === 'admin' ? 'conflicts' : 'alerts', label: role() === 'admin' ? 'Conflicts' : 'Alerts' },
    { href: '/profile', id: 'profile', label: 'Profile' },
  ];
}

export function roomAdminForm() {
  if (!can('room:manage')) {
    return '';
  }
  return `<form id="room-form" class="stack">
      <h2>Add a room</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="room-code">Code</label><input id="room-code" name="code" required maxlength="20"></div>
      <div class="field"><label class="field-label" for="room-name">Name</label><input id="room-name" name="name" required></div>
      <div class="field"><label class="field-label" for="room-building">Building</label><input id="room-building" name="building" required></div>
      <div class="field"><label class="field-label" for="room-capacity">Capacity</label><input id="room-capacity" name="capacity" type="number" min="1" required></div>
      <div class="field"><label class="field-label" for="room-type">Type</label><select id="room-type" name="room_type"><option value="lecture">Lecture</option><option value="lab">Lab</option><option value="seminar">Seminar</option><option value="hall">Hall</option></select></div>
      <div class="field"><label class="field-label" for="room-features">Features</label><input id="room-features" name="features" placeholder="projector, lab"></div>
      <button class="btn btn-primary" type="submit">Save room</button>
    </form>
    <form id="room-edit-form" class="stack">
      <h2>Update a room</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="edit-room-id">Room id</label><input id="edit-room-id" name="id" type="number" required></div>
      <div class="field"><label class="field-label" for="edit-room-capacity">Capacity</label><input id="edit-room-capacity" name="capacity" type="number" min="1"></div>
      <div class="field"><label class="field-label" for="edit-room-status">Status</label><select id="edit-room-status" name="status"><option value="">Keep current</option><option value="available">Available</option><option value="maintenance">Maintenance</option><option value="out_of_service">Out of service</option><option value="reserved">Reserved</option></select></div>
      <div class="field"><label class="field-label" for="edit-room-features">Features</label><input id="edit-room-features" name="features" placeholder="projector, lab"></div>
      <button class="btn btn-primary" type="submit">Update room</button>
    </form>
    <form id="room-block-form" class="stack">
      <h2>Block a room</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="block-room">Room id</label><input id="block-room" name="room_id" type="number" required></div>
      <div class="field"><label class="field-label" for="block-semester">Semester id</label><input id="block-semester" name="semester_id" type="number" required></div>
      <div class="field"><label class="field-label" for="block-slot">Time slot id</label><input id="block-slot" name="time_slot_id" type="number" required></div>
      <div class="field"><label class="field-label" for="block-reason">Reason</label><input id="block-reason" name="reason" required maxlength="150"></div>
      <button class="btn btn-primary" type="submit">Block this slot</button>
    </form>
    <form id="compare-form" class="stack">
      <h2>Compare rooms</h2>
      <div class="field"><label class="field-label" for="compare-ids">Room ids</label><input id="compare-ids" name="ids" placeholder="1,2,3" required></div>
      <button class="btn btn-ghost" type="submit">Compare</button>
    </form>
    <div id="compare-result"></div>`;
}

export function profileExtras() {
  const user = kit.session()?.user || {};
  return `<form id="profile-form" class="stack">
      <h2>Your details</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><div class="field-label"><label for="pf-first">First name</label></div><input id="pf-first" name="first_name" value="${kit.esc(user.first_name || '')}" required></div>
      <div class="field"><div class="field-label"><label for="pf-last">Last name</label></div><input id="pf-last" name="last_name" value="${kit.esc(user.last_name || '')}" required></div>
      <div class="field"><div class="field-label"><label for="pf-phone">Phone</label></div><input id="pf-phone" name="phone" value="${kit.esc(user.phone || '')}" autocomplete="tel"></div>
      <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
    <form id="password-form" class="stack">
      <h2>Password</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><div class="field-label"><label for="pw-current">Current password</label></div><input id="pw-current" name="current_password" type="password" autocomplete="current-password" required></div>
      <div class="field"><div class="field-label"><label for="pw-next">New password</label></div><input id="pw-next" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
      <div class="field"><div class="field-label"><label for="pw-again">Confirm new password</label></div><input id="pw-again" name="password_confirmation" type="password" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
    <nav class="tools" aria-label="More">${sidebarLinks().filter((link) => link.href !== '/profile').map((link) => `<a data-nav href="${link.href}">${kit.esc(link.label)}</a>`).join('')}</nav>`;
}

export function publicHtml(path) {
  const card = path === '/register' ? registerCard() : path === '/forgot' ? forgotCard() : resetCard();
  return `<div class="auth auth-solo"><section class="auth-panel" id="main"><div class="auth-card">${card}</div></section></div>`;
}

export async function extraView(path) {
  if (path === '/manage') return manageHome();
  if (path === '/users') return usersView();
  if (path === '/courses') return coursesView();
  if (path === '/calendar') return calendarView();
  if (path === '/generate') return generateView();
  if (path === '/reports') return reportsView();
  if (path === '/audit') return auditView();
  if (path === '/availability') return availabilityView();
  if (path === '/find') return findView();
  return null;
}

export function handleClick(event) {
  const confirmBtn = event.target.closest('[data-confirm]');
  if (confirmBtn) {
    act(`/allocations/${confirmBtn.dataset.confirm}/confirm`, 'The class is confirmed.');
    return true;
  }
  const cancelBtn = event.target.closest('[data-cancel]');
  if (cancelBtn) {
    const reason = window.prompt('Why is this class cancelled?');
    if (reason && reason.trim()) {
      act(`/allocations/${cancelBtn.dataset.cancel}/cancel`, 'The class is cancelled.', { reason: reason.trim() });
    }
    return true;
  }
  const resolveBtn = event.target.closest('[data-resolve]');
  if (resolveBtn) {
    const resolution = window.prompt('How was this conflict resolved?');
    if (resolution && resolution.trim()) {
      act(`/allocations/conflicts/${resolveBtn.dataset.resolve}/resolve`, 'Conflict marked resolved.', { resolution: resolution.trim() }).then(() => kit.go('/reports?report=conflicts'));
    }
    return true;
  }
  const archiveBtn = event.target.closest('[data-archive]');
  if (archiveBtn) {
    act(`/users/${archiveBtn.dataset.archive}`, 'Account archived.', null, 'DELETE').then(() => kit.go('/users'));
    return true;
  }
  const csvBtn = event.target.closest('[data-report-csv]');
  if (csvBtn) {
    downloadReport(csvBtn.dataset.reportCsv);
    return true;
  }
  const dropCourse = event.target.closest('[data-drop-course]');
  if (dropCourse) {
    act(`/courses/${dropCourse.dataset.dropCourse}`, 'Course removed.', null, 'DELETE').then((ok) => {
      if (ok) kit.go('/courses');
    });
    return true;
  }
  const readAll = event.target.closest('[data-read-all]');
  if (readAll) {
    act('/notifications/read-all', 'Alerts marked read.', null, 'POST').then((ok) => {
      if (ok) kit.render();
    });
    return true;
  }
  const dropBtn = event.target.closest('[data-drop-availability]');
  if (dropBtn) {
    act(`/availability/lecturers/${dropBtn.dataset.dropAvailability}`, 'Availability removed.', null, 'DELETE').then(() => kit.render());
    return true;
  }
  return false;
}

export function handleSubmit(event) {
  const form = event.target;
  const id = form.id;
  if (id === 'register-form') {
    event.preventDefault();
    submitAccount(form, '/auth/register', 'Account created. It stays pending until it is activated.', '/login');
    return true;
  }
  if (id === 'forgot-form') {
    event.preventDefault();
    submitAccount(form, '/auth/forgot-password', 'If that address is registered, a reset link has been issued.', '/login');
    return true;
  }
  if (id === 'reset-form') {
    event.preventDefault();
    submitAccount(form, '/auth/reset-password', 'Password updated. You can sign in.', '/login');
    return true;
  }
  if (id === 'profile-form') {
    event.preventDefault();
    saveProfile(form);
    return true;
  }
  if (id === 'password-form') {
    event.preventDefault();
    savePassword(form);
    return true;
  }
  if (id === 'user-form') {
    event.preventDefault();
    postForm(form, '/users', 'Account registered.', '/users');
    return true;
  }
  if (id === 'course-form') {
    event.preventDefault();
    postForm(form, '/courses', 'Course saved.', '/courses', (body) => {
      body.features = splitCodes(body.features);
      return body;
    });
    return true;
  }
  if (id === 'cohort-form') {
    event.preventDefault();
    postForm(form, '/cohorts', 'Cohort saved.', '/courses');
    return true;
  }
  if (id === 'enrol-form') {
    event.preventDefault();
    const body = Object.fromEntries(new FormData(form));
    const ids = String(body.student_ids || '').split(',').map((part) => Number(part.trim())).filter((id) => id > 0);
    act(`/cohorts/${body.cohort_id}/enrolments`, 'Students enrolled.', { student_ids: ids }).then(() => kit.go('/courses'));
    return true;
  }
  if (id === 'semester-form') {
    event.preventDefault();
    postForm(form, '/semesters', 'Semester saved.', '/calendar');
    return true;
  }
  if (id === 'generate-form') {
    event.preventDefault();
    runGenerate(form);
    return true;
  }
  if (id === 'availability-form') {
    event.preventDefault();
    postForm(form, '/availability/lecturers', 'Unavailability saved.', '/availability');
    return true;
  }
  if (form.dataset.userId) {
    event.preventDefault();
    updatePerson(form);
    return true;
  }
  if (id === 'course-edit-form') {
    event.preventDefault();
    patchResource(form, '/courses', 'Course updated.', '/courses', (body) => {
      if (body.features !== undefined) body.features = splitCodes(body.features);
      if (body.meetings_per_week) body.meetings_per_week = Number(body.meetings_per_week);
      return body;
    });
    return true;
  }
  if (id === 'semester-edit-form') {
    event.preventDefault();
    patchResource(form, '/semesters', 'Semester updated.', '/calendar');
    return true;
  }
  if (id === 'exception-form') {
    event.preventDefault();
    const semester = new FormData(form).get('semester_id');
    postForm(form, `/semesters/${semester}/exceptions`, 'Calendar entry saved.', '/calendar', (body) => {
      delete body.semester_id;
      return body;
    });
    return true;
  }
  if (id === 'room-edit-form') {
    event.preventDefault();
    patchResource(form, '/rooms', 'Room updated.', '/rooms', (body) => {
      if (body.capacity) body.capacity = Number(body.capacity);
      if (body.features !== undefined) body.features = splitCodes(body.features);
      return body;
    });
    return true;
  }
  if (id === 'room-block-form') {
    event.preventDefault();
    postForm(form, '/availability/rooms', 'Room blocked.', '/rooms', (body) => {
      body.room_id = Number(body.room_id);
      body.semester_id = Number(body.semester_id);
      body.time_slot_id = Number(body.time_slot_id);
      return body;
    });
    return true;
  }
  if (id === 'room-form') {
    event.preventDefault();
    postForm(form, '/rooms', 'Room saved.', '/rooms', (body) => {
      body.capacity = Number(body.capacity);
      body.features = splitCodes(body.features);
      body.is_bookable = true;
      return body;
    });
    return true;
  }
  if (id === 'move-form') {
    event.preventDefault();
    moveClass(form);
    return true;
  }
  if (id === 'compare-form') {
    event.preventDefault();
    compareRooms(form);
    return true;
  }
  if (form.dataset.search === 'find') {
    event.preventDefault();
    const data = new FormData(form);
    const params = new URLSearchParams();
    for (const [key, value] of data.entries()) {
      if (String(value).trim() !== '') {
        params.set(key, String(value).trim());
      }
    }
    kit.go(`/find?${params.toString()}`);
    return true;
  }
  return false;
}

function role() {
  return kit.session()?.user?.role || '';
}

function can(permission) {
  return (kit.session()?.permissions || []).includes(permission);
}

function page(title, eyebrow, html) {
  return {
    cachedAt: null,
    title,
    html: `<header class="page-head"><div><h1>${kit.esc(title)}</h1>${eyebrow ? `<p class="muted">${kit.esc(eyebrow)}</p>` : ''}</div></header>${html}`,
  };
}

function registerCard() {
  return `<h2>Create an account</h2>
    <p class="muted">Students and lecturers can sign in after a reset link. An administrator account stays pending until another administrator activates it.</p>
    <form id="register-form" class="stack" novalidate>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><div class="field-label"><label for="reg-email">Email</label></div><input id="reg-email" name="email" type="email" required></div>
      <div class="field"><div class="field-label"><label for="reg-first">First name</label></div><input id="reg-first" name="first_name" required></div>
      <div class="field"><div class="field-label"><label for="reg-last">Last name</label></div><input id="reg-last" name="last_name" required></div>
      <div class="field"><div class="field-label"><label for="reg-role">Role</label></div>
        <select id="reg-role" name="role" required><option value="student">Student</option><option value="lecturer">Lecturer</option><option value="admin">Administrator</option></select>
      </div>
      <div class="field"><div class="field-label"><label for="reg-index">Student ID</label></div><input id="reg-index" name="student_index" inputmode="numeric" autocomplete="off" placeholder="20230410057" aria-describedby="reg-index-hint"><p id="reg-index-hint" class="muted">Required for a student. Use the ID as issued, for example 20230410057.</p></div>
      <div class="field"><div class="field-label"><label for="reg-phone">Phone</label></div><input id="reg-phone" name="phone"></div>
      <div class="field"><div class="field-label"><label for="reg-password">Password</label></div><input id="reg-password" name="password" type="password" minlength="12" required></div>
      <div class="field"><div class="field-label"><label for="reg-confirm">Confirm password</label></div><input id="reg-confirm" name="password_confirmation" type="password" required></div>
      <button class="btn btn-primary" type="submit">Register</button>
    </form>
    <p class="muted"><a data-nav href="/login">Back to sign in</a></p>`;
}

function forgotCard() {
  return `<h2>Reset your password</h2>
    <p class="muted">We answer the same way whether or not the address is registered.</p>
    <form id="forgot-form" class="stack">
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><div class="field-label"><label for="forgot-email">Email</label></div><input id="forgot-email" name="email" type="email" required></div>
      <button class="btn btn-primary" type="submit">Send reset link</button>
    </form>
    <p class="muted"><a data-nav href="/login">Back to sign in</a></p>`;
}

function resetCard() {
  const token = new URLSearchParams(location.search).get('token') || '';
  return `<h2>Choose a new password</h2>
    <form id="reset-form" class="stack">
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><div class="field-label"><label for="reset-token">Reset token</label></div><input id="reset-token" name="token" value="${kit.esc(token)}" required></div>
      <div class="field"><div class="field-label"><label for="reset-password">New password</label></div><input id="reset-password" name="password" type="password" minlength="12" required></div>
      <div class="field"><div class="field-label"><label for="reset-confirm">Confirm password</label></div><input id="reset-confirm" name="password_confirmation" type="password" required></div>
      <button class="btn btn-primary" type="submit">Update password</button>
    </form>`;
}

function deptName(id, code) {
  const prefix = String(code || '').replace(/[0-9].*$/, '').toUpperCase();
  const byCode = {
    CS: 'Computer Science',
    IT: 'Information Technology',
    CE: 'Computer Engineering',
    EE: 'Electrical Engineering',
    IS: 'Information Systems',
  };
  if (byCode[prefix]) {
    return byCode[prefix];
  }
  const byId = {
    1: 'Computer Science',
    2: 'Information Technology',
    3: 'Computer Engineering',
    13: 'Electrical Engineering',
    14: 'Information Systems',
  };
  return byId[id] || (id ? `Department ${id}` : 'Shared');
}

function codeMark(code) {
  return `<span class="code-mark">${kit.esc(String(code || '').slice(0, 2).toUpperCase())}</span>`;
}

async function manageHome() {
  let courses = [];
  let rooms = [];
  let allocations = [];
  let conflicts = [];
  let dash = {};
  try {
    const [courseRes, roomRes, allocRes, dashRes, conflictRes] = await Promise.all([
      kit.api('/courses?per_page=50'),
      kit.api('/rooms?per_page=48'),
      kit.api('/allocations?per_page=200').catch(() => ({ data: [] })),
      kit.api('/dashboard').catch(() => ({ data: {} })),
      kit.api('/reports/conflicts').catch(() => ({ data: [] })),
    ]);
    courses = kit.listOf(courseRes.data);
    rooms = kit.listOf(roomRes.data);
    allocations = kit.listOf(allocRes.data);
    dash = dashRes.data || {};
    conflicts = Array.isArray(conflictRes.data) ? conflictRes.data : kit.listOf(conflictRes.data);
  } catch (error) {
    return page('Dashboard', 'Overview', kit.failureNotice(error, 'Dashboard'));
  }
  const booked = {};
  allocations.forEach((row) => {
    const id = row.room?.id;
    if (id) booked[id] = (booked[id] || 0) + 1;
  });
  const openRooms = rooms.filter((room) => room.status === 'available' && room.is_bookable !== false);
  const hallRange = openRooms.length
    ? `${openRooms[0].code} – ${openRooms[openRooms.length - 1].code} available`
    : 'None available';
  const courseRows = courses.slice(0, 8).map((course) => `<tr>
      <td>${codeMark(course.code)}<span class="code-text">${kit.esc(course.code)}</span></td>
      <td>${kit.esc(course.title)}</td>
      <td><span class="soft-pill">${kit.esc(deptName(course.department_id, course.code))}</span></td>
    </tr>`).join('');
  const hallRows = rooms.slice(0, 8).map((room) => `<tr>
      <td>${kit.esc(room.code)}</td>
      <td>${kit.esc(room.capacity ?? '—')}</td>
      <td><span class="soft-pill">${kit.esc(booked[room.id] || 0)} session${(booked[room.id] || 0) === 1 ? '' : 's'}</span></td>
    </tr>`).join('');
  return {
    cachedAt: null,
    title: 'Dashboard',
    html: `<header class="page-head">
        <div>
          <h1>Dashboard</h1>
          <p class="muted">Overview of timetable allocation status</p>
        </div>
      </header>
      <div class="stats">
        <article class="stat"><small>Total Courses</small><span>${kit.esc(courses.length)}</span><em>Active this semester</em></article>
        <article class="stat"><small>Lecture Halls</small><span>${kit.esc(rooms.length)}</span><em>${kit.esc(hallRange)}</em></article>
        <article class="stat"><small>Allocations</small><span>${kit.esc(allocations.length || dash.proposed_allocations || 0)}</span><em>Scheduled sessions</em></article>
        <article class="stat"><small>Conflicts</small><span class="${conflicts.length ? 'is-alert' : ''}">${kit.esc(conflicts.length || dash.open_conflicts || 0)}</span><em>Detected issues</em></article>
      </div>
      <div class="split-panels">
        <section class="panel">
          <h2>${kit.icon('courses')} Courses This Semester</h2>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Code</th><th>Title</th><th>Dept</th></tr></thead>
            <tbody>${courseRows || `<tr><td colspan="3">No courses yet.</td></tr>`}</tbody>
          </table></div>
        </section>
        <section class="panel">
          <h2>${kit.icon('rooms')} Hall Utilisation</h2>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Hall</th><th>Capacity</th><th>Sessions</th></tr></thead>
            <tbody>${hallRows || `<tr><td colspan="3">No halls yet.</td></tr>`}</tbody>
          </table></div>
        </section>
      </div>`,
  };
}

async function usersView() {
  let body = '';
  try {
    const [result, allocRes] = await Promise.all([
      kit.api('/users?per_page=50'),
      kit.api('/allocations?per_page=200').catch(() => ({ data: [] })),
    ]);
    const taught = {};
    kit.listOf(allocRes.data).forEach((row) => {
      const lecturerId = row.lecturer?.id || row.lecturer_id;
      const code = row.course?.code || row.course_code;
      if (!lecturerId || !code) {
        return;
      }
      taught[lecturerId] = taught[lecturerId] || new Set();
      taught[lecturerId].add(code);
    });
    const lecturers = kit.listOf(result.data).filter((user) => user.role === 'lecturer');
    const shown = lecturers.length ? lecturers : kit.listOf(result.data).filter((user) => user.role !== 'student');
    const table = shown.length
      ? `<div class="panel"><div class="table-wrap"><table class="data-table">
          <thead><tr><th>Name</th><th>Title</th><th>Department</th><th>Assigned Courses</th><th>Email</th></tr></thead>
          <tbody>${shown.map((user) => {
            const id = user.user_id || user.id;
            const name = user.display_name || `${user.first_name || ''} ${user.last_name || ''}`.trim();
            const title = user.role === 'admin' ? 'Administrator' : 'Lecturer';
            const codes = [...(taught[id] || [])];
            const assigned = codes.length
              ? codes.map((code) => `<span class="soft-pill">${kit.esc(code)}</span>`).join(' ')
              : '—';
            return `<tr>
              <td><span class="who-cell">${codeMark(name)}<span>${kit.esc(name)}</span></span></td>
              <td>${kit.esc(title)}</td>
              <td>${kit.esc(deptName(user.department_id))}</td>
              <td>${assigned}</td>
              <td>${kit.esc(user.email || '')}</td>
            </tr>`;
          }).join('')}</tbody>
        </table></div></div>`
      : kit.notice('No accounts', 'People you register will appear here.');
    body = table;
  } catch (error) {
    body = kit.failureNotice(error, 'Lecturers');
  }
  const form = can('user:manage') ? `<form id="user-form" class="stack panel-form">
      <h2>Register someone</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="user-email">Email</label><input id="user-email" name="email" type="email" required></div>
      <div class="field"><label class="field-label" for="user-first">First name</label><input id="user-first" name="first_name" required></div>
      <div class="field"><label class="field-label" for="user-last">Last name</label><input id="user-last" name="last_name" required></div>
      <div class="field"><label class="field-label" for="user-role">Role</label><select id="user-role" name="role"><option value="student">Student</option><option value="lecturer">Lecturer</option><option value="admin">Administrator</option></select></div>
      <div class="field"><label class="field-label" for="user-index">Student ID</label><input id="user-index" name="student_index" inputmode="numeric" autocomplete="off" placeholder="20230410057"></div>
      <div class="field"><label class="field-label" for="user-staff">Staff id</label><input id="user-staff" name="staff_id"></div>
      <p class="muted">The new account is active. They choose a password through forgot password.</p>
      <button class="btn btn-primary" type="submit">Create account</button>
    </form>` : '';
  return page('Lecturers', '', `${body}${form}`);
}

async function coursesView() {
  let body = '';
  try {
    const [result, userRes, allocRes] = await Promise.all([
      kit.api('/courses?per_page=50'),
      kit.api('/users?per_page=50').catch(() => ({ data: [] })),
      kit.api('/allocations?per_page=200').catch(() => ({ data: [] })),
    ]);
    const people = {};
    kit.listOf(userRes.data).forEach((user) => {
      people[user.user_id || user.id] = `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.display_name || '';
    });
    const taught = {};
    kit.listOf(allocRes.data).forEach((row) => {
      const id = row.course?.id || row.course_id;
      const name = row.lecturer?.name || row.lecturer_name;
      if (id && name) {
        taught[id] = name;
      }
    });
    const rows = kit.listOf(result.data);
    body = rows.length
      ? `<div class="panel"><h2>${kit.icon('courses')} Course Registry</h2><div class="table-wrap"><table class="data-table">
          <thead><tr><th>Code</th><th>Course Title</th><th>Department</th><th>Lecturer</th><th>Credits</th><th>Status</th></tr></thead>
          <tbody>${rows.map((course) => {
            const lecturer = taught[course.id] || people[course.default_lecturer_id] || '—';
            return `<tr>
            <td>${codeMark(course.code)}<span class="code-text">${kit.esc(course.code)}</span></td>
            <td>${kit.esc(course.title)}</td>
            <td>${kit.esc(deptName(course.department_id, course.code))}</td>
            <td><span class="who-cell">${kit.icon('profile')}<span>${kit.esc(lecturer)}</span></span></td>
            <td>${kit.esc(course.credit_hours ?? '—')} cr</td>
            <td><span class="status-dot${course.is_active === false ? '' : ' is-on'}">${course.is_active === false ? 'Inactive' : 'Active'}</span></td>
          </tr>`;
          }).join('')}</tbody>
        </table></div></div>`
      : kit.notice('No courses', 'Add the first course for this department.');
  } catch (error) {
    body = kit.failureNotice(error, 'Courses');
  }
  const form = can('course:manage') ? `<form id="course-form" class="stack">
      <h2>New course</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="course-code">Code</label><input id="course-code" name="code" required maxlength="20"></div>
      <div class="field"><label class="field-label" for="course-title">Title</label><input id="course-title" name="title" required></div>
      <div class="field"><label class="field-label" for="course-hours">Meetings per week</label><input id="course-hours" name="meetings_per_week" type="number" min="1" max="7" value="1"></div>
      <div class="field"><label class="field-label" for="course-duration">Minutes</label><input id="course-duration" name="duration_minutes" type="number" min="30" max="480" value="120"></div>
      <div class="field"><label class="field-label" for="course-features">Required features</label><input id="course-features" name="features" placeholder="projector, lab"></div>
      <button class="btn btn-primary" type="submit">Save course</button>
    </form>
    <form id="cohort-form" class="stack">
      <h2>New cohort</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="cohort-course">Course id</label><input id="cohort-course" name="course_id" type="number" required></div>
      <div class="field"><label class="field-label" for="cohort-semester">Semester id</label><input id="cohort-semester" name="semester_id" type="number" required></div>
      <div class="field"><label class="field-label" for="cohort-name">Name</label><input id="cohort-name" name="name" required></div>
      <div class="field"><label class="field-label" for="cohort-lecturer">Lecturer id</label><input id="cohort-lecturer" name="lecturer_id" type="number"></div>
      <button class="btn btn-primary" type="submit">Save cohort</button>
    </form>
    <form id="enrol-form" class="stack">
      <h2>Enrol students</h2>
      <div class="field"><label class="field-label" for="enrol-cohort">Cohort id</label><input id="enrol-cohort" name="cohort_id" type="number" required></div>
      <div class="field"><label class="field-label" for="enrol-students">Student ids</label><input id="enrol-students" name="student_ids" placeholder="4, 5, 6" required></div>
      <button class="btn btn-primary" type="submit">Enrol</button>
    </form>
    <form id="course-edit-form" class="stack">
      <h2>Update a course</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="edit-course-id">Course id</label><input id="edit-course-id" name="id" type="number" required></div>
      <div class="field"><label class="field-label" for="edit-course-title">Title</label><input id="edit-course-title" name="title"></div>
      <div class="field"><label class="field-label" for="edit-course-hours">Meetings per week</label><input id="edit-course-hours" name="meetings_per_week" type="number" min="1" max="7"></div>
      <div class="field"><label class="field-label" for="edit-course-features">Required features</label><input id="edit-course-features" name="features" placeholder="projector, lab"></div>
      <button class="btn btn-primary" type="submit">Update course</button>
    </form>` : '';
  return page('Courses', '', `${body}${form}`);
}

async function calendarView() {
  let body = '';
  try {
    const result = await kit.api('/semesters');
    const semesters = Array.isArray(result.data) ? result.data : kit.listOf(result.data);
    body = semesters.map((semester) => `<article class="record"><header><strong>${kit.esc(semester.name)}</strong>${kit.chip(semester.status)}</header><p>${kit.esc(semester.teaching_start)} to ${kit.esc(semester.teaching_end)} · ${kit.esc(semester.total_weeks)} weeks</p><a data-nav href="/calendar?semester=${kit.esc(semester.id)}">Timeline</a></article>`).join('')
      || kit.notice('No semesters', 'Create the teaching term before allocating rooms.');
    const selected = new URLSearchParams(location.search).get('semester');
    if (selected) {
      const timeline = await kit.api(`/semesters/${encodeURIComponent(selected)}/timeline`);
      const weeks = (timeline.data?.weeks || []).map((week) => `<article class="record"><strong>Week ${kit.esc(week.week)}</strong><p>${kit.esc(week.start)} – ${kit.esc(week.end)}</p></article>`).join('');
      body += `<h2>Timeline</h2><div class="record-list">${weeks}</div>`;
    }
  } catch (error) {
    body = kit.failureNotice(error, 'Calendar');
  }
  const needsDepartment = !(kit.session()?.user || {}).department_id;
  const departmentField = needsDepartment
    ? `<div class="field"><label class="field-label" for="sem-dept">Department id</label><input id="sem-dept" name="department_id" type="number" min="1" required></div>`
    : '';
  const form = can('semester:manage') ? `<form id="semester-form" class="stack">
      <h2>New semester</h2>
      <div class="alert" data-error hidden role="alert"></div>
      ${departmentField}
      <div class="field"><label class="field-label" for="sem-name">Name</label><input id="sem-name" name="name" required maxlength="40"></div>
      <div class="field"><label class="field-label" for="sem-year">Academic year</label><input id="sem-year" name="academic_year" required placeholder="2026/2027"></div>
      <div class="field"><label class="field-label" for="sem-start">Start</label><input id="sem-start" name="start_date" type="date" required></div>
      <div class="field"><label class="field-label" for="sem-end">End</label><input id="sem-end" name="end_date" type="date" required></div>
      <div class="field"><label class="field-label" for="sem-teach-start">Teaching starts</label><input id="sem-teach-start" name="teaching_start" type="date"></div>
      <div class="field"><label class="field-label" for="sem-teach-end">Teaching ends</label><input id="sem-teach-end" name="teaching_end" type="date"></div>
      <button class="btn btn-primary" type="submit">Save semester</button>
    </form>
    <form id="semester-edit-form" class="stack">
      <h2>Update a semester</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="edit-sem-id">Semester id</label><input id="edit-sem-id" name="id" type="number" required></div>
      <div class="field"><label class="field-label" for="edit-sem-status">Status</label><select id="edit-sem-status" name="status"><option value="planning">Planning</option><option value="active">Active</option><option value="closed">Closed</option><option value="archived">Archived</option></select></div>
      <div class="field"><label class="field-label" for="edit-sem-teach-start">Teaching starts</label><input id="edit-sem-teach-start" name="teaching_start" type="date"></div>
      <div class="field"><label class="field-label" for="edit-sem-teach-end">Teaching ends</label><input id="edit-sem-teach-end" name="teaching_end" type="date"></div>
      <button class="btn btn-primary" type="submit">Update semester</button>
    </form>
    <form id="exception-form" class="stack">
      <h2>Calendar entry</h2>
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="ex-semester">Semester id</label><input id="ex-semester" name="semester_id" type="number" required></div>
      <div class="field"><label class="field-label" for="ex-date">Date</label><input id="ex-date" name="exception_date" type="date" required></div>
      <div class="field"><label class="field-label" for="ex-type">Type</label><select id="ex-type" name="type"><option value="holiday">Holiday</option><option value="break">Break</option><option value="exam">Exam</option><option value="makeup">Makeup</option><option value="non_teaching">Non-teaching</option></select></div>
      <div class="field"><label class="field-label" for="ex-label">Label</label><input id="ex-label" name="label" required maxlength="120"></div>
      <button class="btn btn-primary" type="submit">Add entry</button>
    </form>` : '';
  return page('Calendar', 'Academic year', `${form}<div class="record-list">${body}</div>`);
}

async function generateView() {
  let options = '';
  try {
    const result = await kit.api('/semesters');
    const semesters = Array.isArray(result.data) ? result.data : [];
    options = semesters.map((semester) => `<option value="${kit.esc(semester.id)}" data-department="${kit.esc(semester.department_id ?? '')}">${kit.esc(semester.name)}</option>`).join('');
  } catch (error) {
    return page('Allocate Schedule', 'Generate or repair the published week', kit.failureNotice(error, 'Semesters'));
  }
  return page('Allocate Schedule', 'Generate or repair the published week', `<form id="generate-form" class="stack">
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="gen-semester">Semester</label><select id="gen-semester" name="semester_id" required>${options}</select></div>
      <div class="field"><label class="field-label" for="gen-mode">Mode</label><select id="gen-mode" name="mode"><option value="full">Full timetable</option><option value="repair">Repair</option></select></div>
      <fieldset class="days"><legend>Limit to days</legend>
        ${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((label, index) => `<label><input type="checkbox" name="day" value="${index + 1}"> ${label}</label>`).join('')}
      </fieldset>
      <label class="check"><input type="checkbox" name="apply" value="1"> Publish the result</label>
      <button class="btn btn-primary" type="submit">Run allocation</button>
    </form><div id="generate-result"></div>`);
}

async function reportsView() {
  const params = new URLSearchParams(location.search);
  const report = params.get('report') || 'utilisation';
  const paths = {
    utilisation: '/reports/utilisation',
    peak: '/reports/peak-usage',
    conflicts: '/reports/conflicts',
    load: '/reports/lecturer-load',
  };
  let body = '';
  try {
    if (report === 'dashboard') {
      const dash = await kit.api('/dashboard');
      const heat = await kit.api('/dashboard/heat-map');
      const unread = await kit.api('/notifications/unread-count');
      const counts = dash.data || {};
      body = `<div class="stats">
          <article class="stat"><span>${kit.esc(counts.active_users ?? 0)}</span><small>Active people</small></article>
          <article class="stat"><span>${kit.esc(counts.proposed_allocations ?? 0)}</span><small>Proposed</small></article>
          <article class="stat"><span>${kit.esc(counts.open_conflicts ?? 0)}</span><small>Open conflicts</small></article>
          <article class="stat"><span>${kit.esc(counts.available_rooms ?? 0)}</span><small>Rooms free</small></article>
          <article class="stat"><span>${kit.esc(unread.data?.unread_count ?? 0)}</span><small>Unread alerts</small></article>
        </div>` + heatRows(heat.data);
    } else {
      const result = await kit.api(paths[report] || paths.utilisation);
      body = records(result.data, report);
    }
  } catch (error) {
    body = kit.failureNotice(error, 'Reports');
  }
  const tabs = ['dashboard', 'utilisation', 'peak', 'conflicts', 'load'].map((name) => `<a data-nav href="/reports?report=${name}"${name === report ? ' aria-current="page"' : ''}>${kit.esc(name)}</a>`).join('');
  const exportKind = report === 'dashboard' || report === 'peak' ? (report === 'peak' ? 'peak' : 'utilisation') : (report === 'load' ? 'lecturer-load' : report);
  return page(report === 'conflicts' ? 'Conflict Report' : 'Reports', 'Detected clashes and department usage', `<nav class="tools">${tabs}<button class="btn btn-ghost" type="button" data-report-csv="${kit.esc(exportKind)}">Download CSV</button></nav>${body}`);
}

async function auditView() {
  let body = '';
  try {
    const result = await kit.api('/audit?per_page=40');
    body = kit.listOf(result.data).map((row) => `<article class="record"><header><strong>${kit.esc(row.action)}</strong><time>${kit.esc(row.created_at || '')}</time></header><p>${kit.esc(row.entity_type)} ${kit.esc(row.entity_id ?? '')} · ${kit.esc(row.actor_role || 'system')}</p></article>`).join('')
      || kit.notice('No audit rows', 'Overrides and account changes are recorded here.');
  } catch (error) {
    body = kit.failureNotice(error, 'Audit');
  }
  return page('Audit', 'History', `<div class="record-list">${body}</div>`);
}

async function availabilityView() {
  const params = new URLSearchParams(location.search);
  const semester = params.get('semester_id') || '';
  let body = '';
  try {
    const query = semester ? `?semester_id=${encodeURIComponent(semester)}` : '';
    const result = await kit.api(`/availability/lecturers${query}`);
    const rows = Array.isArray(result.data) ? result.data : [];
    body = rows.map((row) => `<article class="record"><p>Day ${kit.esc(row.day_of_week || 'all')} · ${kit.esc(row.reason || 'Unavailable')}</p><button class="btn btn-ghost" type="button" data-drop-availability="${kit.esc(row.id)}">Remove</button></article>`).join('')
      || kit.notice('No blocks', 'Mark a day you cannot teach.');
  } catch (error) {
    body = kit.failureNotice(error, 'Availability');
  }
  return page('Availability', 'Your week', `<form id="availability-form" class="stack">
      <div class="alert" data-error hidden role="alert"></div>
      <div class="field"><label class="field-label" for="av-semester">Semester id</label><input id="av-semester" name="semester_id" type="number" required value="${kit.esc(semester)}"></div>
      <div class="field"><label class="field-label" for="av-day">Day (1 Monday – 7 Sunday)</label><input id="av-day" name="day_of_week" type="number" min="1" max="7"></div>
      <div class="field"><label class="field-label" for="av-reason">Reason</label><input id="av-reason" name="reason" maxlength="150"></div>
      <button class="btn btn-primary" type="submit">Block this time</button>
    </form><div class="record-list">${body}</div>`);
}

async function findView() {
  const params = new URLSearchParams(location.search);
  const q = params.get('q') || '';
  const day = params.get('day_of_week') || '';
  let body = kit.notice('Search the timetable', 'Use a course, a lecturer, a cohort, or a day.');
  if (q || day) {
    try {
      const query = new URLSearchParams();
      if (q) query.set('q', q);
      if (day) query.set('day_of_week', day);
      const result = await kit.api(`/search/schedules?${query.toString()}`);
      const rows = kit.listOf(result.data);
      body = rows.map((row) => `<article class="record"><header><span class="code">${kit.esc(row.course?.code || '')}</span>${kit.chip(row.status)}</header><h2>${kit.esc(row.course?.title || '')}</h2><p>${kit.esc(row.lecturer?.name || '')} · ${kit.esc(row.room?.code || '')} · day ${kit.esc(row.time_slot?.day_of_week || '')}</p></article>`).join('')
        || kit.notice('Nothing matches', 'Try another course, lecturer, or day.');
    } catch (error) {
      body = kit.failureNotice(error, 'Schedule search');
    }
  }
  return page('Find a class', 'Search', `<form class="search" data-search="find" role="search">
      <input type="search" name="q" value="${kit.esc(q)}" placeholder="Course, lecturer, or cohort" aria-label="Search schedules">
      <select name="day_of_week" aria-label="Day"><option value="">Any day</option>${[1, 2, 3, 4, 5, 6, 7].map((number) => `<option value="${number}"${String(number) === day ? ' selected' : ''}>Day ${number}</option>`).join('')}</select>
      <button class="btn btn-primary" type="submit">Search</button>
    </form><div class="record-list">${body}</div>`);
}

function records(data, report) {
  const rows = Array.isArray(data) ? data : kit.listOf(data);
  if (!rows.length) {
    return kit.notice('Nothing to report', 'Generate a timetable and the figures will fill in.');
  }
  if (report === 'conflicts') {
    return `<div class="record-list">${rows.map((row) => `<article class="record"><header><strong>${kit.esc(row.constraint_code)}</strong>${kit.chip(row.severity)}</header><p>${kit.esc(row.message)}</p>${row.resolved_at ? '' : `<button class="btn btn-ghost" type="button" data-resolve="${kit.esc(row.id)}">Resolve</button>`}</article>`).join('')}</div>`;
  }
  return `<div class="record-list">${rows.slice(0, 40).map((row) => `<article class="record"><p>${Object.entries(row).slice(0, 6).map(([key, value]) => `<span>${kit.esc(key)}: ${kit.esc(value ?? '')}</span>`).join(' · ')}</p></article>`).join('')}</div>`;
}

function heatRows(data) {
  const rows = Array.isArray(data) ? data : [];
  if (!rows.length) {
    return kit.notice('No peak data', 'Peak hours appear after classes are published.');
  }
  const max = Math.max(1, ...rows.map((row) => Number(row.session_count) || 0));
  return `<div class="record-list">${rows.map((row) => {
    const step = Math.min(10, Math.max(1, Math.round((Number(row.session_count) / max) * 10)));
    return `<article class="record"><span>Day ${kit.esc(row.day_of_week)} · ${kit.esc(row.start_hour)}:00</span><span class="bar bar-${step}" aria-hidden="true"><span></span></span><span>${kit.esc(row.session_count)} sessions</span></article>`;
  }).join('')}</div>`;
}

export function failureText(reason, fallback = 'That did not save.') {
  const fields = reason?.details?.fields;
  if (fields && typeof fields === 'object') {
    const lines = Object.values(fields).flat().filter(Boolean);
    if (lines.length) {
      return lines.join(' ');
    }
  }
  return reason?.message || fallback;
}

function splitCodes(value) {
  return String(value || '').split(',').map((part) => part.trim()).filter(Boolean);
}

async function submitAccount(form, path, success, next) {
  const error = form.querySelector('[data-error]');
  error.hidden = true;
  const body = Object.fromEntries(new FormData(form));
  try {
    await kit.api(path, { method: 'POST', body });
    kit.toast(success);
    kit.go(next);
  } catch (reason) {
    error.hidden = false;
    error.textContent = failureText(reason);
  }
}

async function saveProfile(form) {
  const error = form.querySelector('[data-error]');
  error.hidden = true;
  try {
    const result = await kit.api('/profile', { method: 'PATCH', body: Object.fromEntries(new FormData(form)) });
    const current = kit.session() || {};
    kit.saveSession({ user: { ...(current.user || {}), ...(result.data || {}) } });
    kit.toast('Profile saved.');
  } catch (reason) {
    error.hidden = false;
    error.textContent = failureText(reason);
  }
}

async function savePassword(form) {
  const error = form.querySelector('[data-error]');
  error.hidden = true;
  try {
    await kit.api('/profile/password', { method: 'PATCH', body: Object.fromEntries(new FormData(form)) });
    kit.saveSession({ must_change_password: false });
    kit.toast('Password changed. Sign in again on your other devices.');
    form.reset();
  } catch (reason) {
    error.hidden = false;
    error.textContent = failureText(reason);
  }
}

async function postForm(form, path, success, next, shape) {
  const error = form.querySelector('[data-error]');
  if (error) {
    error.hidden = true;
  }
  let body = Object.fromEntries(new FormData(form));
  Object.keys(body).forEach((key) => {
    if (body[key] === '') delete body[key];
  });
  if (shape) {
    body = shape(body);
  }
  try {
    await kit.api(path, { method: 'POST', body });
    kit.toast(success);
    kit.go(next);
  } catch (reason) {
    if (error) {
      error.hidden = false;
      error.textContent = failureText(reason);
    } else {
      kit.toast(failureText(reason));
    }
  }
}

async function runGenerate(form) {
  const error = form.querySelector('[data-error]');
  error.hidden = true;
  const data = new FormData(form);
  const days = data.getAll('day').map((day) => Number(day));
  const body = {
    semester_id: Number(data.get('semester_id')),
    mode: data.get('mode') || 'full',
    apply: data.get('apply') === '1',
    execution: 'sync',
  };
  const user = kit.session()?.user || {};
  const departmentId = form.elements.semester_id?.selectedOptions?.[0]?.dataset.department;
  if (!user.department_id && departmentId) {
    body.department_id = Number(departmentId);
  }
  if (days.length) {
    body.scope = { days };
  }
  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const result = await kit.api('/allocations/generate', { method: 'POST', body });
    const run = result.data?.run || {};
    const host = document.getElementById('generate-result');
    if (host) {
      host.innerHTML = `<article class="record"><header><strong>${result.data?.applied ? 'Published' : 'Recorded'}</strong></header><p>Accuracy ${kit.esc(run.accuracy ?? '—')} · ${kit.esc(run.assigned_sessions ?? 0)} of ${kit.esc(run.total_sessions ?? 0)} sessions placed.</p></article>`;
    }
    kit.toast(result.data?.applied ? 'Timetable published.' : 'Run recorded. It was not published.');
  } catch (reason) {
    error.hidden = false;
    error.textContent = failureText(reason, 'The allocation did not run.');
  } finally {
    button.disabled = false;
  }
}

async function moveClass(form) {
  const body = Object.fromEntries(new FormData(form));
  const payload = {
    reason: body.reason,
    force: body.force === '1',
    repair: body.repair === '1',
  };
  if (body.room_id) payload.room_id = Number(body.room_id);
  if (body.time_slot_id) payload.time_slot_id = Number(body.time_slot_id);
  await act(`/allocations/${body.id}/reassign`, 'Class updated.', payload);
}

async function updatePerson(form) {
  const id = form.dataset.userId;
  const data = new FormData(form);
  try {
    await kit.api(`/users/${id}`, { method: 'PATCH', body: { status: data.get('status') } });
    await kit.api(`/users/${id}/role`, { method: 'PATCH', body: { role: data.get('role') } });
    kit.toast('Account updated.');
    kit.go('/users');
  } catch (error) {
    kit.toast(failureText(error, 'That account could not be updated.'));
  }
}

async function patchResource(form, collection, success, next, shape) {
  const error = form.querySelector('[data-error]');
  if (error) error.hidden = true;
  let body = Object.fromEntries(new FormData(form));
  const id = body.id;
  delete body.id;
  Object.keys(body).forEach((key) => {
    if (body[key] === '') delete body[key];
  });
  if (shape) body = shape(body);
  try {
    await kit.api(`${collection}/${id}`, { method: 'PATCH', body });
    kit.toast(success);
    kit.go(next);
  } catch (reason) {
    if (error) {
      error.hidden = false;
      error.textContent = failureText(reason);
    }
  }
}

async function compareRooms(form) {
  const ids = String(new FormData(form).get('ids') || '');
  try {
    const result = await kit.api(`/rooms/compare?ids=${encodeURIComponent(ids)}`);
    const host = document.getElementById('compare-result');
    const rooms = Array.isArray(result.data) ? result.data : [];
    if (host) {
      host.innerHTML = rooms.map((room) => `<article class="record"><strong>${kit.esc(room.code)}</strong><p>Capacity ${kit.esc(room.capacity)} · ${kit.esc((room.features || []).map((feature) => kit.pretty(feature)).join(' · ') || 'no features')}</p></article>`).join('');
    }
  } catch (error) {
    kit.toast(failureText(error, 'Those rooms could not be compared.'));
  }
}

async function downloadReport(kind) {
  const token = kit.session()?.access_token;
  try {
    const response = await fetch(`/api/v1/reports/export.csv?report=${encodeURIComponent(kind)}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!response.ok) {
      throw new Error('The export could not be downloaded.');
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `catms-${kind}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  } catch (error) {
    kit.toast(failureText(error, 'The export could not be downloaded.'));
  }
}

async function act(path, success, body, method = 'POST') {
  try {
    await kit.api(path, { method, body: body || {} });
    kit.toast(success);
    return true;
  } catch (error) {
    kit.toast(failureText(error, 'That change was refused.'));
    return false;
  }
}
