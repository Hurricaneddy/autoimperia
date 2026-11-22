function escapeDemoHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
}
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * assets/js/admin-timeline.js
 * Логика диспетчерского таймлайна постов и управления заявками
 */

// Вспомогательная функция формирования пути с учетом базового префикса домена/папки
function getAppPath(endpoint) {
  const base = (typeof window !== 'undefined' && window.APP_BASE !== undefined) ? window.APP_BASE : '';
  const clean = endpoint.startsWith('/') ? endpoint.slice(1) : endpoint;
  return base ? `${base.replace(/\/+$/, '')}/${clean}` : `/${clean}`;
}

const shopDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit' });
const orderDate = new Intl.DateTimeFormat('ru-RU', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' });
const AdminState = {
  currentDate: shopDate.format(new Date()),
  timelineData: null,
  orders: { visible: false, rows: [], page: 1, pages: 1, filter: 'all', request: 0 },
  currentModalApp: null,
  autoRefreshTimer: null
};

document.addEventListener('DOMContentLoaded', () => {
  initDateControls();
  initViewSwitch();
  initTableFilters();
  initQuickAddForm();
  loadTimelineData();

  // Автообновление каждые 30 секунд
  AdminState.autoRefreshTimer = setInterval(() => {
    loadTimelineData(false);
  }, 30000);
});

/**
 * 1. Управление датой расписания
 */
function initDateControls() {
  const dateInput = document.getElementById('timelineDateInput');
  const btnPrev = document.getElementById('btnPrevDay');
  const btnNext = document.getElementById('btnNextDay');
  const btnToday = document.getElementById('btnToday');

  if (dateInput) {
    AdminState.currentDate = dateInput.value || AdminState.currentDate;
    dateInput.addEventListener('change', () => {
      if (!dateInput.value) { dateInput.value = AdminState.currentDate; return; }
      AdminState.currentDate = dateInput.value;
      loadTimelineData();
    });
  }

  btnPrev?.addEventListener('click', () => changeDateDelta(-1));
  btnNext?.addEventListener('click', () => changeDateDelta(1));
  btnToday?.addEventListener('click', () => {
    AdminState.currentDate = shopDate.format(new Date());
    if (dateInput) dateInput.value = AdminState.currentDate;
    loadTimelineData();
  });
}

function changeDateDelta(days) {
  const d = new Date(AdminState.currentDate);
  d.setUTCDate(d.getUTCDate() + days);
  AdminState.currentDate = d.toISOString().split('T')[0];
  const dateInput = document.getElementById('timelineDateInput');
  if (dateInput) dateInput.value = AdminState.currentDate;
  loadTimelineData();
}

/**
 * 2. Загрузка данных таймлайна с сервера
 */
async function loadTimelineData(showLoading = true) {
  const date = AdminState.currentDate;
  if (AdminState.orders.visible) loadOrders();
  const grid = document.getElementById('timelineGrid');
  if (showLoading && grid) {
    grid.innerHTML = '<div style="padding:40px; text-align:center; color:#94a3b8;">Загрузка расписания постов...</div>';
  }

  try {
    const res = await fetch(getAppPath(`api/admin/get_timeline.php?date=${encodeURIComponent(date)}`));
    if (res.status === 401) {
      window.location.href = getAppPath('admin/login.php');
      return;
    }
    const data = await res.json();

    if (data.success && date === AdminState.currentDate) {
      AdminState.timelineData = data;
      renderKPI(data.summary);
      renderTimeline(data);
    }
  } catch (err) {
    console.error('Ошибка загрузки таймлайна:', err);
  }
}

async function loadOrders(page = AdminState.orders.page) {
  const table = AdminState.orders;
  const request = ++table.request;
  table.page = page;
  const info = document.getElementById('tablePageInfo');
  const previous = document.getElementById('tablePagePrev');
  const next = document.getElementById('tablePageNext');
  previous.disabled = next.disabled = true;
  const query = new URLSearchParams({
    view: 'journal',
    scope: document.getElementById('tableScope').value,
    sort: document.getElementById('tableSort').value,
    q: document.getElementById('tableSearchInput').value.trim(),
    status: table.filter,
    date: AdminState.currentDate,
    page,
  });
  try {
    const res = await fetch(getAppPath(`api/admin/get_timeline.php?${query}`));
    if (request !== table.request) return;
    if (res.status === 401) {
      window.location.href = getAppPath('admin/login.php');
      return;
    }
    const data = await res.json();
    if (request !== table.request) return;
    if (!res.ok || !data.success) throw new Error('Не удалось получить заказы');
    Object.assign(table, { rows: data.orders, bays: data.bays, page: data.page, pages: data.pages });
    renderTable(table.rows);
    previous.disabled = table.page <= 1;
    next.disabled = table.page >= table.pages;
    info.textContent = `Страница ${table.page} из ${table.pages} · Заказов: ${data.total}`;
  } catch (err) {
    if (request !== table.request) return;
    info.textContent = 'Журнал не загрузился. Нажмите «Обновить».';
  }
}

/**
 * 3. Отрисовка KPI показателей
 */
function renderKPI(summary) {
  if (!summary) return;
  document.getElementById('kpiCountNew').textContent = summary.new || 0;
  document.getElementById('kpiCountConfirmed').textContent = summary.confirmed || 0;
  document.getElementById('kpiCountInWork').textContent = summary.in_work || 0;
  document.getElementById('kpiCountDone').textContent = summary.done || 0;
  document.getElementById('kpiRevenue').textContent = `${(summary.revenue || 0).toLocaleString('ru-RU')} ₽`;
}

/**
 * 4. Отрисовка календарной сетки постов (Timeline)
 */
function renderTimeline(data) {
  const grid = document.getElementById('timelineGrid');
  if (!grid) return;

  const hours = ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00'];
  const startHour = 9;
  const totalMinutes = 11 * 60; // с 09:00 до 20:00 = 660 минут

  let html = `
    <div class="timeline-time-header">
      <div class="timeline-bay-col-header">Ремонтный пост</div>
      <div class="timeline-hours">
        ${hours.map(h => `<div class="time-hour-cell">${h}</div>`).join('')}
      </div>
    </div>
  `;

  data.bays.forEach(bay => {
    const bayApps = data.timeline[bay.bay_id] || [];

    html += `
      <div class="timeline-bay-row">
        <div class="timeline-bay-info">
          <div class="bay-name">${bay.bay_name}</div>
          <div class="bay-spec">Макс. вес: ${bay.max_weight}т</div>
        </div>
        <div class="timeline-slots-area">
          ${hours.slice(0, -1).map(() => `<div class="slot-cell-bg"></div>`).join('')}
    `;

    bayApps.forEach(app => {
      // Расчет координат блока
      const startParts = app.start_hm.split(':');
      const startMinTotal = (parseInt(startParts[0], 10) - startHour) * 60 + parseInt(startParts[1], 10);

      const endParts = app.end_hm.split(':');
      const endMinTotal = (parseInt(endParts[0], 10) - startHour) * 60 + parseInt(endParts[1], 10);

      const durationMin = Math.max(30, endMinTotal - startMinTotal);

      const leftPercent = Math.max(0, (startMinTotal / totalMinutes) * 100);
      const widthPercent = Math.min(100 - leftPercent, (durationMin / totalMinutes) * 100);

      const statusClass = `st-${app.status}`;

      html += `
        <div class="app-block ${statusClass}" 
             style="left: ${leftPercent}%; width: ${widthPercent}%;"
             onclick="openAppointmentModal(${app.appointment_id})"
             title="${app.booking_code}: ${escapeDemoHtml(app.brand)} ${escapeDemoHtml(app.model)} — ${escapeDemoHtml(app.client_name)}">
          <div class="app-block-header">
            <span>${app.start_hm} - ${app.end_hm}</span>
            <span style="font-family:var(--font-mono);">${app.booking_code}</span>
          </div>
          <div class="app-block-car">${escapeDemoHtml(app.brand)} ${escapeDemoHtml(app.model)}</div>
          <div class="app-block-client">${uiIcon('user')} ${escapeDemoHtml(app.client_name)} &bull; <strong>${parseInt(app.total_price, 10).toLocaleString('ru-RU')} ₽</strong></div>
        </div>
      `;
    });

    html += `</div></div>`;
  });

  grid.innerHTML = html;
}

/**
 * 5. Отрисовка табличного журнала записей
 */
function renderTable(records) {
  const tbody = document.getElementById('tableBody');
  if (!tbody) return;

  if (records.length === 0) {
    tbody.innerHTML = '<tr><td colspan="11" class="empty-orders">Заказы по этим условиям не найдены</td></tr>';
    return;
  }

  const statusLabels = {
    'new': { text: 'Новая с сайта', cls: 'badge-new' },
    'confirmed': { text: 'Подтверждена', cls: 'badge-confirmed' },
    'in_work': { text: 'В работе в цехе', cls: 'badge-in_work' },
    'done': { text: 'Работы завершены', cls: 'badge-done' },
    'canceled': { text: 'Отменена', cls: 'badge-canceled' }
  };

  let html = '';
  records.forEach(r => {
    const st = statusLabels[r.status] || { text: r.status, cls: '' };
    const payStatus = r.payment_status === 'paid' 
      ? `<span style="color:#10b981; font-weight:700;">${uiIcon('check')} Оплачено</span>` 
      : (r.paid_amount > 0 ? `<span style="color:#0284c7; font-weight:600;">Предоплата ${r.paid_amount} ₽</span>` : '<span style="color:#94a3b8;">Не оплачено</span>');

    html += `
      <tr>
        <td style="font-family:var(--font-mono); font-weight:700;">${r.booking_code}</td>
        <td class="order-visit"><strong>${orderDate.format(new Date(r.visit_date))}</strong><br>${r.start_hm}–${r.end_hm}</td>
        <td>Пост №${r.bay_id || '—'}</td>
        <td><strong>${escapeDemoHtml(r.brand)} ${escapeDemoHtml(r.model)}</strong> ${r.prod_year ? `(${r.prod_year})` : ''}</td>
        <td>
          <div style="font-weight:600;">${escapeDemoHtml(r.client_name)}</div>
          <div style="font-size:0.75rem; color:#94a3b8;">${escapeDemoHtml(r.client_phone)}</div>
        </td>
        <td>
          <div style="max-width:240px; font-size:0.8rem; color:#cbd5e1;">
            ${(r.services || []).map(s => escapeDemoHtml(s.title)).join(', ') || 'Работы по ТО'}
          </div>
        </td>
        <td><div class="order-comment-preview">${escapeDemoHtml(r.client_comment?.trim() || '—')}</div></td>
        <td style="font-weight:700; color:#f87171;">${parseInt(r.total_price, 10).toLocaleString('ru-RU')} ₽</td>
        <td>${payStatus}</td>
        <td><span class="status-badge ${st.cls}">${st.text}</span></td>
        <td>
          <button type="button" class="btn-refresh" style="padding:4px 8px;" onclick="openAppointmentModal(${r.appointment_id})">
            Открыть
          </button>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

/**
 * 6. Модальное окно заявки (Смена статуса мастером)
 */
function openAppointmentModal(appId) {
  const table = AdminState.orders;
  const all = table.visible ? table.rows : AdminState.timelineData?.all_records || [];
  const app = all.find(a => a.appointment_id === appId);
  if (!app) return;
  const bays = table.visible ? table.bays : AdminState.timelineData.bays;

  AdminState.currentModalApp = app;

  document.getElementById('modalAppTitle').textContent = `Заявка ${app.booking_code}`;
  const badge = document.getElementById('modalAppBadge');
  badge.textContent = app.status.toUpperCase();
  badge.className = `status-badge badge-${app.status}`;

  const content = document.getElementById('modalAppContent');
  content.innerHTML = `
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:16px;">
      <div style="background:#0f172a; padding:12px; border-radius:8px;">
        <div style="font-size:0.75rem; color:#94a3b8;">Клиент:</div>
        <div style="font-weight:700; font-size:1rem; margin-top:2px;">${escapeDemoHtml(app.client_name)}</div>
        <div style="font-size:0.85rem; color:#38bdf8; margin-top:2px;">${uiIcon('phone')} ${escapeDemoHtml(app.client_phone)}</div>
      </div>
      <div style="background:#0f172a; padding:12px; border-radius:8px;">
        <div style="font-size:0.75rem; color:#94a3b8;">Автомобиль:</div>
        <div style="font-weight:700; font-size:1rem; margin-top:2px;">${escapeDemoHtml(app.brand)} ${escapeDemoHtml(app.model)} (${app.prod_year || '—'})</div>
        <div style="font-size:0.85rem; color:#94a3b8; margin-top:2px;">Госномер: ${escapeDemoHtml(app.plate_number || 'не указан')}</div>
      </div>
    </div>

    <div style="background:#0f172a; padding:12px; border-radius:8px; margin-bottom:16px;">
      <div style="font-size:0.75rem; color:#94a3b8; margin-bottom:6px;">Выбранные услуги и запчасти:</div>
      <ul style="margin-left:18px; font-size:0.88rem;">
        ${(app.services || []).map(s => `<li>${escapeDemoHtml(s.title)} — ${parseInt(s.price_at_order, 10).toLocaleString('ru-RU')} ₽ (${s.duration_min} мин)</li>`).join('')}
      </ul>
      <div style="margin-top:10px; padding-top:8px; border-top:1px dashed #334155; display:flex; justify-content:space-between; font-weight:700;">
        <span>Итоговая сумма:</span>
        <span style="color:#f87171; font-size:1.1rem;">${parseInt(app.total_price, 10).toLocaleString('ru-RU')} ₽</span>
      </div>
    </div>

    <section class="order-comment">
      <h4>Пожелания клиента и симптомы</h4>
      <p>${escapeDemoHtml(app.client_comment?.trim() || 'Клиент не оставил комментарий.')}</p>
    </section>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Назначенный пост</label>
        <select id="modalSelectBay" class="form-input">
          ${bays.map(b => `<option value="${b.bay_id}" ${b.bay_id === app.bay_id ? 'selected' : ''}>${escapeDemoHtml(b.bay_name)}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Внутренняя заметка мастера</label>
        <input type="text" id="modalInternalNotes" class="form-input" value="${escapeDemoHtml(app.internal_notes || '')}" placeholder="Запчасти на складе...">
      </div>
    </div>
  `;

  // Кнопки смены статуса
  const footer = document.getElementById('modalAppFooter');
  footer.innerHTML = `
    <button type="button" class="btn-cancel" onclick="closeModal('modalAppointment')">Закрыть</button>
    ${app.status === 'new' ? `<button type="button" class="btn-save" style="background:#0284c7;" onclick="updateAppStatus(${app.appointment_id}, 'confirmed')">${uiIcon('check')} Подтвердить запись</button>` : ''}
    ${app.status === 'confirmed' ? `<button type="button" class="btn-save" style="background:#ea580c;" onclick="updateAppStatus(${app.appointment_id}, 'in_work')">${uiIcon('car')} Принять в цех (В работе)</button>` : ''}
    ${app.status === 'in_work' ? `<button type="button" class="btn-save" style="background:#10b981;" onclick="updateAppStatus(${app.appointment_id}, 'done')">${uiIcon('flag')} Работы завершены / Готов</button>` : ''}
    ${app.status !== 'canceled' && app.status !== 'done' ? `<button type="button" class="btn-cancel" style="color:#f87171;" onclick="updateAppStatus(${app.appointment_id}, 'canceled')">${uiIcon('close')} Отменить</button>` : ''}
  `;

  document.getElementById('modalAppointment')?.classList.add('active');
}

async function updateAppStatus(appId, newStatus) {
  const baySelect = document.getElementById('modalSelectBay');
  const notesInput = document.getElementById('modalInternalNotes');

  const bayId = baySelect ? parseInt(baySelect.value, 10) : null;
  const notes = notesInput ? notesInput.value.trim() : null;

  try {
    const res = await fetch(getAppPath('api/admin/update_status.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appointment_id: appId,
        status: newStatus,
        bay_id: bayId,
        internal_notes: notes
      })
    });
    const result = await res.json();

    if (result.success) {
      closeModal('modalAppointment');
      loadTimelineData(false);
    } else {
      alert(result.error || 'Ошибка при обновлении статуса');
    }
  } catch (err) {
    alert('Сетевая ошибка при смене статуса');
  }
}

/**
 * 7. Быстрая запись при телефонном звонке
 */
function initQuickAddForm() {
  const form = document.getElementById('formQuickAdd');
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const payload = {
      phone: document.getElementById('qaPhone').value.trim(),
      full_name: document.getElementById('qaName').value.trim(),
      brand: document.getElementById('qaBrand').value.trim(),
      model: document.getElementById('qaModel').value.trim(),
      plate_number: document.getElementById('qaPlate').value.trim(),
      visit_date: document.getElementById('qaDate').value,
      start_time: document.getElementById('qaTime').value,
      bay_id: document.getElementById('qaBay').value,
      duration_min: document.getElementById('qaDuration').value,
      total_price: document.getElementById('qaPrice').value,
      comment: document.getElementById('qaComment').value.trim()
    };

    try {
      const res = await fetch(getAppPath('api/admin/quick_add.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();

      if (res.ok && data.success) {
        alert(`Запись успешно оформлена! Номер брони: ${data.booking_code}`);
        closeModal('modalQuickAdd');
        loadTimelineData(false);
      } else {
        alert(data.error || 'Ошибка при создании быстрой записи');
      }
    } catch (err) {
      alert('Ошибка при связи с сервером');
    }
  });
}

function openQuickAddModal() {
  document.getElementById('qaDate').value = AdminState.currentDate;
  document.getElementById('modalQuickAdd')?.classList.add('active');
}

function closeModal(modalId) {
  document.getElementById(modalId)?.classList.remove('active');
}

/**
 * 8. Переключение видов и фильтров
 */
function initViewSwitch() {
  const tabs = document.querySelectorAll('.view-tab');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      const view = tab.getAttribute('data-view');
      AdminState.orders.visible = view === 'table';
      document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));

      if (view === 'timeline') {
        document.getElementById('panelTimeline')?.classList.add('active');
      } else {
        document.getElementById('panelTable')?.classList.add('active');
        loadOrders();
      }
    });
  });
}

function initTableFilters() {
  const table = AdminState.orders;
  const pills = document.querySelectorAll('#tableFilterPills .pill');
  const setFilter = active => {
    table.filter = active;
    pills.forEach(pill => {
      const selected = pill.dataset.status === active;
      pill.classList.toggle('active', selected);
      pill.setAttribute('aria-pressed', String(selected));
    });
  };
  setFilter('all');
  pills.forEach(pill => {
    pill.addEventListener('click', () => {
      setFilter(pill.dataset.status);
      loadOrders(1);
    });
  });
  document.getElementById('tableScope').addEventListener('change', event => {
    const history = event.target.value === 'history';
    pills.forEach(pill => {
      pill.disabled = history && !['all', 'done', 'canceled'].includes(pill.dataset.status);
    });
    setFilter('all');
    loadOrders(1);
  });
  document.getElementById('tableSort').addEventListener('change', () => loadOrders(1));
  document.getElementById('tablePagePrev').addEventListener('click', () => loadOrders(table.page - 1));
  document.getElementById('tablePageNext').addEventListener('click', () => loadOrders(table.page + 1));
  let searchTimer;
  document.getElementById('tableSearchInput').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadOrders(1), 250);
  });
}

