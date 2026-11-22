<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/client_maintenance.php';
require_once __DIR__ . '/../includes/ui_icons.php';

$client = signedInClient($pdo);
if (!$client) {
    clientRedirect('/client/login.php');
}

$clientId = (int)$client['client_id'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clientFormValue($_POST, 'action');
    if (!checkClientCsrf()) {
        http_response_code(403);
        $error = 'Форма устарела. Откройте страницу заново.';
    } elseif ($action === 'logout') {
        unset($_SESSION['client_id'], $_SESSION['client_csrf']);
        session_regenerate_id(true);
        clientRedirect('/client/login.php');
    } elseif ($action === 'mileage') {
        $id = (int)clientFormValue($_POST, 'vehicle_id');
        $km = clientOdometer(clientFormValue($_POST, 'mileage'));
        if (!$km) {
            $error = 'Укажите пробег целым числом от 1 до 2 000 000 км.';
        } elseif (!ownedClientVehicle($pdo, $clientId, $id)) {
            http_response_code(404);
            $error = 'Автомобиль не найден в вашем гараже.';
        } else {
            $pdo->prepare('UPDATE vehicles SET mileage = ? WHERE vehicle_id = ? AND client_id = ?')
                ->execute([$km, $id, $clientId]);
            $_SESSION['client_message'] = 'Пробег обновлён.';
            clientRedirect('/client/index.php?vehicle=' . $id . '#maintenance');
        }
    } elseif ($action === 'add_vehicle') {
        $brand = clientFormValue($_POST, 'brand');
        $model = clientFormValue($_POST, 'model');
        $plate = mb_strtoupper(clientFormValue($_POST, 'plate'));
        $yearInput = clientFormValue($_POST, 'year');
        $year = filter_var($yearInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => (int)date('Y') + 1]]) ?: null;
        $kmInput = clientFormValue($_POST, 'mileage');
        $km = clientOdometer($kmInput);
        if ($brand === '' || $model === '' || mb_strlen($brand) > 50 || mb_strlen($model) > 60 || mb_strlen($plate) > 20) {
            $error = 'Введите марку и модель. Госномер может содержать до 20 символов.';
        } elseif (($yearInput !== '' && !$year) || ($kmInput !== '' && !$km)) {
            $error = 'Проверьте год выпуска и пробег автомобиля.';
        } else {
            $vehicleId = insertReturningId($pdo,
                'INSERT INTO vehicles (client_id, brand, model, plate_number, prod_year, mileage) VALUES (?, ?, ?, ?, ?, ?)',
                [$clientId, $brand, $model, $plate ?: null, $year, $km ?? 0], 'vehicle_id');
            $_SESSION['client_message'] = 'Автомобиль добавлен.';
            clientRedirect('/client/index.php?vehicle=' . $vehicleId);
        }
    }
}

$message = $_SESSION['client_message'] ?? '';
unset($_SESSION['client_message']);
$garage = $pdo->prepare('SELECT * FROM vehicles WHERE client_id = ? ORDER BY vehicle_id DESC');
$garage->execute([$clientId]);
$vehicles = $garage->fetchAll();
$vehicleId = isset($_GET['vehicle']) ? (int)clientFormValue($_GET, 'vehicle') : (int)($vehicles[0]['vehicle_id'] ?? 0);
$vehicle = $vehicleId ? ownedClientVehicle($pdo, $clientId, $vehicleId) : null;
if ($vehicleId && !$vehicle) {
    http_response_code(404);
    $error = $error ?: 'Автомобиль не найден в вашем гараже.';
}
$maintenance = $vehicle ? maintenanceForVehicle($pdo, $clientId, $vehicle) : null;
$history = ($_GET['view'] ?? '') === 'history';
$page = max(1, (int)clientFormValue($_GET, 'page'));
$pages = 1;
$orders = [];
if ($vehicle) {
    $when = $history
        ? "(a.status IN ('done', 'canceled') OR a.visit_date < :today)"
        : "(a.status NOT IN ('done', 'canceled') AND a.visit_date >= :today)";
    $condition = "WHERE a.client_id = :client AND a.vehicle_id = :vehicle AND $when";
    $params = ['client' => $clientId, 'vehicle' => $vehicleId, 'today' => date('Y-m-d')];
    $count = $pdo->prepare("SELECT COUNT(*) FROM appointments a $condition");
    $count->execute($params);
    $pages = max(1, (int)ceil($count->fetchColumn() / 10));
    $page = min($page, $pages);
    $offset = ($page - 1) * 10;
    $direction = $history ? 'DESC' : 'ASC';
    $workList = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? 'STRING_AGG(s.title, chr(10) ORDER BY i.item_id)'
        : 'GROUP_CONCAT(s.title, char(10))';
    $list = $pdo->prepare("SELECT a.booking_code, a.visit_date, a.start_time, a.status, a.client_comment,
        a.total_price, ao.kilometers, (SELECT $workList FROM appointment_services i
            JOIN services s ON s.service_id = i.service_id WHERE i.appointment_id = a.appointment_id) AS work
        FROM appointments a LEFT JOIN appointment_odometer ao ON ao.appointment_id = a.appointment_id
        $condition ORDER BY a.visit_date $direction, a.start_time $direction, a.appointment_id $direction
        LIMIT 10 OFFSET $offset");
    $list->execute($params);
    $orders = $list->fetchAll();
}
$cabinetUrl = BASE_URL . '/client/index.php?vehicle=' . $vehicleId;
$statuses = ['new' => 'Новая', 'confirmed' => 'Подтверждена', 'in_work' => 'В работе', 'done' => 'Работы завершены', 'canceled' => 'Отменена'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Личный кабинет · Автоимперия</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/booking-widget.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/client-account.css">
</head>
<body>
<header class="account-header">
  <div class="account-wrap account-header-inner">
    <a class="account-brand" href="<?= BASE_URL ?>/"><?= uiIcon('crown') ?> Автоимперия</a>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= clientCsrfToken() ?>">
      <button class="btn btn-secondary" name="action" value="logout"><?= uiIcon('exit') ?> Выйти</button>
    </form>
  </div>
</header>
<main class="account-wrap account-main">
  <header class="account-intro">
    <h1>Личный кабинет</h1>
    <p class="account-muted"><?= clientHtml($client['full_name']) ?> · <?= clientHtml($client['email']) ?></p>
  </header>
  <?php if (!PERSISTENT_DB): ?><p class="account-notice">Учебный режим. Записи хранятся во временной базе этого сеанса.</p><?php endif; ?>
  <?php if ($message): ?><p class="account-notice" role="status"><?= clientHtml($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="account-error" role="alert"><?= clientHtml($error) ?></p><?php endif; ?>
  <div class="account-layout">
    <aside class="account-panel">
      <h2><?= uiIcon('car') ?> Мои автомобили</h2>
      <?php if (!$vehicles): ?><p class="account-muted">Добавьте автомобиль, чтобы вести его историю.</p><?php endif; ?>
      <nav class="account-garage" aria-label="Автомобили">
        <?php foreach ($vehicles as $car): ?>
          <a href="<?= BASE_URL ?>/client/index.php?vehicle=<?= (int)$car['vehicle_id'] ?>" <?= (int)$car['vehicle_id'] === $vehicleId ? 'aria-current="page"' : '' ?>>
            <strong><?= clientHtml($car['brand'] . ' ' . $car['model']) ?></strong>
            <span><?= clientHtml(implode(' · ', array_filter([$car['prod_year'], $car['plate_number']]))) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <details <?= !$vehicles || ($error && ($_POST['action'] ?? '') === 'add_vehicle') ? 'open' : '' ?>>
        <summary>Добавить автомобиль</summary>
        <form method="post" class="account-form" action="<?= BASE_URL ?>/client/index.php">
          <input type="hidden" name="csrf" value="<?= clientCsrfToken() ?>">
          <label>Марка<input class="form-control" name="brand" maxlength="50" required></label>
          <label>Модель<input class="form-control" name="model" maxlength="60" required></label>
          <label>Год выпуска<input class="form-control" type="number" name="year" min="1900" max="<?= date('Y') + 1 ?>"></label>
          <label>Госномер<input class="form-control" name="plate" maxlength="20"></label>
          <label>Текущий пробег, км<input class="form-control" type="number" name="mileage" min="1" max="2000000"></label>
          <button class="btn btn-primary" name="action" value="add_vehicle">Добавить</button>
        </form>
      </details>
    </aside>
    <div class="account-content">
    <?php if ($vehicle): ?>
      <section class="account-panel account-vehicle">
        <div>
          <h2><?= clientHtml($vehicle['brand'] . ' ' . $vehicle['model']) ?></h2>
          <p class="account-muted"><?= $vehicle['mileage'] ? 'Пробег ' . clientNumber((int)$vehicle['mileage']) . ' км' : 'Пробег пока не указан' ?></p>
        </div>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/?vehicle=<?= $vehicleId ?>"><?= uiIcon('tool') ?> Записаться на ремонт</a>
      </section>
      <section class="account-panel" id="appointments">
        <h2><?= uiIcon('list') ?> Записи и ремонты</h2>
        <nav class="account-tabs" aria-label="Разделы записей">
          <a href="<?= $cabinetUrl ?>#appointments" <?= !$history ? 'aria-current="page"' : '' ?>>Предстоящие</a>
          <a href="<?= $cabinetUrl ?>&amp;view=history#appointments" <?= $history ? 'aria-current="page"' : '' ?>>История ремонтов</a>
        </nav>
        <?php if (!$orders): ?><p class="account-muted"><?= $history ? 'Прошлых записей по этому автомобилю пока нет.' : 'По этому автомобилю нет предстоящих записей.' ?></p><?php endif; ?>
        <?php foreach ($orders as $order): ?>
          <article class="account-order">
            <div class="account-order-head">
              <strong><?= clientDate($order['visit_date']) ?> · <?= clientHtml(substr($order['start_time'], 0, 5)) ?></strong>
              <span class="account-badge <?= $order['status'] === 'done' ? 'account-badge-done' : '' ?>"><?= clientHtml($statuses[$order['status']] ?? $order['status']) ?></span>
            </div>
            <p class="account-muted"><?= clientHtml($order['booking_code']) ?> · <?= clientNumber((float)$order['total_price']) ?> ₽<?= $order['kilometers'] ? ' · ' . clientNumber((int)$order['kilometers']) . ' км при записи' : '' ?></p>
            <?php if ($order['work']): ?>
              <ul><?php foreach (explode("\n", $order['work']) as $work): ?><li><?= clientHtml($work) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($order['client_comment']): ?><p class="account-comment"><?= clientHtml($order['client_comment']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
        <?php if ($pages > 1): ?>
          <nav class="account-pagination" aria-label="Страницы записей">
            <?php $pageUrl = $cabinetUrl . ($history ? '&amp;view=history' : '') . '&amp;page='; ?>
            <?php if ($page > 1): ?><a href="<?= $pageUrl . ($page - 1) ?>#appointments">Назад</a><?php endif; ?>
            <span>Страница <?= $page ?> из <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= $pageUrl . ($page + 1) ?>#appointments">Вперёд</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      </section>
      <section class="account-panel" id="maintenance">
        <h2><?= uiIcon('calendar') ?> Рекомендации по обслуживанию</h2>
        <p class="account-muted">Срок считается от последней завершённой работы по этому автомобилю. Интервалы учебные, для своего авто сверяйтесь с регламентом производителя.</p>
        <form class="account-mileage" method="post" action="<?= $cabinetUrl ?>#maintenance">
          <input type="hidden" name="csrf" value="<?= clientCsrfToken() ?>">
          <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
          <label>Текущий пробег, км<input class="form-control" type="number" name="mileage" min="1" max="2000000" value="<?= $vehicle['mileage'] ?: '' ?>" required></label>
          <button class="btn btn-secondary" name="action" value="mileage">Обновить пробег</button>
        </form>
        <div class="account-recommendations">
          <?php foreach ($maintenance['rules'] as $rule): ?>
            <article class="account-rule <?= $rule['overdue'] ? 'account-rule-due' : '' ?>">
              <h3><?= clientHtml($rule['title']) ?></h3>
              <?php if ($rule['last']): ?>
                <p>Последняя работа <?= clientDate($rule['last']['visit_date']) ?></p>
                <p><strong><?= $rule['overdue'] ? 'Пора планировать' : 'Следующий ориентир' ?></strong><br>
                  <?= clientDate($rule['due_date']->format('Y-m-d')) ?><?= $rule['due_km'] ? ' или ' . clientNumber($rule['due_km']) . ' км' : '' ?>
                </p>
                <?php if ($rule['km'] && !$rule['due_km']): ?><p class="account-muted">Пробег при последней записи не указан.</p><?php endif; ?>
              <?php else: ?>
                <p>Нет завершённой работы в истории. Срок появится после её выполнения.</p>
              <?php endif; ?>
              <p class="account-muted">Интервал <?= $rule['months'] ?> мес.<?= $rule['km'] ? ' или ' . clientNumber($rule['km']) . ' км' : '' ?></p>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($maintenance['recent']): ?>
          <details class="account-recent">
            <summary>Периодичность выполненных работ</summary>
            <ul>
              <?php foreach ($maintenance['recent'] as $work): ?>
                <li>
                  <?= clientHtml($work['title']) ?> · <?= (int)$work['times_done'] ?> в истории, последняя <?= clientDate($work['visit_date']) ?>
                  <?php if ($work['previous_date']): ?>
                    · между двумя последними работами <?= (new DateTimeImmutable($work['previous_date']))->diff(new DateTimeImmutable($work['visit_date']))->days ?> дн.
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="account-panel"><h2>История и напоминания</h2><p class="account-muted">Добавьте или выберите автомобиль в гараже.</p></section>
    <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
