<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Главный экран АРМ мастера-приёмщика (admin/index.php)
 */

require_once __DIR__ . '/../includes/ui_icons.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Проверка сессии мастера
requireStaffAuth();

$currentUser = getAuthUser();
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) 
    ? $_GET['date'] 
    : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>АРМ Мастера-приёмщика | Автосервис «Автоимперия»</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <script>window.APP_BASE = "<?= BASE_URL ?>";</script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin-panel.css">
</head>
<body class="admin-body">

  <!-- Верхняя панель АРМ -->
  <header class="admin-header">
    <div class="admin-brand">
      <div class="brand-crown"><?= uiIcon('crown') ?></div>
      <div>
        <div class="admin-title">Авто<span>империя</span></div>
        <div class="admin-sub">Диспетчерский пульт постов • Сергиев Посад</div>
      </div>
    </div>

    <!-- Управление датой -->
    <div class="date-navigator">
      <button type="button" class="btn-nav-date" id="btnPrevDay">← Вчера</button>
      <input type="date" id="timelineDateInput" class="date-input" value="<?= htmlspecialchars($selectedDate) ?>">
      <button type="button" class="btn-nav-date" id="btnToday">Сегодня</button>
      <button type="button" class="btn-nav-date" id="btnNextDay">Завтра →</button>
    </div>

    <!-- Действия мастера -->
    <div class="admin-actions">
      <button type="button" class="btn-quick-add" onclick="openQuickAddModal()">
        <span>+</span> Новая запись по телефону
      </button>

      <div class="user-pill">
        <span class="user-avatar"><?= uiIcon('user') ?></span>
        <div class="user-info">
          <div class="user-name"><?= htmlspecialchars($currentUser['full_name']) ?></div>
          <div class="user-role"><?= htmlspecialchars($currentUser['role'] === 'admin' ? 'Старший мастер' : 'Мастер-приёмщик') ?></div>
        </div>
        <a href="<?= BASE_URL ?>/admin/login.php?action=logout" class="btn-logout" title="Выйти из системы"><?= uiIcon('exit') ?> Выход</a>
      </div>
    </div>
  </header>

  <!-- Основной контейнер -->
  <main class="admin-container">

    <!-- Статистические показатели за день -->
    <section class="kpi-strip">
      <div class="kpi-card kpi-new">
        <div class="kpi-label">Новые онлайн-заявки</div>
        <div class="kpi-val" id="kpiCountNew">0</div>
        <div class="kpi-desc">Требуют подтверждения</div>
      </div>
      <div class="kpi-card kpi-confirmed">
        <div class="kpi-label">Подтверждено на сегодня</div>
        <div class="kpi-val" id="kpiCountConfirmed">0</div>
        <div class="kpi-desc">Ожидают прибытия авто</div>
      </div>
      <div class="kpi-card kpi-inwork">
        <div class="kpi-label">Автомобилей в ремзоне</div>
        <div class="kpi-val" id="kpiCountInWork">0</div>
        <div class="kpi-desc">Выполняются работы</div>
      </div>
      <div class="kpi-card kpi-done">
        <div class="kpi-label">Работы завершены</div>
        <div class="kpi-val" id="kpiCountDone">0</div>
        <div class="kpi-desc">Готовы к выдаче</div>
      </div>
      <div class="kpi-card kpi-revenue">
        <div class="kpi-label">Выручка по заявкам</div>
        <div class="kpi-val" id="kpiRevenue">0 ₽</div>
        <div class="kpi-desc">С учетом запчастей</div>
      </div>
    </section>

    <!-- Переключатель видов: Сетка постов (Timeline) / Табличный журнал -->
    <div class="view-switch-bar">
      <div class="view-tabs">
        <button type="button" class="view-tab active" data-view="timeline">
          <?= uiIcon('calendar') ?> Сетка загрузки постов (Таймлайн)
        </button>
        <button type="button" class="view-tab" data-view="table">
          <?= uiIcon('list') ?> Журнал заявок списком
        </button>
      </div>

      <div class="auto-refresh-box">
        <span class="live-pulse"></span>
        <span style="font-size: 0.85rem; color: #94a3b8;">Автообновление: <strong>30 сек</strong></span>
        <button type="button" class="btn-refresh" onclick="loadTimelineData()"><?= uiIcon('refresh') ?> Обновить</button>
        <a href="<?= BASE_URL ?>/" target="_blank" class="btn-to-site"><?= uiIcon('globe') ?> Клиентский сайт</a>
      </div>
    </div>

    <!-- ВИД 1: ДИСПЕТЧЕРСКИЙ ТАЙМЛАЙН ПОСТОВ -->
    <div class="view-panel active" id="panelTimeline">
      <div class="timeline-legend">
        <div class="legend-item"><span class="badge-dot dot-new"></span> Новая с сайта</div>
        <div class="legend-item"><span class="badge-dot dot-confirmed"></span> Подтверждена</div>
        <div class="legend-item"><span class="badge-dot dot-inwork"></span> В работе в цехе</div>
        <div class="legend-item"><span class="badge-dot dot-done"></span> Готов к выдаче</div>
        <div class="legend-item"><span class="badge-dot dot-canceled"></span> Отменена</div>
      </div>

      <div class="timeline-wrapper">
        <div class="timeline-grid" id="timelineGrid">
          <!-- Рендерится через JS admin-timeline.js -->
          <div style="padding: 40px; text-align: center; color: #94a3b8;">
            Загрузка расписания постов...
          </div>
        </div>
      </div>
    </div>

    <!-- ВИД 2: ТАБЛИЧНЫЙ ЖУРНАЛ ЗАПИСЕЙ -->
    <div class="view-panel" id="panelTable">
      <div class="table-controls">
        <div class="order-controls">
          <label>Поиск
            <input type="search" id="tableSearchInput" class="table-search" placeholder="Код, авто, имя, телефон или пожелания">
          </label>
          <label>Заказы
            <select id="tableScope" class="form-input">
              <option value="all">Все даты</option>
              <option value="day">Дата из календаря</option>
              <option value="history">История (завершённые и отменённые)</option>
            </select>
          </label>
          <label>Сортировка
            <select id="tableSort" class="form-input">
              <option value="created">Сначала новые заказы</option>
              <option value="visit_asc">Ближайшая дата визита</option>
              <option value="visit_desc">Самая поздняя дата визита</option>
              <option value="price_desc">Сначала дорогие</option>
              <option value="price_asc">Сначала недорогие</option>
            </select>
          </label>
        </div>
        <div class="status-filter-pills" id="tableFilterPills">
          <button type="button" class="pill active" data-status="all">Все</button>
          <button type="button" class="pill" data-status="new">Новые</button>
          <button type="button" class="pill" data-status="confirmed">Подтвержденные</button>
          <button type="button" class="pill" data-status="in_work">В работе</button>
          <button type="button" class="pill" data-status="done">Завершенные</button>
          <button type="button" class="pill" data-status="canceled">Отмененные</button>
        </div>
      </div>

      <div class="table-card">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Код брони</th>
              <th>Дата и время визита</th>
              <th>Пост</th>
              <th>Автомобиль</th>
              <th>Клиент</th>
              <th>Услуги и запчасти</th>
              <th>Пожелания клиента</th>
              <th>Сумма</th>
              <th>Оплата</th>
              <th>Статус</th>
              <th>Действие</th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <!-- Заполняется JS -->
          </tbody>
        </table>
      </div>
      <nav class="order-pagination" aria-label="Страницы заказов">
        <button type="button" class="btn-refresh" id="tablePagePrev" disabled>Назад</button>
        <p id="tablePageInfo" role="status">Загрузка журнала…</p>
        <button type="button" class="btn-refresh" id="tablePageNext" disabled>Вперёд</button>
      </nav>
    </div>

  </main>

  <!-- МОДАЛЬНОЕ ОКНО: КАРТОЧКА ЗАЯВКИ (РЕДАКТИРОВАНИЕ И СМЕНА СТАТУСА) -->
  <div class="modal-backdrop" id="modalAppointment">
    <div class="modal-window">
      <div class="modal-header">
        <div style="display: flex; align-items: center; gap: 10px;">
          <h3 class="modal-title" id="modalAppTitle">Заявка AI-...</h3>
          <span class="status-badge" id="modalAppBadge">new</span>
        </div>
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('modalAppointment')"><?= uiIcon('close') ?></button>
      </div>

      <div class="modal-body" id="modalAppContent">
        <!-- Заполняется JS -->
      </div>

      <div class="modal-footer" id="modalAppFooter">
        <!-- Кнопки смены статуса -->
      </div>
    </div>
  </div>

  <!-- МОДАЛЬНОЕ ОКНО: БЫСТРАЯ ЗАПИСЬ ПО ТЕЛЕФОНУ -->
  <div class="modal-backdrop" id="modalQuickAdd">
    <div class="modal-window">
      <div class="modal-header">
        <h3 class="modal-title"><?= uiIcon('phone') ?> Быстрое внесение записи (входящий звонок)</h3>
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('modalQuickAdd')"><?= uiIcon('close') ?></button>
      </div>
      <form id="formQuickAdd">
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Телефон клиента *</label>
              <input type="tel" id="qaPhone" class="form-input" placeholder="+7 (926) 000-00-00" required>
            </div>
            <div class="form-group">
              <label class="form-label">Имя клиента *</label>
              <input type="text" id="qaName" class="form-input" placeholder="Сергей" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Марка авто *</label>
              <input type="text" id="qaBrand" class="form-input" placeholder="Ford, Kia, BMW..." required>
            </div>
            <div class="form-group">
              <label class="form-label">Модель авто *</label>
              <input type="text" id="qaModel" class="form-input" placeholder="Mustang, Rio, Focus..." required>
            </div>
            <div class="form-group">
              <label class="form-label">Госномер</label>
              <input type="text" id="qaPlate" class="form-input" placeholder="А 123 ВС 150">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Дата визита *</label>
              <input type="date" id="qaDate" class="form-input" value="<?= htmlspecialchars($selectedDate) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Время начала *</label>
              <input type="time" id="qaTime" class="form-input" value="10:00" step="1800" required>
            </div>
            <div class="form-group">
              <label class="form-label">Назначить пост *</label>
              <select id="qaBay" class="form-input">
                <option value="1">Подъемник №1</option>
                <option value="2">Подъемник №2</option>
                <option value="3">Подъемник №3</option>
                <option value="4">Подъемник №4</option>
                <option value="5">Сход-развал 3D</option>
                <option value="6">Диагностика</option>
                <option value="7">Шиномонтаж</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Длительность (мин)</label>
              <input type="number" id="qaDuration" class="form-input" value="60" step="30" min="30">
            </div>
            <div class="form-group">
              <label class="form-label">Сумма (₽)</label>
              <input type="number" id="qaPrice" class="form-input" value="2500" step="100">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Симптомы поломки / пожелания со слов клиента</label>
            <textarea id="qaComment" maxlength="2000" class="form-input" rows="2" placeholder="Например: Стук справа спереди, замена масла и фильтра"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-cancel" onclick="closeModal('modalQuickAdd')">Отмена</button>
          <button type="submit" class="btn-save"><?= uiIcon('check') ?> Сохранить запись в сетку</button>
        </div>
      </form>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/ui-icons.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/admin-timeline.js"></script>
</body>
</html>

