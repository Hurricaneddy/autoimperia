<?php
/**
 * API: api/create_booking.php
 * Транзакционное создание заявки на ремонт с серверной защитой от овербукинга (SELECT ... FOR UPDATE)
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/SlotCalculator.php';
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/CarPriceParser.php';

header('Content-Type: application/json; charset=utf-8');

// Поддержка JSON payload и обычного POST
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

// 1. Извлечение и валидация входных данных
$clientName  = trim($data['client_name'] ?? '');
$clientPhone = trim($data['client_phone'] ?? '');
$clientEmail = trim($data['client_email'] ?? '');
$brand       = trim($data['brand'] ?? '');
$model       = trim($data['model'] ?? '');
$prodYear    = !empty($data['prod_year']) ? (int)$data['prod_year'] : null;
$plateNumber = trim($data['plate_number'] ?? '');
$visitDate   = trim($data['visit_date'] ?? '');
$startTime   = trim($data['start_time'] ?? '');
$serviceIds  = is_array($data['services'] ?? null) ? $data['services'] : [];
$clientComment = trim($data['client_comment'] ?? '');
$partsGrade  = trim($data['parts_grade'] ?? 'oem'); // 'none', 'analog', 'oem', 'premium'
$paymentType = trim($data['payment_type'] ?? 'onsite'); // 'deposit', 'full_online', 'onsite'
$paymentMethod = trim($data['payment_method'] ?? 'sbp'); // 'sbp', 'mir_card', 'tinkoff_pay', 'sber_pay'
$promoCode   = trim($data['promo_code'] ?? '');

$account = signedInClient($pdo);
if ($account) {
    $clientName = $account['full_name'];
    $clientPhone = $account['phone'];
}
$vehicleId = (int)($data['vehicle_id'] ?? 0);
if ($vehicleId) {
    $vehicle = $account ? ownedClientVehicle($pdo, (int)$account['client_id'], $vehicleId) : null;
    if (!$vehicle) {
        sendJsonResponse(['success' => false, 'error' => 'Автомобиль не найден в вашем кабинете'], 404);
    }
    $brand = $vehicle['brand'];
    $model = $vehicle['model'];
    $prodYear = $vehicle['prod_year'];
    $plateNumber = $vehicle['plate_number'] ?? '';
}
$rawOdometer = $data['odometer'] ?? '';
$odometer = is_scalar($rawOdometer) ? clientOdometer(trim((string)$rawOdometer)) : null;
if ($rawOdometer !== '' && !$odometer) {
    sendJsonResponse(['success' => false, 'error' => 'Укажите пробег целым числом от 1 до 2 000 000 км'], 400);
}

if (empty($clientName)) {
    sendJsonResponse(['success' => false, 'error' => 'Укажите ваше имя'], 400);
}
if (mb_strlen($clientComment) > 2000) {
    sendJsonResponse(['success' => false, 'error' => 'Комментарий должен быть не длиннее 2000 символов'], 400);
}

// Валидация телефона (минимум 10 цифр)
$digitsPhone = preg_replace('/\D+/', '', $clientPhone);
if (strlen($digitsPhone) < 10) {
    sendJsonResponse(['success' => false, 'error' => 'Укажите корректный контактный номер телефона'], 400);
}

if (empty($brand) || empty($model)) {
    sendJsonResponse(['success' => false, 'error' => 'Укажите марку и модель автомобиля'], 400);
}

if (!SlotCalculator::isCalendarDate($visitDate) || $visitDate < date('Y-m-d')) {
    sendJsonResponse(['success' => false, 'error' => 'Некорректная дата визита'], 400);
}

if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', $startTime)) {
    sendJsonResponse(['success' => false, 'error' => 'Укажите время визита'], 400);
}
// Нормализуем формат времени в HH:MM:00
if (strlen($startTime) === 5) {
    $startTime .= ':00';
}

if (empty($serviceIds)) {
    sendJsonResponse(['success' => false, 'error' => 'Выберите хотя бы одну услугу из каталога'], 400);
}

// 2. Расчет длительности и окончательной стоимости с учетом автомобиля
$parser = new CarPriceParser($pdo);
$carSpec = $parser->getCarMarketSpecs($brand, $model, $prodYear);
$quote = $parser->calculateServiceQuotes($serviceIds, $carSpec, $partsGrade);
if (!$quote['services']) {
    sendJsonResponse(['success' => false, 'error' => 'Выберите доступную услугу из каталога'], 400);
}

$durationMin = $quote['total_duration'] ?: 30;
$totalLabor  = $quote['total_labor'];
$totalParts  = $quote['total_parts'];
$subtotal    = $quote['total_price'];

// Расчет промокода
$discountAmount = 0.00;
if (!empty($promoCode)) {
    $promoStmt = $pdo->prepare("SELECT * FROM promotions WHERE code = :code AND is_active = 1");
    $promoStmt->execute([':code' => strtoupper($promoCode)]);
    $promo = $promoStmt->fetch(PDO::FETCH_ASSOC);
    if ($promo && $subtotal >= (float)$promo['min_order_sum']) {
        if ($promo['discount_type'] === 'fixed') {
            $discountAmount = min((float)$promo['discount_val'], $subtotal);
        } else {
            $discountAmount = round(($subtotal * (float)$promo['discount_val']) / 100);
        }
    }
}

// Скидка 5% при полной онлайн-оплате
if ($paymentType === 'full_online') {
    $onlineDiscount = round(($subtotal - $discountAmount) * (ONLINE_PAY_DISCOUNT_PERCENT / 100));
    $discountAmount += $onlineDiscount;
}

$finalTotalPrice = max(0, $subtotal - $discountAmount);

// Расчет времени окончания работ
$startTs = strtotime($visitDate . ' ' . $startTime);
$endTs   = $startTs + ($durationMin * 60);
$endTime = date('H:i:s', $endTs);

// 3. Выбор подходящего свободного поста с блокировкой от овербукинга
$calculator = new SlotCalculator($pdo);
if (!$calculator->isBookableStart($visitDate, $startTs, $durationMin)) {
    sendJsonResponse(['success' => false, 'error' => 'Это время недоступно. Выберите другой интервал в расписании.'], 409);
}
$requiredBayType = $calculator->determineBayType($serviceIds);

try {
    $pdo->beginTransaction();

    // Находим активные посты нужного типа
    $bayStmt = $pdo->prepare("
        SELECT bay_id, bay_name FROM work_bays 
        WHERE is_active = 1 AND (bay_type = :bay_type OR :bay_type_all = 'any') 
        ORDER BY bay_id ASC
    ");
    $bayStmt->execute([':bay_type' => $requiredBayType, ':bay_type_all' => $requiredBayType]);
    $compatibleBays = $bayStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($compatibleBays)) {
        // Резерв: универсальный подъемник
        $bayStmt->execute([':bay_type' => 'lift', ':bay_type_all' => 'lift']);
        $compatibleBays = $bayStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $assignedBayId = null;

    $forUpdate = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? '' : 'FOR UPDATE';

    $bayLock = $forUpdate ? $pdo->prepare('SELECT bay_id FROM work_bays WHERE bay_id = ? FOR UPDATE') : null;
    foreach ($compatibleBays as $bay) {
        // Блокируем сам пост, даже если на него ещё нет записей.
        $bayLock?->execute([$bay['bay_id']]);
        $checkSql = "
            SELECT appointment_id 
            FROM appointments 
            WHERE bay_id = :bay_id 
              AND visit_date = :visit_date 
              AND status NOT IN ('canceled')
              AND (
                  (start_time < :end_time AND end_time > :start_time)
              )
            {$forUpdate}
        ";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([
            ':bay_id'     => $bay['bay_id'],
            ':visit_date' => $visitDate,
            ':start_time' => $startTime,
            ':end_time'   => $endTime
        ]);

        if (!$checkStmt->fetch()) {
            // Этот пост свободен!
            $assignedBayId = (int)$bay['bay_id'];
            break;
        }
    }

    if (!$assignedBayId) {
        // Конфликт: все посты заняты на это время
        $pdo->rollBack();
        sendJsonResponse([
            'success' => false,
            'error'   => 'К сожалению, выбранный слот времени только что был занят. Пожалуйста, выберите другое время.'
        ], 409);
    }

    $clientId = (int)($account['client_id'] ?? $_SESSION['guest_clients'][clientPhoneKey($clientPhone)] ?? 0);
    if (!$clientId) {
        $clientId = insertReturningId($pdo, 'INSERT INTO clients (full_name, phone, email) VALUES (?, ?, ?)',
            [$clientName, $clientPhone, $clientEmail ?: null], 'client_id');
    }

    if (!$vehicleId) {
        $vehicleStmt = $pdo->prepare("
            SELECT vehicle_id FROM vehicles WHERE client_id = ?
              AND LOWER(brand) = LOWER(?) AND LOWER(model) = LOWER(?)
              AND COALESCE(prod_year, 0) = CAST(? AS INTEGER)
              AND UPPER(COALESCE(plate_number, '')) = UPPER(?)
            ORDER BY vehicle_id LIMIT 1
        ");
        $vehicleStmt->execute([$clientId, $brand, $model, $prodYear ?? 0, $plateNumber]);
        $vehicleId = (int)$vehicleStmt->fetchColumn();
    }
    if (!$vehicleId) {
        $vehicleId = insertReturningId($pdo, 'INSERT INTO vehicles (client_id, brand, model, prod_year, plate_number) VALUES (?, ?, ?, ?, ?)',
            [$clientId, $brand, $model, $prodYear, $plateNumber ?: null], 'vehicle_id');
    }

    // 6. Создание записи в таблице appointments
    $bookingCode = generateBookingCode();
    $paymentStatus = 'unpaid';
    $paidAmount = 0.00;

    if ($paymentType === 'full_online') {
        $paymentStatus = 'paid';
        $paidAmount = $finalTotalPrice;
    } elseif ($paymentType === 'deposit') {
        $paymentStatus = 'partially_paid';
        $paidAmount = min(PREPAY_DEPOSIT_RUB, $finalTotalPrice);
    }

    $appInsertSql = "
        INSERT INTO appointments 
        (booking_code, client_id, vehicle_id, bay_id, master_id, visit_date, start_time, end_time, 
         total_price, parts_price, discount_amount, payment_status, paid_amount, status, 
         client_comment, parts_included, parts_grade) 
        VALUES 
        (:code, :client_id, :vehicle_id, :bay_id, 1, :visit_date, :start_time, :end_time, 
         :total_price, :parts_price, :discount, :pay_status, :paid_amount, 'new', 
         :comment, :parts_inc, :parts_grade)
    ";
    $appointmentId = insertReturningId($pdo, $appInsertSql, [
        ':code'         => $bookingCode,
        ':client_id'    => $clientId,
        ':vehicle_id'   => $vehicleId,
        ':bay_id'       => $assignedBayId,
        ':visit_date'   => $visitDate,
        ':start_time'   => $startTime,
        ':end_time'     => $endTime,
        ':total_price'  => $finalTotalPrice,
        ':parts_price'  => $totalParts,
        ':discount'     => $discountAmount,
        ':pay_status'   => $paymentStatus,
        ':paid_amount'  => $paidAmount,
        ':comment'      => $clientComment ?: null,
        ':parts_inc'    => ($partsGrade !== 'none' ? 1 : 0),
        ':parts_grade'  => $partsGrade
    ], 'appointment_id');
    if ($odometer !== null) {
        $pdo->prepare('INSERT INTO appointment_odometer (appointment_id, kilometers) VALUES (?, ?)')
            ->execute([$appointmentId, $odometer]);
        $greatest = $forUpdate ? 'GREATEST' : 'MAX';
        $pdo->prepare("UPDATE vehicles SET mileage = $greatest(COALESCE(mileage, 0), CAST(? AS INTEGER)) WHERE vehicle_id = ?")
            ->execute([$odometer, $vehicleId]);
    }


    // 7. Добавление услуг в appointment_services
    $servInsertStmt = $pdo->prepare("
        INSERT INTO appointment_services (appointment_id, service_id, price_at_order, parts_cost, duration_min) 
        VALUES (:app_id, :serv_id, :price, :parts, :dur)
    ");
    foreach ($quote['services'] as $qs) {
        $servInsertStmt->execute([
            ':app_id'  => $appointmentId,
            ':serv_id' => $qs['service_id'],
            ':price'   => $qs['total_price'],
            ':parts'   => $qs['parts_cost'],
            ':dur'     => $qs['duration_min']
        ]);
    }

    // 8. Если была выбрана онлайн-оплата, фиксируем платеж в payments
    $paymentReceipt = null;
    if ($paidAmount > 0) {
        $payCode = 'PAY-' . str_replace('-', '', $bookingCode);
        $fiscalNumber = null;
        $fiscalQr = null;
        $payStmt = $pdo->prepare("
            INSERT INTO payments 
            (appointment_id, payment_code, amount, payment_type, payment_method, status, fiscal_receipt_number, fiscal_qr_payload) 
            VALUES 
            (:app_id, :code, :amount, :ptype, :pmethod, 'succeeded', :fisc_num, :fisc_qr)
        ");
        $payStmt->execute([
            ':app_id'   => $appointmentId,
            ':code'     => $payCode,
            ':amount'   => $paidAmount,
            ':ptype'    => ($paymentType === 'full_online' ? 'full_online' : 'deposit_prepay'),
            ':pmethod'  => $paymentMethod,
            ':fisc_num' => $fiscalNumber,
            ':fisc_qr'  => $fiscalQr
        ]);

        $paymentReceipt = [
            'payment_code'          => $payCode,
            'amount_paid'           => $paidAmount,
            'payment_method'        => $paymentMethod,
            'fiscal_receipt_number' => $fiscalNumber,
            'fiscal_qr'             => $fiscalQr,
            'paid_at'               => date('d.m.Y H:i:s')
        ];
    }

    $pdo->commit();
    if (!$account) {
        rememberGuestBooking($clientPhone, $clientId);
    }

    sendJsonResponse([
        'success'         => true,
        'booking_code'    => $bookingCode,
        'appointment_id'  => $appointmentId,
        'visit_date'      => $visitDate,
        'start_time'      => substr($startTime, 0, 5),
        'end_time'        => substr($endTime, 0, 5),
        'bay_id'          => $assignedBayId,
        'car'             => "{$brand} {$model}" . ($prodYear ? " ({$prodYear})" : ''),
        'total_price'     => $finalTotalPrice,
        'parts_price'     => $totalParts,
        'discount_amount' => $discountAmount,
        'payment_status'  => $paymentStatus,
        'paid_amount'     => $paidAmount,
        'payment_receipt' => $paymentReceipt,
        'ticket' => [
            'shop_name'    => APP_NAME,
            'shop_address' => APP_ADDRESS,
            'shop_phone'   => APP_PHONE,
            'client_name'  => $clientName,
            'client_phone' => $clientPhone
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonResponse([
        'success' => false,
        'error'   => 'Внутренняя ошибка при сохранении записи'
    ], 500);
}

