<?php
declare(strict_types=1);

function clientDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('d.m.Y');
}

function clientNumber(int|float $number): string
{
    return number_format($number, 0, ',', ' ');
}

function serviceDueDate(DateTimeImmutable $last, int $months): DateTimeImmutable
{
    $first = $last->modify('first day of this month')->modify("+$months months");
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min((int)$last->format('j'), (int)$first->format('t')));
}

function maintenanceForVehicle(PDO $pdo, int $clientId, array $vehicle): array
{
    // Интервалы учебные. Комплексное ТО включает масло и оба фильтра.
    $rules = [
        ['title' => 'Моторное масло и масляный фильтр', 'services' => [1, 5], 'months' => 12, 'km' => 10000],
        ['title' => 'Воздушный и салонный фильтры', 'services' => [2, 5], 'months' => 12, 'km' => 15000],
        ['title' => 'Тормозная жидкость', 'services' => [4], 'months' => 24, 'km' => null],
        ['title' => 'Охлаждающая жидкость', 'services' => [3], 'months' => 48, 'km' => null],
    ];
    $today = new DateTimeImmutable('today');
    $stmt = $pdo->prepare("SELECT s.service_id, s.title, COUNT(*) OVER (PARTITION BY s.service_id) AS times_done,
        a.visit_date, ao.kilometers,
        LEAD(a.visit_date) OVER (PARTITION BY s.service_id ORDER BY a.visit_date DESC, a.appointment_id DESC) AS previous_date,
        ROW_NUMBER() OVER (PARTITION BY s.service_id ORDER BY a.visit_date DESC, a.appointment_id DESC) AS occurrence
        FROM appointments a JOIN appointment_services item ON item.appointment_id = a.appointment_id
        JOIN services s ON s.service_id = item.service_id
        LEFT JOIN appointment_odometer ao ON ao.appointment_id = a.appointment_id
        WHERE a.client_id = ? AND a.vehicle_id = ? AND a.status = 'done' AND a.visit_date <= ?
        ORDER BY a.visit_date DESC, a.appointment_id DESC");
    $stmt->execute([$clientId, $vehicle['vehicle_id'], $today->format('Y-m-d')]);
    $latest = [];
    foreach ($stmt as $event) {
        if ((int)$event['occurrence'] === 1) {
            $latest[(int)$event['service_id']] = $event;
        }
    }

    foreach ($rules as &$rule) {
        $event = null;
        foreach ($latest as $serviceId => $candidate) {
            if (in_array($serviceId, $rule['services'], true)) {
                $event = $candidate;
                break;
            }
        }
        $rule['last'] = $event;
        $rule['due_date'] = $event ? serviceDueDate(new DateTimeImmutable($event['visit_date']), $rule['months']) : null;
        $rule['due_km'] = $event && $event['kilometers'] && $rule['km'] ? (int)$event['kilometers'] + $rule['km'] : null;
        $rule['overdue'] = $event && ($today >= $rule['due_date'] || ($rule['due_km'] !== null && $vehicle['mileage'] >= $rule['due_km']));
    }
    unset($rule);
    usort($rules, static fn ($a, $b) => $b['overdue'] <=> $a['overdue']);
    return ['rules' => $rules, 'recent' => $latest];
}
