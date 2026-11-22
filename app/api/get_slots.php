<?php
/**
 * API: api/get_slots.php
 * Расчет свободных временных слотов на выбранную дату с учетом занятости постов
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/SlotCalculator.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $visitDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
    $servicesRaw = isset($_GET['services']) ? trim($_GET['services']) : '';

    // Валидация даты (YYYY-MM-DD)
    if (!SlotCalculator::isCalendarDate($visitDate)) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Укажите существующую дату в формате ГГГГ-ММ-ДД'
        ], 400);
    }

    // Нельзя выбирать дату в прошлом
    if ($visitDate < date('Y-m-d')) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Нельзя выбрать прошедшую дату'
        ], 400);
    }

    $serviceIds = [];
    if (!empty($servicesRaw)) {
        foreach (explode(',', $servicesRaw) as $item) {
            $val = (int)trim($item);
            if ($val > 0) {
                $serviceIds[] = $val;
            }
        }
    }

    $calculator = new SlotCalculator($pdo, WORK_START_TIME, WORK_END_TIME, SLOT_STEP_MINUTES);

    // Рассчитываем суммарную продолжительность выбранных услуг
    $totalDuration = 30; // минимум 30 мин
    $bayType = 'lift';

    if (!empty($serviceIds)) {
        $in = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $pdo->prepare("SELECT SUM(duration_min) as total_dur FROM services WHERE service_id IN ($in) AND is_active = 1");
        $stmt->execute($serviceIds);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['total_dur'])) {
            $totalDuration = (int)$res['total_dur'];
        }

        $bayType = $calculator->determineBayType($serviceIds);
    }

    $slots = $calculator->getAvailableSlots($visitDate, $totalDuration, $bayType);

    sendJsonResponse([
        'success'           => true,
        'date'              => $visitDate,
        'total_duration'    => $totalDuration,
        'required_bay_type' => $bayType,
        'slots_count'       => count($slots),
        'slots'             => $slots
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при расчете временных слотов'
    ], 500);
}

