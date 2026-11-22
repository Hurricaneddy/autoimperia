<?php
/**
 * API: api/admin/quick_add.php
 * Быстрое создание записи мастером-приёмщиком при телефонном звонке
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

requireStaffAuth(true);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$phone       = trim($data['phone'] ?? '');
$fullName    = trim($data['full_name'] ?? '');
$brand       = trim($data['brand'] ?? '');
$model       = trim($data['model'] ?? '');
$plateNumber = trim($data['plate_number'] ?? '');
$bayId       = !empty($data['bay_id']) ? (int)$data['bay_id'] : 1;
$visitDate   = trim($data['visit_date'] ?? date('Y-m-d'));
$startTime   = trim($data['start_time'] ?? '10:00');
$durationMin = !empty($data['duration_min']) ? (int)$data['duration_min'] : 60;
$totalPrice  = !empty($data['total_price']) ? (float)$data['total_price'] : 1500.00;
$comment     = trim($data['comment'] ?? 'Запись оформлена по телефону');
$serviceTitle= trim($data['service_title'] ?? 'Слесарные работы / ТО');

if (empty($phone) || empty($fullName) || empty($brand)) {
    sendJsonResponse(['success' => false, 'error' => 'Заполните обязательные поля: телефон, имя, марка авто'], 400);
}
if (mb_strlen($comment) > 2000) {
    sendJsonResponse(['success' => false, 'error' => 'Комментарий должен быть не длиннее 2000 символов'], 400);
}

if (strlen($startTime) === 5) {
    $startTime .= ':00';
}
$startTs = strtotime($visitDate . ' ' . $startTime);
$endTs   = $startTs + ($durationMin * 60);
$endTime = date('H:i:s', $endTs);

try {
    $pdo->beginTransaction();

    // 1. Проверка доступности поста
    $forUpdate = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? '' : 'FOR UPDATE';
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
        ':bay_id'     => $bayId,
        ':visit_date' => $visitDate,
        ':start_time' => $startTime,
        ':end_time'   => $endTime
    ]);

    if ($checkStmt->fetch()) {
        $pdo->rollBack();
        sendJsonResponse([
            'success' => false,
            'error'   => 'Выбранный пост уже занят на этот интервал времени'
        ], 409);
    }

    // 2. Клиент
    $clStmt = $pdo->prepare("SELECT client_id FROM clients WHERE phone = :phone AND clients.notes = 'Внесён мастером' LIMIT 1");
    $clStmt->execute([':phone' => $phone]);
    $client = $clStmt->fetch(PDO::FETCH_ASSOC);

    if ($client) {
        $clientId = (int)$client['client_id'];
    } else {
        $insCl = $pdo->prepare("INSERT INTO clients (full_name, phone, notes) VALUES (:name, :phone, 'Внесён мастером')");
        $insCl->execute([':name' => $fullName, ':phone' => $phone]);
        $clientId = (int)$pdo->lastInsertId();
    }

    // 3. Автомобиль
    $vhStmt = $pdo->prepare("SELECT vehicle_id FROM vehicles WHERE client_id = :cid AND brand = :brand AND model = :model LIMIT 1");
    $vhStmt->execute([':cid' => $clientId, ':brand' => $brand, ':model' => $model]);
    $veh = $vhStmt->fetch(PDO::FETCH_ASSOC);

    if ($veh) {
        $vehicleId = (int)$veh['vehicle_id'];
    } else {
        $insVh = $pdo->prepare("INSERT INTO vehicles (client_id, brand, model, plate_number) VALUES (:cid, :brand, :model, :plate)");
        $insVh->execute([':cid' => $clientId, ':brand' => $brand, ':model' => $model, ':plate' => $plateNumber ?: null]);
        $vehicleId = (int)$pdo->lastInsertId();
    }

    // 4. Запись
    $bookingCode = generateBookingCode();
    $masterUser = getAuthUser();
    $masterId = $masterUser ? (int)$masterUser['id'] : 1;

    $appStmt = $pdo->prepare("
        INSERT INTO appointments 
        (booking_code, client_id, vehicle_id, bay_id, master_id, visit_date, start_time, end_time, total_price, status, client_comment, internal_notes) 
        VALUES 
        (:code, :cid, :vid, :bid, :mid, :vdate, :stime, :etime, :price, 'confirmed', :comment, 'Оформлено по телефону')
    ");
    $appStmt->execute([
        ':code'    => $bookingCode,
        ':cid'     => $clientId,
        ':vid'     => $vehicleId,
        ':bid'     => $bayId,
        ':mid'     => $masterId,
        ':vdate'   => $visitDate,
        ':stime'   => $startTime,
        ':etime'   => $endTime,
        ':price'   => $totalPrice,
        ':comment' => $comment
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    // 5. Услуга по умолчанию
    $servStmt = $pdo->prepare("
        INSERT INTO appointment_services (appointment_id, service_id, price_at_order, duration_min) 
        VALUES (:aid, 1, :price, :dur)
    ");
    $servStmt->execute([':aid' => $appointmentId, ':price' => $totalPrice, ':dur' => $durationMin]);

    $pdo->commit();

    sendJsonResponse([
        'success'        => true,
        'booking_code'   => $bookingCode,
        'appointment_id' => $appointmentId,
        'message'        => 'Запись по звонку успешно сохранена в расписании'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при сохранении быстрой записи'
    ], 500);
}

