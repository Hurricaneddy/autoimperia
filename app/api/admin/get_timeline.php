<?php
/**
 * API: api/admin/get_timeline.php
 * Выборка расписания загрузки постов автосервиса для диспетчерского пульта мастера-приёмщика
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка сессии сотрудника
requireStaffAuth(true);

try {
    $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    // 1. Загрузка всех активных постов
    $baysStmt = $pdo->query("SELECT * FROM work_bays WHERE is_active = 1 ORDER BY bay_id ASC");
    $bays = $baysStmt->fetchAll(PDO::FETCH_ASSOC);

    $journal = ($_GET['view'] ?? '') === 'journal';
    $conditions = [];
    $params = [];
    $order = 'a.start_time ASC, a.appointment_id ASC';
    $pageSize = 10;
    $page = 1;
    $pages = 1;
    $total = 0;

    if (!$journal || ($_GET['scope'] ?? '') === 'day') {
        $conditions[] = 'a.visit_date = :date';
        $params[':date'] = $date;
    }
    if ($journal) {
        if (($_GET['scope'] ?? '') === 'history') {
            $conditions[] = "a.status IN ('done', 'canceled')";
        }
        $status = $_GET['status'] ?? 'all';
        if (in_array($status, ['new', 'confirmed', 'in_work', 'done', 'canceled'], true)) {
            $conditions[] = 'a.status = :status';
            $params[':status'] = $status;
        }

        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
        if ($search !== '') {
            $pdo->sqliteCreateFunction('order_search_lower', static fn ($text) => mb_strtolower((string)$text));
            $pdo->sqliteCreateFunction('order_search_digits', static fn ($text) => preg_replace('/\D+/', '', (string)$text));
            $conditions[] = "(
                INSTR(order_search_lower(CONCAT(a.booking_code, ' ', c.full_name, ' ', c.phone, ' ',
                    v.brand, ' ', v.model, ' ', v.plate_number, ' ', a.client_comment)), :search) > 0
                OR (CAST(:phone_enabled AS INTEGER) = 1 AND INSTR(order_search_digits(c.phone), :search_phone) > 0)
            )";
            $params[':search'] = mb_strtolower($search);
            $digits = preg_replace('/\D+/', '', $search);
            $phoneLike = strlen($digits) >= 4 && preg_match('/^[+\d\s().-]+$/', $search);
            $params[':phone_enabled'] = $phoneLike ? '1' : '0';
            $params[':search_phone'] = strlen($digits) === 11 ? substr($digits, 1) : $digits;
        }

        $order = match ($_GET['sort'] ?? '') {
            'visit_asc' => 'a.visit_date ASC, a.start_time ASC, a.appointment_id ASC',
            'visit_desc' => 'a.visit_date DESC, a.start_time DESC, a.appointment_id DESC',
            'price_asc' => 'a.total_price ASC, a.appointment_id DESC',
            'price_desc' => 'a.total_price DESC, a.appointment_id DESC',
            default => 'a.created_at DESC, a.appointment_id DESC',
        };
    }

    $from = "FROM appointments a
        JOIN clients c ON a.client_id = c.client_id
        JOIN vehicles v ON a.vehicle_id = v.vehicle_id
        LEFT JOIN staff_users u ON a.master_id = u.user_id";
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

    if ($journal) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) $from $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    }

    $appSql = "
        SELECT 
            a.appointment_id,
            a.booking_code,
            a.bay_id,
            a.master_id,
            a.visit_date,
            a.start_time,
            a.end_time,
            a.total_price,
            a.parts_price,
            a.payment_status,
            a.paid_amount,
            a.status,
            a.client_comment,
            a.internal_notes,
            a.created_at,
            c.full_name as client_name,
            c.phone as client_phone,
            v.brand,
            v.model,
            v.prod_year,
            v.plate_number,
            u.full_name as master_name
        $from $where
        ORDER BY $order
    ";
    if ($journal) {
        $offset = ($page - 1) * $pageSize;
        $appSql .= " LIMIT $pageSize OFFSET $offset";
    }
    $appStmt = $pdo->prepare($appSql);
    $appStmt->execute($params);
    $appointments = $appStmt->fetchAll(PDO::FETCH_ASSOC);

    // Загрузка услуг для каждой заявки
    $servStmt = $pdo->prepare("
        SELECT s.title, aserv.price_at_order, aserv.duration_min 
        FROM appointment_services aserv
        JOIN services s ON aserv.service_id = s.service_id
        WHERE aserv.appointment_id = :id
    ");

    $appsByBay = [];
    foreach ($bays as $bay) {
        $appsByBay[$bay['bay_id']] = [];
    }

    $summaryCounts = [
        'total'     => count($appointments),
        'new'       => 0,
        'confirmed' => 0,
        'in_work'   => 0,
        'done'      => 0,
        'canceled'  => 0,
        'revenue'   => 0.00
    ];

    foreach ($appointments as &$item) {
        $servStmt->execute([':id' => $item['appointment_id']]);
        $item['services'] = $servStmt->fetchAll(PDO::FETCH_ASSOC);
        $item['start_hm'] = substr($item['start_time'], 0, 5);
        $item['end_hm']   = substr($item['end_time'], 0, 5);

        // Статистика
        $st = $item['status'];
        if (isset($summaryCounts[$st])) {
            $summaryCounts[$st]++;
        }
        if ($st !== 'canceled') {
            $summaryCounts['revenue'] += (float)$item['total_price'];
        }

        if (!empty($item['bay_id']) && isset($appsByBay[$item['bay_id']])) {
            $appsByBay[$item['bay_id']][] = $item;
        }
    }
    unset($item);

    if ($journal) {
        sendJsonResponse([
            'success' => true,
            'orders' => $appointments,
            'bays' => $bays,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    sendJsonResponse([
        'success'        => true,
        'date'           => $date,
        'date_formatted' => date('d.m.Y', strtotime($date)),
        'work_start'     => WORK_START_TIME,
        'work_end'       => WORK_END_TIME,
        'bays'           => $bays,
        'timeline'       => $appsByBay,
        'all_records'    => $appointments,
        'summary'        => $summaryCounts
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при формировании таймлайна'
    ], 500);
}

