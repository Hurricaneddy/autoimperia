function escapeDemoHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
}
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * assets/js/booking-wizard.js
 * Пошаговая онлайн-запись и расчёт учебной сметы.
 */

// Вспомогательная функция формирования пути с учетом базового префикса домена/папки
function getAppPath(endpoint) {
  const base = (typeof window !== 'undefined' && window.APP_BASE !== undefined) ? window.APP_BASE : '';
  const clean = endpoint.startsWith('/') ? endpoint.slice(1) : endpoint;
  return base ? `${base.replace(/\/+$/, '')}/${clean}` : `/${clean}`;
}

// Состояние визарда
const BookingState = {
  currentStep: 1,
  car: {
    brand: 'Ford',
    model: 'Mustang',
    year: 2016,
    plate: '',
    vehicleId: 0
  },
  carSpec: null,
  partsGrade: 'oem', // 'oem', 'analog', 'none'
  selectedServices: [],
  selectedDate: '',
  selectedTime: '',
  selectedBayId: null,
  client: {
    name: '',
    phone: '',
    comment: ''
  },
  promoCode: '',
  discountAmount: 0,
  paymentType: 'deposit', // 'deposit', 'full_online', 'onsite'
  paymentMethod: 'sbp',
  quote: null,
  bookingResult: null
};

let latestQuoteRequest = 0;
let latestCatalogRequest = 0;
let latestSlotRequest = 0;

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
  initDateSelectors();
  initCarSelector();
  initStepperNavigation();
  initPaymentSelectors();
  initPromoCode();
  initStatusLookup();
  
  const car = BookingState.car;
  car.brand = document.getElementById('inputBrand').value.trim();
  car.model = document.getElementById('inputModel').value.trim();
  car.year = parseInt(document.getElementById('inputYear').value, 10) || null;
  car.plate = document.getElementById('inputPlate').value.trim();
  car.vehicleId = Number(document.getElementById('inputSavedVehicle').value);
  triggerCarParsing(car.brand, car.model, car.year);
});

/**
 * 1. Инициализация выбора автомобиля и быстрого переключения марок
 */
function initCarSelector() {
  const brandChips = document.querySelectorAll('.car-brand-chips .brand-chip');
  const brandInput = document.getElementById('inputBrand');
  const modelInput = document.getElementById('inputModel');
  const yearInput  = document.getElementById('inputYear');

  brandChips.forEach(chip => {
    chip.addEventListener('click', () => {
      clearTimeout(typingTimer);
      if (BookingState.car.vehicleId) document.getElementById('inputPlate').value = '';
      BookingState.car.vehicleId = 0;
      brandChips.forEach(c => c.classList.remove('selected'));
      chip.classList.add('selected');

      const brand = chip.getAttribute('data-brand');
      const model = chip.getAttribute('data-model') || '';
      const year  = chip.getAttribute('data-year') || 2016;

      if (brandInput) brandInput.value = brand;
      if (modelInput) modelInput.value = model;
      if (yearInput)  yearInput.value  = year;

      BookingState.car.brand = brand;
      BookingState.car.model = model;
      BookingState.car.year  = parseInt(year, 10);

      triggerCarParsing(brand, model, parseInt(year, 10));
    });
  });

  // Ручной ввод с дебаунсом для авто-парсинга
  let typingTimer;
  const onCarInputChange = () => {
    clearTimeout(typingTimer);
    BookingState.car.vehicleId = 0;
    brandChips.forEach(chip => chip.classList.remove('selected'));
    typingTimer = setTimeout(() => {
      const b = brandInput ? brandInput.value.trim() : '';
      const m = modelInput ? modelInput.value.trim() : '';
      const y = yearInput ? parseInt(yearInput.value, 10) : null;

      if (b && m) {
        BookingState.car.brand = b;
        BookingState.car.model = m;
        BookingState.car.year  = y;
        triggerCarParsing(b, m, y);
      }
    }, 600);
  };

  if (brandInput) brandInput.addEventListener('input', onCarInputChange);
  if (modelInput) modelInput.addEventListener('input', onCarInputChange);
  if (yearInput)  yearInput.addEventListener('input', onCarInputChange);
  document.getElementById('inputPlate').addEventListener('input', () => { BookingState.car.vehicleId = 0; });

  // Переключатель типа запчастей (Эконом / OEM Оригинал / Свои)
  const gradeCards = document.querySelectorAll('.grade-card');
  gradeCards.forEach(card => {
    card.addEventListener('click', () => {
      gradeCards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      BookingState.partsGrade = card.getAttribute('data-grade') || 'oem';
      recalculateQuote();
      loadServicesCatalog();
    });
  });
}

/**
 * 2. Загрузка учебной спецификации автомобиля
 */
async function triggerCarParsing(brand, model, year) {
  const specBox = document.getElementById('carSpecContainer');
  if (specBox) {
    specBox.innerHTML = `
      <div style="padding: 16px; text-align: center; color: #64748b;">
        <span class="live-pulse" style="display:inline-block; margin-right: 8px;"></span>
        Загружаем учебные данные для <strong>${escapeDemoHtml(brand)} ${escapeDemoHtml(model)} ${year || ''}</strong>…
      </div>
    `;
  }

  await recalculateQuote();
}

/**
 * Отрисовка карточки распарсенных спецификаций автомобиля
 */
function renderCarSpecCard(spec) {
  const specBox = document.getElementById('carSpecContainer');
  if (!specBox) return;

  const isMustang = spec.brand.toLowerCase() === 'ford' && spec.model.toLowerCase().includes('mustang');
  const carName = `${escapeDemoHtml(spec.brand)} ${escapeDemoHtml(spec.model)}${spec.year ? ` (${spec.year} г.в.)` : ''}`;
  if (spec.is_estimate) {
    specBox.innerHTML = `<div class="car-spec-card"><strong>${carName}</strong><p>Для этой модели и года нет данных в учебном справочнике. Стоимость будет рассчитана ориентировочно, без подбора деталей.</p></div>`;
    return;
  }

  specBox.innerHTML = `
    <div class="car-spec-box">
      <div class="car-spec-header">
        <div class="car-spec-title">
          <span>${uiIcon('car')}</span>
          <span>${carName}</span>
          ${isMustang ? `<span style="background:#fee2e2; color:#dc2626; font-size:0.75rem; font-weight:700; padding:2px 8px; border-radius:10px;">${uiIcon('star')} High Performance</span>` : ''}
        </div>
        <div class="parsing-badge">
          Учебный справочник · пример расчёта
        </div>
      </div>

      <div style="font-size: 0.88rem; color: #475569; margin-bottom: 8px;">
        Двигатель: <strong>${spec.engine_desc}</strong> &bull; Коэффициент сложности нормо-часа: <strong>${spec.labor_multiplier}x</strong>
      </div>

      <div class="car-specs-grid">
        <div class="spec-item">
          <div class="spec-item-label">Моторное масло OEM</div>
          <div class="spec-item-val">${spec.oil_liters} л &bull; ${spec.oil_type}</div>
          <div style="font-size:0.72rem; color:#64748b;">${spec.oil_price_per_l} ₽ / литр</div>
        </div>
        <div class="spec-item">
          <div class="spec-item-label">Масляный фильтр</div>
          <div class="spec-item-val">${spec.oil_filter_oem}</div>
          <div style="font-size:0.72rem; color:#64748b;">${spec.oil_filter_price} ₽ в демо</div>
        </div>
        <div class="spec-item">
          <div class="spec-item-label">Передние колодки</div>
          <div class="spec-item-val">${spec.brake_pads_oem}</div>
          <div style="font-size:0.72rem; color:#0284c7;">${spec.brake_pads_price} ₽ (Оригинал) / ${spec.brake_pads_analog} ₽ (Аналог)</div>
        </div>
        <div class="spec-item">
          <div class="spec-item-label">Комплект ТО (Масло + Фильтры)</div>
          <div class="spec-item-val" style="color:var(--primary);">${spec.oil_service_parts} ₽</div>
          <div style="font-size:0.72rem; color:#64748b;">Расходники со скидкой сервиса</div>
        </div>
      </div>
    </div>
  `;
}

/**
 * 3. Загрузка каталога услуг с персонализированными ценами
 */
async function loadServicesCatalog() {
  const container = document.getElementById('servicesAccordionContainer');
  if (!container) return;

  const brand = BookingState.car.brand;
  const model = BookingState.car.model;
  const year  = BookingState.car.year;
  const grade = BookingState.partsGrade;
  const request = ++latestCatalogRequest;

  try {
    const url = getAppPath(`api/get_services.php?brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}&year=${year || ''}&parts_grade=${grade}`);
    const res = await fetch(url);
    const data = await res.json();

    if (request === latestCatalogRequest && res.ok && data.success && data.categories) {
      renderServicesAccordion(data.categories);
    }
  } catch (e) {
    console.error('Ошибка загрузки каталога услуг:', e);
  }
}

function renderServicesAccordion(categories) {
  const container = document.getElementById('servicesAccordionContainer');
  if (!container) return;

  let html = '';
  categories.forEach((cat, idx) => {
    const isOpen = idx === 0 || cat.items.some(it => BookingState.selectedServices.includes(it.id));

    html += `
      <div class="category-accordion">
        <div class="category-header" onclick="toggleAccordion(this)">
          <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:1.2rem;">${uiIcon(cat.icon || 'tool')}</span>
            <span>${cat.category}</span>
          </div>
          <span class="acc-chevron">${isOpen ? '▲' : '▼'}</span>
        </div>
        <div class="category-services-list" style="display: ${isOpen ? 'block' : 'none'};">
    `;

    cat.items.forEach(item => {
      const isChecked = BookingState.selectedServices.includes(item.id);
      html += `
        <div class="service-row">
          <div class="service-left">
            <input type="checkbox" class="service-checkbox" value="${item.id}" ${isChecked ? 'checked' : ''} onchange="toggleServiceSelection(${item.id}, this.checked)">
            <div class="service-info">
              <div class="service-title">${item.title}</div>
              <div class="service-meta">
                <span>${uiIcon('clock')} ~${item.duration} мин</span>
                ${item.parts_cost > 0 ? `<span class="service-parts-badge"> &bull; Вкл. детали и жидкости (+${item.parts_cost} ₽)</span>` : ''}
              </div>
              ${item.upsell ? `<div style="font-size:0.76rem; color:#d97706; margin-top:2px;">${uiIcon('bulb')} Рекомендуем: ${item.upsell}</div>` : ''}
            </div>
          </div>
          <div class="service-price-box">
            <div class="service-price">${item.calculated_price.toLocaleString('ru-RU')} ₽</div>
            ${item.labor_price && item.labor_price !== item.calculated_price ? `<div class="service-duration">Работа: ${item.labor_price} ₽</div>` : ''}
          </div>
        </div>
      `;
    });

    html += `</div></div>`;
  });

  container.innerHTML = html;
}

function toggleAccordion(header) {
  const list = header.nextElementSibling;
  const chevron = header.querySelector('.acc-chevron');
  if (list.style.display === 'none') {
    list.style.display = 'block';
    if (chevron) chevron.textContent = '▲';
  } else {
    list.style.display = 'none';
    if (chevron) chevron.textContent = '▼';
  }
}

function toggleServiceSelection(serviceId, isChecked) {
  const id = parseInt(serviceId, 10);
  if (isChecked) {
    if (!BookingState.selectedServices.includes(id)) {
      BookingState.selectedServices.push(id);
    }
  } else {
    BookingState.selectedServices = BookingState.selectedServices.filter(s => s !== id);
  }

  BookingState.selectedTime = '';
  BookingState.selectedBayId = null;
  updateSummaryCard();
  recalculateQuote();
}

/**
 * 4. Пересчет итоговой сметы и онлайн-платежей
 */
async function recalculateQuote() {
  const brand = BookingState.car.brand;
  const model = BookingState.car.model;
  const year  = BookingState.car.year;
  const grade = BookingState.partsGrade;
  const sList = BookingState.selectedServices.join(',');
  const request = ++latestQuoteRequest;
  const promoFeedback = document.getElementById('promoFeedback');

  try {
    const url = getAppPath(`api/parse_car_pricing.php?brand=${encodeURIComponent(brand)}&model=${encodeURIComponent(model)}&year=${year || ''}&parts_grade=${grade}&services=${sList}&promo=${encodeURIComponent(BookingState.promoCode)}`);
    const res = await fetch(url);
    const data = await res.json();

    if (request !== latestQuoteRequest) return;
    if (res.ok && data.success) {
      BookingState.carSpec = data.car_spec;
      BookingState.quote = data.quote;
      BookingState.discountAmount = data.discount_amount || 0;
      renderCarSpecCard(data.car_spec);
      updateSummaryCard();
      if (promoFeedback) {
        promoFeedback.textContent = !BookingState.promoCode ? '' : data.promo_applied
          ? `Промокод ${data.promo_applied.code} применён.`
          : 'Промокод недействителен или сумма заказа меньше требуемой.';
      }
    } else {
      throw new Error(data.error || 'Не удалось рассчитать стоимость');
    }
  } catch (e) {
    if (request !== latestQuoteRequest) return;
    if (promoFeedback && BookingState.promoCode) promoFeedback.textContent = 'Не удалось проверить промокод. Попробуйте ещё раз.';
    console.error('Ошибка пересчета стоимости:', e);
  }
}

function updateSummaryCard() {
  const carEl = document.getElementById('summaryCarTitle');
  const dateEl = document.getElementById('summaryDateTime');
  const itemsEl = document.getElementById('summaryItemsList');
  const laborEl = document.getElementById('summaryLabor');
  const partsEl = document.getElementById('summaryParts');
  const discountEl = document.getElementById('summaryDiscount');
  const finalEl = document.getElementById('summaryFinalTotal');
  const durEl = document.getElementById('summaryDuration');

  if (carEl) {
    const { brand, model, year } = BookingState.car;
    carEl.textContent = `${brand} ${model}${year ? ` (${year})` : ''}`;
  }

  if (dateEl) {
    if (BookingState.selectedDate && BookingState.selectedTime) {
      dateEl.textContent = `${formatDateRus(BookingState.selectedDate)} в ${BookingState.selectedTime}`;
      dateEl.style.color = '#0284c7';
    } else {
      dateEl.textContent = 'Не выбрано (Шаг 3)';
      dateEl.style.color = '#94a3b8';
    }
  }

  const q = BookingState.quote;
  if (!q || !q.services || q.services.length === 0) {
    if (itemsEl) itemsEl.innerHTML = '<div style="color:#94a3b8; padding:8px 0;">Услуги еще не выбраны</div>';
    if (laborEl) laborEl.textContent = '0 ₽';
    if (partsEl) partsEl.textContent = '0 ₽';
    if (finalEl) finalEl.textContent = '0 ₽';
    if (durEl) durEl.textContent = '0 мин';
    if (discountEl) discountEl.parentElement.style.display = 'none';
    return;
  }

  if (itemsEl) {
    let itemsHtml = '';
    q.services.forEach(s => {
      itemsHtml += `
        <div class="summary-item-row">
          <div>
            <div style="font-weight:600; color:#1e293b;">${escapeDemoHtml(s.title)}</div>
            ${s.parts_cost > 0 ? `<div style="font-size:0.75rem; color:#0284c7;">+ Детали (${s.parts_cost} ₽)</div>` : ''}
          </div>
          <div style="font-weight:700; white-space:nowrap;">${s.total_price.toLocaleString('ru-RU')} ₽</div>
        </div>
      `;
    });
    itemsEl.innerHTML = itemsHtml;
  }

  if (laborEl) laborEl.textContent = `${q.total_labor.toLocaleString('ru-RU')} ₽`;
  if (partsEl) partsEl.textContent = `${q.total_parts.toLocaleString('ru-RU')} ₽`;
  if (durEl) durEl.textContent = `~${q.total_duration} мин`;

  let finalSum = Math.max(0, q.total_price - BookingState.discountAmount);

  // Скидка при 100% онлайн-оплате
  if (BookingState.paymentType === 'full_online') {
    const onlineDisc = Math.round(finalSum * 0.05);
    finalSum -= onlineDisc;
    if (discountEl) {
      discountEl.textContent = `-${(BookingState.discountAmount + onlineDisc).toLocaleString('ru-RU')} ₽ (вкл. 5% онлайн)`;
      discountEl.parentElement.style.display = 'flex';
    }
  } else if (BookingState.discountAmount > 0) {
    if (discountEl) {
      discountEl.textContent = `-${BookingState.discountAmount.toLocaleString('ru-RU')} ₽`;
      discountEl.parentElement.style.display = 'flex';
    }
  } else {
    if (discountEl) discountEl.parentElement.style.display = 'none';
  }

  if (finalEl) finalEl.textContent = `${finalSum.toLocaleString('ru-RU')} ₽`;
}

/**
 * 5. Инициализация селектора даты и слотов времени
 */
function initDateSelectors() {
  const container = document.getElementById('bookingDatePicker');
  if (!container) return;

  const daysOfWeek = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
  const months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

  let html = '';
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
  
  // Если текущее время после 19:30, рабочий день сегодня закончен -> предлагаем завтра
  const isLateEvening = (now.getHours() >= 20 || (now.getHours() === 19 && now.getMinutes() >= 30));
  const defaultDayIndex = isLateEvening ? 1 : 0;

  for (let i = 0; i < 14; i++) {
    const d = new Date();
    d.setDate(now.getDate() + i);

    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const dateStr = `${yyyy}-${mm}-${dd}`;

    const isSelected = (i === defaultDayIndex);
    if (isSelected) {
      BookingState.selectedDate = dateStr;
    }

    const dayLabel = (i === 0) ? 'Сегодня' : ((i === 1) ? 'Завтра' : daysOfWeek[d.getDay()]);

    html += `
      <button type="button" class="date-card ${isSelected ? 'active' : ''}" data-date="${dateStr}" aria-pressed="${isSelected}" onclick="selectDateCard(this, '${dateStr}')">
        <span class="date-dayname">${dayLabel}</span>
        <span class="date-daynum">${d.getDate()}</span>
        <span class="date-month">${months[d.getMonth()]}</span>
      </button>
    `;
  }

  container.innerHTML = html;
}

function selectDateCard(element, dateStr) {
  document.querySelectorAll('.date-card').forEach(c => {
    c.classList.remove('active');
    c.setAttribute('aria-pressed', 'false');
  });
  element.classList.add('active');
  element.setAttribute('aria-pressed', 'true');
  BookingState.selectedDate = dateStr;
  BookingState.selectedTime = '';
  updateSummaryCard();
  loadSlotsForDate(dateStr);
}

async function loadSlotsForDate(dateStr) {
  const grid = document.getElementById('bookingSlotsGrid');
  if (!grid) return;
  const request = ++latestSlotRequest;
  const next = document.getElementById('btnStep3Next');
  next.disabled = true;

  grid.innerHTML = '<div style="padding:15px; color:#64748b; font-size:0.9rem;">Расчет свободных постов автосервиса...</div>';

  try {
    const sList = BookingState.selectedServices.length > 0 ? BookingState.selectedServices.join(',') : '1';
    const url = getAppPath(`api/get_slots.php?date=${dateStr}&services=${sList}`);
    const res = await fetch(url);
    const data = await res.json();
    if (request !== latestSlotRequest) return;

    if (!res.ok || !data.success) {
      grid.innerHTML = `
        <div style="grid-column: 1/-1; padding:18px; background:#fff1f2; border:1px solid #fecdd3; border-radius:8px; color:#be123c;">
          ${uiIcon('warning')} ${data.error || 'Ошибка при загрузке свободных слотов'}.
        </div>
      `;
      return;
    }

    if (data.slots && data.slots.length > 0) {
      let slotsHtml = '';
      data.slots.forEach(slot => {
        const isSelected = (slot.time === BookingState.selectedTime);
        slotsHtml += `
          <button type="button" class="slot-btn ${isSelected ? 'active' : ''}" onclick="selectSlotTime(this, '${slot.time}', ${slot.suggested_bay})">
            ${slot.time}
          </button>
        `;
      });
      grid.innerHTML = slotsHtml;
      if (!data.slots.some(slot => slot.time === BookingState.selectedTime)) {
        BookingState.selectedTime = '';
        BookingState.selectedBayId = null;
        updateSummaryCard();
      }
      next.disabled = !BookingState.selectedTime;
    } else {
      // Проверяем, не вечер ли сегодняшнего дня
      const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
      const todayYMD = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
      const isTodayClosed = (dateStr === todayYMD && now.getHours() >= 20);

      if (isTodayClosed) {
        grid.innerHTML = `
          <div style="grid-column: 1/-1; padding:20px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; color:#1e40af;">
            <div style="font-weight:700; margin-bottom:4px;">${uiIcon('clock')} Прием автомобилей на сегодня завершен</div>
            <div style="font-size:0.88rem;">Техцентр работает ежедневно с 09:00 до 20:00. Пожалуйста, выберите в календаре выше дату <strong>Завтра</strong> или любой другой удобный день для визита.</div>
          </div>
        `;
      } else {
        grid.innerHTML = `
          <div style="grid-column: 1/-1; padding:20px; background:#fff1f2; border:1px solid #fecdd3; border-radius:8px; color:#be123c;">
            ${uiIcon('warning')} На выбранную дату все ремонтные посты заняты или проводятся регламентные работы. Пожалуйста, выберите соседний день.
          </div>
        `;
      }
    }
  } catch (err) {
    if (request !== latestSlotRequest) return;
    grid.innerHTML = '<div style="color:#ef4444; padding:15px;">Ошибка соединения с сервером при расчете слотов. Пожалуйста, обновите страницу.</div>';
  }
}

function selectSlotTime(btn, timeStr, bayId) {
  document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  BookingState.selectedTime = timeStr;
  BookingState.selectedBayId = bayId;
  document.getElementById('btnStep3Next').disabled = false;
  updateSummaryCard();
}

/**
 * 6. Навигация по степперу шагов
 */
function initStepperNavigation() {
  document.getElementById('btnStep1Next')?.addEventListener('click', () => {
    const brand = document.getElementById('inputBrand')?.value.trim();
    const model = document.getElementById('inputModel')?.value.trim();
    if (!brand || !model) {
      alert('Пожалуйста, укажите марку и модель автомобиля');
      return;
    }
    BookingState.car.brand = brand;
    BookingState.car.model = model;
    const yearInput = document.getElementById('inputYear');
    if (yearInput && !yearInput.reportValidity()) return;
    BookingState.car.year  = parseInt(yearInput?.value, 10) || null;
    BookingState.car.plate = document.getElementById('inputPlate')?.value.trim() || '';
    recalculateQuote();
    goToStep(2);
  });

  document.getElementById('btnStep2Back')?.addEventListener('click', () => goToStep(1));
  document.getElementById('btnStep2Next')?.addEventListener('click', () => {
    if (BookingState.selectedServices.length === 0) {
      alert('Выберите хотя бы одну услугу из каталога');
      return;
    }
    goToStep(3);
  });

  document.getElementById('btnStep3Back')?.addEventListener('click', () => goToStep(2));
  document.getElementById('btnStep3Next')?.addEventListener('click', () => {
    if (!BookingState.selectedDate || !BookingState.selectedTime) {
      alert('Пожалуйста, выберите удобное время для записи');
      return;
    }
    goToStep(4);
  });

  document.getElementById('btnStep4Back')?.addEventListener('click', () => goToStep(3));

  // Отправка формы бронирования (Шаг 4)
  document.getElementById('bookingFinalForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    await submitBookingForm();
  });
}

function goToStep(stepNum) {
  BookingState.currentStep = stepNum;

  // Обновление индикатора шагов
  document.querySelectorAll('.step-node').forEach(node => {
    const s = parseInt(node.getAttribute('data-step'), 10);
    node.classList.remove('active', 'completed');
    if (s === stepNum) node.classList.add('active');
    else if (s < stepNum) node.classList.add('completed');
  });

  // Отображение активной страницы
  document.querySelectorAll('.wizard-step').forEach(step => {
    step.classList.remove('active');
  });
  const currentStep = document.getElementById(`wizardStep${stepNum}`);
  currentStep?.classList.add('active');
  if (stepNum === 2) loadServicesCatalog();
  if (stepNum === 3 && BookingState.selectedDate) loadSlotsForDate(BookingState.selectedDate);

  window.scrollTo({ top: 180, behavior: 'smooth' });
}

/**
 * 7. Выбор вариантов оплаты (Монетизация)
 */
function initPaymentSelectors() {
  const payCards = document.querySelectorAll('.pay-radio-card');
  payCards.forEach(card => {
    card.addEventListener('click', () => {
      payCards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');

      const radio = card.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;

      BookingState.paymentType = card.getAttribute('data-pay-type') || 'deposit';
      updateSummaryCard();
    });
  });
}

function initPromoCode() {
  const btn = document.getElementById('btnApplyPromo');
  const input = document.getElementById('inputPromo');

  btn?.addEventListener('click', () => {
    const val = input ? input.value.trim() : '';
    BookingState.promoCode = val;
    recalculateQuote();
  });
}

/**
 * 8. Отправка бронирования на сервер (Транзакционная блокировка)
 */
async function submitBookingForm() {
  const nameInput = document.getElementById('inputClientName');
  const phoneInput = document.getElementById('inputClientPhone');
  const commentInput = document.getElementById('inputClientComment');

  const name = nameInput ? nameInput.value.trim() : '';
  const phone = phoneInput ? phoneInput.value.trim() : '';
  const comment = commentInput ? commentInput.value.trim() : '';

  if (!name || phone.replace(/\D/g, '').length < 10) {
    alert('Пожалуйста, заполните имя и корректный номер телефона');
    return;
  }

  const payload = {
    client_name: name,
    client_phone: phone,
    client_comment: comment,
    vehicle_id: BookingState.car.vehicleId,
    odometer: document.getElementById('inputVisitOdometer')?.value ?? '',
    brand: BookingState.car.brand,
    model: BookingState.car.model,
    prod_year: BookingState.car.year,
    plate_number: BookingState.car.plate,
    visit_date: BookingState.selectedDate,
    start_time: BookingState.selectedTime,
    services: BookingState.selectedServices,
    parts_grade: BookingState.partsGrade,
    payment_type: BookingState.paymentType,
    payment_method: BookingState.paymentMethod,
    promo_code: BookingState.promoCode
  };

  const submitBtn = document.querySelector('#bookingFinalForm button[type="submit"]');
  const submitLabel = submitBtn?.innerHTML;
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Бронируем время…';
  }

  try {
    const res = await fetch(getAppPath('api/create_booking.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (res.ok && result.success) {
      BookingState.bookingResult = result;
      renderSuccessTicket(result);
      goToStep(5);
    } else if (res.status === 409) {
      alert(result.error || 'К сожалению, выбранный слот времени уже занят другим клиентом. Пожалуйста, выберите другое время.');
      BookingState.selectedTime = '';
      goToStep(3);
    } else {
      alert(result.error || 'Произошла ошибка при бронировании');
    }
  } catch (err) {
    alert('Ошибка соединения с сервером автосервиса');
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = submitLabel;
    }
  }
}

/**
 * 9. Отрисовка талона успешной записи
 */
function renderSuccessTicket(res) {
  const container = document.getElementById('successTicketBox');
  if (!container) return;

  const isPaid = (res.paid_amount > 0);

  container.innerHTML = `
    <div class="ticket-card">
      <div style="text-align: center;">
        <div class="ticket-badge-success">${uiIcon('check')} Запись успешно подтверждена и внесена в график!</div>
        <div style="font-size:0.95rem; color:#64748b; margin-bottom:8px;">Ваш электронный номер бронирования:</div>
        <div class="ticket-code">${res.booking_code}</div>
      </div>

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin:20px 0;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
          <div>
            <div style="font-size:0.8rem; color:#64748b;">Дата и время визита:</div>
            <div style="font-size:1.1rem; font-weight:700; color:#0284c7;">
              ${formatDateRus(res.visit_date)} в ${res.start_time} - ${res.end_time}
            </div>
          </div>
          <div>
            <div style="font-size:0.8rem; color:#64748b;">Автомобиль:</div>
            <div style="font-size:1.1rem; font-weight:700; color:#0f172a;">${escapeDemoHtml(res.car)}</div>
          </div>
          <div>
            <div style="font-size:0.8rem; color:#64748b;">Адрес автосервиса:</div>
            <div style="font-size:0.95rem; font-weight:600;">Сергиев Посад · учебный стенд</div>
          </div>
          <div>
            <div style="font-size:0.8rem; color:#64748b;">Итоговая сумма:</div>
            <div style="font-size:1.25rem; font-weight:800; color:var(--primary);">${res.total_price.toLocaleString('ru-RU')} ₽</div>
          </div>
        </div>
      </div>

      <div class="qr-preview">
        <div><strong>Демонстрационный талон</strong>
          <p>${isPaid ? 'Оплата смоделирована.' : 'Выбрана оплата в сервисе.'} Реальных платежей нет.</p>
        </div>
      </div>

      <div class="ticket-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">${uiIcon('printer')} Распечатать талон</button>
        <button type="button" class="btn btn-primary" id="ticketCheckStatus">${uiIcon('search')} Проверить статус заявки</button>
      </div>
    </div>
  `;
  document.getElementById('ticketCheckStatus')?.addEventListener('click', () => {
    switchMainTab('status');
    quickStatusLookup(res.booking_code);
  });
}

/**
 * 10. Проверка статуса записи клиентом
 */
function initStatusLookup() {
  const form = document.getElementById('statusLookupForm');
  const input = document.getElementById('inputStatusQuery');
  const resultsBox = document.getElementById('statusResultsContainer');

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const query = input ? input.value.trim() : '';
    if (!query) return;

    if (resultsBox) {
      resultsBox.innerHTML = '<div style="padding:20px; text-align:center; color:#64748b;">Поиск заявки в базе данных...</div>';
    }

    try {
      const url = getAppPath(`api/check_status.php?query=${encodeURIComponent(query)}`);
      const res = await fetch(url);
      const data = await res.json();

      if (data.success && data.records && data.records.length > 0) {
        renderStatusResults(data.records);
      } else {
        if (resultsBox) {
          resultsBox.innerHTML = `
            <div style="padding:20px; background:#fff1f2; border:1px solid #fecdd3; border-radius:10px; color:#be123c; margin-top:20px; text-align:center;">
              ${uiIcon('warning')} По запросу «${escapeDemoHtml(query)}» среди доступных вам записей ничего не найдено. Проверьте номер телефона или код талона.
            </div>
          `;
        }
      }
    } catch (err) {
      if (resultsBox) resultsBox.innerHTML = '<div style="color:#ef4444;">Ошибка запроса статуса</div>';
    }
  });
}

function renderStatusResults(records) {
  const container = document.getElementById('statusResultsContainer');
  if (!container) return;

  const statusColors = {
    'new': '#f59e0b',
    'confirmed': '#0284c7',
    'in_work': '#ea580c',
    'done': '#10b981',
    'canceled': '#ef4444'
  };

  let html = '<div style="margin-top:25px; display:flex; flex-direction:column; gap:16px;">';
  records.forEach(r => {
    const color = statusColors[r.status] || '#64748b';
    html += `
      <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; box-shadow:var(--shadow-sm); border-left:5px solid ${color};">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
          <div>
            <span style="font-family:var(--font-mono); font-weight:700; font-size:1.15rem;">${r.booking_code}</span>
            <span style="margin-left:12px; font-weight:700; color:${color}; background:#f8fafc; padding:3px 10px; border-radius:12px; border:1px solid #e2e8f0;">
              ● ${r.status_label}
            </span>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; font-size:0.9rem; margin-bottom:14px;">
          <div><strong>Автомобиль:</strong> ${escapeDemoHtml(r.car)} (${escapeDemoHtml(r.plate_number)})</div>
          <div><strong>Визит:</strong> ${r.visit_date} в ${r.start_time} - ${r.end_time}</div>
          <div><strong>Пост:</strong> ${escapeDemoHtml(r.bay_name)}</div>
          <div><strong>Стоимость:</strong> <span style="font-weight:700; color:var(--primary);">${r.total_price.toLocaleString('ru-RU')} ₽</span></div>
        </div>

        <div style="background:#f8fafc; padding:10px 14px; border-radius:6px; font-size:0.86rem; border:1px solid #e2e8f0;">
          <strong>Услуги:</strong>
          <ul style="margin:6px 0 0 20px;">
            ${r.services.map(s => `<li>${escapeDemoHtml(s.title)} — ${parseInt(s.price_at_order, 10).toLocaleString('ru-RU')} ₽</li>`).join('')}
          </ul>
        </div>
      </div>
    `;
  });
  html += '</div>';

  container.innerHTML = html;
}

// Вспомогательное форматирование даты
function formatDateRus(dateStr) {
  if (!dateStr) return '';
  const parts = dateStr.split('-');
  if (parts.length !== 3) return dateStr;
  const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  const mIdx = parseInt(parts[1], 10) - 1;
  return `${parseInt(parts[2], 10)} ${months[mIdx]} ${parts[0]}`;
}

// Переключение табов сайта
function switchMainTab(tabId) {
  // Скрываем все вкладки
  document.querySelectorAll('.main-tab-pane').forEach(p => {
    p.classList.remove('active');
    p.style.display = 'none';
  });
  document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));

  // Активируем целевую вкладку
  const targetPane = document.getElementById(`tab-${tabId}`);
  if (targetPane) {
    targetPane.classList.add('active');
    targetPane.style.display = 'block';
  }

  const activeBtn = document.querySelector(`.nav-link[data-tab="${tabId}"]`);
  if (activeBtn) {
    activeBtn.classList.add('active');
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Быстрая подстановка кода или телефона в проверку статуса
function quickStatusLookup(query) {
  const input = document.getElementById('inputStatusQuery');
  const form = document.getElementById('statusLookupForm');
  if (input) {
    input.value = query;
  }
  if (form) {
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
  }
}

// Переход из прайс-листа прямо к выбору даты и расчету для выбранной услуги
function selectServiceAndGoToBooking(serviceId) {
  BookingState.selectedServices = [serviceId];
  BookingState.selectedTime = '';
  BookingState.selectedBayId = null;
  
  switchMainTab('booking');
  recalculateQuote();
  goToStep(2);
}

