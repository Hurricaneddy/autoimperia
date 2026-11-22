<?php
/**
 * API: api/check_status.php
 * Проверка статуса записи клиентом по номеру телефона или коду бронирования (AI-YYYYMMDD-XXXX)
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/client_auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $query = isset($_GET['query']) ? trim($_GET['query']) : '';
    if (empty($query)) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Введите номер телефона или номер талона (AI-...)'
        ], 400);
    }

    $cleanDigits = preg_replace('/\D+/', '', $query);

    // Определение типа запроса: по коду бронирования или по номеру телефона
    if (strpos(strtoupper($query), 'AI-') === 0 || strlen($cleanDigits) < 10) {
        $whereSql = "a.booking_code = :code_exact";
        $params = [':code_exact' => strtoupper($query)];
    } else {
        $whereSql = "a.booking_code = :code_exact OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')  = :phone_like";
        $params = [
            ':code_exact' => strtoupper($query),
            ':phone_like' => $cleanDigits
        ];
    }

    $ownerConditions = [];
    foreach (sessionBookingClients() as $id) {
        $key = ':owner_' . count($ownerConditions);
        $ownerConditions[] = $key;
        $params[$key] = $id;
    }
    if (!$ownerConditions) {
        sendJsonResponse(['success' => false, 'error' => 'Записи в этом сеансе не найдены'], 404);
    }
    $owners = implode(',', $ownerConditions);
    $sql = "
        SELECT 
            a.appointment_id,
            a.booking_code,
            a.visit_date,
            a.start_time,
            a.end_time,
            a.total_price,
            a.parts_price,
            a.payment_status,
            a.paid_amount,
            a.status,
            a.client_comment,
            a.cancel_reason,
            a.created_at,
            c.full_name as client_name,
            c.phone as client_phone,
            v.brand,
            v.model,
            v.prod_year,
            v.plate_number,
            b.bay_name
        FROM appointments a
        JOIN clients c ON a.client_id = c.client_id
        JOIN vehicles v ON a.vehicle_id = v.vehicle_id
        LEFT JOIN work_bays b ON a.bay_id = b.bay_id
        WHERE ($whereSql) AND a.client_id IN ($owners)
        ORDER BY a.visit_date DESC, a.start_time DESC
        LIMIT 5
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($appointments)) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Запись с такими данными не найдена. Проверьте правильность кода бронирования или номера телефона.'
        ], 404);
    }

    // Для каждой заявки подтягиваем перечень услуг
    $servStmt = $pdo->prepare("
        SELECT s.title, aserv.price_at_order, aserv.duration_min 
        FROM appointment_services aserv
        JOIN services s ON aserv.service_id = s.service_id
        WHERE aserv.appointment_id = :app_id
    ");

    $statusNames = [
        'new'       => 'Новая (на рассмотрении)',
        'confirmed' => 'Подтверждена мастером',
        'in_work'   => 'Автомобиль в цехе / В работе',
        'done'      => 'Работы завершены (готов к выдаче)',
        'canceled'  => 'Отменена'
    ];

    $results = [];
    foreach ($appointments as $app) {
        $servStmt->execute([':app_id' => $app['appointment_id']]);
        $services = $servStmt->fetchAll(PDO::FETCH_ASSOC);

        $results[] = [
            'booking_code'    => $app['booking_code'],
            'status'          => $app['status'],
            'status_label'    => $statusNames[$app['status']] ?? $app['status'],
            'visit_date'      => date('d.m.Y', strtotime($app['visit_date'])),
            'visit_date_raw'  => $app['visit_date'],
            'start_time'      => substr($app['start_time'], 0, 5),
            'end_time'        => substr($app['end_time'], 0, 5),
            'bay_name'        => $app['bay_name'] ?: 'Слесарный пост',
            'client_name'     => $app['client_name'],
            'client_phone'    => $app['client_phone'],
            'car'             => "{$app['brand']} {$app['model']}" . ($app['prod_year'] ? " ({$app['prod_year']})" : ''),
            'plate_number'    => $app['plate_number'] ?: '—',
            'total_price'     => (float)$app['total_price'],
            'payment_status'  => $app['payment_status'],
            'paid_amount'     => (float)$app['paid_amount'],
            'client_comment'  => $app['client_comment'],
            'cancel_reason'   => $app['cancel_reason'],
            'services'        => $services,
            'shop_address'    => APP_ADDRESS,
            'shop_phone'      => APP_PHONE
        ];
    }

    sendJsonResponse([
        'success'      => true,
        'records_count'=> count($results),
        'records'      => $results
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при проверке статуса'
    ], 500);
}

