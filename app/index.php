<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Главная страница с встроенным модулем онлайн-записи и личным гаражом (index.php)
 */

require_once __DIR__ . '/includes/ui_icons.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/client_auth.php';

$isStaff = isStaffLoggedIn();
$customer = signedInClient($pdo);
$savedVehicle = $customer && isset($_GET['vehicle'])
    ? ownedClientVehicle($pdo, (int)$customer['client_id'], (int)clientFormValue($_GET, 'vehicle'))
    : null;
$selectedCar = $savedVehicle ?? ['brand' => 'Ford', 'model' => 'Mustang', 'prod_year' => 2016, 'plate_number' => ''];


// Загрузка полного каталога услуг для вкладки "Прейскурант и цены"
$groupedCatalog = [];
try {
    $categoriesStmt = $pdo->query("
        SELECT sc.category_id, sc.category_name, sc.description as cat_desc,
               s.service_id, s.title, s.description, s.duration_min, s.price as base_labor_price
        FROM service_categories sc
        JOIN services s ON sc.category_id = s.category_id
        WHERE s.is_active = 1
        ORDER BY sc.sort_order, s.service_id
    ");
    while ($row = $categoriesStmt->fetch()) {
        $cid = $row['category_id'];
        if (!isset($groupedCatalog[$cid])) {
            $groupedCatalog[$cid] = [
                'name'     => $row['category_name'],
                'icon'     => serviceCategoryIcon((int)$cid),
                'desc'     => $row['cat_desc'],
                'services' => []
            ];
        }
        $groupedCatalog[$cid]['services'][] = $row;
    }
} catch (Exception $e) {
    $groupedCatalog = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Автосервис «Автоимперия» | Онлайн-запись и техцентр в г. Сергиев Посад</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
  <script>window.APP_BASE = "<?= BASE_URL ?>";</script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/booking-widget.css">
</head>
<body>

  <!-- Верхняя инфо-полоса -->
  <div class="header-topbar">
    <div class="header-topbar-content">
      <div>
        <span><?= uiIcon('pin') ?></span>
        <span><?= htmlspecialchars(APP_ADDRESS) ?></span>
      </div>
      <div>
        <span class="topbar-badge">● Открыто сегодня</span>
        <span>с <?= WORK_START_TIME ?> до <?= WORK_END_TIME ?> (без выходных)</span>
      </div>
    </div>
  </div>

  <!-- Главная шапка сайта -->
  <header class="site-header">
    <div class="header-container">
      <div class="brand-logo" onclick="switchMainTab('booking')">
        <div class="logo-crown"><?= uiIcon('crown') ?></div>
        <div>
          <div class="brand-title">Авто<span>империя</span></div>
          <div class="brand-sub">Техцентр • <?= htmlspecialchars(APP_CITY) ?></div>
        </div>
      </div>

      <!-- Навигационное меню -->
      <nav class="main-nav">
        <button type="button" class="nav-link active" data-tab="booking" onclick="switchMainTab('booking')">
          <span><?= uiIcon('tool') ?></span> Запись на ремонт
        </button>
        <button type="button" class="nav-link" data-tab="status" onclick="switchMainTab('status')">
          <span><?= uiIcon('search') ?></span> Проверить запись
        </button>
        <button type="button" class="nav-link" data-tab="prices" onclick="switchMainTab('prices')">
          <span><?= uiIcon('price') ?></span> Услуги и цены
        </button>
        <button type="button" class="nav-link" data-tab="about" onclick="switchMainTab('about')">
          <span><?= uiIcon('pin') ?></span> О сервисе
        </button>
        <a href="<?= BASE_URL ?>/client/<?= $customer ? 'index.php' : 'login.php' ?>" class="nav-link">
          <?= uiIcon('user') ?> <?= $customer ? 'Личный кабинет' : 'Вход для клиента' ?>
        </a>
        <a href="<?= BASE_URL ?>/admin/index.php" class="nav-link nav-admin-btn">
          <span><?= uiIcon('settings') ?></span> <?= $isStaff ? 'АРМ Мастера (Вход выполнен)' : 'Вход для сотрудников' ?>
        </a>
      </nav>
    </div>
  </header>

  <!-- Основная рабочая область -->
  <main class="main-layout">
    <div class="course-demo" role="status">Учебная демоверсия. Используйте тестовые имя и телефон. <?= PERSISTENT_DB ? 'Данные сохраняются в локальной базе, а оплата имитируется.' : 'Записи сохраняются временно в текущем сеансе, а оплата имитируется.' ?></div>

    <!-- ============================================================== -->
    <!-- ВКЛАДКА 1: ОНЛАЙН-ЗАПИСЬ (ПОШАГОВЫЙ ВИЗАРД) -->
    <!-- ============================================================== -->
    <div class="main-tab-pane active" id="tab-booking">
      <div class="hero-banner">
        <h1>Онлайн-запись в техцентр «Автоимперия»</h1>
        <p>Выбор времени и расчёт стоимости обслуживания на примере автосервиса в Сергиевом Посаде.</p>
        <div class="hero-perks">
          <div class="hero-perk-item"><?= uiIcon('check') ?> 4 слесарных поста до 4 тонн</div>
          <div class="hero-perk-item"><?= uiIcon('check') ?> 3D Сход-развал и диагностика</div>
          <div class="hero-perk-item"><?= uiIcon('check') ?> Справочник цен на запчасти</div>
          <div class="hero-perk-item"><?= uiIcon('check') ?> Демонстрация оплаты</div>
        </div>
      </div>

      <div class="wizard-layout">
        <!-- Левая колонка: шаги мастера записи -->
        <div class="wizard-card">
          <!-- Степпер -->
          <div class="stepper-row">
            <div class="step-node active" data-step="1">
              <div class="step-circle">1</div>
              <span>Автомобиль</span>
            </div>
            <div class="step-node" data-step="2">
              <div class="step-circle">2</div>
              <span>Услуги и детали</span>
            </div>
            <div class="step-node" data-step="3">
              <div class="step-circle">3</div>
              <span>Дата и время</span>
            </div>
            <div class="step-node" data-step="4">
              <div class="step-circle">4</div>
              <span>Оплата и контакты</span>
            </div>
          </div>

          <!-- ШАГ 1: ВЫБОР АВТОМОБИЛЯ -->
          <div class="wizard-step active" id="wizardStep1">
            <div class="step-heading">
              <h2>Шаг 1: Укажите ваш автомобиль</h2>
              <p>Выберите автомобиль. Примеры характеристик и цен берутся из учебного справочника.</p>
            </div>

            <!-- Быстрые чипсы марок -->
            <label class="form-label">Популярные автомобили (кликните для быстрой подстановки):</label>
            <div class="car-brand-chips">
              <button type="button" class="brand-chip highlight-mustang<?= $savedVehicle ? '' : ' selected' ?>" data-brand="Ford" data-model="Mustang" data-year="2016">
                <?= uiIcon('bolt') ?> Ford Mustang (2016)
              </button>
              <button type="button" class="brand-chip" data-brand="Ford" data-model="Focus" data-year="2018">Ford Focus</button>
              <button type="button" class="brand-chip" data-brand="Kia" data-model="Rio" data-year="2019">Kia Rio</button>
              <button type="button" class="brand-chip" data-brand="Hyundai" data-model="Solaris" data-year="2020">Hyundai Solaris</button>
              <button type="button" class="brand-chip" data-brand="Toyota" data-model="Camry" data-year="2019">Toyota Camry</button>
              <button type="button" class="brand-chip" data-brand="BMW" data-model="5 Series" data-year="2018">BMW 5 Series</button>
              <button type="button" class="brand-chip" data-brand="Lada" data-model="Vesta" data-year="2020">Lada Vesta</button>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="inputBrand">Марка авто *</label>
                <input type="text" id="inputBrand" class="form-control" value="<?= clientHtml($selectedCar['brand']) ?>" maxlength="50" placeholder="Ford, Kia, Toyota..." required>
              </div>
              <div class="form-group">
                <label class="form-label" for="inputModel">Модель авто *</label>
                <input type="text" id="inputModel" class="form-control" value="<?= clientHtml($selectedCar['model']) ?>" maxlength="60" placeholder="Mustang, Rio, Camry..." required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="inputYear">Год выпуска</label>
                <input type="number" id="inputYear" class="form-control" value="<?= clientHtml($selectedCar['prod_year']) ?>" placeholder="2016" min="1900" max="<?= date('Y') + 1 ?>">
              </div>
              <div class="form-group">
                <label class="form-label" for="inputPlate">Госномер (необязательно)</label>
                <input type="text" id="inputPlate" class="form-control" value="<?= clientHtml($selectedCar['plate_number']) ?>" maxlength="20" placeholder="М 777 УС 77">
              </div>
            </div>

            <!-- Контейнер карточки распарсенных данных авто -->
            <input type="hidden" id="inputSavedVehicle" value="<?= (int)($savedVehicle['vehicle_id'] ?? 0) ?>">
            <div id="carSpecContainer"></div>

            <div class="step-actions">
              <div></div>
              <button type="button" class="btn btn-primary" id="btnStep1Next">
                Перейти к выбору услуг →
              </button>
            </div>
          </div>

          <!-- ШАГ 2: ВЫБОР УСЛУГ И ЗАПЧАСТЕЙ -->
          <div class="wizard-step" id="wizardStep2">
            <div class="step-heading">
              <h2>Шаг 2: Выберите необходимые работы и запчасти</h2>
              <p>Прайс-лист автоматически пересчитан с учетом нормативов и запчастей для вашего авто</p>
            </div>

            <!-- Переключатель запчастей (Монетизация) -->
            <div class="parts-grade-selector">
              <div style="font-weight:700; font-size:0.95rem; color:#065f46;">
                <?= uiIcon('tool') ?> Комплектация запчастями техцентра «Автоимперия»:
              </div>
              <div class="grade-options">
                <div class="grade-card active" data-grade="oem">
                  <div class="grade-card-title"><?= uiIcon('star') ?> Оригинал OEM (Рекомендуется)</div>
                  <div class="grade-card-desc">Фирменные масла и фильтры по регламенту завода. Гарантия 12 месяцев.</div>
                </div>
                <div class="grade-card" data-grade="analog">
                  <div class="grade-card-title"><?= uiIcon('check') ?> Сертифицированный аналог</div>
                  <div class="grade-card-desc">Надежные европейские бренды (TRW, Mann, Bosch). Экономия до 35%.</div>
                </div>
                <div class="grade-card" data-grade="none">
                  <div class="grade-card-title"><?= uiIcon('box') ?> Только работа (со своими деталями)</div>
                  <div class="grade-card-desc">Оплата исключительно работы мастера по нормо-часам автосервиса.</div>
                </div>
              </div>
            </div>

            <!-- Аккордеон категорий услуг -->
            <div id="servicesAccordionContainer">
              <!-- Заполняется динамически через JS -->
            </div>

            <div class="step-actions">
              <button type="button" class="btn btn-secondary" id="btnStep2Back">← Назад к авто</button>
              <button type="button" class="btn btn-primary" id="btnStep2Next">Выбрать дату и время →</button>
            </div>
          </div>

          <!-- ШАГ 3: ДАТА И СВОБОДНЫЕ СЛОТЫ -->
          <div class="wizard-step" id="wizardStep3">
            <div class="step-heading">
              <h2>Шаг 3: Выберите дату и свободное время</h2>
              <p>Расписание учитывает рабочие часы и заявки в текущем учебном сеансе.</p>
            </div>

                <label class="form-label">1. Выберите дату визита:</label>
            <div class="dates-row" id="bookingDatePicker">
              <!-- Генерация дней JS -->
            </div>

            <label class="form-label">2. Доступные интервалы времени (свободные посты):</label>
            <div class="slots-grid" id="bookingSlotsGrid">
              <!-- Генерация слотов JS -->
            </div>

            <div class="step-actions">
              <button type="button" class="btn btn-secondary" id="btnStep3Back">← Назад к услугам</button>
              <button type="button" class="btn btn-primary" id="btnStep3Next">Контактные данные и оплата →</button>
            </div>
          </div>

          <!-- ШАГ 4: КОНТАКТЫ И ОПЛАТА (МОНЕТИЗАЦИЯ) -->
          <div class="wizard-step" id="wizardStep4">
            <div class="step-heading">
              <h2>Шаг 4: Контакты автовладельца и способ оплаты</h2>
              <p>Укажите ваши данные для связи с мастером-приёмщиком и подтверждения бронирования</p>
            </div>

            <form id="bookingFinalForm">
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="inputClientName">Ваше ФИО / Имя *</label>
                  <input type="text" id="inputClientName" class="form-control" placeholder="Иван Иванович" value="<?= clientHtml($customer['full_name'] ?? 'Тестовый клиент') ?>" <?= $customer ? 'readonly' : '' ?> required>
                </div>
                <div class="form-group">
                  <label class="form-label" for="inputClientPhone">Контактный телефон *</label>
                  <input type="tel" id="inputClientPhone" class="form-control" placeholder="+7 (926) 000-00-00" value="<?= clientHtml($customer['phone'] ?? '+7 (900) 000-00-00') ?>" <?= $customer ? 'readonly' : '' ?> required>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label" for="inputClientComment">Пожелания по ремонту и симптомы (необязательно)</label>
                <textarea id="inputClientComment" maxlength="2000" class="form-control" rows="2" placeholder="Например: Стук в передней подвеске при проезде неровностей, проверить состояние тормозов"></textarea>
              </div>

              <div class="form-group" style="max-width:380px;">
                <label class="form-label" for="inputVisitOdometer">Текущий пробег, км (необязательно)</label>
                <input type="number" id="inputVisitOdometer" class="form-control" min="1" max="2000000" step="1" placeholder="Например, 85000">
                <small>Пригодится для расчёта следующего обслуживания в кабинете.</small>
              </div>

              <!-- Блок промокода -->
              <div class="form-group" style="max-width: 380px;">
                <label class="form-label" for="inputPromo">Есть промокод на скидку?</label>
                <div style="display:flex; gap:8px;">
                  <input type="text" id="inputPromo" class="form-control" placeholder="MUSTANG500 или IMPERIA2026" style="text-transform:uppercase;">
                  <button type="button" class="btn btn-secondary" id="btnApplyPromo">Применить</button>
                </div>
                <p id="promoFeedback" class="promo-feedback" role="status"></p>
              </div>

              <!-- Варианты оплаты для максимального заработка -->
              <div class="payment-selector-box">
                <label class="form-label" style="color:#0f172a; font-size:0.95rem;">
                  <?= uiIcon('card') ?> Выберите удобный формат оплаты:
                </label>
                <div class="payment-radios">
                  <label class="pay-radio-card active" data-pay-type="deposit">
                    <input type="radio" name="payType" value="deposit" checked>
                    <div style="flex:1;">
                      <div style="display:flex; align-items:center; gap:8px; font-weight:700;">
                        <span>Проверить предоплату 500 ₽</span>
                        <span class="sbp-badge">ДЕМО</span>
                        <span style="color:#10b981; font-size:0.75rem;">Рекомендуется</span>
                      </div>
                      <div style="font-size:0.8rem; color:#64748b;">
                        Проверка сценария частичной оплаты. Денежные средства не списываются.
                      </div>
                    </div>
                  </label>

                  <label class="pay-radio-card" data-pay-type="full_online">
                    <input type="radio" name="payType" value="full_online">
                    <div style="flex:1;">
                      <div style="display:flex; align-items:center; gap:8px; font-weight:700;">
                        <span>Проверить полную оплату со скидкой 5%</span>
                        <span style="background:#fee2e2; color:#dc2626; font-size:0.75rem; padding:1px 6px; border-radius:4px;">Экономия 5%</span>
                      </div>
                      <div style="font-size:0.8rem; color:#64748b;">
                        Расчёт скидки в учебном режиме. Банковские данные не требуются.
                      </div>
                    </div>
                  </label>

                  <label class="pay-radio-card" data-pay-type="onsite">
                    <input type="radio" name="payType" value="onsite">
                    <div style="flex:1;">
                      <div style="font-weight:700;">Оплата в автосервисе при визите</div>
                      <div style="font-size:0.8rem; color:#64748b;">
                        Наличными или банковской картой мастеру-приёмщику после выполнения работ.
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <div class="step-actions">
                <button type="button" class="btn btn-secondary" id="btnStep4Back">← Назад ко времени</button>
                <button type="submit" class="btn btn-primary" style="font-size:1.05rem;">
                  <?= uiIcon('check') ?> Подтвердить запись в сервис
                </button>
              </div>
            </form>
          </div>

          <!-- ШАГ 5: ТАЛОН УСПЕШНОЙ ЗАПИСИ -->
          <div class="wizard-step" id="wizardStep5">
            <div id="successTicketBox">
              <!-- Заполняется JS после успешного ответа сервера -->
            </div>
          </div>
        </div>

        <!-- Правая колонка: Плавающая сводка заказа -->
        <aside class="summary-card">
          <div class="summary-header">
            <h3>Ваш заказ-наряд</h3>
            <span style="font-size:0.8rem; color:var(--primary); font-weight:700;">СТО «Автоимперия»</span>
          </div>

          <div class="summary-car">
            <div style="font-size:0.78rem; color:var(--text-muted);">Автомобиль:</div>
            <div class="summary-car-title" id="summaryCarTitle"><?= clientHtml(trim($selectedCar['brand'] . ' ' . $selectedCar['model'])) ?></div>
          </div>

          <div style="margin-bottom:14px; font-size:0.88rem;">
            <span style="color:var(--text-muted);">Дата и время визита:</span>
            <div id="summaryDateTime" style="font-weight:700; color:#0284c7; margin-top:2px;">Не выбрано (Шаг 3)</div>
          </div>

          <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:6px;">Выбранные работы:</div>
          <div class="summary-items" id="summaryItemsList">
            <!-- Заполняется JS -->
          </div>

          <div class="summary-totals">
            <div class="total-line">
              <span>Стоимость работ:</span>
              <span id="summaryLabor">0 ₽</span>
            </div>
            <div class="total-line">
              <span>Запчасти и расходники:</span>
              <span id="summaryParts">0 ₽</span>
            </div>
            <div class="total-line">
              <span>Расчетное время:</span>
              <span id="summaryDuration">0 мин</span>
            </div>
            <div class="total-line" style="display:none; color:#10b981; font-weight:700;">
              <span>Скидка:</span>
              <span id="summaryDiscount">0 ₽</span>
            </div>
            <div class="total-final">
              <span>Итого к оплате:</span>
              <span id="summaryFinalTotal">0 ₽</span>
            </div>
          </div>
        </aside>
      </div>
    </div>

    <!-- ============================================================== -->
    <!-- ВКЛАДКА 2: ПРОВЕРКА СТАТУСА ЗАПИСИ КЛИЕНТОМ -->
    <!-- ============================================================== -->
    <div class="main-tab-pane" id="tab-status">
      <div style="max-width:760px; margin:0 auto;">
        <div class="step-heading" style="text-align:center;">
          <h2><?= uiIcon('search') ?> Проверка статуса записи на ремонт</h2>
          <p>Узнайте текущий статус выполнения работ по номеру телефона или коду талона в реальном времени</p>
        </div>

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:var(--shadow-sm);">
          <form id="statusLookupForm">
            <label class="form-label" for="inputStatusQuery">Код бронирования или контактный телефон:</label>
            <div class="status-lookup-row">
              <input type="text" id="inputStatusQuery" class="form-control" placeholder="Код с талона или номер телефона" required>
              <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Найти запись</button>
            </div>
            <div style="margin-top:12px; font-size:0.85rem; color:#64748b; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <span>Пробный поиск по телефону:</span>
              <button type="button" class="brand-chip" style="padding:3px 10px; font-size:0.8rem;" onclick="quickStatusLookup('+7 (900) 000-00-00')">Телефон +7 (900) 000-00-00</button>
            </div>
          </form>

          <div id="statusResultsContainer" aria-live="polite"></div>
        </div>
      </div>
    </div>

    <!-- ============================================================== -->
    <!-- ВКЛАДКА 3: УСЛУГИ И ЦЕНЫ (ПРАЙС-ЛИСТ) -->
    <!-- ============================================================== -->
    <div class="main-tab-pane" id="tab-prices">
      <div class="step-heading" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
          <h2><?= uiIcon('price') ?> Прейскурант услуг техцентра «Автоимперия»</h2>
          <p>Фиксированные расценки на работы автосервиса в г. Сергиев Посад</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="switchMainTab('booking')">
          <?= uiIcon('tool') ?> Рассчитать стоимость для авто (Ford Mustang и др.) →
        </button>
      </div>

      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 18px; margin-bottom:24px; font-size:0.92rem; color:#166534;">
        <?= uiIcon('bulb') ?> <strong>Обратите внимание:</strong> Ниже указана базовая стоимость слесарных работ. При оформлении через онлайн-калькулятор цена автоматически корректируется с учетом коэффициента сложности вашего автомобиля, типа двигателя и учебных цен запчастей.
      </div>

      <div class="price-catalog-container">
        <?php foreach ($groupedCatalog as $cat): ?>
          <div class="price-category-card">
            <div class="price-category-header">
              <div class="price-category-title">
                <span><?= uiIcon($cat['icon']) ?></span>
                <span><?= htmlspecialchars($cat['name']) ?></span>
              </div>
              <span style="font-size:0.85rem; color:#64748b;"><?= count($cat['services']) ?> услуг(и)</span>
            </div>
            <div class="price-table-scroll"><table class="price-table">
              <thead>
                <tr>
                  <th style="width: 55%;">Наименование услуги</th>
                  <th style="width: 15%;">Нормо-время</th>
                  <th style="width: 15%;">Цена от</th>
                  <th style="width: 15%; text-align: right;">Действие</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cat['services'] as $svc): ?>
                  <tr>
                    <td>
                      <div class="price-service-title"><?= htmlspecialchars($svc['title']) ?></div>
                      <?php if (!empty($svc['description'])): ?>
                        <div class="price-service-desc"><?= htmlspecialchars($svc['description']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="color:#64748b; font-size:0.88rem; font-weight:600;">
                      <?= uiIcon('clock') ?> ~<?= (int)$svc['duration_min'] ?> мин
                    </td>
                    <td>
                      <span class="price-tag-value"><?= number_format($svc['base_labor_price'], 0, '.', ' ') ?> ₽</span>
                    </td>
                    <td style="text-align: right;">
                      <button type="button" class="btn-book-service" onclick="selectServiceAndGoToBooking(<?= (int)$svc['service_id'] ?>)">
                        Записаться →
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ============================================================== -->
    <!-- ВКЛАДКА 4: О СЕРВИСЕ И КОНТАКТЫ -->
    <!-- ============================================================== -->
    <div class="main-tab-pane" id="tab-about">
      <div class="step-heading">
        <h2><?= uiIcon('pin') ?> Автосервис «Автоимперия» в Сергиевом Посаде</h2>
        <p>Современный мультибрендовый техцентр полного цикла: от регулярного ТО до сложного агрегатного ремонта</p>
      </div>

      <!-- Ключевые показатели -->
      <div class="about-hero-stats">
        <div class="stat-box">
          <div class="stat-number">12+</div>
          <div class="stat-label">Лет успешной работы в Сергиевом Посаде</div>
        </div>
        <div class="stat-box">
          <div class="stat-number">4</div>
          <div class="stat-label">Универсальных подъемника грузоподъемностью до 4т</div>
        </div>
        <div class="stat-box">
          <div class="stat-number">100%</div>
          <div class="stat-label">Прозрачный расчет цен по нормо-часам и базам OEM</div>
        </div>
        <div class="stat-box">
          <div class="stat-number">12 мес</div>
          <div class="stat-label">Гарантия на выполненные работы и оригинальные детали</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap:24px; margin-bottom:24px;">
        <!-- Контактная информация -->
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:var(--shadow-sm);">
          <h3 style="font-size:1.2rem; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;">
            <span><?= uiIcon('pin') ?></span> Контактная информация
          </h3>
          <ul style="list-style:none; display:flex; flex-direction:column; gap:12px; font-size:0.95rem;">
            <li><?= uiIcon('pin') ?> <strong>Город:</strong> Сергиев Посад</li>
            <li><?= uiIcon('clock') ?> <strong>Часы работы:</strong> Ежедневно с <?= WORK_START_TIME ?> до <?= WORK_END_TIME ?> (без перерывов и выходных)</li>
          </ul>
          <div style="margin-top:20px;">
            <button type="button" class="btn btn-primary" style="width:100%; justify-content:center;" onclick="switchMainTab('booking')">
              Записаться на ремонт онлайн
            </button>
          </div>
        </div>

        <!-- Оснащение цеха -->
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:var(--shadow-sm);">
          <h3 style="font-size:1.2rem; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px;">
            <span><?= uiIcon('tool') ?></span> Оснащение и производственная база
          </h3>
          <ul style="list-style:none; display:flex; flex-direction:column; gap:12px; font-size:0.92rem; color:#334155;">
            <li><?= uiIcon('car') ?> <strong>4 двухстоечных слесарных подъемника</strong> (до 4.0 тонн) для любых легковых авто, внедорожников и микроавтобусов.</li>
            <li><?= uiIcon('ruler') ?> <strong>3D стенд сход-развала Hunter</strong> с калиброванными 4-точечными мишенями и базой углов 2026 года.</li>
            <li><?= uiIcon('screen') ?> <strong>Дилерский диагностический комплекс Autel MaxiSys</strong> для глубокого сканирования ЭБУ, адаптации дросселей и АКПП.</li>
            <li><?= uiIcon('wheel') ?> <strong>Шиномонтажная линия Hofmann</strong>: балансировка с точностью до 1 грамма, монтаж шин RunFlat до R22 без повреждения дисков.</li>
            <li><?= uiIcon('card') ?> <strong>Оплата и фискализация:</strong> В демоверсии платёж и чек имитируются.</li>
          </ul>
        </div>
      </div>
    </div>

  </main>

  <!-- Подвал сайта -->
  <footer class="site-footer">
    <div class="footer-container">
      <div>
        <strong>Техцентр «Автоимперия»</strong> — Сергиев Посад, учебный стенд<br>
        <span>Профессиональный слесарный ремонт, регулярное ТО и круглосуточная онлайн-запись.</span>
      </div>

    </div>
  </footer>

  <script src="<?= BASE_URL ?>/assets/js/ui-icons.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/booking-wizard.js"></script>
</body>
</html>

