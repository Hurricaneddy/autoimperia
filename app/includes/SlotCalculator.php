<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Класс расчета свободных временных слотов с защитой от овербукинга
 */

class SlotCalculator {
    private PDO $pdo;
    private string $workStart;
    private string $workEnd;
    private int $slotStep;

    public function __construct(PDO $pdo, string $workStart = '09:00', string $workEnd = '20:00', int $slotStep = 30) {
        $this->pdo = $pdo;
        $this->workStart = $workStart;
        $this->workEnd = $workEnd;
        $this->slotStep = $slotStep;
    }

    public static function isCalendarDate(string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    public function isBookableStart(string $date, int $start, int $durationMin): bool {
        $opens = strtotime($date . ' ' . $this->workStart);
        $closes = strtotime($date . ' ' . $this->workEnd);
        return $start >= $opens
            && $start + max($durationMin, $this->slotStep) * 60 <= $closes
            && ($start - $opens) % ($this->slotStep * 60) === 0
            && ($date !== date('Y-m-d') || $start >= time() + 1800);
    }

    /**
     * Расчет свободных временных окон на указанную дату
     * @param string $visitDate Дата в формате YYYY-MM-DD
     * @param int $durationMin Суммарная продолжительность работ в минутах
     * @param string $requiredBayType Требуемый тип поста ('lift', 'diag', 'wheel_align', 'tire')
     * @return array Список доступных слотов времени ['time' => '10:00', 'available_bays' => [1, 2]]
     */
    public function getAvailableSlots(string $visitDate, int $durationMin, string $requiredBayType = 'lift'): array {
        // Округляем длительность до шага слота
        if ($durationMin < $this->slotStep) {
            $durationMin = $this->slotStep;
        }

        // 1. Получаем все активные посты подходящего типа
        $bayStmt = $this->pdo->prepare("
            SELECT bay_id, bay_name, bay_type 
            FROM work_bays 
            WHERE is_active = 1 
              AND (bay_type = :bay_type OR :bay_type_all = 'any')
            ORDER BY bay_id ASC
        ");
        $bayStmt->execute([
            ':bay_type'     => $requiredBayType,
            ':bay_type_all' => $requiredBayType
        ]);
        $bays = $bayStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($bays)) {
            // Если специализированный пост не найден, ищем слесарный подъемник
            $bayStmt->execute([':bay_type' => 'lift', ':bay_type_all' => 'lift']);
            $bays = $bayStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($bays)) {
                return [];
            }
        }

        $bayIds = array_column($bays, 'bay_id');
        $placeholders = implode(',', array_fill(0, count($bayIds), '?'));

        // 2. Получаем все занятые интервалы на эту дату
        $busyStmt = $this->pdo->prepare("
            SELECT bay_id, start_time, end_time 
            FROM appointments 
            WHERE visit_date = ? 
              AND bay_id IN ($placeholders)
              AND status NOT IN ('canceled')
        ");
        $params = array_merge([$visitDate], $bayIds);
        $busyStmt->execute($params);
        $busyAppointments = $busyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Индексируем занятость по постам
        $bayBusyMap = [];
        foreach ($bayIds as $bId) {
            $bayBusyMap[$bId] = [];
        }
        foreach ($busyAppointments as $app) {
            $bayBusyMap[$app['bay_id']][] = [
                'start' => strtotime($visitDate . ' ' . $app['start_time']),
                'end'   => strtotime($visitDate . ' ' . $app['end_time'])
            ];
        }

        // 3. Формируем сетку кандидатов
        $workStartTs = strtotime($visitDate . ' ' . $this->workStart);
        $workEndTs   = strtotime($visitDate . ' ' . $this->workEnd);

        $availableSlots = [];
        $currentTs = $workStartTs;

        while ($currentTs + ($durationMin * 60) <= $workEndTs) {
            $slotStart = $currentTs;
            $slotEnd   = $currentTs + ($durationMin * 60);

            if (!$this->isBookableStart($visitDate, $slotStart, $durationMin)) {
                $currentTs += ($this->slotStep * 60);
                continue;
            }

            // Проверяем, свободен ли хотя бы один пост
            $freeBayIds = [];
            foreach ($bayIds as $bId) {
                $isBayFree = true;
                foreach ($bayBusyMap[$bId] as $busy) {
                    // Пересечение интервалов: start < busy_end AND end > busy_start
                    if ($slotStart < $busy['end'] && $slotEnd > $busy['start']) {
                        $isBayFree = false;
                        break;
                    }
                }
                if ($isBayFree) {
                    $freeBayIds[] = $bId;
                }
            }

            if (!empty($freeBayIds)) {
                $availableSlots[] = [
                    'time'           => date('H:i', $slotStart),
                    'end_time'       => date('H:i', $slotEnd),
                    'available_bays' => $freeBayIds,
                    'suggested_bay'  => $freeBayIds[0]
                ];
            }

            $currentTs += ($this->slotStep * 60);
        }

        return $availableSlots;
    }

    /**
     * Определение основного типа поста по списку услуг
     */
    public function determineBayType(array $serviceIds): string {
        if (empty($serviceIds)) {
            return 'lift';
        }
        $in = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $this->pdo->prepare("SELECT required_bay FROM services WHERE service_id IN ($in) AND is_active = 1");
        $stmt->execute(array_values($serviceIds));
        $types = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Приоритеты: сход-развал -> шиномонтаж -> диагностика -> подъемник
        if (in_array('wheel_align', $types, true)) return 'wheel_align';
        if (in_array('tire', $types, true)) return 'tire';
        if (in_array('diag', $types, true)) return 'diag';
        return 'lift';
    }
}

